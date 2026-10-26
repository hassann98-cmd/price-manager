<?php
/**
 * WAPM_Scheduler — زمان‌بندی تغییر قیمت + بازگشت خودکار
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Scheduler {

	const OPTION      = 'wapm_schedules';
	const LOCK_OPTION = 'wapm_schedules_lock';
	const LOCK_TTL    = 600; // ثانیه — قفل قدیمی (مثلاً بعد از کرش) بعد از این مدت آزاد می‌شود.

	/**
	 * مقدار قفلِ همین اجرا (timestamp|token) — برای heartbeat و آزادکردن امن.
	 */
	private static string $lock_value = '';

	public function __construct() {
		add_action( 'wapm_run_schedules', [ $this, 'run_due_schedules' ] );
	}

	public static function all(): array {
		$schedules = get_option( self::OPTION, [] );
		return is_array( $schedules ) ? $schedules : [];
	}

	public static function save_schedule( array $raw ): array {
		$schedules = self::all();

		$schedule = [
			'id'        => sanitize_key( $raw['id'] ?? '' ) ?: 'sch_' . wp_generate_password( 8, false ),
			'title'     => sanitize_text_field( $raw['title'] ?? '' ),
			'start'     => sanitize_text_field( $raw['start'] ?? '' ), // Y-m-d H:i به وقت سایت
			'end'       => sanitize_text_field( $raw['end'] ?? '' ),   // اختیاری — بازگشت خودکار
			'filters'   => WAPM_Product_Handler::sanitize_filters( (array) ( $raw['filters'] ?? [] ) ),
			'settings'  => WAPM_Price_Engine::sanitize_settings( (array) ( $raw['settings'] ?? [] ) ),
			'status'    => 'pending', // pending | running | applied | reverting | reverted
			'batch_id'  => '',
			'created'   => current_time( 'mysql' ),
		];

		$replaced = false;
		foreach ( $schedules as $i => $existing ) {
			if ( $existing['id'] === $schedule['id'] ) {
				$schedule['status']   = $existing['status'];
				$schedule['batch_id'] = $existing['batch_id'];
				$schedules[ $i ]      = $schedule;
				$replaced             = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$schedules[] = $schedule;
		}

		update_option( self::OPTION, $schedules, false );
		return $schedule;
	}

	public static function delete_schedule( string $id ): void {
		$schedules = array_values( array_filter( self::all(), fn( $s ) => $s['id'] !== $id ) );
		update_option( self::OPTION, $schedules, false );
	}

	private static function update_schedule( array $schedule ): void {
		$schedules = self::all();
		foreach ( $schedules as $i => $s ) {
			if ( $s['id'] === $schedule['id'] ) {
				$schedules[ $i ] = $schedule;
			}
		}
		update_option( self::OPTION, $schedules, false );
	}

	/**
	 * اجرای زمان‌بندی‌های سررسیدشده (هر ساعت با WP-Cron).
	 * برای زمان‌بندی‌ها اجرای کامل (بدون Ajax) لازم است؛ صفحه به صفحه با محدودیت زمانی.
	 */
	public function run_due_schedules(): void {
		// جلوگیری از اجرای هم‌زمان (مثلاً چند spawn موازی WP-Cron) که می‌تواند
		// یک زمان‌بندی را دوبار اعمال کند (double-apply قیمت).
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$now = current_time( 'timestamp' );

			foreach ( self::all() as $schedule ) {
				$start_ts = $schedule['start'] ? strtotime( $schedule['start'] ) : 0;
				$end_ts   = $schedule['end'] ? strtotime( $schedule['end'] ) : 0;

				// ۱) اعمال تغییر در تاریخ شروع.
				//
				// idempotency: batch_id *قبل از* اجرا ساخته و ذخیره می‌شود و وضعیت روی
				// «running» می‌رود. اگر اجرا وسط کار قطع شود (timeout/OOM/ری‌استارت)،
				// اجرای بعدی همین batch_id را ادامه می‌دهد؛ چون
				// WAPM_Logger::product_done_in_batch() محصولات انجام‌شده را رد می‌کند،
				// تغییر دوباره اعمال نمی‌شود. (قبلاً batch_id تازه ساخته می‌شد و کل
				// تغییر — مثلاً +۱۰٪ — برای بار دوم اعمال می‌شد.)
				if ( in_array( $schedule['status'], [ 'pending', 'running' ], true ) && $start_ts && $now >= $start_ts ) {
					if ( empty( $schedule['batch_id'] ) ) {
						$schedule['batch_id'] = 'sch_' . wp_generate_password( 12, false );
					}
					$schedule['status'] = 'running';
					self::update_schedule( $schedule ); // ← قبل از اجرا، نه بعد از آن.

					$this->run_full( $schedule['filters'], $schedule['settings'], $schedule['batch_id'], 'schedule:' . $schedule['id'] );

					$schedule['status'] = 'applied';
					self::update_schedule( $schedule );
				}

				// ۲) بازگشت خودکار در تاریخ پایان.
				// بازگردانی هم idempotent است: هر ردیف قیمت قبلی خودش را می‌نویسد.
				if ( in_array( $schedule['status'], [ 'applied', 'reverting' ], true ) && $end_ts && $now >= $end_ts && $schedule['batch_id'] ) {
					if ( 'applied' === $schedule['status'] ) {
						$schedule['status'] = 'reverting';
						self::update_schedule( $schedule );
					}

					// force=true: پایان یک کمپین زمان‌بندی‌شده قرارداد صریح کاربر است،
					// پس حتی اگر کسی وسط کمپین قیمتی را عوض کرده باشد، بازگشت انجام می‌شود
					// (در غیر این صورت کمپین «reverted» ثبت می‌شد ولی قیمت‌ها نیمه‌کاره می‌ماندند).
					WAPM_Logger::rollback_batch( $schedule['batch_id'], true );

					$schedule['status'] = 'reverted';
					self::update_schedule( $schedule );
				}
			}

			// ۳) قوانین خودکار (P1-11).
			//
			// قبلاً تب «قوانین» فقط با دکمه‌ی «اجرا» کار می‌کرد؛ «اولویت» و «بازه
			// اعتبار» خودکار بودن را القا می‌کردند. حالا قانونی که صریحاً «خودکار»
			// علامت خورده، این‌جا و داخل همین قفل اجرا می‌شود (نه در اجرای موازی).
			if ( class_exists( 'WAPM_Rules' ) ) {
				$result = WAPM_Rules::run_auto();
				if ( $result['ran'] > 0 || $result['left'] > 0 ) {
					update_option(
						'wapm_rules_last_auto',
						[
							'at'        => current_time( 'mysql' ),
							'ran'       => (int) $result['ran'],
							'processed' => (int) $result['processed'],
							'left'      => (int) $result['left'],
						],
						false
					);
				}
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * قفل ساده مبتنی بر wp_options. add_option() به دلیل ایندکس UNIQUE روی
	 * option_name در سطح دیتابیس اتمیک است، پس برای جلوگیری از اجرای هم‌زمان کافی است.
	 *
	 * مقدار قفل «timestamp|token» است: توکن اجازه می‌دهد فقط صاحب قفل آن را آزاد کند
	 * (اگر قفل کهنه توسط اجرای دیگری تصاحب شده باشد، اجرای اول قفل او را پاک نمی‌کند).
	 */
	private static function acquire_lock(): bool {
		self::$lock_value = time() . '|' . wp_generate_password( 12, false );

		if ( add_option( self::LOCK_OPTION, self::$lock_value, '', false ) ) {
			return true;
		}

		// قفل موجود است؛ اگر خیلی قدیمی است (مثلاً اجرای قبلی کرش کرده) آزادش کن.
		$locked_at = (int) strtok( (string) get_option( self::LOCK_OPTION, '' ), '|' );
		if ( $locked_at && ( time() - $locked_at ) > self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, self::$lock_value, '', false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * تمدید قفلِ در اختیار (heartbeat).
	 * اجرای یک کاتالوگ بزرگ می‌تواند بیشتر از LOCK_TTL طول بکشد؛ بدون تمدید،
	 * اجرای دوم قفل را «کهنه» می‌دید و هم‌زمان شروع می‌شد.
	 */
	public static function refresh_lock(): void {
		if ( '' === self::$lock_value ) {
			return;
		}
		if ( (string) get_option( self::LOCK_OPTION, '' ) === self::$lock_value ) {
			update_option( self::LOCK_OPTION, self::$lock_value, false );
		}
	}

	private static function release_lock(): void {
		// فقط قفلی که خودمان گرفتیم را آزاد کن.
		if ( '' !== self::$lock_value && (string) get_option( self::LOCK_OPTION, '' ) === self::$lock_value ) {
			delete_option( self::LOCK_OPTION );
		}
		self::$lock_value = '';
	}

	/**
	 * اجرای کامل روی همه محصولات هدف (در بستر Cron).
	 * قابل ادامه‌دادن است: با همان batch_id، محصولات انجام‌شده دوباره اعمال نمی‌شوند.
	 */
	private function run_full( array $filters, array $settings, string $batch_id, string $operation ): void {
		$paged    = 1;
		$per_page = 50;

		if ( function_exists( 'wc_set_time_limit' ) ) {
			// اجرای cron — افزایش محدودیت زمانی؛ ولی هاست می‌تواند همچنان قطع کند،
			// به همین دلیل این متد باید قابل ادامه‌دادن باشد.
			wc_set_time_limit( 300 );
		}

		do {
			self::refresh_lock(); // heartbeat تا اجرای موازی شروع نشود.

			$targets = WAPM_Product_Handler::get_target_ids( $filters, $paged, $per_page );
			foreach ( $targets['ids'] as $pid ) {
				WAPM_Price_Engine::apply_to_product( $pid, $settings, $batch_id, $operation );
			}
			$paged++;
			$more = ! empty( $targets['ids'] ) && ( ( $paged - 1 ) * $per_page ) < $targets['total'];
		} while ( $more );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
	}
}
