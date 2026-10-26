<?php
/**
 * تست‌های واحد منطق قیمت‌گذاری — بدون نیاز به نصب وردپرس/ووکامرس/MySQL.
 *
 * اجرا:   php tests/run.php
 * خروجی:  کد ۰ = همه تست‌ها موفق، کد ۱ = حداقل یک شکست.
 *
 * چه چیزی تست می‌شود: توابع خالص engine قیمت (محاسبه، گرد کردن، محدودیت، کف قیمت).
 * چه چیزی تست نمی‌شود: هر چیزی که به دیتابیس/ووکامرس واقعی نیاز دارد
 * (ذخیره‌ی محصول، لاگ، rollback، Ajax).
 *
 * توجه: این فایل بخشی از افزونه نیست و در پروداکشن بارگذاری نمی‌شود.
 */

declare( strict_types = 0 );

if ( PHP_VERSION_ID < 80000 ) {
	fwrite( STDERR, "این تست‌ها به PHP 8.0+ نیاز دارند (نسخه‌ی فعلی: " . PHP_VERSION . ").\n" );
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

/* -------------------------------------------------------------------------
 * stub حداقلی وردپرس/ووکامرس
 * ---------------------------------------------------------------------- */

$GLOBALS['wapm_stub'] = [
	'decimals' => 0,
	'currency' => 'IRT',
	'filters'  => [],
];

function wc_get_price_decimals() {
	return (int) $GLOBALS['wapm_stub']['decimals'];
}

function wc_format_decimal( $number, $dp = false ) {
	if ( '' === $number || null === $number ) {
		return '';
	}
	return ( false === $dp ) ? (string) $number : number_format( (float) $number, (int) $dp, '.', '' );
}

function get_woocommerce_currency() {
	return (string) $GLOBALS['wapm_stub']['currency'];
}

/**
 * apply_filters: مقدارِ تست‌شده را برمی‌گرداند و آرگومان‌های اضافی را نادیده می‌گیرد.
 */
function apply_filters( $tag, $value ) {
	return array_key_exists( $tag, $GLOBALS['wapm_stub']['filters'] )
		? $GLOBALS['wapm_stub']['filters'][ $tag ]
		: $value;
}

function __( $text, $domain = null ) {
	return $text;
}

/* ---- stubهای لازم برای WAPM_Torob (توابع خالص) ---- */

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $str ) ) );
}

function absint( $v ) {
	return abs( (int) $v );
}

/** دفتر زمان‌بندی‌های WP-Cron در تست. */
$GLOBALS['wapm_cron'] = [];

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = [] ) {
	$GLOBALS['wapm_cron'][ $hook ] = (object) [
		'hook'       => $hook,
		'timestamp'  => (int) $timestamp,
		'recurrence' => $recurrence,
	];
	return true;
}

function wp_next_scheduled( $hook, $args = [] ) {
	return isset( $GLOBALS['wapm_cron'][ $hook ] ) ? (int) $GLOBALS['wapm_cron'][ $hook ]->timestamp : false;
}

function wp_clear_scheduled_hook( $hook, $args = [] ) {
	unset( $GLOBALS['wapm_cron'][ $hook ] );
	return 0;
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, null === $timestamp ? time() : (int) $timestamp );
}

/**
 * شبیه‌سازی mysql2date وردپرس: رشته‌ی «زمان دیواری محلی» را همان‌طور که هست
 * نمایش می‌دهد (بدون جمع‌کردن offset).
 */
function mysql2date( $format, $date, $translate = true ) {
	if ( '' === (string) $date ) {
		return false;
	}
	return gmdate( $format, (int) strtotime( $date . ' UTC' ) );
}

/** شبیه‌سازی date_i18n: timestamp → وقت محلی با offset سایت. */
function date_i18n( $format, $timestamp = null ) {
	$offset = (float) ( $GLOBALS['wapm_gmt_offset'] ?? 0 );
	return gmdate( $format, (int) $timestamp + (int) round( $offset * 3600 ) );
}

/** انبار ساده‌ی متای محصول برای تست‌های تاریخچه‌ی ترب. */
$GLOBALS['wapm_meta'] = [];

function get_post_meta( $post_id, $key = '', $single = false ) {
	$id = (int) $post_id;

	if ( '' === $key ) {
		return $GLOBALS['wapm_meta'][ $id ] ?? [];
	}

	$value = $GLOBALS['wapm_meta'][ $id ][ $key ] ?? '';

	return $single ? $value : ( '' === $value ? [] : [ $value ] );
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['wapm_meta'][ (int) $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['wapm_meta'][ (int) $post_id ][ $key ] );
	return true;
}

function esc_js( $text ) {
	return addcslashes( (string) $text, "\\'\"\r\n</" );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( str_repeat( 'abc123xyz', 10 ), 0, max( 1, (int) $length ) );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

function current_time( $type = 'mysql', $gmt = 0 ) {
	return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
}

function get_option( $key, $default = false ) {
	return $GLOBALS['wapm_options'][ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['wapm_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['wapm_options'][ $key ] );
	return true;
}

$GLOBALS['wapm_options'] = [];

/** دفتر محصولات stub — کلید: شناسه، مقدار: WC_Product */
function wc_get_product( $id ) {
	return $GLOBALS['wapm_stub']['products'][ (int) $id ] ?? false;
}

/**
 * stub حداقلی محصول ووکامرس — فقط برای تست can_manage در WAPM_Stock.
 * WAPM_Stock::can_manage با instanceof WC_Product کار می‌کند، پس کلاس باید وجود داشته باشد.
 */
class WC_Product {

	public string $type       = 'simple';
	public bool   $managing   = true;
	public $qty                = 10;
	public bool   $backorders = false;
	public string $status     = 'instock';
	public int    $id         = 1;
	public array  $children   = [];

	public function __construct( array $props = [] ) {
		foreach ( $props as $key => $value ) {
			$this->$key = $value;
		}
	}

	public function get_id(): int {
		return $this->id;
	}

	public function is_type( $type ): bool {
		return $type === $this->type;
	}

	public function managing_stock(): bool {
		return $this->managing;
	}

	public function get_stock_quantity() {
		return $this->qty;
	}

	public function backorders_allowed(): bool {
		return $this->backorders;
	}

	public function get_stock_status( $context = 'view' ): string {
		return $this->status;
	}

	public function get_children(): array {
		return $this->children;
	}
}

require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-str.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-price-engine.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-logger.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-torob.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-torob-api.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-stock.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-list.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-queue.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-product-handler.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-bookmarklet.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-rules.php';

/* -------------------------------------------------------------------------
 * چارچوب تست کوچک
 * ---------------------------------------------------------------------- */

$GLOBALS['wapm_tests'] = [ 'run' => 0, 'failed' => 0, 'failures' => [] ];

function stub_reset( array $overrides = [] ): void {
	$GLOBALS['wapm_stub'] = array_merge(
		[ 'decimals' => 0, 'currency' => 'IRT', 'filters' => [] ],
		$overrides
	);
}

function test( string $label, callable $fn ): void {
	$GLOBALS['wapm_tests']['run']++;
	try {
		$fn();
	} catch ( Throwable $e ) {
		$GLOBALS['wapm_tests']['failed']++;
		$GLOBALS['wapm_tests']['failures'][] = $label . ' — استثنا: ' . $e->getMessage();
	}
}

function ok( bool $cond, string $label, string $detail = '' ): void {
	if ( $cond ) {
		return;
	}
	$GLOBALS['wapm_tests']['failed']++;
	$GLOBALS['wapm_tests']['failures'][] = $label . ( $detail ? ' — ' . $detail : '' );
}

function eq( $expected, $actual, string $label ): void {
	if ( is_float( $expected ) || is_float( $actual ) ) {
		ok( abs( (float) $expected - (float) $actual ) < 0.000001, $label, 'انتظار: ' . var_export( $expected, true ) . ' / نتیجه: ' . var_export( $actual, true ) );
		return;
	}
	ok( $expected === $actual, $label, 'انتظار: ' . var_export( $expected, true ) . ' / نتیجه: ' . var_export( $actual, true ) );
}

/** ساخت تنظیمات کامل از ورودی خام */
function settings( array $raw ): array {
	return WAPM_Price_Engine::sanitize_settings( $raw );
}

/* -------------------------------------------------------------------------
 * ۱) sanitize_settings
 * ---------------------------------------------------------------------- */

test( 'sanitize: مقادیر پیش‌فرض', function () {
	stub_reset();
	$s = settings( [] );
	eq( 'percent', $s['mode'], 'mode پیش‌فرض' );
	eq( 'increase', $s['direction'], 'direction پیش‌فرض' );
	eq( 'regular', $s['price_target'], 'price_target پیش‌فرض' );
	eq( 'variations', $s['variable_scope'], 'variable_scope پیش‌فرض' );
	eq( 'independent', $s['sale_handling'], 'sale_handling پیش‌فرض' );
	eq( 'none', $s['round_mode'], 'round_mode پیش‌فرض' );
	eq( 0.0, $s['amount'], 'amount پیش‌فرض' );
} );

test( 'sanitize: مقادیر نامعتبر به پیش‌فرض برمی‌گردند', function () {
	stub_reset();
	$s = settings( [
		'mode'           => 'hack',
		'direction'      => 'sideways',
		'price_target'   => 'all',
		'variable_scope' => 'everything',
		'round_mode'     => 'floor-ish',
		'currency_unit'  => 'dollar',
	] );
	eq( 'percent', $s['mode'], 'mode نامعتبر' );
	eq( 'increase', $s['direction'], 'direction نامعتبر' );
	eq( 'regular', $s['price_target'], 'price_target نامعتبر' );
	eq( 'variations', $s['variable_scope'], 'variable_scope نامعتبر' );
	eq( 'none', $s['round_mode'], 'round_mode نامعتبر' );
	eq( 'toman', $s['currency_unit'], 'currency_unit نامعتبر' );
} );

test( 'sanitize: اعداد منفی و رشته‌ای', function () {
	stub_reset();
	$s = settings( [ 'amount' => '-12.5', 'round_base' => '-50', 'min_price' => '-1', 'max_price' => '-1' ] );
	eq( 12.5, $s['amount'], 'amount منفی → قدرمطلق' );
	eq( 0.0, (float) $s['round_base'], 'round_base منفی → ۰' );
	eq( 0.0, (float) $s['min_price'], 'min_price منفی → ۰' );
	eq( 0.0, (float) $s['max_price'], 'max_price منفی → ۰' );
} );

test( 'sanitize: سازگاری با scope والدِ ذخیره‌شده', function () {
	stub_reset();
	// گزینه‌ی «فقط قیمت والد» از UI حذف شده، ولی داده‌ی قدیمی نباید بی‌صدا
	// به «همه واریشن‌ها» تبدیل شود (وگرنه قانون قدیمی ناگهان قیمت‌ها را عوض می‌کند).
	eq( 'parent', settings( [ 'variable_scope' => 'parent' ] )['variable_scope'], 'parent حفظ می‌شود' );
} );

/* -------------------------------------------------------------------------
 * ۲) محاسبه‌ی درصدی
 * ---------------------------------------------------------------------- */

test( 'درصد: افزایش ۱۰٪', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'increase', 'amount' => 10 ] );
	eq( 1100.0, WAPM_Price_Engine::calculate( '1000', $s ), '۱۰۰۰ + ۱۰٪' );
} );

test( 'درصد: کاهش ۱۰٪', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 10 ] );
	eq( 900.0, WAPM_Price_Engine::calculate( '1000', $s ), '۱۰۰۰ − ۱۰٪' );
} );

test( 'درصد: قیمت خالی → null', function () {
	stub_reset();
	$s = settings( [ 'amount' => 10 ] );
	eq( null, WAPM_Price_Engine::calculate( '', $s ), 'قیمت خالی' );
	eq( null, WAPM_Price_Engine::calculate( null, $s ), 'قیمت null' );
} );

/* -------------------------------------------------------------------------
 * ۳) محافظ قیمت صفر (P0-4)
 * ---------------------------------------------------------------------- */

test( 'کف قیمت: کاهش ۱۰۰٪ محصول را رایگان نمی‌کند', function () {
	stub_reset(); // تومان، ۰ رقم اعشار
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 100 ] );
	$new = WAPM_Price_Engine::calculate( '1000000', $s );
	eq( 1.0, $new, 'کاهش ۱۰۰٪ → ۱ (نه ۰)' );
	ok( $new > 0, 'قیمت باید بزرگ‌تر از صفر بماند' );
} );

test( 'کف قیمت: کاهش بیش از ۱۰۰٪', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 250 ] );
	eq( 1.0, WAPM_Price_Engine::calculate( '5000', $s ), 'کاهش ۲۵۰٪ → کف' );
} );

test( 'کف قیمت: با ۲ رقم اعشار ۰.۰۱ است', function () {
	stub_reset( [ 'decimals' => 2, 'currency' => 'USD' ] );
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 100 ] );
	eq( 0.01, WAPM_Price_Engine::calculate( '49.99', $s ), 'کاهش ۱۰۰٪ با ۲ اعشار' );
} );

test( 'کف قیمت: فیلتر wapm_price_floor', function () {
	stub_reset( [ 'filters' => [ 'wapm_price_floor' => 50000 ] ] );
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 100 ] );
	eq( 50000.0, WAPM_Price_Engine::calculate( '1000000', $s ), 'کف سفارشی ۵۰٬۰۰۰' );
} );

test( 'کف قیمت: افزایش تحت‌تاثیر قرار نمی‌گیرد', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'fixed', 'direction' => 'increase', 'amount' => 0 ] );
	eq( 800.0, WAPM_Price_Engine::calculate( '800', $s ), 'افزایش صفر → همان قیمت' );
} );

/* -------------------------------------------------------------------------
 * ۴) مبلغ ثابت و تبدیل واحد
 * ---------------------------------------------------------------------- */

test( 'مبلغ ثابت: واحد مطابق ارز فروشگاه (تومان)', function () {
	stub_reset(); // فروشگاه IRT
	$s = settings( [ 'mode' => 'fixed', 'direction' => 'increase', 'amount' => 15000, 'currency_unit' => 'toman' ] );
	eq( 65000.0, WAPM_Price_Engine::calculate( '50000', $s ), '۵۰٬۰۰۰ + ۱۵٬۰۰۰' );
} );

test( 'مبلغ ثابت: ورودی ریال در فروشگاه تومانی (÷۱۰)', function () {
	stub_reset(); // فروشگاه IRT
	$s = settings( [ 'mode' => 'fixed', 'direction' => 'increase', 'amount' => 100000, 'currency_unit' => 'rial' ] );
	eq( 60000.0, WAPM_Price_Engine::calculate( '50000', $s ), '۹۹٬۹۹۹ ریال ≈ ۱۰٬۰۰۰ تومان' );
} );

test( 'مبلغ ثابت: ورودی تومان در فروشگاه ریالی (×۱۰)', function () {
	stub_reset( [ 'currency' => 'IRR' ] );
	$s = settings( [ 'mode' => 'fixed', 'direction' => 'increase', 'amount' => 1000, 'currency_unit' => 'toman' ] );
	eq( 510000.0, WAPM_Price_Engine::calculate( '500000', $s ), '۱٬۰۰۰ تومان = ۱۰٬۰۰۰ ریال' );
} );

test( 'مبلغ ثابت: کاهش بیش از قیمت → کف', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'fixed', 'direction' => 'decrease', 'amount' => 100000, 'currency_unit' => 'toman' ] );
	eq( 1.0, WAPM_Price_Engine::calculate( '30000', $s ), 'کاهش ۱۰۰٬۰۰۰ از ۳۰٬۰۰۰' );
} );

/* -------------------------------------------------------------------------
 * ۵) گرد کردن
 * ---------------------------------------------------------------------- */

test( 'گرد کردن: نزدیک‌ترین مضرب ۱۰۰۰', function () {
	stub_reset();
	$s = settings( [ 'amount' => 0, 'round_mode' => 'nearest', 'round_base' => 1000 ] );
	eq( 235000.0, WAPM_Price_Engine::calculate( '235430', $s ), '۲۳۵٬۴۳۰ → ۲۳۵٬۰۰۰' );
} );

test( 'گرد کردن: رو به بالا', function () {
	stub_reset();
	$s = settings( [ 'amount' => 0, 'round_mode' => 'up', 'round_base' => 1000 ] );
	eq( 236000.0, WAPM_Price_Engine::calculate( '235001', $s ), '۲۳۵٬۰۰۱ → ۲۳۶٬۰۰۰' );
} );

test( 'گرد کردن: رو به پایین', function () {
	stub_reset();
	$s = settings( [ 'amount' => 0, 'round_mode' => 'down', 'round_base' => 1000 ] );
	eq( 235000.0, WAPM_Price_Engine::calculate( '235999', $s ), '۲۳۵٬۹۹۹ → ۲۳۵٬۰۰۰' );
} );

test( 'گرد کردن: base صفر = بی‌اثر', function () {
	stub_reset();
	$s = settings( [ 'amount' => 0, 'round_mode' => 'up', 'round_base' => 0 ] );
	eq( 235001.0, WAPM_Price_Engine::calculate( '235001', $s ), 'بدون گرد کردن' );
} );

/* -------------------------------------------------------------------------
 * ۶) حداقل/حداکثر
 * ---------------------------------------------------------------------- */

test( 'محدودیت: حداقل قیمت', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 50, 'min_price' => 800 ] );
	eq( 800.0, WAPM_Price_Engine::calculate( '1000', $s ), '۵۰۰ → کف ۸۰۰' );
} );

test( 'محدودیت: حداکثر قیمت', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'increase', 'amount' => 100, 'max_price' => 1500 ] );
	eq( 1500.0, WAPM_Price_Engine::calculate( '1000', $s ), '۲۰۰۰ → سقف ۱۵۰۰' );
} );

test( 'محدودیت: حداقل بر کف مطلق اولویت دارد', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 100, 'min_price' => 5000 ] );
	eq( 5000.0, WAPM_Price_Engine::calculate( '9000', $s ), 'min_price بزرگ‌تر از کف مطلق' );
} );

test( 'محدودیت: قیمت منفی هرگز', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 150 ] );
	$new = WAPM_Price_Engine::calculate( '100', $s );
	ok( $new >= 0, 'قیمت منفی نشود', 'نتیجه: ' . var_export( $new, true ) );
} );

/* -------------------------------------------------------------------------
 * ۷) اعتبارسنجی قیمت کوچک (رگرسیون: قیمت اجناس ارزان)
 * ---------------------------------------------------------------------- */

test( 'کاهش معقول روی محصول ارزان', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'percent', 'direction' => 'decrease', 'amount' => 10 ] );
	eq( 90000.0, WAPM_Price_Engine::calculate( '100000', $s ), '۱۰٪ کاهش عادی' );
	eq( 90.0, WAPM_Price_Engine::calculate( '100', $s ), '۱۰٪ کاهش روی ۱۰۰' );
} );

/* -------------------------------------------------------------------------
 * ۸) تشخیص تداخل در Rollback (P0-5)
 *    WAPM_Logger::same_price() تصمیم می‌گیرد آیا قیمت فعلی همان چیزی است که
 *    Batch نوشته بود یا نه؛ اگر false برگرداند، ردیف بازگردانی نمی‌شود.
 * ---------------------------------------------------------------------- */

$GLOBALS['wapm_same_price'] = ( new ReflectionMethod( 'WAPM_Logger', 'same_price' ) );
$GLOBALS['wapm_same_price']->setAccessible( true );

function same_price( string $a, string $b ): bool {
	return $GLOBALS['wapm_same_price']->invoke( null, $a, $b );
}

test( 'تداخل: قیمت یکسان', function () {
	stub_reset();
	ok( same_price( '1200000', '1200000' ), 'رشته‌ی یکسان' );
	ok( same_price( '1200000', '1200000.00' ), 'قالب اعشاری متفاوت ولی مقدار یکسان' );
} );

test( 'تداخل: قیمت عوض شده', function () {
	stub_reset();
	ok( ! same_price( '1200000', '1250000' ), 'قیمت جدیدتر → تداخل' );
} );

test( 'تداخل: حذف/افزودن قیمت ویژه', function () {
	stub_reset();
	ok( same_price( '', '' ), 'هر دو بدون قیمت' );
	ok( ! same_price( '', '900000' ), 'قبلاً حذف شده بود، حالا قیمت دارد → تداخل' );
	ok( ! same_price( '900000', '' ), 'قبلاً قیمت داشت، حالا حذف شده → تداخل' );
} );

/* -------------------------------------------------------------------------
 * ۸) ترب — نرمال‌سازی، استخراج کد، تطبیق (توابع خالص)
 * ---------------------------------------------------------------------- */

test( 'ترب/normalize: یکسان‌سازی حروف فارسی و عربی', function () {
	stub_reset();
	// ي عربی و ك عربی باید با ی/ک فارسی یکی شوند، وگرنه دو محصول یکسان
	// هرگز به هم وصل نمی‌شوند.
	eq( WAPM_Torob::normalize( 'يخچال' ), WAPM_Torob::normalize( 'یخچال' ), 'ي عربی = ی فارسی' );
	eq( WAPM_Torob::normalize( 'كولر' ), WAPM_Torob::normalize( 'کولر' ), 'ك عربی = ک فارسی' );
} );

test( 'ترب/normalize: نیم‌فاصله، اعراب و فاصله‌ی اضافه', function () {
	stub_reset();
	eq( WAPM_Torob::normalize( 'گوشی‌های   سامسونگ' ), 'گوشی های سامسونگ', 'نیم‌فاصله → فاصله، فاصله‌ی تکراری → یکی' );
	eq( WAPM_Torob::normalize( 'مَرهی' ), WAPM_Torob::normalize( 'مرهی' ), 'اعراب حذف می‌شود' );
	eq( 'گوشی سامسونگ', WAPM_Torob::normalize( '  گوشی   سامسونگ  ' ), 'فاصله‌ی ابتدا/انتها' );
} );

test( 'ترب/normalize: الفبا و ارقام لاتین یکسان می‌شوند', function () {
	stub_reset();
	// ش و س جدا می‌مانند (درست است — متفاوت‌اند) ولی ارقام فارسی/عربی
	// باید به لاتین تبدیل شوند.
	$n = WAPM_Torob::normalize( 'گوشی سامسونگ ۱۲۸ گیگ' );
	ok( false !== strpos( $n, '128' ), 'ارقام فارسی → لاتین', $n );
} );

test( 'ترب/to_ascii_digits: ارقام فارسی و عربی', function () {
	stub_reset();
	eq( '123456', WAPM_Torob::to_ascii_digits( '۱۲۳۴۵۶' ), 'فارسی' );
	eq( '123456', WAPM_Torob::to_ascii_digits( '١٢٣٤٥٦' ), 'عربی' );
	eq( 'A-42', WAPM_Torob::to_ascii_digits( 'A-42' ), 'لاتین دست‌نخورده' );
} );

test( 'ترب/extract_codes: الگوی «کد»', function () {
	stub_reset();
	$codes = WAPM_Torob::extract_codes( 'یخچال بوش مدل KGN86 کد 123456' );
	ok( in_array( '123456', $codes, true ), 'کد بعد از «کد»', implode( ',', $codes ) );
} );

test( 'ترب/extract_codes: عدد انتهایی و مدل حرف+رقم', function () {
	stub_reset();
	$codes = WAPM_Torob::extract_codes( 'گوشی سامسونگ Galaxy SM-G991 64GB' );
	ok( in_array( 'SM-G991', $codes, true ), 'مدل حرف+رقم کامل (نه G991)', implode( ',', $codes ) );

	$codes = WAPM_Torob::extract_codes( 'ماشین لباسشویی 98765' );
	ok( in_array( '98765', $codes, true ), 'عدد انتهایی', implode( ',', $codes ) );
} );

test( 'ترب/extract_codes: عنوان بدون کد → آرایه‌ی خالی', function () {
	stub_reset();
	eq( [], WAPM_Torob::extract_codes( 'گوشی موبایل سامسونگ' ), 'بدون کد' );
} );

test( 'ترب/extract_codes: سقف ۵ کد', function () {
	stub_reset();
	$codes = WAPM_Torob::extract_codes( 'کد 1111 (2222) 3333 مدل A123 B456' );
	ok( count( $codes ) <= 5, 'حداکثر ۵ کد', (string) count( $codes ) );
} );

test( 'ترب/token_similarity: عنوان یکسان = ۱', function () {
	stub_reset();
	eq( 1.0, WAPM_Torob::token_similarity( 'گوشی سامسونگ s21', 'گوشی سامسونگ s21' ), 'یکسان' );
} );

test( 'ترب/token_similarity: عنوان کاملاً متفاوت = ۰', function () {
	stub_reset();
	eq( 0.0, WAPM_Torob::token_similarity( 'یخچال بوش', 'گوشی شیائومی' ), 'بی‌اشتراک' );
} );

test( 'ترب/token_similarity: واژه‌های اضافه‌ی ترب امتیاز را خراب نمی‌کنند', function () {
	stub_reset();
	// عنوان ترب معمولاً «گارانتی …» و «کد …» دارد؛ باید همچنان بالا بماند.
	$score = WAPM_Torob::token_similarity(
		'گوشی موبایل سامسونگ',
		'گوشی موبایل سامسونگ گارانتی ۱۸ ماهه کد 12345'
	);
	ok( $score >= 0.6, 'امتیاز بالا با واژه‌های اضافه', (string) $score );
} );

test( 'ترب/token_similarity: رشته‌ی خالی = ۰', function () {
	stub_reset();
	eq( 0.0, WAPM_Torob::token_similarity( '', 'گوشی' ), 'خالی' );
} );

test( 'ترب/diff_pct: محاسبه‌ی درصد اختلاف', function () {
	stub_reset();
	eq( 10.0, WAPM_Torob::diff_pct( 1100.0, 1000.0 ), 'گران‌تر ۱۰٪' );
	eq( -9.09, WAPM_Torob::diff_pct( 1000.0, 1100.0 ), 'ارزان‌تر ۹.۰۹٪' );
	eq( 0.0, WAPM_Torob::diff_pct( 1000.0, 1000.0 ), 'برابر' );
} );

test( 'ترب/diff_pct: ورودی نامعتبر → null (نه تقسیم بر صفر)', function () {
	stub_reset();
	eq( null, WAPM_Torob::diff_pct( 0.0, 1000.0 ), 'قیمت خودمان صفر' );
	eq( null, WAPM_Torob::diff_pct( 1000.0, null ), 'رقیب نامعلوم' );
	eq( null, WAPM_Torob::diff_pct( 1000.0, 0.0 ), 'رقیب صفر' );
} );

/* -------------------------------------------------------------------------
 * ۹) ترب — وضعیت مقایسه
 * ---------------------------------------------------------------------- */

function torob_fresh( array $over = [] ): array {
	return array_merge( [
		'has_data'         => true,
		'shop_known'       => true,
		'checked_at'       => gmdate( 'Y-m-d H:i:s' ),
		'competitor_price' => 1000.0,
	], $over );
}

test( 'ترب/status: بدون داده → none', function () {
	stub_reset();
	eq( 'none', WAPM_Torob::compute_status( [ 'has_data' => false ], 1000.0 ), 'داده‌ای نیست' );
} );

test( 'ترب/status: شناسه‌ی فروشگاه نامعلوم → needs_shop_id (نه مقایسه‌ی غلط)', function () {
	stub_reset();
	// مهم‌ترین محافظ: اگر خودمان را نشناسیم، «رقیب» ممکن است خودمان باشیم.
	eq( 'needs_shop_id', WAPM_Torob::compute_status( torob_fresh( [ 'shop_known' => false ] ), 1200.0 ), 'بدون شناسه' );
} );

test( 'ترب/status: اتصال تازه‌ی بدون داده → none (نه «کهنه»)', function () {
	stub_reset();
	// محصولی که دستی به ترب وصل شده ولی هنوز همگام‌سازی نشده است.
	eq( 'none', WAPM_Torob::compute_status( torob_fresh( [ 'checked_at' => '', 'competitor_price' => null ] ), 1200.0 ), 'بدون داده و بدون تاریخ' );
} );

test( 'ترب/status: داده‌ی قدیمی → stale', function () {
	stub_reset();
	$old = gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) );
	eq( 'stale', WAPM_Torob::compute_status( torob_fresh( [ 'checked_at' => $old ] ), 1200.0 ), '۱۰ روز پیش' );

	// مرز: درست داخل بازه‌ی fresh_days نباید کهنه شود.
	$inside = gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) );
	eq( 'pricier', WAPM_Torob::compute_status( torob_fresh( [ 'checked_at' => $inside ] ), 1200.0 ), '۲ روز پیش' );
} );

test( 'ترب/status: بدون قیمت رقیب → no_competitor (فقط ما)', function () {
	stub_reset();
	eq( 'no_competitor', WAPM_Torob::compute_status( torob_fresh( [ 'competitor_price' => null ] ), 1200.0 ), 'null' );
	eq( 'no_competitor', WAPM_Torob::compute_status( torob_fresh( [ 'competitor_price' => 0.0 ] ), 1200.0 ), 'صفر' );
} );

test( 'ترب/status: گران‌تر/ارزان‌تر/برابر با آستانه', function () {
	stub_reset();
	eq( 'pricier', WAPM_Torob::compute_status( torob_fresh(), 1200.0 ), '۲۰٪ گران‌تر' );
	eq( 'cheaper', WAPM_Torob::compute_status( torob_fresh(), 800.0 ), '۲۰٪ ارزان‌تر' );
	eq( 'equal', WAPM_Torob::compute_status( torob_fresh(), 1005.0 ), '۰.۵٪ → برابر' );
	eq( 'pricier', WAPM_Torob::compute_status( torob_fresh(), 1020.0 ), '۲٪ → گران‌تر' );
} );

test( 'ترب/status: آستانه‌ی «برابر» قابل تنظیم است', function () {
	stub_reset();
	$s = [ 'fresh_days' => 3, 'tolerance_pct' => 5.0, 'shop_name' => '' ];
	eq( 'equal', WAPM_Torob::compute_status( torob_fresh(), 1030.0, $s ), '۳٪ با آستانه‌ی ۵٪' );
	eq( 'pricier', WAPM_Torob::compute_status( torob_fresh(), 1060.0, $s ), '۶٪ با آستانه‌ی ۵٪' );
} );

test( 'ترب/status: قیمت خودمان صفر → no_price', function () {
	stub_reset();
	eq( 'no_price', WAPM_Torob::compute_status( torob_fresh(), 0.0 ), 'قیمت نامشخص' );
} );

/* -------------------------------------------------------------------------
 * ۱۰) ترب — جداکردن قیمت خودمان از رقیب
 * ---------------------------------------------------------------------- */

function sellers( array $rows ): array {
	return WAPM_Torob::extract_sellers( $rows );
}

test( 'ترب/sellers: فیلتر قیمت صفر و مرتب‌سازی صعودی', function () {
	stub_reset();
	$res = sellers( [
		[ 'shop_id' => 1, 'shop_name' => 'الف', 'price' => 500 ],
		[ 'shop_id' => 2, 'shop_name' => 'ب', 'price' => 0 ],
		[ 'shop_id' => 3, 'shop_name' => 'ج', 'price' => 300 ],
	] );
	eq( 2, $res['count'], 'ردیف بی‌قیمت حذف شد' );
	eq( 300.0, $res['list'][0]['price'], 'ارزان‌ترین اول' );
} );

test( 'ترب/sellers: پذیرش نام‌های جایگزین کلیدها', function () {
	stub_reset();
	$res = sellers( [ [ 'shopId' => 7, 'seller_name' => 'فروشگاه هفت', 'price' => '250000' ] ] );
	eq( 7, $res['list'][0]['shop_id'], 'shopId' );
	eq( 'فروشگاه هفت', $res['list'][0]['shop_name'], 'seller_name' );
	eq( 250000.0, $res['list'][0]['price'], 'price رشته‌ای' );
} );

test( 'ترب/cheapest: قیمت خودمان رقیب حساب نمی‌شود', function () {
	stub_reset();
	$s = sellers( [
		[ 'shop_id' => 99, 'shop_name' => 'ما', 'price' => 900 ],
		[ 'shop_id' => 5, 'shop_name' => 'رقیب', 'price' => 1000 ],
	] );
	$r = WAPM_Torob::cheapest_competitor( $s, 99 );
	eq( 900.0, $r['ours'], 'قیمت ما' );
	eq( 1000.0, $r['competitor'], 'ارزان‌ترین رقیب (نه خودمان)' );
	eq( 1, $r['others'], 'یک رقیب' );
	ok( $r['shop_known'], 'فروشگاه شناسایی شد' );
} );

test( 'ترب/cheapest: شناسایی با نام فروشگاه (fallback)', function () {
	stub_reset();
	$s = sellers( [
		[ 'shop_id' => 0, 'shop_name' => 'فروشگاه من', 'price' => 700 ],
		[ 'shop_id' => 5, 'shop_name' => 'رقیب', 'price' => 800 ],
	] );
	$r = WAPM_Torob::cheapest_competitor( $s, 0, WAPM_Torob::normalize( 'فروشگاه من' ) );
	eq( 700.0, $r['ours'], 'تشخیص با نام' );
	eq( 800.0, $r['competitor'], 'رقیب' );
} );

test( 'ترب/cheapest: بدون شناسه، competitor خالی می‌ماند (P1-1)', function () {
	stub_reset();
	$s = sellers( [
		[ 'shop_id' => 1, 'shop_name' => 'الف', 'price' => 500 ],
		[ 'shop_id' => 2, 'shop_name' => 'ب', 'price' => 600 ],
	] );
	$r = WAPM_Torob::cheapest_competitor( $s, 0 );
	eq( null, $r['competitor'], 'رقیب محاسبه نمی‌شود' );
	ok( ! $r['shop_known'], 'فروشگاه نامعلوم' );
	eq( 2, $r['others'], 'ولی تعداد رقبا گزارش می‌شود' );
} );

test( 'ترب/cheapest: ما چند رکورد داریم → کمترین قیمت خودمان', function () {
	stub_reset();
	$s = sellers( [
		[ 'shop_id' => 99, 'shop_name' => 'ما', 'price' => 900 ],
		[ 'shop_id' => 99, 'shop_name' => 'ما', 'price' => 950 ],
		[ 'shop_id' => 5, 'shop_name' => 'رقیب', 'price' => 2000 ],
	] );
	$r = WAPM_Torob::cheapest_competitor( $s, 99 );
	eq( 900.0, $r['ours'], 'کمترین قیمت ما' );
	eq( 2000.0, $r['competitor'], 'رقیب' );
	eq( 1, $r['others'], 'دو رکورد ما رقیب شمرده نشد' );
} );

/* -------------------------------------------------------------------------
 * ۱۱) ترب — قیمت هدف (هسته‌ی تصمیم، پرریسک‌ترین بخش)
 * ---------------------------------------------------------------------- */

test( 'ترب/هدف: زیربرش درصدی از ارزان‌ترین رقیب', function () {
	stub_reset(); // تومان، ۰ اعشار
	$s = settings( [ 'source' => 'torob', 'torob_undercut_mode' => 'percent', 'torob_undercut' => 1 ] );
	eq( 990.0, WAPM_Price_Engine::torob_target_price( 1000.0, $s ), '۱۰۰۰ − ۱٪' );
	eq( 950000.0, WAPM_Price_Engine::torob_target_price( 959596.0, $s ), 'زیربرش ۱٪ روی قیمت واقعی' );
} );

test( 'ترب/هدف: زیربرش مبلغ ثابت با تبدیل واحد', function () {
	stub_reset( [ 'currency' => 'IRT' ] );
	$s = settings( [ 'source' => 'torob', 'torob_undercut_mode' => 'fixed', 'torob_undercut' => 10000, 'torob_currency_unit' => 'toman' ] );
	eq( 990000.0, WAPM_Price_Engine::torob_target_price( 1000000.0, $s ), '۱۰٬۰۰۰ تومان کمتر' );

	// کاربر ریال وارد می‌کند ولی فروشگاه تومانی است → ۱۰٬۰۰۰ ریال = ۱٬۰۰۰ تومان.
	$s = settings( [ 'source' => 'torob', 'torob_undercut_mode' => 'fixed', 'torob_undercut' => 10000, 'torob_currency_unit' => 'rial' ] );
	eq( 999000.0, WAPM_Price_Engine::torob_target_price( 1000000.0, $s ), 'ورودی ریال در فروشگاه تومانی' );
} );

test( 'ترب/هدف: گرد کردن قبل از اعمال (به مضرب ۱۰۰۰)', function () {
	stub_reset();
	$s = settings( [ 'source' => 'torob', 'torob_undercut' => 1, 'round_mode' => 'nearest', 'round_base' => 1000 ] );
	// ۹۹۰٬۵۰۰ × ۹۹٪ = ۹۸۰٬۵۹۵ → نزدیک‌ترین مضرب ۱۰۰۰ = ۹۸۱٬۰۰۰
	eq( 981000.0, WAPM_Price_Engine::torob_target_price( 990500.0, $s ), 'گرد کردن روی قیمت هدف' );
} );

test( 'ترب/هدف: کف قیمت مانع صفرشدن می‌شود', function () {
	stub_reset();
	$s = settings( [ 'source' => 'torob', 'torob_undercut_mode' => 'percent', 'torob_undercut' => 100 ] );
	eq( 1.0, WAPM_Price_Engine::torob_target_price( 1000.0, $s ), 'زیربرش ۱۰۰٪ → ۱ تومان' );
} );

test( 'ترب/هدف: حداقل قیمت تنظیم‌شده حاکم است', function () {
	stub_reset();
	$s = settings( [ 'source' => 'torob', 'torob_undercut' => 1, 'min_price' => 500000 ] );
	eq( 500000.0, WAPM_Price_Engine::torob_target_price( 400000.0, $s ), 'قیمت هدف زیر کف → کف' );
} );

test( 'ترب/هدف: رقیب نامعلوم → null (نه قیمت صفر)', function () {
	stub_reset();
	$s = settings( [ 'source' => 'torob' ] );
	eq( null, WAPM_Price_Engine::torob_target_price( 0.0, $s ), 'صفر' );
	eq( null, WAPM_Price_Engine::torob_target_price( -5.0, $s ), 'منفی' );
} );

test( 'ترب/هدف: منبع پیش‌فرض manual است', function () {
	stub_reset();
	eq( 'manual', settings( [] )['source'], 'پیش‌فرض' );
	eq( true, WAPM_Price_Engine::uses_torob( settings( [ 'source' => 'torob' ] ) ), 'torob' );
	eq( false, WAPM_Price_Engine::uses_torob( settings( [] ) ), 'manual' );
} );

test( 'ترب/هدف: صفرِ عمدی کاربر برای زیربرش حفظ می‌شود', function () {
	stub_reset();
	// پیش‌فرض ۱٪ است، ولی «۰» یک انتخاب معتبر است (دقیقاً برابر رقیب).
	$s = settings( [ 'source' => 'torob', 'torob_undercut' => 0 ] );
	eq( 0.0, $s['torob_undercut'], '۰ حفظ شد' );
	eq( 1000.0, WAPM_Price_Engine::torob_target_price( 1000.0, $s ), 'برابر رقیب' );
} );

/* -------------------------------------------------------------------------
 * ۱۲) ترب — API: پارس قیمت و نرمال‌سازی ردیف‌ها
 * ---------------------------------------------------------------------- */

test( 'ترب/API: پارس قیمت فارسی با جداکننده', function () {
	stub_reset();
	eq( 8680000.0, WAPM_Torob_Api::parse_price_text( 'از ۸٫۶۸۰٫۰۰۰ تومان' ), 'با پیشوند «از»' );
	eq( 1990000.0, WAPM_Torob_Api::parse_price_text( '۱٫۹۹۰٫۰۰۰ تومان' ), 'بدون پیشوند' );
	eq( 500000.0, WAPM_Torob_Api::parse_price_text( '500,000 تومان' ), 'لاتین با کاما' );
} );

test( 'ترب/API: پارس قیمت — «ریال» به تومان تبدیل می‌شود', function () {
	stub_reset();
	// اگر روزی ترب واحد را عوض کند، داده باید بی‌صدا ۱۰ برابر نشود.
	eq( 10.0, WAPM_Torob_Api::parse_price_text( '۱۰۰ ریال' ), 'ریال → تومان' );
} );

test( 'ترب/API: پارس قیمت بدون رقم → null', function () {
	stub_reset();
	eq( null, WAPM_Torob_Api::parse_price_text( 'ناموجود' ), 'متن بدون عدد' );
	eq( null, WAPM_Torob_Api::parse_price_text( '' ), 'خالی' );
} );

test( 'ترب/API: کارت محصول از لیست فروشگاه', function () {
	stub_reset();
	$card = WAPM_Torob_Api::card( [
		'random_key'              => 'd0640307-e9ca-4f25-9331-b76f9bba682c',
		'name1'                   => 'شارژر شیائومی',
		'price'                   => 1990000,
		'price_text'              => '۱٫۹۹۰٫۰۰۰ تومان',
		'web_client_absolute_url' => '/p/d0640307-e9ca-4f25-9331-b76f9bba682c/x/',
	] );
	eq( 'd0640307-e9ca-4f25-9331-b76f9bba682c', $card['prk'], 'prk' );
	eq( 1990000.0, $card['price'], 'قیمت (قیمت خود فروشگاه)' );
	eq( 'https://torob.com/p/d0640307-e9ca-4f25-9331-b76f9bba682c/x/', $card['url'], 'آدرس کامل' );
} );

test( 'ترب/API: کارت با متن قیمت ناسازگار → قیمت دور ریخته می‌شود', function () {
	stub_reset();
	// عدد ۱٬۰۰۰ ولی متن ۱۰٬۰۰۰ → ۱۰ برابر اختلاف: یا واحد عوض شده یا داده خراب.
	$card = WAPM_Torob_Api::card( [
		'random_key' => 'aaaaaaaa-1111-2222-3333-444444444444',
		'name1'      => 'محصول',
		'price'      => 1000,
		'price_text' => '۱۰٫۰۰۰ تومان',
	] );
	eq( null, $card['price'], 'قیمت نامعتبر → null (نه مقایسه‌ی ۱۰ برابری)' );
} );

test( 'ترب/API: کارت بدون prk → null', function () {
	stub_reset();
	eq( null, WAPM_Torob_Api::card( [ 'name1' => 'بدون شناسه', 'price' => 100 ] ), 'ردیف بی‌شناسه' );
} );

test( 'ترب/API: ردیف فروشنده — ناموجود و قیمت طعمه', function () {
	stub_reset();
	$s1 = WAPM_Torob_Api::seller( [
		'shop_id' => 5, 'shop_name' => 'فروشگاه', 'price' => 1000,
		'price_text' => '۱٬۰۰۰ تومان', 'availability' => false,
	] );
	eq( false, $s1['available'], 'ناموجود' );
	eq( 0.0, $s1['price'], 'قیمت ناموجود صفر می‌شود' );

	$s2 = WAPM_Torob_Api::seller( [
		'shop_id' => 6, 'shop_name' => 'طعمه', 'price' => 1000,
		'price_text' => '۱٬۰۰۰ تومان', 'is_price_unreliable' => true,
	] );
	eq( true, $s2['unreliable'], 'پرچم قیمت نامعتبر' );
	eq( 1000.0, $s2['price'], 'قیمت حفظ می‌شود (فقط علامت می‌خورد)' );
} );

test( 'ترب/API: ردیف فروشنده با متن قیمت ناسازگار → نامعتبر', function () {
	stub_reset();
	$s = WAPM_Torob_Api::seller( [
		'shop_id' => 7, 'shop_name' => 'شک', 'price' => 500,
		'price_text' => '۵٬۰۰۰ تومان',
	] );
	eq( true, $s['unreliable'], 'اختلاف ۱۰ برابری متن/عدد' );
} );

test( 'ترب/to_bool: پرچم‌های رشته‌ای درست خوانده می‌شوند', function () {
	stub_reset();

	// مقادیر منفی: در PHP خالی/صفر/«false» همه درست‌نما هستند.
	foreach ( [ false, 0, 0.0, '0', 'false', 'FALSE', ' false ', 'no', 'off', 'null', '', null ] as $i => $v ) {
		eq( false, WAPM_Torob::to_bool( $v ), 'مقدار منفی #' . $i );
	}

	// مقادیر مثبت.
	foreach ( [ true, 1, 2.5, '1', 'true', 'yes', 'on', 'قیمت' ] as $i => $v ) {
		eq( true, WAPM_Torob::to_bool( $v ), 'مقدار مثبت #' . $i );
	}

	eq( false, WAPM_Torob::to_bool( [] ), 'آرایه‌ی خالی' );
	eq( true, WAPM_Torob::to_bool( [ 1 ] ), 'آرایه‌ی پر' );
} );

test( 'ترب/API: availability رشته‌ای «false» یعنی ناموجود', function () {
	stub_reset();

	// قبلاً `false !== "false"` همیشه true بود؛ کالای ناموجود با قیمت طعمه
	// در محاسبه‌ی «ارزان‌ترین رقیب» شرکت می‌کرد.
	$s = WAPM_Torob_Api::seller( [
		'shop_id' => 9, 'shop_name' => 'ناموجود', 'price' => 99000,
		'price_text' => '۹۹٬۰۰۰ تومان', 'availability' => 'false',
	] );
	eq( false, $s['available'], 'رشته‌ی «false» = ناموجود' );
	eq( 0.0, $s['price'], 'قیمت پاک می‌شود' );

	$s2 = WAPM_Torob_Api::seller( [
		'shop_id' => 10, 'shop_name' => 'موجود', 'price' => 99000,
		'price_text' => '۹۹٬۰۰۰ تومان', 'availability' => 'true',
	] );
	eq( true, $s2['available'], 'رشته‌ی «true» = موجود' );
	eq( 99000.0, $s2['price'], 'قیمت حفظ می‌شود' );
} );

test( 'ترب/API: is_price_unreliable رشته‌ای', function () {
	stub_reset();

	$s = WAPM_Torob_Api::seller( [
		'shop_id' => 11, 'shop_name' => 'سالم', 'price' => 1000,
		'is_price_unreliable' => 'false',
	] );
	eq( false, $s['unreliable'], 'رشته‌ی «false» = معتبر' );

	$s2 = WAPM_Torob_Api::seller( [
		'shop_id' => 12, 'shop_name' => 'مشکوک', 'price' => 1000,
		'is_price_unreliable' => '1',
	] );
	eq( true, $s2['unreliable'], 'رشته‌ی «1» = نامعتبر' );
} );

test( 'ترب/sellers: پرچم‌های رشته‌ای فید/بوکمارکلت', function () {
	stub_reset();

	$res = sellers( [
		[ 'shop_id' => 1, 'shop_name' => 'موجود', 'price' => 700, 'available' => 'true', 'unreliable' => 'false' ],
		[ 'shop_id' => 2, 'shop_name' => 'ناموجود', 'price' => 300, 'available' => 'false' ],
	] );

	eq( 1, $res['count'], 'فقط موجود' );
	eq( 1, $res['hidden'], 'ناموجود شمرده شد' );
	eq( 700.0, $res['list'][0]['price'], 'قیمت موجود' );
	eq( false, $res['list'][0]['unreliable'], '«false» رشته‌ای پرچم نمی‌زند' );
} );

test( 'ترب/API: صفحه‌ی بعدی کاتالوگ با next تعیین می‌شود، نه شمارش کارت‌ها', function () {
	stub_reset();

	$card = [ 'random_key' => 'abc123', 'name1' => 'محصول', 'price' => 1000 ];

	// یک کارت خراب (بی‌شناسه) نباید صفحه را «آخرین» جا بزند.
	$page = WAPM_Torob_Api::parse_shop_products( [
		'count'   => 48,
		'next'    => '/v4/internet-shop/base-product/list/?page=1',
		'results' => [ $card, [ 'name1' => 'خراب', 'price' => 5 ] ],
	], 0 );

	eq( 1, count( $page['items'] ), 'کارت خراب دور ریخته شد' );
	eq( true, $page['has_more'], 'صفحه‌ی بعدی وجود دارد' );
	eq( 48, $page['count'], 'کل کاتالوگ' );

	$last = WAPM_Torob_Api::parse_shop_products( [ 'count' => 48, 'results' => [ $card ] ], 1 );
	eq( false, $last['has_more'], 'بدون next = آخرین صفحه' );

	$empty = WAPM_Torob_Api::parse_shop_products( [ 'next' => '/x', 'results' => [] ], 2 );
	eq( false, $empty['has_more'], 'صفحه‌ی خالی حلقه نمی‌سازد' );
	eq( 0, count( $empty['items'] ), 'بدون آیتم' );
} );

test( 'ترب/API: سقف بودجه‌ی روزانه یک بار خوانده می‌شود', function () {
	stub_reset();

	eq( 60, WAPM_Torob_Api::daily_budget(), 'سقف پیش‌فرض' );

	update_option( 'wapm_torob_api_budget', [ 'date' => gmdate( 'Y-m-d' ), 'count' => 5 ], false );
	$state = WAPM_Torob_Api::state();

	eq( 60, $state['budget'], 'سقف' );
	eq( 5, $state['used'], 'مصرف امروز' );
	eq( 55, $state['left'], 'باقی‌مانده' );
	eq( 0, $state['minutes_left'], 'بدون کول‌داون' );
	eq( false, $state['blocked'], 'بلاک نیست' );

	// شمارنده‌ی دیروز نباید امروز حساب شود.
	update_option( 'wapm_torob_api_budget', [ 'date' => '2000-01-01', 'count' => 59 ], false );
	eq( 60, WAPM_Torob_Api::state()['left'], 'بودجه‌ی دیروز ریست می‌شود' );
} );

/* -------------------------------------------------------------------------
 * ۱۳) ترب — تصمیم‌گیری با پرچم‌های موجودی/اعتبار
 * ---------------------------------------------------------------------- */

test( 'ترب/sellers: ناموجود در لیست نمی‌آید ولی شمرده می‌شود', function () {
	stub_reset();
	$res = sellers( [
		[ 'shop_id' => 1, 'shop_name' => 'الف', 'price' => 500, 'available' => true ],
		[ 'shop_id' => 2, 'shop_name' => 'ب', 'price' => 300, 'available' => false ],
	] );
	eq( 1, $res['count'], 'فقط موجودها' );
	eq( 1, $res['hidden'], 'ناموجود شمرده شد' );
	eq( 500.0, $res['list'][0]['price'], 'قیمت موجود' );
} );

test( 'ترب/sellers: پرچم «قیمت نامعتبر» منتقل می‌شود', function () {
	stub_reset();
	$res = sellers( [ [ 'shop_id' => 1, 'shop_name' => 'الف', 'price' => 500, 'unreliable' => true ] ] );
	eq( true, $res['list'][0]['unreliable'], 'پرچم' );
} );

test( 'ترب/cheapest: ارزان‌ترین قیمت طعمه نادیده گرفته می‌شود', function () {
	stub_reset();
	$s = sellers( [
		[ 'shop_id' => 99, 'shop_name' => 'ما', 'price' => 900 ],
		[ 'shop_id' => 5, 'shop_name' => 'طعمه', 'price' => 100, 'unreliable' => true ],
		[ 'shop_id' => 6, 'shop_name' => 'واقعی', 'price' => 1000 ],
	] );
	$r = WAPM_Torob::cheapest_competitor( $s, 99 );
	eq( 1000.0, $r['competitor'], 'قیمت معتبر، نه ۱۰۰' );
	eq( 1, $r['unreliable_skipped'], 'یک قیمت نامعتبر رد شد' );
	eq( 2, $r['others'], 'هر دو رقیب شمرده شدند' );
} );

test( 'ترب/cheapest: اگر همه‌ی رقبا نامعتبر باشند → رقیب نامعلوم', function () {
	stub_reset();
	$s = sellers( [
		[ 'shop_id' => 99, 'shop_name' => 'ما', 'price' => 900 ],
		[ 'shop_id' => 5, 'shop_name' => 'طعمه ۱', 'price' => 100, 'unreliable' => true ],
		[ 'shop_id' => 6, 'shop_name' => 'طعمه ۲', 'price' => 200, 'unreliable' => true ],
	] );
	$r = WAPM_Torob::cheapest_competitor( $s, 99 );
	eq( null, $r['competitor'], 'مبنا ندارد' );
	eq( 2, $r['unreliable_skipped'], 'هر دو رد شدند' );
	ok( $r['shop_known'], 'فروشگاه خودمان شناسایی شد' );
} );

test( 'ترب/cheapest: داده‌ی قدیمی فید (بدون پرچم) مثل قبل کار می‌کند', function () {
	stub_reset();
	$s = sellers( [
		[ 'shop_id' => 99, 'shop_name' => 'ما', 'price' => 900 ],
		[ 'shop_id' => 5, 'shop_name' => 'رقیب', 'price' => 800 ],
	] );
	$r = WAPM_Torob::cheapest_competitor( $s, 99 );
	eq( 800.0, $r['competitor'], 'سازگاری با فید قدیمی' );
	eq( 0, $r['unreliable_skipped'], 'چیزی رد نشد' );
} );



test( 'مطلق/calculate: هر محصول دقیقاً به قیمت هدف می‌رسد و جهت بی‌اثر است', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'absolute', 'amount' => 500000, 'currency_unit' => 'toman', 'direction' => 'decrease' ] );
	eq( 'absolute', $s['mode'], 'حالت پذیرفته می‌شود' );
	eq( 500000.0, WAPM_Price_Engine::calculate( '1000000', $s ), 'قیمت مطلق' );
	eq( 500000.0, WAPM_Price_Engine::calculate( '200000', $s ), 'حتی وقتی قیمت فعلی کمتر است' );
} );

test( 'مطلق/calculate: تبدیل ریال، گرد کردن و حداقل قیمت', function () {
	stub_reset();
	$rial = settings( [ 'mode' => 'absolute', 'amount' => 5000000, 'currency_unit' => 'rial' ] );
	eq( 500000.0, WAPM_Price_Engine::calculate( '900000', $rial ), '۵٬۰۰۰٬۰۰۰ ریال = ۵۰۰٬۰۰۰ تومان' );

	$round = settings( [ 'mode' => 'absolute', 'amount' => 123456, 'round_mode' => 'nearest', 'round_base' => 1000 ] );
	eq( 123000.0, WAPM_Price_Engine::calculate( '1000', $round ), 'گرد کردن نزدیک‌ترین مضرب' );

	$min = settings( [ 'mode' => 'absolute', 'amount' => 100000, 'min_price' => 150000 ] );
	eq( 150000.0, WAPM_Price_Engine::calculate( '900000', $min ), 'حداقل قیمت اعمال می‌شود' );
} );

test( 'مطلق/calculate: قیمت صفر ورودی هم هدف را می‌گیرد (برخلاف حالت درصدی)', function () {
	stub_reset();
	$s = settings( [ 'mode' => 'absolute', 'amount' => 250000 ] );
	eq( 250000.0, WAPM_Price_Engine::calculate( '0', $s ), 'صفر هم به هدف می‌رسد' );
	eq( null, WAPM_Price_Engine::calculate( '', $s ), 'قیمت خالی رد می‌شود' );
} );

/* -------------------------------------------------------------------------
 * موجودی — WAPM_Stock (توابع خالص)
 * ---------------------------------------------------------------------- */

test( 'موجودی/compute_qty: تنظیم، افزایش و کاهش با تعداد', function () {
	eq( 25.0, WAPM_Stock::compute_qty( 10, 'set', 'count', 25 ), 'تنظیم' );
	eq( 30.0, WAPM_Stock::compute_qty( 10, 'increase', 'count', 20 ), 'افزایش' );
	eq( 4.0, WAPM_Stock::compute_qty( 10, 'decrease', 'count', 6 ), 'کاهش' );
} );

test( 'موجودی/compute_qty: درصد از موجودی فعلی', function () {
	eq( 15.0, WAPM_Stock::compute_qty( 10, 'increase', 'percent', 50 ), 'ده + پنجاه درصد' );
	eq( 5.0, WAPM_Stock::compute_qty( 10, 'decrease', 'percent', 50 ), 'ده منهای پنجاه درصد' );
	eq( 0.0, WAPM_Stock::compute_qty( 0, 'increase', 'percent', 50 ), 'درصد روی صفر اثری ندارد' );
} );

test( 'موجودی/compute_qty: کاهش زیر صفر مسدود می‌شود مگر پیش‌خرید', function () {
	eq( 0.0, WAPM_Stock::compute_qty( 3, 'decrease', 'count', 10 ), 'پیش‌فرض: صفر' );
	eq( -7.0, WAPM_Stock::compute_qty( 3, 'decrease', 'count', 10, true ), 'پیش‌خرید: منفی مجاز' );
} );

test( 'موجودی/compute_qty: مقدار منفی ورودی مثل قدرمطلق رفتار می‌کند', function () {
	eq( 20.0, WAPM_Stock::compute_qty( 10, 'increase', 'count', -10 ), 'افزایش با عدد منفی' );
} );

test( 'موجودی/compute_qty: گرد کردن تا سه رقم', function () {
	eq( 3.333, WAPM_Stock::compute_qty( 10, 'decrease', 'percent', 66.67 ), 'کاهش درصدی گرد می‌شود' );
} );

test( 'موجودی/needs_change: تفاوت ناچیز تغییر حساب نمی‌شود', function () {
	ok( true === WAPM_Stock::needs_change( 5, 6 ), '۵ → ۶ تغییر است' );
	ok( false === WAPM_Stock::needs_change( 5, 5 ), '۵ → ۵ تغییر نیست' );
	ok( false === WAPM_Stock::needs_change( 5, 5.0000001 ), 'اختلاف میکروسکوپی نادیده' );
} );

test( 'موجودی/is_valid_amount: محدوده‌ی مجاز', function () {
	ok( true === WAPM_Stock::is_valid_amount( 0, 'count' ), 'صفر مجاز' );
	ok( false === WAPM_Stock::is_valid_amount( -1, 'count' ), 'منفی رد' );
	ok( false === WAPM_Stock::is_valid_amount( 1e9, 'count' ), 'عدد بزرگ رد' );
	ok( false === WAPM_Stock::is_valid_amount( 1001, 'percent' ), 'درصد بیش از ۱۰۰۰ رد' );
	ok( true === WAPM_Stock::is_valid_amount( 1000, 'percent' ), 'درصد ۱۰۰۰ مجاز' );
	ok( false === WAPM_Stock::is_valid_amount( INF, 'count' ), 'بی‌نهایت رد' );
	ok( false === WAPM_Stock::is_valid_amount( NAN, 'count' ), 'NaN رد' );
} );

test( 'موجودی/sanitize_operation: «تنظیم» همیشه با تعداد است', function () {
	$op = WAPM_Stock::sanitize_operation( [ 'mode' => 'set', 'unit' => 'percent', 'amount' => '12' ] );
	eq( 'set', $op['mode'], 'حالت' );
	eq( 'count', $op['unit'], 'درصد بی‌معنی است، تعداد می‌شود' );
	eq( 12.0, $op['amount'], 'مقدار عددی' );
} );

test( 'موجودی/sanitize_operation: ورودی نامعتبر null می‌دهد', function () {
	ok( null === WAPM_Stock::sanitize_operation( [ 'mode' => 'multiply', 'unit' => 'count', 'amount' => 5 ] ), 'حالت ناشناخته' );
	ok( null === WAPM_Stock::sanitize_operation( [ 'mode' => 'increase', 'unit' => 'count', 'amount' => -5 ] ), 'مقدار منفی' );
	ok( null === WAPM_Stock::sanitize_operation( [ 'mode' => 'increase', 'unit' => 'percent', 'amount' => 5000 ] ), 'درصد بزرگ' );
	ok( null === WAPM_Stock::sanitize_operation( [ 'mode' => '', 'amount' => 5 ] ), 'حالت خالی' );
} );

test( 'موجودی/sanitize_stock_status: فارسی، انگلیسی و نیم‌فاصله', function () {
	eq( 'instock', WAPM_Stock::sanitize_stock_status( 'In Stock' ), 'انگلیسی با فاصله' );
	eq( 'instock', WAPM_Stock::sanitize_stock_status( 'in_stock' ), 'آندرلاین' );
	eq( 'instock', WAPM_Stock::sanitize_stock_status( 'موجود' ), 'فارسی' );
	eq( 'outofstock', WAPM_Stock::sanitize_stock_status( 'out of stock' ), 'انگلیسی ناموجود' );
	eq( 'outofstock', WAPM_Stock::sanitize_stock_status( 'ناموجود' ), 'فارسی ناموجود' );
	eq( 'onbackorder', WAPM_Stock::sanitize_stock_status( 'backorder' ), 'پیش‌خرید' );
	eq( 'onbackorder', WAPM_Stock::sanitize_stock_status( "پیش\u{200C}خرید" ), 'فارسی با نیم‌فاصله' );
	eq( '', WAPM_Stock::sanitize_stock_status( 'چیزی دیگر' ), 'ناشناخته → خالی' );
} );

test( 'موجودی/can_manage: محصول ساده‌ی دارای مدیریت انبار', function () {
	$r = WAPM_Stock::can_manage( new WC_Product( [ 'qty' => 7 ] ) );
	ok( true === $r['ok'], 'مجاز' );
	eq( 7.0, $r['qty'], 'موجودی فعلی' );
	eq( '', $r['reason'], 'بدون دلیل رد' );
} );

test( 'موجودی/can_manage: والد متغیر و محصول بدون مدیریت انبار رد می‌شوند', function () {
	$var = WAPM_Stock::can_manage( new WC_Product( [ 'type' => 'variable' ] ) );
	ok( false === $var['ok'], 'والد متغیر' );
	eq( 'variable_parent', $var['reason'], 'دلیل' );

	$off = WAPM_Stock::can_manage( new WC_Product( [ 'managing' => false ] ) );
	ok( false === $off['ok'], 'مدیریت انبار خاموش' );
	eq( 'not_managed', $off['reason'], 'دلیل' );

	$nothing = WAPM_Stock::can_manage( null );
	ok( false === $nothing['ok'], 'محصولی وجود ندارد' );
	eq( 'not_found', $nothing['reason'], 'دلیل' );
} );

test( 'موجودی/can_manage: موجودی null صفر گزارش می‌شود', function () {
	$r = WAPM_Stock::can_manage( new WC_Product( [ 'qty' => null ] ) );
	ok( true === $r['ok'], 'مجاز' );
	eq( 0.0, $r['qty'], 'null → صفر' );
} );

test( 'موجودی/reason_label: دلیل ناشناخته خودش برگردانده می‌شود', function () {
	eq( 'محصول پیدا نشد', WAPM_Stock::reason_label( 'not_found' ), 'ترجمه‌ی شناخته‌شده' );
	eq( 'salam', WAPM_Stock::reason_label( 'salam' ), 'ناشناخته' );
} );


test( 'موجودی/expand_ids: والد متغیر به واریشن‌ها باز می‌شود', function () {
	stub_reset();
	$GLOBALS['wapm_stub']['products'] = [
		10  => new WC_Product( [ 'id' => 10, 'type' => 'variable', 'managing' => false, 'children' => [ 101, 102 ] ] ),
		101 => new WC_Product( [ 'id' => 101, 'type' => 'variation', 'qty' => 3 ] ),
		20  => new WC_Product( [ 'id' => 20 ] ),
	];

	eq( [ 101, 102 ], WAPM_Stock::expand_ids( [ 10 ] ), 'والد → دو واریشن' );
	eq( [ 20 ], WAPM_Stock::expand_ids( [ 20 ] ), 'محصول ساده دست‌نخورده' );
	eq( [ 10 ], WAPM_Stock::expand_ids( [ 10 ], false ), 'با خاموش‌کردن باز شدن، والد می‌ماند' );
	eq( [ 101, 102, 20 ], WAPM_Stock::expand_ids( [ 10, 101, 0, 20 ] ), 'تکراری/صفر حذف می‌شود' );
} );

/* -------------------------------------------------------------------------
 * ۱۱) ترب — داده‌ی ناقص نباید داده‌ی سالم را پاک کند
 * ---------------------------------------------------------------------- */

test( 'ترب/seller_payload: نبودِ کلید sellers یعنی «نمی‌دانم» → null', function () {
	eq( null, WAPM_Torob::seller_payload( [ 'prk' => 'abc123', 'name' => 'کالا' ] ), 'بدون کلید' );
	eq( null, WAPM_Torob::seller_payload( [ 'prk' => 'abc123', 'sellers' => [] ] ), 'آرایه‌ی خالی' );
} );

test( 'ترب/seller_payload: فروشنده‌های بی‌قیمت/ناموجود داده حساب نمی‌شوند', function () {
	$item = [ 'sellers' => [ [ 'shop_id' => 1, 'price' => 0 ], [ 'shop_id' => 2, 'price' => 500, 'available' => false ] ] ];
	eq( null, WAPM_Torob::seller_payload( $item ), 'همه ناموجود' );
} );

test( 'ترب/seller_payload: داده‌ی معتبر با شکل یکدست برمی‌گردد', function () {
	$item = [ 'sellers' => [ [ 'shop_id' => 1, 'price' => 700 ], [ 'shop_id' => 2, 'price' => 400 ] ] ];
	$res  = WAPM_Torob::seller_payload( $item );
	ok( is_array( $res ), 'آرایه' );
	eq( 2, $res['count'], 'دو فروشنده' );
	eq( 400.0, $res['list'][0]['price'], 'ارزان‌ترین اول' );
} );

/* -------------------------------------------------------------------------
 * ۱۲) ترب — منطقه‌ی زمانی سن داده
 * ---------------------------------------------------------------------- */

test( 'ترب/time: زمان محلی ذخیره‌شده درست به timestamp تبدیل می‌شود', function () {
	$GLOBALS['wapm_options']['gmt_offset'] = 3.5;
	$real  = 1700000000;
	$local = gmdate( 'Y-m-d H:i:s', $real + 12600 ); // ساعت محلی ایران
	eq( $real, WAPM_Torob::local_to_timestamp( $local ), 'اختلاف ۳:۳۰ برگشت داده شد' );

	$GLOBALS['wapm_options']['gmt_offset'] = 0;
	eq( 0, WAPM_Torob::local_to_timestamp( '' ), 'رشته‌ی خالی' );
	eq( 0, WAPM_Torob::local_to_timestamp( 'نامعتبر' ), 'رشته‌ی نامعتبر' );
} );

test( 'ترب/time: داده‌ی ۲۵ ساعت پیش با اختلاف منطقه‌ی زمانی هم کهنه است', function () {
	$GLOBALS['wapm_options']['gmt_offset'] = 3.5;
	$local = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS - HOUR_IN_SECONDS + 12600 );

	// با fresh_days=1، این داده ۲۵ ساعت سن دارد → کهنه. (بدون تصحیح منطقه‌ی
	// زمانی ۲۰.۵ ساعت حساب می‌شد و «تازه» می‌ماند.)
	$s = [ 'fresh_days' => 1, 'tolerance_pct' => 1.0, 'shop_name' => '' ];
	eq( 'stale', WAPM_Torob::compute_status( torob_fresh( [ 'checked_at' => $local ] ), 1200.0, $s ), 'مرز کهنگی درست حساب شد' );

	$GLOBALS['wapm_options']['gmt_offset'] = 0;
} );

/* -------------------------------------------------------------------------
 * ۱۳) ترب — محافظ واحد قیمت (ریال/تومان)
 * ---------------------------------------------------------------------- */

test( 'ترب/unit_suspect: نسبت ~۱۰ علامت واحد اشتباه است', function () {
	ok( WAPM_Torob::unit_suspect( 12_000_000, 1_200_000 ), 'ما ۱۰ برابر' );
	ok( WAPM_Torob::unit_suspect( 1_200_000, 12_000_000 ), 'ترب ۱۰ برابر' );
	ok( WAPM_Torob::unit_suspect( 8_500_000, 1_000_000 ), '۸.۵ برابر' );
	ok( ! WAPM_Torob::unit_suspect( 1_200_000, 1_000_000 ), '۱.۲ برابر سالم است' );
	ok( ! WAPM_Torob::unit_suspect( 1_000_000, 8_000_000 ), '۸ برابر دقیقاً مرز است (هشدار نمی‌دهد)' );
} );

test( 'ترب/unit_suspect: قیمت صفر/منفی محافظ را فعال نمی‌کند', function () {
	ok( ! WAPM_Torob::unit_suspect( 0, 1_000_000 ), 'قیمت ما صفر' );
	ok( ! WAPM_Torob::unit_suspect( 1_000_000, 0 ), 'قیمت ترب صفر' );
} );

/* -------------------------------------------------------------------------
 * ۱۴) ترب — صف تأیید تطبیق
 * ---------------------------------------------------------------------- */

function unmatched_rows(): array {
	return [
		[
			'name' => 'گوشی سامسونگ A55 128', 'prk' => 'aaa111', 'candidate' => 12,
			'score' => 0.93, 'how' => 'ambiguous', 'sellers' => 4, 'price' => 9000000,
			'seen' => '2026-10-20 10:00:00',
		],
		[
			'name' => 'لپ تاپ ایسوس VivoBook', 'prk' => 'bbb222', 'candidate' => 0,
			'score' => 0.0, 'how' => 'no_match', 'sellers' => 0, 'price' => null,
			'seen' => '2026-10-22 10:00:00',
		],
		[
			'name' => 'کفش نایک زنانه', 'prk' => 'ccc333', 'candidate' => 30,
			'score' => 1.0, 'how' => 'variation', 'sellers' => 2, 'price' => 2500000,
			'seen' => '2026-10-24 10:00:00',
		],
		[
			'name' => 'هدفون سونی', 'prk' => 'ddd444', 'candidate' => 40,
			'score' => 1.0, 'how' => 'occupied', 'sellers' => 0, 'price' => null,
			'seen' => '2026-10-19 10:00:00',
		],
	];
}

test( 'ترب/صف: شمارش دسته‌ها درست است', function () {
	$r = WAPM_Torob::filter_unmatched( unmatched_rows() );
	eq( 4, $r['counts']['all'], 'همه' );
	eq( 3, $r['counts']['candidate'], 'با کاندید' );
	eq( 1, $r['counts']['no_candidate'], 'بدون کاندید' );
	eq( 1, $r['counts']['variation'], 'واریشن' );
	eq( 1, $r['counts']['occupied'], 'وصل‌شده به دیگری' );
} );

test( 'ترب/صف: فیلتر «با کاندید» و «بدون کاندید»', function () {
	$with = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'candidate' );
	eq( 3, $with['total'], 'سه ردیف با کاندید' );

	$without = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'no_candidate' );
	eq( 1, $without['total'], 'یک ردیف بدون کاندید' );
	eq( 'bbb222', $without['rows'][0]['prk'], 'همان ردیف لپ‌تاپ' );
} );

test( 'ترب/صف: فیلتر واریشن/occupied', function () {
	$v = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'variation' );
	eq( 1, $v['total'], 'یک واریشن' );
	eq( 'ccc333', $v['rows'][0]['prk'], 'prk درست' );

	$o = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'occupied' );
	eq( 1, $o['total'], 'یک مورد اشتغال' );
} );

test( 'ترب/صف: جستجو روی نام (نیم‌فاصله/حروف) و prk', function () {
	eq( 1, WAPM_Torob::filter_unmatched( unmatched_rows(), 'ایسوس' )['total'], 'نام' );
	eq( 1, WAPM_Torob::filter_unmatched( unmatched_rows(), 'aaa111' )['total'], 'قسمتی از prk' );
	eq( 1, WAPM_Torob::filter_unmatched( unmatched_rows(), 'لپ‌تاپ' )['total'], 'با نیم‌فاصله' );
	eq( 0, WAPM_Torob::filter_unmatched( unmatched_rows(), 'یخچال' )['total'], 'بی‌نتیجه' );
} );

test( 'ترب/صف: ترتیب تازه‌ترین/قدیمی‌ترین/شباهت', function () {
	$newest = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'all', 'newest' );
	eq( 'ccc333', $newest['rows'][0]['prk'], 'تازه‌ترین اول' );

	$oldest = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'all', 'oldest' );
	eq( 'ddd444', $oldest['rows'][0]['prk'], 'قدیمی‌ترین اول' );

	$score = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'all', 'score' );
	eq( 1.0, (float) $score['rows'][0]['score'], 'بیشترین شباهت اول' );
} );

test( 'ترب/صف: مقدار نامعتبر only/sort سقوط نمی‌کند', function () {
	$r = WAPM_Torob::filter_unmatched( unmatched_rows(), '', 'چیز-نامعتبر', 'نامعتبر' );
	eq( 4, $r['total'], 'همه‌ی ردیف‌ها' );
} );

test( 'ترب/صف: حذف گروهی با یک نوشتن و prk تکراری/نامعتبر', function () {
	$GLOBALS['wapm_options'][ WAPM_Torob::OPTION_UNMATCHED ] = unmatched_rows();
	eq( 2, WAPM_Torob::remove_unmatched_by_prks( [ 'aaa111', 'bbb222', 'aaa111' ] ), 'دو ردیف یکتا' );
	eq( 2, count( WAPM_Torob::unmatched() ), 'دو ردیف ماند' );
	eq( 'ccc333', WAPM_Torob::unmatched()[0]['prk'], 'ردیف درست حذف شد' );
	eq( 0, WAPM_Torob::remove_unmatched_by_prks( [ 'نامعتبر!', '' ] ), 'ورودی نامعتبر' );
} );

test( 'ترب/صف: برچسب دلیل تطبیق‌نشدن', function () {
	eq( 'واریشن هم‌نام شد — ترب در سطح والد مقایسه می‌کند', WAPM_Torob::match_reason_label( [ 'how' => 'variation' ] ), 'واریشن' );
	ok( false !== mb_strpos( WAPM_Torob::match_reason_label( [ 'candidate' => 5, 'score' => 0.934 ] ), '93' ), 'درصد شباهت در متن' );
	eq( 'هیچ محصول مشابهی پیدا نشد', WAPM_Torob::match_reason_label( [ 'how' => 'no_match' ] ), 'بدون تطبیق' );
} );

/* -------------------------------------------------------------------------
 * ۱۵) کشوی موجودی — قواعدی که UI به کاربر قول می‌دهد
 * ---------------------------------------------------------------------- */

test( 'موجودی/sanitize_operation: «تنظیم» همیشه با تعداد است', function () {
	$op = WAPM_Stock::sanitize_operation( [ 'mode' => 'set', 'unit' => 'percent', 'amount' => 5 ] );
	eq( 'set', $op['mode'], 'حالت' );
	eq( 'count', $op['unit'], 'واحد درصد به تعداد تبدیل شد' );
} );

test( 'موجودی/sanitize_operation: ورودی نامعتبر رد می‌شود', function () {
	eq( null, WAPM_Stock::sanitize_operation( [ 'mode' => 'multiply', 'unit' => 'count', 'amount' => 2 ] ), 'حالت ناشناخته' );
	eq( null, WAPM_Stock::sanitize_operation( [ 'mode' => 'increase', 'unit' => 'count', 'amount' => -3 ] ), 'مقدار منفی' );
} );

test( 'موجودی/expand_ids: انتخاب همه‌ی نتایج هم از همین قاعده رد می‌شود', function () {
	stub_reset();
	$GLOBALS['wapm_stub']['products'] = [
		10  => new WC_Product( [ 'id' => 10, 'type' => 'variable', 'managing' => false, 'children' => [ 101, 102 ] ] ),
		101 => new WC_Product( [ 'id' => 101, 'type' => 'variation', 'qty' => 0 ] ),
		102 => new WC_Product( [ 'id' => 102, 'type' => 'variation', 'qty' => 7 ] ),
		20  => new WC_Product( [ 'id' => 20 ] ),
	];

	eq( [ 101, 102, 20 ], WAPM_Stock::expand_ids( [ 10, 20 ] ), 'والد متغیر + محصول ساده' );
	eq( 0.0, WAPM_Stock::compute_qty( 0, 'set', 'count', 0 ), 'تنظیم روی صفر مجاز است' );
} );

/* -------------------------------------------------------------------------
 * ۱۶) فیلترهای ذخیره‌شده‌ی لیست
 * ---------------------------------------------------------------------- */

test( 'تفکیک صفحه: هر تب یک view مستقل با شناسه‌ی درست دارد', function () {
	$base  = __DIR__ . '/../woocommerce-advanced-price-manager/';
	$views = [
		'tab-change'   => 'wapm-tab-change',
		'tab-list'     => 'wapm-tab-list',
		'tab-stock'    => 'wapm-tab-stock',
		'tab-rules'    => 'wapm-tab-rules',
		'tab-schedule' => 'wapm-tab-schedule',
		'tab-history'  => 'wapm-tab-history',
		'tab-csv'      => 'wapm-tab-csv',
		'tab-torob'    => 'wapm-tab-torob',
	];

	$page = (string) file_get_contents( $base . 'includes/class-admin.php' );

	foreach ( $views as $file => $id ) {
		$path = $base . 'includes/admin/views/' . $file . '.php';
		ok( file_exists( $path ), 'فایل view هست: ' . $file );

		$body = (string) file_get_contents( $path );
		ok( false !== strpos( $body, "id=\"$id\"" ), 'شناسه‌ی تب درست است: ' . $file );
		ok( false !== strpos( $body, "if ( ! defined( 'ABSPATH' ) )" ), 'گارد ABSPATH در view: ' . $file );

		// هر view فقط یک بار از render_page صدا زده می‌شود.
		eq( 1, substr_count( $page, "includes/admin/views/$file.php" ), 'یک include برای ' . $file );
	}

	// مارک‌آپ تب‌ها نباید داخل فایل ادمین بماند (وگرنه تفکیک نیمه‌کاره است).
	ok( false === strpos( $page, 'id="wapm-tab-list"' ), 'مارک‌آپ لیست از class-admin.php خارج شد' );
	ok( strlen( $page ) < 40000, 'class-admin.php کوچک شد', (string) strlen( $page ) );
} );

test( 'بوکمارکلت: لینک امن شامل توکن و نقطه‌ی پایانی است', function () {
	stub_reset();
	update_option( 'wapm_torob_bm_token', 'token123', false );
	update_option( 'wapm_torob_shop_id', 34575, false );

	$href = WAPM_Bookmarklet::href();

	ok( 0 === strpos( $href, 'javascript:' ), 'با javascript: شروع می‌شود', substr( $href, 0, 20 ) );

	// لینک باید URL-encode شده باشد تا داخل href نشکند؛ بعد از decode باید
	// توکن و نقطه‌ی پایانی داخل کد باشد.
	$code = rawurldecode( substr( $href, strlen( 'javascript:' ) ) );
	ok( false !== strpos( $code, 'token123' ), 'توکن', '' );
	ok( false !== strpos( $code, 'admin-ajax.php' ), 'نقطه‌ی پایانی ajax', '' );
	ok( false !== strpos( $code, '34575' ), 'شناسه فروشگاه', '' );
	ok( false !== strpos( $code, 'wapm_bookmarklet_sync' ) || false !== strpos( $code, 'token' ), 'اکشن ارسال', '' );

	// هیچ جای‌نگهدار جایگزین‌نشده نماند.
	ok( false === strpos( $code, '%%' ), 'جای‌نگهدار باقی نمانده', '' );
} );

test( 'قوانین: اجرای خودکار فقط برای قانون فعالِ خودکار و سررسیدشده', function () {
	stub_reset();
	$now = 1_700_000_000;

	update_option( 'wapm_rules', [
		[ 'id' => 'r1', 'title' => 'دستی', 'priority' => 10, 'enabled' => true, 'auto' => false, 'last_run' => 0 ],
		[ 'id' => 'r2', 'title' => 'خودکار', 'priority' => 5, 'enabled' => true, 'auto' => true, 'last_run' => 0 ],
		[ 'id' => 'r3', 'title' => 'غیرفعال', 'priority' => 1, 'enabled' => false, 'auto' => true, 'last_run' => 0 ],
		[ 'id' => 'r4', 'title' => 'تازه اجرا شده', 'priority' => 2, 'enabled' => true, 'auto' => true, 'last_run' => $now - 3600 ],
		[ 'id' => 'r5', 'title' => 'منقضی', 'priority' => 3, 'enabled' => true, 'auto' => true, 'last_run' => 0, 'start' => '2020-01-01T00:00', 'end' => '2020-02-01T00:00' ],
	], false );

	$due = WAPM_Rules::due_auto_rules( $now );
	$ids = array_map( static fn( $r ) => $r['id'], $due );

	eq( [ 'r2' ], $ids, 'فقط قانون خودکار و سررسیدشده' );
} );

test( 'قوانین: فاصله‌ی اجرای خودکار و ثبت زمان اجرا', function () {
	stub_reset();
	$now = 1_700_000_000;

	eq( DAY_IN_SECONDS, WAPM_Rules::auto_interval(), 'فاصله‌ی پیش‌فرض روزانه' );

	update_option( 'wapm_rules', [
		[ 'id' => 'a', 'title' => 'خودکار', 'priority' => 1, 'enabled' => true, 'auto' => true, 'last_run' => 0 ],
	], false );

	eq( 1, count( WAPM_Rules::due_auto_rules( $now ) ), 'بار اول سررسید است' );

	WAPM_Rules::mark_auto_run( 'a', $now );
	eq( 0, count( WAPM_Rules::due_auto_rules( $now + HOUR_IN_SECONDS ) ), 'یک ساعت بعد هنوز نه' );
	eq( 1, count( WAPM_Rules::due_auto_rules( $now + DAY_IN_SECONDS ) ), 'یک روز بعد سررسید است' );
} );

test( 'قوانین: ویرایش قانون زمان آخرین اجرای خودکار را پاک نمی‌کند', function () {
	stub_reset();
	$now = 1_700_000_000;

	update_option( 'wapm_rules', [
		[ 'id' => 'keep', 'title' => 'قانون', 'priority' => 1, 'enabled' => true, 'auto' => true, 'last_run' => $now ],
	], false );

	$saved = WAPM_Rules::save_rule( [ 'id' => 'keep', 'title' => 'قانون ویرایش‌شده', 'priority' => 1, 'enabled' => true, 'auto' => true ] );

	eq( $now, $saved['last_run'], 'last_run حفظ شد' );
	eq( 0, count( WAPM_Rules::due_auto_rules( $now ) ), 'بلافاصله دوباره اجرا نمی‌شود' );

	// قانون تازه (بدون id) با auto خاموش ذخیره می‌شود.
	$fresh = WAPM_Rules::save_rule( [ 'title' => 'تازه', 'priority' => 2, 'enabled' => true ] );
	eq( false, $fresh['auto'], 'پیش‌فرض: دستی' );
	eq( 0, $fresh['last_run'], 'بدون اجرا' );
} );

test( 'صف: بدون Action Scheduler به wp-cron برمی‌گردد و idempotent است', function () {
	stub_reset();
	$GLOBALS['wapm_cron'] = [];

	eq( false, WAPM_Queue::uses_action_scheduler(), 'در این محیط AS نیست' );
	eq( 0, WAPM_Queue::next_run(), 'شروع بدون زمان‌بندی' );

	eq( true, WAPM_Queue::schedule_hourly(), 'بار اول ساخته می‌شود' );
	$first = WAPM_Queue::next_run();
	ok( $first > time(), 'زمان آینده', (string) $first );

	// فراخوانی دوباره (مثلاً هر بار لود افزونه) نباید زمان‌بندی دوم بسازد.
	eq( false, WAPM_Queue::schedule_hourly(), 'بار دوم کاری نمی‌کند' );
	eq( $first, WAPM_Queue::next_run(), 'همان زمان قبلی' );

	$state = WAPM_Queue::state();
	eq( 'wp-cron', $state['engine'], 'موتور' );
	eq( 'WP-Cron', $state['label'], 'برچسب' );
	ok( false !== strpos( $state['next_label'], 'اجرای بعدی' ), 'برچسب زمان', $state['next_label'] );

	WAPM_Queue::unschedule_all();
	eq( 0, WAPM_Queue::next_run(), 'پاک شد' );
	eq( 'زمان‌بندی فعالی وجود ندارد.', WAPM_Queue::state()['next_label'], 'پیام بدون زمان‌بندی' );
} );

test( 'صف: مهاجرت از wp-cron به AS، بقایای مسیر قدیمی را پاک می‌کند', function () {
	stub_reset();
	$GLOBALS['wapm_cron'] = [];

	// وضعیت نسخه‌ی قبلی: زمان‌بندی wp-cron هست و گزینه‌ی موتور ثبت نشده.
	wp_schedule_event( time() + 300, 'hourly', 'wapm_run_schedules' );
	ok( WAPM_Queue::next_run() > 0, 'زمان‌بندی قدیمی هست' );

	// در این محیط AS وجود ندارد؛ پس مهاجرت نباید زمان‌بندی موجود را حذف کند
	// (وگرنه کار زمان‌بندی‌شده گم می‌شود).
	WAPM_Queue::maybe_migrate();

	ok( WAPM_Queue::next_run() > 0, 'زمان‌بندی باقی می‌ماند' );
	eq( 'wp-cron', (string) get_option( 'wapm_queue_engine' ), 'موتور ثبت شد' );

	// بار دوم: گزینه ثبت شده و زمان‌بندی هست → هیچ تغییری لازم نیست.
	$before = WAPM_Queue::next_run();
	WAPM_Queue::maybe_migrate();
	eq( $before, WAPM_Queue::next_run(), 'بی‌اثر' );
} );

test( 'تاریخچه: زمان ذخیره‌شده دو بار offset نمی‌خورد (P2-1)', function () {
	stub_reset();

	// ایران: +۳:۳۰. زمان ذخیره‌شده «۱۰:۰۰ محلی» است و باید همان ۱۰:۰۰ نمایش داده شود.
	$GLOBALS['wapm_gmt_offset'] = 3.5;

	eq( '2024/05/01 10:00', WAPM_Logger::format_time( '2024-05-01 10:00:00' ), 'بدون جابه‌جایی' );

	// مسیر قدیمی (strtotime + date_i18n) ۱۳:۳۰ می‌داد — اثبات تفاوت:
	eq( '2024/05/01 13:30', date_i18n( 'Y/m/d H:i', strtotime( '2024-05-01 10:00:00' ) ), 'مسیر قدیمی جابه‌جا بود' );

	eq( '—', WAPM_Logger::format_time( '' ), 'خالی' );
	eq( '—', WAPM_Logger::format_time( '   ' ), 'فقط فاصله' );

	$GLOBALS['wapm_gmt_offset'] = 0;
} );

test( 'لیست: پاسخ «کپی محصول» وضعیت پیش‌نویس را توضیح می‌دهد (P1-8)', function () {
	stub_reset();

	$draft = WAPM_List::duplicate_result( 321, 'draft' );

	eq( true, $draft['success'], 'موفق' );
	eq( 'draft', $draft['status'], 'وضعیت' );
	eq( 321, $draft['id'], 'شناسه‌ی کپی' );
	ok( false !== strpos( $draft['message'], 'پیش‌نویس' ), 'پیام می‌گوید چرا در لیست دیده نمی‌شود', $draft['message'] );
	ok( false !== strpos( $draft['edit_url'], 'post=321' ), 'لینک ویرایش', $draft['edit_url'] );

	eq( 'draft', WAPM_List::duplicate_result( 322, '' )['status'], 'وضعیت خالی = پیش‌نویس (محافظ)' );
	eq( 'publish', WAPM_List::duplicate_result( 323, 'publish' )['status'], 'کپی منتشرشده' );
} );

test( 'فیلتر/برچسب خودکار: از شرط‌ها ساخته می‌شود', function () {
	$label = WAPM_List::saved_filter_label( WAPM_List::sanitize_filters( [
		'torob_status' => 'pricier',
		'price_min'    => 100000,
		'on_sale'      => 1,
	] ) );

	ok( false !== mb_strpos( $label, 'گران‌تر از رقیب' ), 'وضعیت ترب' );
	ok( false !== mb_strpos( $label, 'قیمت' ), 'بازه‌ی قیمت' );
	ok( false !== mb_strpos( $label, 'تخفیف' ), 'فقط تخفیف‌دار' );
} );

test( 'فیلتر/برچسب خودکار: فیلتر خالی «همه‌ی محصولات» است', function () {
	eq( 'همه‌ی محصولات', WAPM_List::saved_filter_label( WAPM_List::sanitize_filters( [] ) ), 'بدون شرط' );
} );

test( 'فیلتر/ذخیره: ردیف نامعتبر حذف و شناسه‌ی تکراری یکتا می‌شود', function () {
	$list = WAPM_List::sanitize_saved_filters( [
		'رشته-نامعتبر',
		[ 'id' => 'f1', 'name' => 'اول', 'filters' => [ 'search' => 'آیفون' ] ],
		[ 'id' => 'f1', 'name' => 'تکراری', 'filters' => [ 'search' => 'شیائومی' ] ],
		[ 'id' => 'f2', 'filters' => [ 'stock_status' => 'instock' ] ],
	] );

	eq( 3, count( $list ), 'سه ردیف معتبر' );
	ok( $list[0]['id'] !== $list[1]['id'], 'شناسه‌ی تکراری عوض شد' );
	eq( 'موجود', $list[2]['name'], 'نام خالی از فیلتر ساخته شد' );
} );

test( 'فیلتر/ذخیره: فیلتر یکسان دوباره اضافه نمی‌شود', function () {
	$f = WAPM_List::sanitize_filters( [ 'search' => 'آیفون', 'per_page' => 25, 'paged' => 2 ] );
	$list = WAPM_List::add_saved_filter( [], 'نام اول', $f );
	eq( 1, count( $list ), 'یک ردیف' );

	// همان شرط با نام و صفحه‌ی دیگر → همان ردیف، نام جدید.
	$again = WAPM_List::add_saved_filter( $list, 'نام دوم', WAPM_List::sanitize_filters( [ 'search' => 'آیفون', 'paged' => 7 ] ) );
	eq( 1, count( $again ), 'تکراری اضافه نشد' );
	eq( 'نام دوم', $again[0]['name'], 'نام به‌روز شد' );
	eq( $list[0]['id'], $again[0]['id'], 'شناسه ثابت ماند' );
} );

test( 'فیلتر/ذخیره: جدیدترین اول و سقف رعایت می‌شود', function () {
	$list = [];
	for ( $i = 1; $i <= 5; $i++ ) {
		$list = WAPM_List::add_saved_filter( $list, 'فیلتر ' . $i, [ 'search' => 'کالا' . $i ] );
	}

	eq( 'فیلتر 5', $list[0]['name'], 'جدیدترین اول' );

	$capped = [];
	for ( $i = 1; $i <= 4; $i++ ) {
		$capped = WAPM_List::add_saved_filter( $capped, 'x' . $i, [ 'search' => 's' . $i ], 3 );
	}
	eq( 3, count( $capped ), 'سقف ۳ ردیف' );
	eq( 'x4', $capped[0]['name'], 'آخرین اضافه‌شده مانده' );
} );

test( 'فیلتر/ذخیره: حذف با شناسه و بی‌اثر بودن شناسه‌ی نامعتبر', function () {
	$a = WAPM_List::add_saved_filter( [], 'الف', [ 'search' => 'a' ] );
	$b = WAPM_List::add_saved_filter( $a, 'ب', [ 'search' => 'b' ] );

	eq( 1, count( WAPM_List::remove_saved_filter( $b, $b[0]['id'] ) ), 'یکی حذف شد' );
	eq( 2, count( WAPM_List::remove_saved_filter( $b, 'نامعتبر!' ) ), 'شناسه‌ی نامعتبر چیزی حذف نمی‌کند' );
} );

test( 'فیلتر/ذخیره: ورودی نامطمئن (رشته) کرش نمی‌کند', function () {
	eq( [], WAPM_List::sanitize_saved_filters( 'چیز-نامعتبر' ), 'رشته' );
	eq( [], WAPM_List::sanitize_saved_filters( null ), 'null' );
} );

/* -------------------------------------------------------------------------
 * ۱۷) حذف افزونه — هیچ کلید داده‌ای جا نمی‌ماند
 * ---------------------------------------------------------------------- */

/**
 * فهرست کلیدهای داده‌ای را از سورس استخراج می‌کند.
 *
 * چرا تست: `uninstall.php` نمی‌تواند از کلاس‌ها استفاده کند (وردپرس آن را قبل از
 * بارگذاری افزونه اجرا می‌کند)، پس کلیدها آن‌جا رشته‌ی خام‌اند و با هر تغییر نام
 * ثابت، بی‌صدا از پاک‌سازی عقب می‌مانند. این تست همان عقب‌ماندگی را می‌گیرد.
 *
 * @return array{options:string[],metas:string[]}
 */
function wapm_scan_data_keys(): array {
	$root    = __DIR__ . '/../woocommerce-advanced-price-manager';
	$files   = array_merge( glob( $root . '/includes/*.php' ) ?: [], [ $root . '/woocommerce-advanced-price-manager.php' ] );
	$options = [];
	$metas   = [];

	foreach ( $files as $file ) {
		$code = (string) file_get_contents( $file );

		if ( preg_match_all( "/const (?:OPTION|OPT|USER_META)_[A-Z0-9_]*\s*=\s*'([^']+)'/", $code, $m ) ) {
			$options = array_merge( $options, $m[1] );
		}
		if ( preg_match_all( "/const META_[A-Z0-9_]*\s*=\s*'([^']+)'/", $code, $m ) ) {
			$metas = array_merge( $metas, $m[1] );
		}
		if ( preg_match_all( "/(?:get|update|delete)_option\(\s*'(wapm_[a-z0-9_]+)'/", $code, $m ) ) {
			$options = array_merge( $options, $m[1] );
		}
	}

	return [
		'options' => array_values( array_unique( $options ) ),
		'metas'   => array_values( array_unique( $metas ) ),
	];
}

test( 'حذف افزونه: همه‌ی گزینه‌ها و متاهای شناخته‌شده پاک می‌شوند', function () {
	$root = __DIR__ . '/../woocommerce-advanced-price-manager';
	ok( file_exists( $root . '/uninstall.php' ), 'uninstall.php وجود دارد' );

	$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
	$keys      = wapm_scan_data_keys();

	ok( count( $keys['options'] ) > 8, 'فهرست گزینه‌ها پیدا شد' );
	ok( count( $keys['metas'] ) > 10, 'فهرست متاها پیدا شد' );

	$missing = [];
	foreach ( array_merge( $keys['options'], $keys['metas'] ) as $key ) {
		if ( false === strpos( $uninstall, "'" . $key . "'" ) ) {
			$missing[] = $key;
		}
	}

	eq( [], $missing, 'کلید جامانده در uninstall.php' );
} );

test( 'حذف افزونه: جدول‌ها، کرون و نگهبان WP_UNINSTALL_PLUGIN', function () {
	$uninstall = (string) file_get_contents( __DIR__ . '/../woocommerce-advanced-price-manager/uninstall.php' );

	ok( false !== strpos( $uninstall, "defined( 'WP_UNINSTALL_PLUGIN' )" ), 'گارد مستقیم‌اجرا' );
	ok( false !== strpos( $uninstall, 'wapm_price_log' ), 'جدول تاریخچه‌ی قیمت' );
	ok( false !== strpos( $uninstall, 'wapm_stock_log' ), 'جدول تاریخچه‌ی موجودی' );
	ok( false !== strpos( $uninstall, "wp_clear_scheduled_hook( 'wapm_run_schedules' )" ), 'کرون' );
	ok( false !== strpos( $uninstall, 'delete_metadata( \'user\', 0' ), 'متای کاربران (فیلترهای ذخیره‌شده)' );
	ok( false !== strpos( $uninstall, 'wapm_keep_data_on_uninstall' ), 'راه حفظ داده' );
	ok( false !== strpos( $uninstall, 'is_multisite()' ), 'مالتی‌سایت' );
} );

/* -------------------------------------------------------------------------
 * ۱۸) سازگاری رشته/کاراکتر — بدون mbstring هم صفحه سفید نمی‌شود
 * ---------------------------------------------------------------------- */

test( 'رشته: طول و برش کاراکتری برای فارسی (بدون شکستن کاراکتر)', function () {
	eq( 9, WAPM_Str::len( 'سلام دنیا' ), 'طول «سلام دنیا» ۹ کاراکتر' );
	eq( 4, WAPM_Str::len( '۱۲۳۴' ), 'ارقام فارسی' );
	eq( 2, WAPM_Str::len( '👕👖' ), 'ایموجی (۴ بایت)' );

	eq( 'سلام', WAPM_Str::sub( 'سلام دنیا', 0, 4 ), 'برش ۴ کاراکتر اول' );
	eq( 'دنیا', WAPM_Str::sub( 'سلام دنیا', 5 ), 'برش از وسط تا انتها' );
	eq( 4, WAPM_Str::len( WAPM_Str::sub( 'سلام دنیا', 0, 4 ) ), 'طول نتیجه‌ی برش' );
	eq( '', WAPM_Str::sub( 'سلام', 10 ), 'شروع بیرون از رشته' );
} );

test( 'رشته: contains و lower', function () {
	ok( WAPM_Str::contains( 'از ۸٫۶۸۰٫۰۰۰ ریال', 'ریال' ), 'جستجوی فارسی' );
	ok( ! WAPM_Str::contains( 'از ۸٫۶۸۰٫۰۰۰ تومان', 'ریال' ), 'نبود' );
	ok( WAPM_Str::contains( 'هرچیز', '' ), 'سوزن خالی = درست' );
	eq( 'iphone 15 pro', WAPM_Str::lower( 'IPHONE 15 Pro' ), 'لاتین' );
	eq( 'آیفون', WAPM_Str::lower( 'آیفون' ), 'فارسی بدون تغییر' );
} );

test( 'رشته: هیچ فایل افزونه‌ای مستقیم mb_* صدا نمی‌زند (به‌جز WAPM_Str)', function () {
	$root = __DIR__ . '/../woocommerce-advanced-price-manager';
	$hits = [];

	foreach ( glob( $root . '/includes/*.php' ) ?: [] as $file ) {
		$name = basename( $file );
		if ( 'class-str.php' === $name ) {
			continue;
		}
		$code = (string) file_get_contents( $file );
		// فراخوانی واقعی، نه اسم تابع در string: «mb_strlen(»
		if ( preg_match( '/\bmb_[a-z_]+\s*\(/', $code ) ) {
			$hits[] = $name;
		}
	}

	eq( [], $hits, 'فایل‌های با mb_* بدون گارد' );
} );

/* -------------------------------------------------------------------------
 * ۱۹) «هم‌تراز با ترب» — تنظیمات، برچسب دلیل و ثبت اکشن
 * ---------------------------------------------------------------------- */

test( 'CSV مقایسه‌ی ترب: اکشن، ستون‌ها و فیلترهای تب لیست', function () {
	$base = __DIR__ . '/../woocommerce-advanced-price-manager';

	$ajax = (string) file_get_contents( $base . '/includes/class-ajax.php' );
	ok( false !== strpos( $ajax, "add_action( 'admin_post_wapm_export_torob_csv'" ), 'اکشن admin-post ثبت شده' );
	ok( false !== strpos( $ajax, 'WAPM_List::sanitize_filters' ), 'فیلترهای لیست (نه تب قیمت) sanitize می‌شوند' );

	$csv = (string) file_get_contents( $base . '/includes/class-csv-handler.php' );
	ok( false !== strpos( $csv, 'function stream_torob_comparison' ), 'تابع خروجی وجود دارد' );
	ok( false !== strpos( $csv, 'WAPM_List::query_ids' ), 'از کوئری همان لیست استفاده می‌کند' );
	foreach ( [ 'Cheapest Competitor', 'Diff %', 'Status', 'Sellers', 'Data Age (days)' ] as $column ) {
		ok( false !== strpos( $csv, $column ), 'ستون ' . $column );
	}

	$admin = (string) file_get_contents( $base . '/includes/class-admin.php' );
	ok( false !== strpos( $admin, 'torob_export_url' ), 'آدرس خروجی به JS داده می‌شود' );

	$view = (string) file_get_contents( $base . '/includes/admin/views/tab-csv.php' );
	ok( false !== strpos( $view, 'wapm-torob-export-btn' ), 'دکمه در تب CSV هست' );
} );

test( 'استثنای دسته/برند: اشتراک شناسه‌ها', function () {
	eq( true, WAPM_Torob::matches_exclusion( [ 5, 7 ], [ 7 ] ), 'دسته‌ی مستثنا' );
	eq( false, WAPM_Torob::matches_exclusion( [ 5 ], [ 7 ] ), 'بی‌ربط' );
	eq( false, WAPM_Torob::matches_exclusion( [ 5 ], [] ), 'بدون استثنا' );
	eq( false, WAPM_Torob::matches_exclusion( [], [ 5 ] ), 'محصول بدون دسته' );
	eq( true, WAPM_Torob::matches_exclusion( [ '5' ], [ 5 ] ), 'شناسه‌ی رشته‌ای (خروجی wp_get_post_terms)' );
} );

test( 'استثنای دسته/برند: در تنظیمات ذخیره و نرمال می‌شود', function () {
	$GLOBALS['wapm_options'] = [];

	WAPM_Torob::save_settings( [
		'shop_name'          => 'فروشگاه تست',
		'fresh_days'         => 5,
		'tolerance_pct'      => 2,
		'exclude_categories' => [ '12', 12, 0, 'x', 30 ],
		'exclude_brands'     => [ 4 ],
	] );

	$saved = WAPM_Torob::settings();

	eq( [ 12, 30 ], $saved['exclude_categories'], 'یکتا + عددی + بدون صفر' );
	eq( [ 4 ], $saved['exclude_brands'], 'برندها' );
	eq( 5, $saved['fresh_days'], 'بقیه‌ی تنظیمات حفظ شد' );

	// ذخیره‌ی جزئی (فرم قدیمی) استثناها را پاک نمی‌کند.
	WAPM_Torob::save_settings( [ 'fresh_days' => 6 ] );
	$after = WAPM_Torob::settings();

	eq( 6, $after['fresh_days'], 'مقدار جدید' );
	eq( [ 12, 30 ], $after['exclude_categories'], 'استثناها دست‌نخورده' );
} );

test( 'استثنای دسته/برند: برچسب دلیل در حالت ترب', function () {
	ok( WAPM_Torob::skip_label( 'excluded_category' ) !== 'excluded_category', 'برچسب دسته' );
	ok( WAPM_Torob::skip_label( 'excluded_brand' ) !== 'excluded_brand', 'برچسب برند' );
} );

test( 'آزمون ساختار ترب: سه حالت ok / structure / empty', function () {
	$ok = WAPM_Torob_Api::judge_probe( [ 'items' => [ [ 'prk' => 'a' ] ], 'count' => 5, 'raw_count' => 5 ] );
	eq( true, $ok['ok'], 'کارت سالم = آماده' );
	eq( 'ok', $ok['reason'], 'دلیل ok' );
	eq( 1, $ok['samples'], 'تعداد نمونه' );

	$structure = WAPM_Torob_Api::judge_probe( [ 'items' => [], 'count' => 5, 'raw_count' => 5 ] );
	eq( false, $structure['ok'], 'ردیف هست ولی خوانده نمی‌شود' );
	eq( 'structure', $structure['reason'], 'ساختار عوض شده' );

	$empty = WAPM_Torob_Api::judge_probe( [ 'items' => [], 'count' => 0, 'raw_count' => 0 ] );
	eq( false, $empty['ok'], 'خالی' );
	eq( 'empty', $empty['reason'], 'فروشگاه خالی' );

	// count مثبت ولی هیچ ردیفی نیامده هم نشانه‌ی تغییر ساختار است.
	eq( 'structure', WAPM_Torob_Api::judge_probe( [ 'items' => [], 'count' => 12, 'raw_count' => 0 ] )['reason'], 'count بدون ردیف' );
} );

test( 'آزمون ساختار ترب: parse_shop_products تعداد ردیف خام را برمی‌گرداند', function () {
	$data = [
		'count'   => 2,
		'results' => [
			[ 'prk' => 'p1', 'name1' => 'محصول یک', 'price' => 100000 ],
			[ 'name1' => 'بی‌شناسه' ], // کارت بدون prk دور ریخته می‌شود
		],
	];

	$parsed = WAPM_Torob_Api::parse_shop_products( $data, 0 );

	eq( 1, count( $parsed['items'] ), 'فقط کارت سالم' );
	eq( 2, $parsed['raw_count'], 'ردیف خام برای probe' );
	eq( false, $parsed['has_more'], 'بدون next = آخرین صفحه' );
} );

test( '«چند رقیب زیر قیمت ما» از فروشنده‌های ذخیره‌شده', function () {
	$sellers = [
		[ 'price' => 900000, 'unreliable' => false ],
		[ 'price' => 1000000, 'unreliable' => false ], // برابر با قیمت ما → «زیر» نیست
		[ 'price' => 800000, 'unreliable' => true ],    // قیمت مشکوک → شمرده نمی‌شود
		[ 'price' => 1100000, 'unreliable' => false ],
	];

	$result = WAPM_Torob::sellers_below( $sellers, 1000000 );

	eq( 1, $result['below'], 'یک رقیب واقعاً ارزان‌تر' );
	eq( 3, $result['stored'], 'قیمت مشکوک در شمارش نیست' );
	eq( 900000.0, $result['cheapest'], 'ارزان‌ترین زیر قیمت ما' );

	$none = WAPM_Torob::sellers_below( $sellers, 500000 );
	eq( 0, $none['below'], 'اگر از همه ارزان‌تریم، صفر' );
	eq( null, $none['cheapest'], 'بدون ارزان‌تر' );

	$unknown = WAPM_Torob::sellers_below( $sellers, 0 );
	eq( 0, $unknown['below'], 'قیمت نامعلوم = بدون شمارش' );
	eq( 3, $unknown['stored'], 'ولی فروشنده‌ها شناخته می‌شوند' );

	eq( [ 'below' => 0, 'stored' => 0, 'cheapest' => null ], WAPM_Torob::sellers_below( [], 1000 ), 'لیست خالی' );
} );

test( 'لیست تطبیق‌نیافته: ردیف‌های کهنه منقضی می‌شوند', function () {
	$now  = strtotime( '2024-06-01 12:00:00' );
	$list = [
		[ 'prk' => 'new', 'seen' => '2024-05-31 12:00:00' ],  // ۱ روز پیش → می‌ماند
		[ 'prk' => 'old', 'seen' => '2024-04-01 12:00:00' ],  // ۶۱ روز پیش → حذف
		[ 'prk' => 'nodate', 'seen' => '' ],                    // بی‌تاریخ → نمی‌شود گفت کهنه است
	];

	$result = WAPM_Torob::prune_unmatched_list( $list, $now, 30 );

	eq( 1, $result['removed'], 'یک ردیف حذف شد' );
	eq( [ 'new', 'nodate' ], array_column( $result['list'], 'prk' ), 'ردیف‌های زنده ماندند' );

	$off = WAPM_Torob::prune_unmatched_list( $list, $now, 0 );
	eq( 0, $off['removed'], 'انقضای خاموش = بدون حذف' );
	eq( $list, $off['list'], 'لیست دست‌نخورده' );

	eq( [ 'list' => [], 'removed' => 0 ], WAPM_Torob::prune_unmatched_list( [], $now, 30 ), 'لیست خالی' );
} );

test( 'پرچم‌ها: ناسازگاری فید و واحد مشکوک (همان چیزی که ستون نشان می‌دهد)', function () {
	$same = WAPM_Torob::price_flags( 1000000, 1000000, 1 );
	eq( false, $same['mismatch'], 'قیمت یکسان = بدون ناسازگاری' );
	eq( false, $same['unit'], 'قیمت یکسان = واحد سالم' );

	$off = WAPM_Torob::price_flags( 1100000, 1000000, 1 );
	eq( true, $off['mismatch'], '۱۰٪ اختلاف با فید = ناسازگار' );

	$near = WAPM_Torob::price_flags( 1005000, 1000000, 1 );
	eq( false, $near['mismatch'], '۰٫۵٪ زیر آستانه = ناسازگار نیست' );

	$unit = WAPM_Torob::price_flags( 1000000, 100000, 1 );
	eq( true, $unit['unit'], 'نسبت ۱۰ = واحد مشکوک' );

	$unknown = WAPM_Torob::price_flags( 500000, null, 1 );
	eq( false, $unknown['mismatch'], 'بدون قیمت ترب = بدون پرچم' );
	eq( false, $unknown['unit'], 'بدون قیمت ترب = بدون پرچم واحد' );
} );

test( 'فیلتر پرچم‌ها در SQL ساخته می‌شود', function () {
	$mismatch = WAPM_Torob::meta_clause( 'feed_mismatch' );
	eq( WAPM_Torob::META_FEED_MISMATCH, $mismatch['key'], 'کلید متای ناسازگاری' );
	eq( '1', $mismatch['value'], 'فقط پرچم‌دارها' );

	$unit = WAPM_Torob::meta_clause( 'unit_suspect' );
	eq( WAPM_Torob::META_UNIT_SUSPECT, $unit['key'], 'کلید متای واحد' );

	eq( null, WAPM_Torob::meta_clause( 'چیز-ناشناخته' ), 'وضعیت ناشناخته = بدون شرط' );
	ok( WAPM_Torob::status_label( 'feed_mismatch' ) !== 'feed_mismatch', 'برچسب فارسی دارد' );
	ok( WAPM_Torob::status_label( 'unit_suspect' ) !== 'unit_suspect', 'برچسب واحد مشکوک' );
} );

test( 'تاریخچه‌ی قیمت رقیب: فقط تغییرها ثبت می‌شوند (بدون تکرار)', function () {
	$GLOBALS['wapm_meta'] = [];
	$id = 501;

	WAPM_Torob::push_price_point( $id, 100000, '2024-05-01 10:00:00' );
	WAPM_Torob::push_price_point( $id, 100000, '2024-05-01 11:00:00' ); // همان قیمت
	eq( 1, count( WAPM_Torob::get_price_history( $id ) ), 'بررسی تکراری نقطه نمی‌سازد' );

	WAPM_Torob::push_price_point( $id, 95000, '2024-05-02 10:00:00' );
	$history = WAPM_Torob::get_price_history( $id );

	eq( 2, count( $history ), 'تغییر قیمت ثبت شد' );
	eq( 95000.0, $history[1]['p'], 'قیمت جدید' );
	eq( '2024-05-02 10:00:00', $history[1]['t'], 'زمان محلی همان‌طور که هست' );
} );

test( 'تاریخچه‌ی قیمت رقیب: بدون قیمت چیزی ثبت نمی‌شود', function () {
	$GLOBALS['wapm_meta'] = [];

	WAPM_Torob::push_price_point( 502, null, '2024-05-01 10:00:00' );
	WAPM_Torob::push_price_point( 502, 0.0, '2024-05-01 10:00:00' );
	WAPM_Torob::push_price_point( 502, 5000, '' );

	eq( [], WAPM_Torob::get_price_history( 502 ), 'هیچ نقطه‌ای ساخته نشد' );
} );

test( 'تاریخچه‌ی قیمت رقیب: سقف ۳۰ نقطه، قدیمی‌ها حذف می‌شوند', function () {
	$GLOBALS['wapm_meta'] = [];
	$id = 503;

	// ۳۵ تغییر قیمت (هر ساعت ۱۰۰۰ تومان ارزان‌تر).
	for ( $i = 0; $i < 35; $i++ ) {
		WAPM_Torob::push_price_point( $id, 100000 - ( $i * 1000 ), sprintf( '2024-05-01 %02d:00:00', $i % 24 ) );
	}

	$history = WAPM_Torob::get_price_history( $id );

	eq( WAPM_Torob::HISTORY_POINTS, count( $history ), 'فقط ۳۰ نقطه نگه داشته شد' );
	eq( 66000.0, $history[ count( $history ) - 1 ]['p'], 'آخرین قیمت درست است' );
} );

test( 'تاریخچه‌ی قیمت رقیب: نقطه‌های خراب نادیده گرفته می‌شوند', function () {
	$GLOBALS['wapm_meta'] = [];
	$id = 504;
	update_post_meta( $id, WAPM_Torob::META_PRICE_HISTORY, '{"نه":"آرایه"}' );
	eq( [], WAPM_Torob::get_price_history( $id ), 'JSON غیرآرایه' );

	update_post_meta( $id, WAPM_Torob::META_PRICE_HISTORY, '[{"t":"2024-05-01 10:00:00","p":1000},{"t":"2024-05-02 10:00:00"},{"bad":1},{"t":"2024-05-03 10:00:00","p":"2000"}]' );
	$history = WAPM_Torob::get_price_history( $id );

	eq( 2, count( $history ), 'فقط نقطه‌های سالم' );
	eq( 2000.0, $history[1]['p'], 'قیمت رشته‌ای به عدد تبدیل شد' );
} );

test( 'تاریخچه‌ی قیمت رقیب: خلاصه‌ی تغییر ۷ روزه', function () {
	// دو نقطه در بازه: مبنای مقدار اولیه.
	$now = '2024-05-10 10:00:00';
	$h   = [
		[ 't' => '2024-05-01 10:00:00', 'p' => 200000 ],
		[ 't' => '2024-05-06 10:00:00', 'p' => 100000 ],
		[ 't' => $now, 'p' => 90000 ],
	];

	// بازه‌ی ۳ روزه: مبنا = آخرین قیمتِ معلوم در شروع بازه (۶ مه، ۱۰۰٬۰۰۰)
	// → ۹۰٬۰۰۰ = −۱۰٪. (نقطه‌ی ۱ مه بیرون از بازه است.)
	$change = WAPM_Torob::history_change( $h, 3 );

	eq( -10.0, $change['pct'], 'درصد تغییر نسبت به مبنای بازه' );
	eq( 100000.0, $change['from'], 'قیمت مبنا' );
	eq( 4, $change['days'], 'فاصله‌ی روزها' );

	// بازه‌ی ۷ روزه: هیچ نقطه‌ای داخل بازه نیست، پس مبنا اولین نقطه‌ی معلوم است.
	$wide = WAPM_Torob::history_change( $h );
	eq( 200000.0, $wide['from'], 'مبنای بازه‌ی بلندتر = اولین نقطه' );
	eq( -55.0, $wide['pct'], '‒۵۵٪ از ۲۰۰٬۰۰۰ به ۹۰٬۰۰۰' );

	eq( null, WAPM_Torob::history_change( [ $h[0] ] ), 'یک نقطه = بدون خلاصه' );
	eq( null, WAPM_Torob::history_change( [ [ 't' => '2024-05-01 10:00:00', 'p' => 5000 ], [ 't' => '2024-05-09 10:00:00', 'p' => 5000 ] ] ), 'بدون تغییر = null' );

	// تاریخچه‌ی کوتاه‌تر از بازه: مبنا اولین نقطه است.
	$short = [
		[ 't' => '2024-05-09 10:00:00', 'p' => 100000 ],
		[ 't' => '2024-05-10 10:00:00', 'p' => 95000 ],
	];
	eq( -5.0, WAPM_Torob::history_change( $short )['pct'], 'مبنای اولین نقطه' );
} );

test( 'هم‌تراز: تنظیمات امن و پیش‌فرض‌های محافظه‌کارانه', function () {
	$s = WAPM_Price_Engine::torob_align_settings( 2.5, 'percent' );

	eq( 'torob', $s['source'], 'منبع ترب' );
	eq( 2.5, $s['torob_undercut'], 'مقدار زیربرش' );
	eq( 'percent', $s['torob_undercut_mode'], 'واحد درصدی' );
	eq( true, $s['torob_only_if_pricier'], 'هرگز گران نمی‌کند' );
	eq( 0.0, $s['torob_min_gap_pct'], 'اختلاف ناچیز جلوی کار دستی را نمی‌گیرد' );
	eq( 'regular', $s['price_target'], 'قیمت اصلی' );
	eq( 'both', $s['variable_scope'], 'والد + واریشن' );
	eq( 'down', $s['round_mode'], 'گرد کردن رو به پایین' );
	eq( 1000.0, $s['round_base'], 'مضرب ۱۰۰۰' );

	// ورودی نامعتبر نباید راه پیدا کند.
	$a = WAPM_Price_Engine::torob_align_settings( -5, 'چیز-نامعتبر' );
	eq( 0.0, $a['torob_undercut'], 'زیربرش منفی → صفر' );
	eq( 'percent', $a['torob_undercut_mode'], 'واحد نامعتبر → درصد' );
} );

test( 'هم‌تراز: قیمت هدف = ارزان‌ترین رقیب منهای زیربرش', function () {
	$percent = WAPM_Price_Engine::torob_align_settings( 1, 'percent' );
	eq( 99000.0, WAPM_Price_Engine::torob_target_price( 100000, $percent ), '۱٪ زیر ۱۰۰٬۰۰۰' );

	$fixed = WAPM_Price_Engine::torob_align_settings( 5000, 'fixed' );
	eq( 95000.0, WAPM_Price_Engine::torob_target_price( 100000, $fixed ), '۵٬۰۰۰ تومان زیر رقیب' );

	$zero = WAPM_Price_Engine::torob_align_settings( 0, 'percent' );
	eq( 100000.0, WAPM_Price_Engine::torob_target_price( 100000, $zero ), 'بدون زیربرش' );
	// رند رو به پایین: ۹۹٬۹۹۹ → ۹۹٬۰۰۰ (حداکثر ۹۹۹ تومان پایین‌تر، هرگز بالاتر از رقیب).
	eq( 99000.0, WAPM_Price_Engine::torob_target_price( 99999, WAPM_Price_Engine::torob_align_settings( 0, 'percent' ) ), 'رند رو به پایین' );

	eq( null, WAPM_Price_Engine::torob_target_price( 0, $percent ), 'بدون قیمت رقیب → null' );
} );

test( 'هم‌تراز: هر کد دلیل رد‌شدن، برچسب فارسی دارد', function () {
	// این فهرست باید با کدهایی که torob_context برمی‌گرداند هم‌خوان بماند.
	foreach ( [ 'already_cheaper', 'small_gap', 'sanity', 'unit_suspect', 'no_data', 'missing' ] as $code ) {
		$label = WAPM_Torob::skip_label( $code );
		ok( $label !== $code && '' !== $label, 'برچسب برای ' . $code );
	}

	eq( 'دلیل-ناشناخته', WAPM_Torob::skip_label( 'دلیل-ناشناخته' ), 'کد ناشناخته بدون تغییر برمی‌گردد' );
} );

test( 'هم‌تراز: اکشن Ajax و هندلرش وجود دارند', function () {
	$ajax = (string) file_get_contents( __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-ajax.php' );

	ok( false !== strpos( $ajax, "'torob_align'," ), 'اکشن در فهرست مجاز ثبت شده' );
	ok( false !== strpos( $ajax, 'public function torob_align(): void' ), 'هندلر تعریف شده' );
	// مسیر اعمال باید از موتور قیمت رد شود، نه اینکه مستقیم متا را بنویسد.
	ok( false !== strpos( $ajax, 'WAPM_Price_Engine::apply_to_product( $id, $settings, $batch_id, \'torob_align\' )' ), 'اعمال از موتور قیمت' );
} );

/* -------------------------------------------------------------------------
 * نتیجه
 * ---------------------------------------------------------------------- */

$total  = $GLOBALS['wapm_tests']['run'];
$failed = $GLOBALS['wapm_tests']['failed'];

if ( $failed ) {
	echo "\n❌ {$failed} از {$total} تست شکست خورد:\n\n";
	foreach ( $GLOBALS['wapm_tests']['failures'] as $f ) {
		echo "  • {$f}\n";
	}
	echo "\n";
	exit( 1 );
}

echo "✅ همه‌ی {$total} تست موفق بود.\n";
exit( 0 );
