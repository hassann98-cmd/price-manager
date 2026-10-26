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
			'status'    => 'pending', // pending | applied | reverted
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

				// ۱) اعمال تغییر در تاریخ شروع
				if ( 'pending' === $schedule['status'] && $start_ts && $now >= $start_ts ) {
					$batch_id = 'sch_' . wp_generate_password( 12, false );
					$this->run_full( $schedule['filters'], $schedule['settings'], $batch_id, 'schedule:' . $schedule['id'] );
					$schedule['status']   = 'applied';
					$schedule['batch_id'] = $batch_id;
					self::update_schedule( $schedule );
				}

				// ۲) بازگشت خودکار در تاریخ پایان
				if ( 'applied' === $schedule['status'] && $end_ts && $now >= $end_ts && $schedule['batch_id'] ) {
					WAPM_Logger::rollback_batch( $schedule['batch_id'] );
					$schedule['status'] = 'reverted';
					self::update_schedule( $schedule );
				}
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * قفل ساده مبتنی بر wp_options. add_option() به دلیل ایندکس UNIQUE روی
	 * option_name در سطح دیتابیس اتمیک است، پس برای جلوگیری از اجرای هم‌زمان کافی است.
	 */
	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}

		// قفل موجود است؛ اگر خیلی قدیمی است (مثلاً اجرای قبلی کرش کرده) آزادش کن.
		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $locked_at && ( time() - $locked_at ) > self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, time(), '', false );
		}

		return false;
	}

	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * اجرای کامل روی همه محصولات هدف (در بستر Cron).
	 */
	private function run_full( array $filters, array $settings, string $batch_id, string $operation ): void {
		$paged    = 1;
		$per_page = 50;

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 ); // اجرای cron — بدون محدودیت زمانی.
		}

		do {
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
