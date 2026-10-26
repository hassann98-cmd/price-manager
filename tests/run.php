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

require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-price-engine.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-logger.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-torob.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-torob-api.php';
require_once __DIR__ . '/../woocommerce-advanced-price-manager/includes/class-stock.php';

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
