<?php
/**
 * حذف کامل داده‌های افزونه هنگام «حذف» آن از پیشخوان.
 *
 * ## قاعده‌ی تصمیم
 *
 * «غیرفعال‌کردن» هیچ داده‌ای را پاک نمی‌کند (در `WAPM_Plugin::deactivate()` فقط کرون
 * برداشته می‌شود). «حذف» یعنی کاربر تصمیم گرفته افزونه را برای همیشه بردارد، پس
 * پاک‌سازی کامل انجام می‌شود: تنظیمات ترب، قوانین، زمان‌بندی‌ها، تاریخچه‌ی قیمت و
 * موجودی، متاهای مقایسه‌ی ترب روی محصولات و لیست فیلترهای ذخیره‌شده‌ی کاربران.
 *
 * اگر کسی می‌خواهد داده بماند (مثلاً برای نصب دوباره یا مهاجرت)، می‌تواند یکی از
 * این دو را فعال کند:
 *
 *     define( 'WAPM_KEEP_DATA_ON_UNINSTALL', true );   // در wp-config.php
 *     add_filter( 'wapm_keep_data_on_uninstall', '__return_true' ); // در یک mu-plugin
 *
 * در آن حالت فقط چیزهای موقت (کرون و transient) پاک می‌شوند.
 *
 * ## چرا این فایل مستقل است
 *
 * وردپرس `uninstall.php` را در بوت قبل از بارگذاری کلاس‌های افزونه اجرا می‌کند؛
 * پس هیچ کلاسی از افزونه این‌جا در دسترس نیست و همه‌ی کلیدها عمداً «رشته‌ی خام»
 * نوشته شده‌اند. برای اینکه در آینده از کلاس‌ها عقب نماند، یک تست در
 * `tests/run.php` فهرست کلیدها را با ثابت‌های کلاس‌ها مقایسه می‌کند.
 *
 * @package WAPM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * آیا داده باید حفظ شود؟
 */
function wapm_uninstall_keep_data(): bool {
	if ( defined( 'WAPM_KEEP_DATA_ON_UNINSTALL' ) && WAPM_KEEP_DATA_ON_UNINSTALL ) {
		return true;
	}

	return (bool) apply_filters( 'wapm_keep_data_on_uninstall', false );
}

/**
 * پاک‌سازی یک سایت (در مالتی‌سایت، برای هر سایت جدا صدا زده می‌شود).
 */
function wapm_uninstall_site(): void {
	global $wpdb;

	$keep = wapm_uninstall_keep_data();

	// ۱) زمان‌بندی — در هر حالت باید برود، وگرنه بعد از حذف افزونه یک هوک
	//    بی‌صاحب ساعت‌به‌ساعت اجرا می‌شود. هر دو مسیر پاک می‌شود: Action
	//    Scheduler (اگر ووکامرس لودش کرده باشد) و WP-Cron.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'wapm_run_schedules', [], 'wapm' );
	}
	wp_clear_scheduled_hook( 'wapm_run_schedules' );

	// ۲) transientهای ثابت.
	$transients = [
		'wapm_torob_title_index',
	];
	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}

	// ۳) transientهای پویا (کلید نرخ درخواست بوکمارکلت شامل هش توکن است، پس
	//    فهرست ثابت ندارد). مستقیم روی جدول options کار می‌کنیم تا یک کوئری باشد.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wapm_torob_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wapm_torob_rl_' ) . '%'
		)
	);

	// ۴) کش گذرای جدول‌های افزونه (اگر هست) — با حذف جدول خودش می‌رود، ولی
	//    برای حالت «حفظ داده» هم لازم است.
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}

	if ( $keep ) {
		return;
	}

	// ۵) گزینه‌ها.
	$options = [
		// نسخه‌ی ساختار داده.
		'wapm_db_version',
		'wapm_stock_db_version',
		// قوانین و زمان‌بندی.
		'wapm_rules',
		'wapm_schedules',
		'wapm_schedules_lock',
		// موتور زمان‌بندی (Action Scheduler / wp-cron).
		'wapm_queue_engine',
		// ترب — تنظیمات و آمار.
		'wapm_torob_settings',
		'wapm_torob_shop_id',
		'wapm_torob_bm_token',
		'wapm_torob_unmatched',
		'wapm_torob_last_sync',
		'wapm_torob_last_catalog',
		// ترب — وضعیت کلاینت API.
		'wapm_torob_api_cooldown',
		'wapm_torob_api_last_call',
		'wapm_torob_api_budget',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// ۶) متاهای محصول. `delete_post_meta_by_key` یک کوئری است، نه یکی برای هر محصول.
	$post_meta = [
		// تاریخچه/قواعد.
		'_wapm_last_changed',
		'_wapm_exclude',
		// ترب.
		'_wapm_torob_prk',
		'_wapm_torob_name',
		'_wapm_torob_price',
		'_wapm_torob_our_price',
		'_wapm_torob_sellers',
		'_wapm_torob_sellers_list',
		'_wapm_torob_url',
		'_wapm_torob_checked_at',
		'_wapm_torob_our_checked',
		'_wapm_torob_card_price',
		'_wapm_torob_source',
		'_wapm_torob_link',
		'_wapm_torob_status',
		'_wapm_torob_diff_pct',
	];

	foreach ( $post_meta as $key ) {
		delete_post_meta_by_key( $key );
	}

	// ۷) فیلترهای ذخیره‌شده‌ی هر کاربر (متای کاربر، نه گزینه).
	delete_metadata( 'user', 0, 'wapm_saved_filters', '', true );

	// ۸) جدول‌های اختصاصی تاریخچه.
	$tables = [
		$wpdb->prefix . 'wapm_price_log',
		$wpdb->prefix . 'wapm_stock_log',
	];

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}

if ( is_multisite() ) {
	$wapm_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ( (array) $wapm_site_ids as $wapm_site_id ) {
		switch_to_blog( (int) $wapm_site_id );
		wapm_uninstall_site();
		restore_current_blog();
	}

	// آپشن‌های سطح شبکه (اگر روزی چیزی این‌جا ذخیره شد).
	if ( ! wapm_uninstall_keep_data() ) {
		delete_site_option( 'wapm_network_settings' );
	}
} else {
	wapm_uninstall_site();
}
