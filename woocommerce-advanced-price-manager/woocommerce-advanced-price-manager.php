<?php
/**
 * Plugin Name:       WooCommerce Advanced Price Manager
 * Plugin URI:        https://jluxe.ir
 * Description:       مدیریت حرفه‌ای تغییرات گروهی قیمت محصولات ووکامرس — درصدی، عددی، قوانین، زمان‌بندی، پیش‌نمایش، تاریخچه و Rollback.
 * Version:           1.1.3
 * Author:            Hassan
 * Text Domain:       wapm
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WAPM_VERSION', '1.1.3' );
define( 'WAPM_FILE', __FILE__ );
define( 'WAPM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WAPM_URL', plugin_dir_url( __FILE__ ) );

/**
 * HPOS / custom order tables compatibility declaration.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

final class WAPM_Plugin {

	private static ?WAPM_Plugin $instance = null;

	public static function instance(): WAPM_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'init' ], 20 );
		register_activation_hook( WAPM_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( WAPM_FILE, [ $this, 'deactivate' ] );
	}

	public function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'افزونه WooCommerce Advanced Price Manager برای کار کردن به ووکامرس نیاز دارد.', 'wapm' ) .
					'</p></div>';
			} );
			return;
		}

		load_plugin_textdomain( 'wapm', false, dirname( plugin_basename( WAPM_FILE ) ) . '/languages' );

		require_once WAPM_PATH . 'includes/class-str.php';
		require_once WAPM_PATH . 'includes/class-logger.php';
		require_once WAPM_PATH . 'includes/class-price-engine.php';
		require_once WAPM_PATH . 'includes/class-product-handler.php';
		require_once WAPM_PATH . 'includes/class-list.php';
		require_once WAPM_PATH . 'includes/class-stock.php';
		require_once WAPM_PATH . 'includes/class-torob.php';
		require_once WAPM_PATH . 'includes/class-torob-api.php';
		require_once WAPM_PATH . 'includes/class-rollback.php';
		require_once WAPM_PATH . 'includes/class-csv-handler.php';
		require_once WAPM_PATH . 'includes/class-rules.php';
		require_once WAPM_PATH . 'includes/class-queue.php';
		require_once WAPM_PATH . 'includes/class-scheduler.php';
		require_once WAPM_PATH . 'includes/class-ajax.php';
		require_once WAPM_PATH . 'includes/class-bookmarklet.php';
		require_once WAPM_PATH . 'includes/class-admin.php';

		WAPM_Logger::maybe_upgrade();
		WAPM_Stock::maybe_upgrade();
		// زمان‌بندی: Action Scheduler اگر ووکامرس آن را همراه دارد، وگرنه wp-cron.
		WAPM_Queue::maybe_migrate();
		WAPM_Torob::hooks();
		new WAPM_Scheduler();
		new WAPM_Ajax();

		if ( is_admin() ) {
			new WAPM_Admin();
		}
	}

	public function activate(): void {
		require_once WAPM_PATH . 'includes/class-logger.php';
		WAPM_Logger::create_table();
		require_once WAPM_PATH . 'includes/class-stock.php';
		WAPM_Stock::create_table();
		require_once WAPM_PATH . 'includes/class-queue.php';
		WAPM_Queue::schedule_hourly();

		// اگر کاربر تازه‌سازی خودکار قیمت رقبا را روشن کرده، زمان‌بندی‌اش دوباره
		// ساخته شود (غیرفعال/فعال‌سازی افزونه، زمان‌بندی‌ها را پاک می‌کند).
		require_once WAPM_PATH . 'includes/class-torob.php';
		WAPM_Torob::schedule_auto();
	}

	public function deactivate(): void {
		// تنظیمات، قوانین و تاریخچه حفظ می‌شوند؛ فقط زمان‌بندی پاک می‌شود.
		require_once WAPM_PATH . 'includes/class-queue.php';
		WAPM_Queue::unschedule_all();

		require_once WAPM_PATH . 'includes/class-torob.php';
		WAPM_Queue::unschedule( WAPM_Torob::HOOK_AUTO );
	}
}

WAPM_Plugin::instance();
