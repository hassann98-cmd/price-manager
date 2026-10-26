<?php
/**
 * WAPM_Rules — سیستم قوانین قیمت‌گذاری
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Rules {

	const OPTION = 'wapm_rules';

	/**
	 * همه‌ی قوانین، مرتب‌شده بر اساس اولویت (کم = زودتر).
	 *
	 * @return array<int,array>
	 */
	public static function all(): array {
		$rules = get_option( self::OPTION, [] );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}
		usort( $rules, fn( $a, $b ) => ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 ) );
		return $rules;
	}

	/**
	 * ساخت/به‌روزرسانی یک قانون از ورودی خام فرم.
	 *
	 * فیلترها و تنظیمات از همان sanitizerهای موتور قیمت رد می‌شوند تا قانونی که
	 * از فرم می‌آید با قانونی که از API می‌آید یک شکل باشد. `last_run` با
	 * ویرایش بازنویسی نمی‌شود (وگرنه هر ویرایش، اجرای خودکار بعدی را جلو
	 * می‌انداخت).
	 *
	 * @return array قانون ذخیره‌شده
	 */
	public static function save_rule( array $raw ): array {
		$rules = self::all();

		$rule = [
			'id'       => sanitize_key( $raw['id'] ?? '' ) ?: 'rule_' . wp_generate_password( 8, false ),
			'title'    => sanitize_text_field( $raw['title'] ?? '' ),
			'priority' => intval( $raw['priority'] ?? 10 ),
			'enabled'  => ! empty( $raw['enabled'] ),
			// اجرای خودکار: قبلاً «قوانین» فقط با دکمه‌ی دستی کار می‌کردند و
			// «اولویت»/«بازه اعتبار» القای خودکاری می‌داد. حالا خودکار بودن
			// یک انتخاب صریح است (پیش‌فرض: خاموش) و در جدول هم نمایش داده می‌شود.
			'auto'     => ! empty( $raw['auto'] ),
			'last_run' => 0,
			'start'    => sanitize_text_field( $raw['start'] ?? '' ), // اختیاری: قبل از این تاریخ اجرا نشود
			'end'      => sanitize_text_field( $raw['end'] ?? '' ),   // اختیاری: بعد از این تاریخ اجرا نشود
			'filters'  => WAPM_Product_Handler::sanitize_filters( (array) ( $raw['filters'] ?? [] ) ),
			'settings' => WAPM_Price_Engine::sanitize_settings( (array) ( $raw['settings'] ?? [] ) ),
		];

		$replaced = false;
		foreach ( $rules as $i => $existing ) {
			if ( $existing['id'] === $rule['id'] ) {
				// زمان آخرین اجرای خودکار با ویرایش قانون از بین نمی‌رود؛
				// وگرنه هر ویرایش، اجرای خودکار بعدی را بلافاصله جلو می‌انداخت.
				$rule['last_run'] = (int) ( $existing['last_run'] ?? 0 );
				$rules[ $i ]      = $rule;
				$replaced         = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$rules[] = $rule;
		}

		update_option( self::OPTION, $rules, false );
		return $rule;
	}

	/**
	 * حذف یک قانون با شناسه (اگر نباشد، بی‌اثر است).
	 */
	public static function delete_rule( string $id ): void {
		$rules = array_values( array_filter( self::all(), fn( $r ) => $r['id'] !== $id ) );
		update_option( self::OPTION, $rules, false );
	}

	/**
	 * روشن/خاموش کردن قانون بدون دست‌زدن به بقیه‌ی فیلدها.
	 */
	public static function toggle_rule( string $id, bool $enabled ): void {
		$rules = self::all();
		foreach ( $rules as &$r ) {
			if ( $r['id'] === $id ) {
				$r['enabled'] = $enabled;
			}
		}
		update_option( self::OPTION, $rules, false );
	}

	/**
	 * یک قانون با شناسه، یا null.
	 */
	public static function get_rule( string $id ): ?array {
		foreach ( self::all() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * آیا قانون در بازه زمانی مجاز است؟
	 *
	 * `start`/`end` اختیاری‌اند و با `strtotime` خوانده می‌شوند؛ رشته‌ی
	 * نامعتبر (strtotime = false) به‌معنای «بدون محدودیت» است، نه «همیشه غیرفعال».
	 */
	public static function is_rule_active_now( array $rule ): bool {
		$now = current_time( 'timestamp' );
		if ( ! empty( $rule['start'] ) && $now < strtotime( $rule['start'] ) ) {
			return false;
		}
		if ( ! empty( $rule['end'] ) && $now > strtotime( $rule['end'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * فاصله‌ی پیش‌فرض بین دو اجرای خودکار یک قانون (ثانیه).
	 *
	 * چرا روزانه و نه ساعتی: قانونی مثل «+۵٪» اگر هر ساعت اجرا شود، در یک روز
	 * ۸۰٪ گران‌تر می‌شود. اجرای روزانه قابل پیش‌بینی است و با فیلتر
	 * `wapm_rules_auto_interval` عوض می‌شود.
	 */
	public static function auto_interval(): int {
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'wapm_rules_auto_interval', DAY_IN_SECONDS ) );
	}

	/**
	 * قوانینی که همین حالا باید خودکار اجرا شوند (به ترتیب اولویت).
	 *
	 * شرط‌ها: فعال && خودکار && داخل بازه‌ی اعتبار && فاصله از اجرای قبلی گذشته.
	 *
	 * @return array<int,array>
	 */
	public static function due_auto_rules( int $now = 0 ): array {
		$now   = $now > 0 ? $now : current_time( 'timestamp' );
		$gap   = self::auto_interval();
		$due   = [];

		foreach ( self::all() as $rule ) { // all() با اولویت مرتب است.
			if ( empty( $rule['enabled'] ) || empty( $rule['auto'] ) ) {
				continue;
			}
			if ( ! self::is_rule_active_now( $rule ) ) {
				continue;
			}

			$last = (int) ( $rule['last_run'] ?? 0 );
			if ( $last > 0 && ( $now - $last ) < $gap ) {
				continue;
			}

			$due[] = $rule;
		}

		return $due;
	}

	/**
	 * ثبت زمان اجرای خودکار یک قانون.
	 */
	public static function mark_auto_run( string $id, int $now = 0 ): void {
		$rules = self::all();
		$now   = $now > 0 ? $now : current_time( 'timestamp' );

		foreach ( $rules as $i => $rule ) {
			if ( $rule['id'] === $id ) {
				$rules[ $i ]['last_run'] = $now;
			}
		}

		update_option( self::OPTION, $rules, false );
	}

	/**
	 * اجرای خودکار قوانین سررسیدشده (از کرون/صف صدا زده می‌شود).
	 *
	 * محدودیت زمانی و سقف صفحه دارد تا اجرای cron سایت را قفل نکند؛ اگر کار
	 * تمام نشد، دفعه‌ی بعد ادامه می‌دهد (قانون `last_run` نمی‌خورد).
	 *
	 * @param int $max_seconds سقف زمانی این اجرا
	 * @param int $max_pages   سقف صفحه برای هر قانون
	 * @return array{ran:int,processed:int,left:int}
	 */
	public static function run_auto( int $max_seconds = 120, int $max_pages = 200 ): array {
		$started   = time();
		$ran       = 0;
		$processed = 0;
		$due       = self::due_auto_rules();

		foreach ( $due as $rule ) {
			if ( ( time() - $started ) >= $max_seconds ) {
				break;
			}

			$batch_id = 'rule_auto_' . wp_generate_password( 10, false );
			$paged    = 1;
			$done     = false;

			for ( $i = 0; $i < $max_pages; $i++ ) {
				$result = self::run_rule_batch( $rule, $batch_id, $paged );
				$processed += (int) ( $result['processed'] ?? 0 );

				if ( ! empty( $result['skipped'] ) ) {
					$done = true; // خارج از بازه‌ی اعتبار — اجرا لازم نیست.
					break;
				}

				if ( ! empty( $result['done'] ) ) {
					$done = true;
					break;
				}

				$paged++;

				if ( ( time() - $started ) >= $max_seconds ) {
					break;
				}
			}

			if ( $done ) {
				self::mark_auto_run( $rule['id'] );
				$ran++;
			}
		}

		return [
			'ran'       => $ran,
			'processed' => $processed,
			'left'      => max( 0, count( $due ) - $ran ),
		];
	}

	/**
	 * اجرای یک قانون به صورت Batch (یک صفحه در هر فراخوانی Ajax).
	 *
	 * صفحه‌به‌صفحه اجرا می‌شود تا هر درخواست کوتاه بماند و کاربر در اجرای
	 * دستی، پیشرفت را ببیند. `skipped` یعنی قانون بیرون از بازه‌ی اعتبار است.
	 *
	 * @param int $paged    شماره‌ی صفحه (از ۱)
	 * @param int $per_page تعداد محصول در هر صفحه
	 * @return array{processed:int,total:int,done:bool,batch_id:string,skipped?:string}
	 */
	public static function run_rule_batch( array $rule, string $batch_id, int $paged, int $per_page = 25 ): array {
		if ( ! self::is_rule_active_now( $rule ) ) {
			return [ 'processed' => 0, 'total' => 0, 'done' => true, 'batch_id' => $batch_id, 'skipped' => 'date_window' ];
		}

		$targets = WAPM_Product_Handler::get_target_ids( $rule['filters'], $paged, $per_page );

		$processed = 0;
		foreach ( $targets['ids'] as $pid ) {
			WAPM_Price_Engine::apply_to_product( $pid, $rule['settings'], $batch_id, 'rule:' . $rule['id'] );
			$processed++;
		}

		$done = ( $paged * $per_page ) >= $targets['total'] || empty( $targets['ids'] );

		if ( $done && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return [
			'processed' => $processed,
			'total'     => $targets['total'],
			'done'      => $done,
			'batch_id'  => $batch_id,
		];
	}
}
