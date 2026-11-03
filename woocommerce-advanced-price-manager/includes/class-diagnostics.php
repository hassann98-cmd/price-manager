<?php
/**
 * WAPM_Diagnostics — ثبت و نمایش خطاهای خود افزونه + خودآزمایی محیط.
 *
 * چرا این کلاس ساخته شد: گزارش‌های هاست (LightHouse/ابزارهای تحلیلی) می‌گویند
 * «خطای مهم در افزونه» ولی متن خطا را نمی‌دهند، و در وردپرس خطاهای PHP یا در
 * لاگ هاست می‌مانند (که کاربر معمولاً دسترسی ندارد) یا با `WP_DEBUG_DISPLAY`
 * خاموش، کاملاً بی‌صدا دور ریخته می‌شوند. نتیجه: کاربر می‌داند «خطایی هست» ولی
 * نمی‌داند چه چیزی، و ما هم نمی‌توانیم حدس بزنیم.
 *
 * این کلاس دو کار می‌کند:
 *
 *   ۱) **گرفتن خطای کشنده**: با `register_shutdown_function` آخرین خطا را
 *      می‌خواند؛ اگر فایلش داخل همین افزونه باشد، متن دقیق (پیام + فایل + خط +
 *      آدرس درخواست + نسخه‌ی PHP) در یک گزینه ذخیره می‌شود. بعد از آن، خود
 *      پیشخوان افزونه همان متن را نشان می‌دهد — قابل کپی و ارسال.
 *
 *   ۲) **خودآزمایی محیط**: فهرست کوتاهی از چیزهایی که روی هاست‌های اشتراکی
 *      واقعاً می‌شکنند (نبودن mbstring، نسخه‌ی قدیمی PHP/ووکامرس، نبودن جدول
 *      لاگ، خاموش‌بودن کرون، نبودن توکن ترب) با وضعیت سبز/زرد/قرمز.
 *
 * نکته‌ی مهم درباره‌ی «هشدارها و اعلان‌ها» (deprecated/notice): گرفتن آن‌ها
 * نیازمند `set_error_handler` سراسری است که می‌تواند رفتار خود وردپرس را عوض
 * کند. برای همین فقط وقتی فعال می‌شود که کاربر صریحاً در `wp-config.php` بنویسد:
 *
 *     define( 'WAPM_CAPTURE_NOTICES', true );
 *
 * پیش‌فرض خاموش است تا روی سایت زنده هیچ هزینه/ریسکی اضافه نکند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Diagnostics {

	/** گزینه‌ی خطای کشنده‌ی ثبت‌شده (آخرین مورد). */
	const OPTION_LAST_ERROR = 'wapm_last_error';

	/** گزینه‌ی استثناهای گرفته‌شده در هندلرهای Ajax (حداکثر ۵ مورد). */
	const OPTION_LAST_THROWN = 'wapm_last_thrown';

	/** گزینه‌ی اعلان‌های PHP (فقط با WAPM_CAPTURE_NOTICES). */
	const OPTION_NOTICES = 'wapm_php_notices';

	const MAX_THROWN  = 5;
	const MAX_NOTICES = 20;

	/** انواع خطای «کشنده» که صفحه را سفید می‌کنند. */
	const FATAL_TYPES = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];

	/** انواع غیرکشنده‌ای که در حالت تشخیص ثبت می‌شوند. */
	const NOTICE_TYPES = [ E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED ];

	/** ثبت هندلرها — از بوت‌استرپ افزونه صدا زده می‌شود. */
	public static function init(): void {
		register_shutdown_function( [ __CLASS__, 'capture_fatal' ] );

		if ( defined( 'WAPM_CAPTURE_NOTICES' ) && WAPM_CAPTURE_NOTICES ) {
			set_error_handler( [ __CLASS__, 'capture_notice' ] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/* ---------- قطعات خالص (قابل تست بدون وردپرس) ---------- */

	/**
	 * آیا این مسیر فایل، داخل پوشه‌ی افزونه است؟
	 *
	 * چرا مقایسه‌ی مسیر و نه نام فایل: خطاهای افزونه‌های دیگر و وردپرس نباید به
	 * گردن این افزونه نوشته شوند (همان اشتباهی که گزارش‌های خودکار می‌کنند).
	 */
	public static function is_plugin_file( string $file ): bool {
		if ( '' === $file || ! defined( 'WAPM_PATH' ) ) {
			return false;
		}

		$root = rtrim( str_replace( '\\', '/', (string) WAPM_PATH ), '/' ) . '/';

		return 0 === strpos( str_replace( '\\', '/', $file ), $root );
	}

	/** آیا این نوع خطا کشنده است؟ */
	public static function is_fatal_type( int $type ): bool {
		return in_array( $type, self::FATAL_TYPES, true );
	}

	/** برچسب فارسی نوع خطا (برای نمایش). */
	public static function type_label( int $type ): string {
		$labels = [
			E_ERROR             => __( 'خطای کشنده', 'wapm' ),
			E_PARSE             => __( 'خطای نحوی', 'wapm' ),
			E_CORE_ERROR        => __( 'خطای هسته‌ی PHP', 'wapm' ),
			E_COMPILE_ERROR     => __( 'خطای کامپایل', 'wapm' ),
			E_USER_ERROR        => __( 'خطای کاربر', 'wapm' ),
			E_RECOVERABLE_ERROR => __( 'خطای بازیافت‌پذیر', 'wapm' ),
			E_WARNING           => __( 'هشدار', 'wapm' ),
			E_NOTICE            => __( 'اعلان', 'wapm' ),
			E_USER_WARNING      => __( 'هشدار کاربر', 'wapm' ),
			E_USER_NOTICE       => __( 'اعلان کاربر', 'wapm' ),
			E_DEPRECATED        => __( 'منسوخ‌شده', 'wapm' ),
			E_USER_DEPRECATED   => __( 'منسوخ‌شده (کاربر)', 'wapm' ),
		];

		return $labels[ $type ] ?? ( 'E' . $type );
	}

	/**
	 * متن آماده برای کپی/ارسال به پشتیبانی از یک رکورد خطا.
	 *
	 * @param array $record رکورد ذخیره‌شده
	 */
	public static function format_record( array $record ): string {
		$lines = [
			'type: ' . self::type_label( (int) ( $record['type'] ?? 0 ) ),
			'message: ' . (string) ( $record['message'] ?? '' ),
			'file: ' . (string) ( $record['file'] ?? '' ) . ':' . (string) ( $record['line'] ?? '' ),
			'uri: ' . (string) ( $record['uri'] ?? '' ),
			'at: ' . (string) ( $record['at'] ?? '' ),
			'plugin: ' . (string) ( $record['version'] ?? '' ) . ' | php: ' . (string) ( $record['php'] ?? '' ) . ' | wp: ' . (string) ( $record['wp'] ?? '' ),
		];

		if ( ! empty( $record['action'] ) ) {
			$lines[] = 'action: ' . (string) $record['action'];
		}

		return implode( "\n", $lines );
	}

	/** شماره‌ی خطی که در گزارش نمایش داده می‌شود (بدون مسیر کامل). */
	public static function short_file( string $file ): string {
		$parts = preg_split( '#[\\\\/]#', $file ) ?: [];
		$keep  = array_slice( $parts, -2 );

		return implode( '/', $keep );
	}

	/* ---------- گرفتن خطای کشنده ---------- */

	/**
	 * در پایان درخواست اجرا می‌شود؛ فقط خطای کشنده‌ی داخل افزونه ثبت می‌شود.
	 *
	 * چرا اینجا باید «کم‌هزینه» بماند: در حالت خطای حافظه (OOM) که رایج‌ترین
	 * خطای هاست‌های اشتراکی است، همان چند خط باقی‌مانده هم اجرا نمی‌شود اگر
	 * کار سنگینی انجام دهیم. برای همین فقط یک `update_option` ساده است.
	 */
	public static function capture_fatal(): void {
		$error = error_get_last();

		if ( ! is_array( $error ) || ! self::is_fatal_type( (int) ( $error['type'] ?? 0 ) ) ) {
			return;
		}

		$file = (string) ( $error['file'] ?? '' );

		if ( ! self::is_plugin_file( $file ) ) {
			return;
		}

		self::store( self::OPTION_LAST_ERROR, self::build_record( $error ) );
	}

	/** ساخت رکورد استاندارد خطا از خروجی `error_get_last`. */
	private static function build_record( array $error ): array {
		return [
			'type'    => (int) ( $error['type'] ?? 0 ),
			'message' => (string) ( $error['message'] ?? '' ),
			'file'    => (string) ( $error['file'] ?? '' ),
			'line'    => (int) ( $error['line'] ?? 0 ),
			'uri'     => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
			'at'      => function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'version' => defined( 'WAPM_VERSION' ) ? (string) WAPM_VERSION : '',
			'php'     => PHP_VERSION,
			'wp'      => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
		];
	}

	/* ---------- گرفتن استثنا در هندلرهای Ajax ---------- */

	/**
	 * ثبت یک Throwable که در هندلر Ajax رخ داده است.
	 *
	 * چرا لازم است: بدون این، یک `TypeError` در هندلر به پاسخ ۵۰۰ با HTML
	 * تبدیل می‌شود، جاوااسکریپت پیام «خطایی رخ داد» را نشان می‌دهد و هیچ‌کس
	 * نمی‌فهمد چه شده. اینجا متن دقیق ذخیره می‌شود تا در پیشخوان دیده شود.
	 */
	public static function record_throwable( Throwable $e, string $action = '' ): void {
		$record = [
			'type'    => E_ERROR,
			'message' => get_class( $e ) . ': ' . $e->getMessage(),
			'file'    => $e->getFile(),
			'line'    => $e->getLine(),
			'uri'     => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
			'at'      => function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'version' => defined( 'WAPM_VERSION' ) ? (string) WAPM_VERSION : '',
			'php'     => PHP_VERSION,
			'wp'      => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			'action'  => $action,
		];

		$list = self::thrown();

		// تکرار یک خطا (کاربر دوباره همان دکمه را می‌زند) فهرست را پر نمی‌کند.
		$list = array_filter(
			$list,
			static fn( $row ) => ( $row['message'] ?? '' ) !== $record['message'] || ( $row['line'] ?? 0 ) !== $record['line']
		);

		array_unshift( $list, $record );
		self::store( self::OPTION_LAST_THROWN, array_slice( array_values( $list ), 0, self::MAX_THROWN ) );

		// در لاگ PHP هم برود؛ اگر هاست لاگ را نگه دارد، دو نسخه بهتر از هیچ است.
		error_log( '[wapm] ' . $action . ' — ' . $record['message'] . ' @ ' . $record['file'] . ':' . $record['line'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/* ---------- گرفتن اعلان‌ها (اختیاری) ---------- */

	/**
	 * هندلر اختیاری اعلان‌ها — فقط برای تشخیص روی سایتی که مشکل دارد.
	 *
	 * @return bool همیشه false تا PHP خودش خطا را هم مدیریت کند.
	 */
	public static function capture_notice( int $type, string $message, string $file = '', int $line = 0 ): bool {
		if ( ! in_array( $type, self::NOTICE_TYPES, true ) || ! self::is_plugin_file( $file ) ) {
			return false;
		}

		$list = get_option( self::OPTION_NOTICES, [] );
		$list = is_array( $list ) ? $list : [];

		$record = [
			'type'    => $type,
			'message' => $message,
			'file'    => $file,
			'line'    => $line,
			'at'      => function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		];

		foreach ( $list as $row ) {
			if ( ( $row['message'] ?? '' ) === $message && ( $row['line'] ?? 0 ) === $line ) {
				return false; // تکراری — نوشتن دیتابیس لازم نیست.
			}
		}

		array_unshift( $list, $record );
		self::store( self::OPTION_NOTICES, array_slice( $list, 0, self::MAX_NOTICES ) );

		return false;
	}

	/* ---------- خواندن/پاک‌کردن ---------- */

	public static function last_error(): array {
		$raw = get_option( self::OPTION_LAST_ERROR, [] );

		return is_array( $raw ) ? $raw : [];
	}

	/** @return array<int, array> */
	public static function thrown(): array {
		$raw = get_option( self::OPTION_LAST_THROWN, [] );

		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/** @return array<int, array> */
	public static function notices(): array {
		$raw = get_option( self::OPTION_NOTICES, [] );

		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/** آیا چیزی برای نمایش هست؟ */
	public static function has_issues(): bool {
		return [] !== self::last_error() || [] !== self::thrown() || [] !== self::notices();
	}

	public static function clear(): void {
		foreach ( [ self::OPTION_LAST_ERROR, self::OPTION_LAST_THROWN, self::OPTION_NOTICES ] as $option ) {
			delete_option( $option );
		}
	}

	/** نوشتن رکورد با `update_option` (بدون autoload — در هر درخواست لازم نیست). */
	private static function store( string $option, $value ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $option, $value, false );
		}
	}

	/* ---------- خودآزمایی محیط ---------- */

	/**
	 * فهرست بررسی‌های محیطی.
	 *
	 * هر آیتم: key، label، status (ok|warn|fail)، detail.
	 * «warn» یعنی افزونه کار می‌کند ولی چیزی نیمه‌تنظیم است (مثل کرون یا توکن).
	 *
	 * @return array<int, array{key:string,label:string,status:string,detail:string}>
	 */
	public static function checks(): array {
		$checks = [];

		// PHP
		$php_ok   = version_compare( PHP_VERSION, '8.0', '>=' );
		$checks[] = [
			'key'    => 'php',
			'label'  => __( 'نسخه‌ی PHP', 'wapm' ),
			'status' => $php_ok ? 'ok' : 'fail',
			'detail' => PHP_VERSION . ( $php_ok ? '' : ' — ' . __( 'افزونه به PHP 8.0 یا بالاتر نیاز دارد.', 'wapm' ) ),
		];

		// mbstring
		$mb       = function_exists( 'mb_strlen' );
		$checks[] = [
			'key'    => 'mbstring',
			'label'  => __( 'افزونه‌ی mbstring', 'wapm' ),
			'status' => $mb ? 'ok' : 'warn',
			'detail' => $mb
				? __( 'نصب است.', 'wapm' )
				: __( 'نصب نیست؛ افزونه با مسیر جایگزین کار می‌کند ولی جستجو/برش متن فارسی کندتر است.', 'wapm' ),
		];

		// ووکامرس
		$wc = defined( 'WC_VERSION' ) ? (string) WC_VERSION : '';
		$checks[] = [
			'key'    => 'woocommerce',
			'label'  => __( 'ووکامرس', 'wapm' ),
			'status' => ( '' !== $wc && version_compare( $wc, '7.0', '>=' ) ) ? 'ok' : ( '' === $wc ? 'fail' : 'warn' ),
			'detail' => '' !== $wc ? $wc : __( 'شناسایی نشد.', 'wapm' ),
		];

		// جدول‌های دیتابیس
		$checks[] = self::table_check( WAPM_Logger::table(), __( 'جدول تاریخچه‌ی قیمت', 'wapm' ) );
		$checks[] = self::table_check( WAPM_Stock::table(), __( 'جدول تاریخچه‌ی موجودی', 'wapm' ) );

		// زمان‌بندی
		$next  = class_exists( 'WAPM_Queue' ) ? WAPM_Queue::next_run() : 0;
		$state = class_exists( 'WAPM_Queue' ) ? WAPM_Queue::state() : [];
		$checks[] = [
			'key'    => 'cron',
			'label'  => __( 'زمان‌بندی خودکار', 'wapm' ),
			'status' => $next > 0 ? 'ok' : 'warn',
			'detail' => $next > 0
				? (string) ( $state['next_label'] ?? '' ) . ' — ' . (string) ( $state['label'] ?? '' )
				: __( 'هیچ اجرایی زمان‌بندی نشده؛ یک بار صفحه‌ی افزونه را باز کنید یا افزونه را غیرفعال/فعال کنید.', 'wapm' ),
		];

		// محدودیت‌های اجرا (هاست‌های اشتراکی این‌ها را پایین می‌بندند و
		// عملیات گروهی بزرگ نیمه‌کاره می‌ماند).
		$checks[] = [
			'key'    => 'limits',
			'label'  => __( 'محدودیت اجرا', 'wapm' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: 1: memory limit, 2: max execution time */
				__( 'حافظه: %1$s — حداکثر زمان اجرا: %2$s ثانیه', 'wapm' ),
				(string) ini_get( 'memory_limit' ),
				(string) ini_get( 'max_execution_time' )
			),
		];

		// ترب: توکن بوکمارکلت/فید
		if ( class_exists( 'WAPM_Torob' ) ) {
			$token    = (string) WAPM_Torob::get_bookmarklet_token();
			$checks[] = [
				'key'    => 'torob_token',
				'label'  => __( 'توکن ورود داده‌ی ترب', 'wapm' ),
				'status' => '' !== $token ? 'ok' : 'warn',
				'detail' => '' !== $token
					? __( 'ساخته شده (بوکمارکلت و فید کار می‌کنند).', 'wapm' )
					: __( 'ساخته نشده؛ بوکمارکلت/فید کار نمی‌کند. یک بار تب ترب را باز کنید.', 'wapm' ),
			];

			$shop     = WAPM_Torob::can_identify_shop();
			$checks[] = [
				'key'    => 'torob_shop',
				'label'  => __( 'اتصال فروشگاه ترب', 'wapm' ),
				'status' => $shop ? 'ok' : 'warn',
				'detail' => $shop ? __( 'شناسه/نام فروشگاه ثبت شده.', 'wapm' ) : __( 'ثبت نشده؛ مقایسه‌ی قیمت انجام نمی‌شود.', 'wapm' ),
			];
		}

		// وضعیت ثبت خطا
		$has_error = [] !== self::last_error() || [] !== self::thrown();
		$checks[]  = [
			'key'    => 'errors',
			'label'  => __( 'خطای ثبت‌شده', 'wapm' ),
			'status' => $has_error ? 'fail' : 'ok',
			'detail' => $has_error
				? __( 'خطا ثبت شده است — متن دقیقش پایین همین کارت آمده.', 'wapm' )
				: __( 'خطایی ثبت نشده.', 'wapm' ),
		];

		return $checks;
	}

	/** بررسی وجود یک جدول، بدون فرض وجود $wpdb (قابل اجرا در تست). */
	private static function table_check( string $table, string $label ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return [ 'key' => $table, 'label' => $label, 'status' => 'warn', 'detail' => __( 'قابل بررسی نیست.', 'wapm' ) ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return [
			'key'    => $table,
			'label'  => $label,
			'status' => $found === $table ? 'ok' : 'fail',
			'detail' => $found === $table ? __( 'موجود است.', 'wapm' ) : __( 'ساخته نشده — یک بار افزونه را غیرفعال/فعال کنید.', 'wapm' ),
		];
	}

	/** جمع‌بندی وضعیت برای نمایش کوتاه در هدر کارت. */
	public static function summary(): array {
		$counts = [ 'ok' => 0, 'warn' => 0, 'fail' => 0 ];
		foreach ( self::checks() as $check ) {
			$status = (string) ( $check['status'] ?? 'ok' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}

		return $counts;
	}
}
