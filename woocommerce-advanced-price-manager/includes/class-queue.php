<?php
/**
 * WAPM_Queue — لایه‌ی زمان‌بندی (Action Scheduler با fallback به WP-Cron)
 *
 * چرا Action Scheduler: ووکامرس از نسخه‌ی ۴ آن را همراه دارد و اجرای خودش
 * مطمئن‌تر از WP-Cron است — صف در دیتابیس است، خطاها ثبت می‌شوند، اجرای
 * هم‌زمان مدیریت می‌شود و اگر سایت ترافیک نداشته باشد، «wp-cron» ممکن است
 * ساعت‌ها دیر کند یا هرگز اجرا نشود. برای عملیات قیمت (که تغییر پول است)
 * دیرکردن یعنی قیمت اشتباه روی سایت.
 *
 * چرا fallback: ووکامرس همیشه Action Scheduler را لود نمی‌کند (نسخه‌های قدیمی،
 * غیرفعال بودن ابزارها، یا بارگذاری دیرهنگام). افزونه باید در هر حالتی کار کند.
 *
 * هیچ‌جا زمان‌بندی دوباره ساخته نمی‌شود: `schedule_hourly()` idempotent است تا
 * شبکه‌ی وردپرس یا چند فراخوانی هم‌زمان، چند اجرای موازی نسازد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Queue {

	/** نام اکشن زمان‌بندی‌شده (همان hook قبلی — سازگاری با نسخه‌های پیشین). */
	const HOOK = 'wapm_run_schedules';

	/** گروه اکشن‌ها در Action Scheduler. */
	const GROUP = 'wapm';

	/** گزینه‌ای که موتور استفاده‌شده را برای نمایش/دیباگ نگه می‌دارد. */
	const OPTION_ENGINE = 'wapm_queue_engine';

	/**
	 * آیا Action Scheduler در دسترس است؟
	 *
	 * فقط توابع عمومی AS بررسی می‌شوند؛ وجود کلاس کافی نیست، چون در بعضی
	 * نصب‌ها کتابخانه لود می‌شود ولی API عمومی در دسترس نیست.
	 */
	public static function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * زمان‌بندی ساعتانه — اگر از قبل هست، کاری نمی‌کند.
	 *
	 * @return bool آیا در این فراخوانی ساخته شد؟
	 */
	public static function schedule_hourly(): bool {
		if ( self::next_run() > 0 ) {
			self::remember_engine();
			return false;
		}

		if ( self::uses_action_scheduler() ) {
			// اولین اجرا یک دقیقه بعد، بعدی‌ها هر ساعت.
			as_schedule_recurring_action( time() + 60, HOUR_IN_SECONDS, self::HOOK, [], self::GROUP );
		} else {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}

		self::remember_engine();
		return true;
	}

	/**
	 * حذف همه‌ی زمان‌بندی‌های این افزونه (غیرفعال‌سازی/حذف افزونه).
	 *
	 * هم مسیر Action Scheduler و هم WP-Cron پاک می‌شوند: اگر کاربر از نسخه‌ی
	 * قبلی (wp-cron) به‌روزرسانی کرده باشد و AS فعال شده باشد، بقایای مسیر قدیمی
	 * نباید بماند و اجرای دوباره بسازد.
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}

		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::OPTION_ENGINE );
	}

	/**
	 * زمان اجرای بعدی (timestamp) — 0 اگر چیزی زمان‌بندی نشده باشد.
	 */
	public static function next_run(): int {
		if ( self::uses_action_scheduler() ) {
			$next = as_next_scheduled_action( self::HOOK, [], self::GROUP );
			if ( is_numeric( $next ) && (int) $next > 0 ) {
				return (int) $next;
			}
		}

		$next = wp_next_scheduled( self::HOOK );
		return $next ? (int) $next : 0;
	}

	/**
	 * تعداد اکشن‌های در انتظار در گروه این افزونه (برای نمایش در پنل).
	 */
	public static function pending_count(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$ids = as_get_scheduled_actions(
			[
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
				'return'   => 'ids',
			],
			'ids'
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}

	/**
	 * وضعیت کوتاه برای نمایش در پنل/پاسخ Ajax.
	 *
	 * @return array{engine:string,label:string,next:int,next_label:string,pending:int}
	 */
	public static function state(): array {
		$as    = self::uses_action_scheduler();
		$next  = self::next_run();
		$label = $as ? 'Action Scheduler' : 'WP-Cron';

		return [
			'engine'     => $as ? 'action-scheduler' : 'wp-cron',
			'label'      => $label,
			'next'       => $next,
			'next_label' => $next > 0
				? sprintf(
					/* translators: %s: date/time of the next scheduled run */
					__( 'اجرای بعدی: %s', 'wapm' ),
					wp_date( 'Y/m/d H:i', $next )
				)
				: __( 'زمان‌بندی فعالی وجود ندارد.', 'wapm' ),
			'pending'    => self::pending_count(),
		];
	}

	/**
	 * مهاجرت خودکار از wp-cron به Action Scheduler.
	 *
	 * چرا لازم است: نسخه‌ی قبلی با `wp_schedule_event` زمان‌بندی می‌ساخت. اگر
	 * همان رویداد باقی بماند، هم wp-cron و هم AS اجرا می‌کنند — دو اجرای هم‌زمانِ
	 * یک کار. این تابع یک بار (تا وقتی گزینه ثبت شود) رویداد قدیمی را پاک
	 * می‌کند و از مسیر تازه زمان‌بندی می‌سازد.
	 */
	public static function maybe_migrate(): void {
		$engine = (string) get_option( self::OPTION_ENGINE, '' );
		$target = self::uses_action_scheduler() ? 'action-scheduler' : 'wp-cron';

		if ( $engine === $target && self::next_run() > 0 ) {
			return;
		}

		if ( 'action-scheduler' === $target && wp_next_scheduled( self::HOOK ) ) {
			// بقایای مسیر قدیمی — قبل از زمان‌بندی تازه پاک می‌شود.
			wp_clear_scheduled_hook( self::HOOK );
		}

		if ( self::next_run() <= 0 ) {
			self::schedule_hourly();
			return;
		}

		self::remember_engine();
	}

	/** ثبت موتور فعلی برای پرهیز از مهاجرت تکراری در هر بار لود. */
	private static function remember_engine(): void {
		update_option(
			self::OPTION_ENGINE,
			self::uses_action_scheduler() ? 'action-scheduler' : 'wp-cron',
			false
		);
	}
}
