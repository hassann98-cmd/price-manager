<?php
/**
 * WAPM_Torob — مقایسه‌ی قیمت با ترب و همگام‌سازی داده‌ی رقبا.
 *
 * ## معماری داده
 *
 * ترب درخواست‌های سرور-به-سرور را بلاک می‌کند (پاسخ HTTP 490 و صفحه‌ی «آیا شما
 * یک ربات هستید؟»). بنابراین جمع‌آوری داده در سه مسیر ممکن انجام می‌شود و همه‌ی
 * آن‌ها به یک هسته‌ی مشترک (`ingest`) می‌رسند:
 *
 *  1. **Bookmarklet** (پیش‌فرض و بدون نیاز به زیرساخت): کدی که در مرورگر ادمین و
 *     روی دامنه‌ی torob.com اجرا می‌شود — پس مثل بازدید واقعی دیده می‌شود.
 *  2. **فید CSV**: خروجی/فید پنل فروشندگان ترب (یا هر منبع دیگری) که ادمین
 *     آپلود می‌کند. اگر/وقتی دسترسی به فید رسمی ترب فراهم شد، همین مسیر کافی است.
 *  3. **ورود برنامه‌نویسی (API)**: هر اسکریپت/سرور بیرونی می‌تواند با همان توکن
 *     مخفی (`wapm_torob_ingest`) داده بفرستد — برای اتصال به فید رسمی در آینده.
 *
 * ## قواعد مهم (چرا این‌طور نوشته شده)
 *
 * - **بدون شناسه‌ی فروشگاه، مقایسه انجام نمی‌شود.** قبلاً اگر `shop_id` تنظیم
 *   نبود، قیمت خودمان هم در «ارزان‌ترین رقیب» حساب می‌شد و افزونه می‌گفت
 *   «۵٪ ارزان‌تر از رقیب» در حالی که با خودمان مقایسه شده بود. حالا اگر فروشگاه
 *   خودمان قابل تشخیص نباشد، `competitor_min` ذخیره نمی‌شود و وضعیت
 *   `needs_shop_id` گزارش می‌شود.
 * - **تطبیق محافظه‌کارانه:** تطبیق خودکار فقط با اطمینان کافی انجام می‌شود؛
 *   موارد مشکوک/مبهم به لیست «تطبیق‌نیافته» می‌روند تا دستی وصل شوند. تطبیق
 *   دستی هرگز با اجرای بعدی همگام‌سازی بازنویسی نمی‌شود.
 * - **بعد از هر تغییر قیمت، اختلاف بازمحاسبه می‌شود** تا ستون «قیمت ترب» و
 *   فیلترها با قیمت فعلی فروشگاه بخوانند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Torob {

	/* ---------- آپشن‌ها ---------- */

	const OPTION_SHOP_ID   = 'wapm_torob_shop_id';   // سازگاری با نسخه‌ی قبل
	const OPTION_TOKEN     = 'wapm_torob_bm_token';  // سازگاری با نسخه‌ی قبل
	const OPTION_SETTINGS  = 'wapm_torob_settings';
	const OPTION_UNMATCHED = 'wapm_torob_unmatched';
	const OPTION_LAST_SYNC    = 'wapm_torob_last_sync';
	const OPTION_LAST_CATALOG = 'wapm_torob_last_catalog'; // همگام‌سازی کاتالوگ (قیمت ما از دید ترب)

	const INDEX_TRANSIENT = 'wapm_torob_title_index';

	/* ---------- متای محصول ---------- */

	const META_PRK          = '_wapm_torob_prk';           // شناسه‌ی محصول در ترب
	const META_NAME         = '_wapm_torob_name';          // عنوان محصول در ترب
	const META_PRICE        = '_wapm_torob_price';         // ارزان‌ترین قیمت رقیب
	const META_OUR_PRICE    = '_wapm_torob_our_price';     // قیمت ما از دید ترب (فید ما)
	const META_SELLERS      = '_wapm_torob_sellers';       // تعداد فروشنده‌ها
	const META_SELLERS_LIST = '_wapm_torob_sellers_list';  // JSON: ارزان‌ترین فروشنده‌ها
	const META_URL          = '_wapm_torob_url';
	const META_CHECKED      = '_wapm_torob_checked_at';    // آخرین داده‌ی رقیب
	const META_OUR_CHECKED  = '_wapm_torob_our_checked';    // آخرین همگام‌سازی کاتالوگ (قیمت ما از دید ترب)
	const META_CARD_PRICE   = '_wapm_torob_card_price';    // قیمت کارت در لیست فروشگاه (تخمینی)
	const META_SOURCE       = '_wapm_torob_source';        // bookmarklet | feed | api | manual
	const META_LINK         = '_wapm_torob_link';          // auto | manual
	const META_STATUS       = '_wapm_torob_status';        // برای فیلتر/مرتب‌سازی SQL
	const META_DIFF_PCT     = '_wapm_torob_diff_pct';      // درصد اختلاف قیمت ما با ارزان‌ترین رقیب
	const META_FEED_MISMATCH = '_wapm_torob_feed_mismatch'; // 1 = قیمت ترب از ما با ووکامرس نمی‌خواند
	const META_UNIT_SUSPECT  = '_wapm_torob_unit_suspect';  // 1 = واحد قیمت مشکوک (تومان/ریال)
	const META_PRICE_HISTORY = '_wapm_torob_price_history'; // JSON: ۳۰ نقطه‌ی آخر قیمت رقیب
	const HISTORY_POINTS    = 30;                            // سقف نقطه‌های نگهداری‌شده

	const MAX_SELLERS_STORED = 12;   // فقط ارزان‌ترین‌ها ذخیره می‌شوند (حجم متا)
	const MAX_UNMATCHED      = 500;  // سقف لیست تطبیق‌نیافته
	const SCHEMA_VERSION     = '2.0'; // نسخه‌ی ساختار داده (برای فید/بوکمارکلت)

	/**
	 * هوک‌ها — از بوت‌استرپ افزونه صدا زده می‌شود.
	 *
	 * چرا لازم است: «قیمت ترب» و فیلتر/مرتب‌سازی بر پایه‌ی اختلاف قیمت کار می‌کنند.
	 * اگر قیمت محصولی که در ترب تطبیق شده به هر شکلی عوض شود (دستی، CSV، افزونه‌ی
	 * دیگر)، اختلاف ذخیره‌شده باید بازمحاسبه شود؛ وگرنه ستون «قیمت ترب» و فیلترها
	 * عدد کهنه نشان می‌دهند.
	 */
	public static function hooks(): void {
		add_action( 'woocommerce_update_product', [ __CLASS__, 'on_product_saved' ], 20, 1 );
		add_action( 'woocommerce_new_product', [ __CLASS__, 'on_product_saved' ], 20, 1 );

		// ساخت/حذف محصول، ایندکس عنوان را باطل می‌کند (تطبیق از این ایندکس می‌آید).
		add_action( 'save_post_product', [ __CLASS__, 'flush_index' ], 20, 0 );
		add_action( 'trashed_post', [ __CLASS__, 'on_post_removed' ], 10, 1 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_post_removed' ], 10, 1 );
	}

	public static function on_product_saved( $product_id ): void {
		self::refresh_diff_meta( (int) $product_id );
	}

	public static function on_post_removed( $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
			self::flush_index();
		}
	}

	/* ---------- تنظیمات ---------- */

	/**
	 * تنظیمات پیش‌فرض.
	 * fresh_days: بعد از این تعداد روز، داده «کهنه» حساب می‌شود.
	 * tolerance_pct: اختلاف کمتر از این درصد «برابر» در نظر گرفته می‌شود.
	 */
	public static function default_settings(): array {
		return [
			'shop_name'     => '',
			'fresh_days'    => 3,
			'tolerance_pct' => 1.0,
		];
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION_SETTINGS, [] );
		$saved = is_array( $saved ) ? $saved : [];

		$settings = array_merge( self::default_settings(), array_intersect_key( $saved, self::default_settings() ) );
		$settings['fresh_days']    = max( 1, min( 120, (int) $settings['fresh_days'] ) );
		$settings['tolerance_pct'] = max( 0, min( 100, (float) $settings['tolerance_pct'] ) );
		$settings['shop_name']     = sanitize_text_field( (string) $settings['shop_name'] );

		return $settings;
	}

	public static function save_settings( array $raw ): array {
		$current = self::settings();

		$next = [
			'shop_name'     => sanitize_text_field( (string) ( $raw['shop_name'] ?? $current['shop_name'] ) ),
			'fresh_days'    => isset( $raw['fresh_days'] ) ? absint( $raw['fresh_days'] ) : $current['fresh_days'],
			'tolerance_pct' => isset( $raw['tolerance_pct'] ) ? (float) $raw['tolerance_pct'] : $current['tolerance_pct'],
		];

		update_option( self::OPTION_SETTINGS, $next, false );

		if ( isset( $raw['shop_id'] ) ) {
			self::save_shop_id( (string) $raw['shop_id'] );
		}

		return self::settings();
	}

	public static function get_shop_id(): string {
		return trim( (string) get_option( self::OPTION_SHOP_ID, '' ) );
	}

	public static function save_shop_id( string $shop_id ): void {
		// شناسه‌ی فروشگاه ترب عددی است؛ هر چیز دیگری را دور می‌ریزیم تا در AJAX/URL
		// استفاده‌ی ناخواسته نشود.
		$shop_id = preg_replace( '/[^0-9]/', '', $shop_id );
		update_option( self::OPTION_SHOP_ID, (string) $shop_id, false );
	}

	public static function get_shop_name(): string {
		return (string) self::settings()['shop_name'];
	}

	/**
	 * آیا فروشگاه خودمان در میان فروشنده‌های ترب قابل تشخیص است؟
	 * بدون این، «ارزان‌ترین رقیب» می‌تواند قیمت خودمان باشد.
	 */
	public static function can_identify_shop(): bool {
		return '' !== self::get_shop_id() || '' !== self::get_shop_name();
	}

	/**
	 * توکن مخفیِ ورودی بوکمارکلت/فید — چون این درخواست از دامنه‌ی دیگری و
	 * بدون سشن ادمین می‌آید، به‌جای nonce با این توکن احراز هویت می‌شود.
	 */
	public static function get_bookmarklet_token(): string {
		$token = (string) get_option( self::OPTION_TOKEN, '' );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::OPTION_TOKEN, $token, false );
		}
		return $token;
	}

	public static function regenerate_bookmarklet_token(): string {
		$token = wp_generate_password( 32, false );
		update_option( self::OPTION_TOKEN, $token, false );
		return $token;
	}

	/* ---------- آمار و وضعیت همگام‌سازی ---------- */

	public static function count_matched(): int {
		$q = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => [ [ 'key' => self::META_PRK, 'compare' => 'EXISTS' ] ],
		] );
		return (int) $q->found_posts;
	}

	/**
	 * اطلاعات آخرین همگام‌سازی.
	 * نسخه‌ی قبلی این آپشن یک رشته‌ی تاریخ بود؛ برای سازگاری، همان هم خوانده می‌شود.
	 */
	public static function get_last_sync(): array {
		$raw = get_option( self::OPTION_LAST_SYNC, '' );

		if ( is_string( $raw ) && '' !== $raw ) {
			return [ 'at' => $raw, 'source' => '', 'matched' => 0, 'checked' => 0, 'unmatched' => 0, 'discovered' => 0, 'pages' => 0 ];
		}

		return array_merge(
			[ 'at' => '', 'source' => '', 'matched' => 0, 'checked' => 0, 'unmatched' => 0, 'discovered' => 0, 'pages' => 0, 'structure_warning' => false, 'run' => '' ],
			is_array( $raw ) ? $raw : []
		);
	}

	public static function get_last_sync_time(): string {
		return (string) self::get_last_sync()['at'];
	}

	/**
	 * آخرین همگام‌سازی کاتالوگ (جدا از آخرین دریافت قیمت رقیب).
	 *
	 * چرا جدا: کاتالوگ فقط «قیمت ما از دید ترب» را می‌آورد و اگر همان رکورد
	 * همگام‌سازی اصلی را بازنویسی کند، کاربر فکر می‌کند قیمت رقبا تازه شده است.
	 */
	public static function get_last_catalog(): array {
		$raw = get_option( self::OPTION_LAST_CATALOG, [] );
		return array_merge(
			[ 'at' => '', 'items' => 0, 'pages' => 0, 'matched' => 0, 'source' => '' ],
			is_array( $raw ) ? $raw : []
		);
	}

	/* ---------- زمان ---------- */

	/**
	 * زمان‌های ذخیره‌شده با `current_time( 'mysql' )` ساعتِ محلی سایت‌اند، نه UTC.
	 * اگر مستقیم با `time()` مقایسه شوند، «سن داده» به‌اندازه‌ی اختلاف منطقه‌ی
	 * زمانی اشتباه می‌شود (برای ایران ۳:۳۰ ساعت) و فیلتر SQL با ستون جدول
	 * نتیجه‌ی متفاوت می‌دهد. این دو تابع همان تبدیل را در دو جهت انجام می‌دهند.
	 */
	public static function local_to_timestamp( string $mysql_local ): int {
		$local = $mysql_local ? (int) strtotime( $mysql_local ) : 0;
		if ( ! $local ) {
			return 0;
		}
		$offset = function_exists( 'get_option' ) ? (float) get_option( 'gmt_offset' ) : 0;

		return $local - (int) round( $offset * HOUR_IN_SECONDS );
	}

	/** همان قالب محلی، از یک timestamp واقعی (UTC). */
	private static function local_datetime( int $timestamp ): string {
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'Y-m-d H:i:s', $timestamp );
		}
		$offset = function_exists( 'get_option' ) ? (float) get_option( 'gmt_offset' ) : 0;

		return gmdate( 'Y-m-d H:i:s', $timestamp + (int) round( $offset * HOUR_IN_SECONDS ) );
	}

	/* ---------- نرمال‌سازی و تطبیق ---------- */

	/**
	 * یکسان‌سازی رشته برای تطبیق عنوان (بدون نیاز به intl/normalizer).
	 */
	public static function normalize( string $s ): string {
		// نیم‌فاصله (ZWNJ) → فاصله، تا «گوشی‌های» و «گوشی های» یکی شوند.
		// کاراکترهای جهت‌دهی و ZWJ بی‌اثرند و حذف می‌شوند.
		$s = str_replace( "\xE2\x80\x8C", ' ', $s );
		$s = str_replace( [ "\xE2\x80\x8D", "\xE2\x80\x8E", "\xE2\x80\x8F", "\xE2\x80\xAA", "\xE2\x80\xAB" ], '', $s );

		// ي/ك عربی و أ/إ/آ → شکل فارسی/ساده.
		$s = str_replace(
			[ 'ي', 'ك', 'ۀ', 'ة', 'أ', 'إ', 'آ', 'ٱ' ],
			[ 'ی', 'ک', 'ه', 'ه', 'ا', 'ا', 'ا', 'ا' ],
			$s
		);

		// حذف اعراب (فتحه/کسره/تنوین/تشدید) — «مَرهی» و «مرهی» باید یکی شوند.
		$s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $s );

		// ارقام فارسی/عربی → ASCII.
		$s = self::to_ascii_digits( (string) $s );

		// علائم و جداکننده‌ها → فاصله.
		$s = preg_replace( '/[«»"\'`”“‘’\[\]\(\)\{\}<>|*_+=\/\\\\،؛,.:!?؟…—–ـ~^%$#@&]+/u', ' ', $s );

		// کوچک‌سازی و جمع‌کردن فاصله‌ها.
		$s = WAPM_Str::lower( (string) $s );
		$s = preg_replace( '/\s+/u', ' ', (string) $s );

		return trim( (string) $s );
	}

	/**
	 * تبدیل یک مقدار ورودی (از API، فید CSV، یا بوکمارکلت) به بولین.
	 *
	 * چرا لازم است: ترب و فیدهای CSV مقدارهای پرچمی را گاهی بولین و گاهی رشته
	 * می‌فرستند — `"false"` و `"0"` در PHP **درست‌نما** هستند و `false !== "false"`
	 * هم true است؛ نتیجه این بود که کالای ناموجود «موجود» و قیمت مشکوک «سالم»
	 * شمرده می‌شد. این تابع همان تصمیم را یک‌جا و صریح می‌گیرد.
	 *
	 * @param mixed $value مقدار خام
	 * @return bool
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0;
		}

		if ( is_string( $value ) ) {
			$text = strtolower( trim( $value ) );

			// مقادیر «خالی/منفی» که در JSON، CSV و HTML یکسان به کار می‌روند.
			return ! in_array( $text, [ '', '0', 'false', 'no', 'off', 'null', 'none', 'n/a' ], true );
		}

		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		return (bool) $value;
	}

	/**
	 * تبدیل ارقام فارسی/عربی به لاتین (بدون intl).
	 */
	public static function to_ascii_digits( string $s ): string {
		$map = [
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		];
		return strtr( $s, $map );
	}

	/**
	 * استخراج «کد کالا» از عنوان ترب: الگوهای رایج
	 *   «… کد 123456»، «(کد: 123456)»، «… 123456»، «مدل A1234»
	 *
	 * @return string[] کدهای کاندید (بدون تکرار)
	 */
	public static function extract_codes( string $name ): array {
		$name  = self::to_ascii_digits( $name );
		$codes = [];

		// «کد» + عدد (با یا بدون دونقطه/فاصله).
		if ( preg_match_all( '/کد\s*:?\s*(\d{3,})/u', $name, $m ) ) {
			$codes = array_merge( $codes, $m[1] );
		}

		// عدد داخل پرانتز.
		if ( preg_match_all( '/\((\d{4,})\)/u', $name, $m ) ) {
			$codes = array_merge( $codes, $m[1] );
		}

		// عدد انتهایی (۴ رقم یا بیشتر) — رایج‌ترین شکل عنوان ترب.
		if ( preg_match( '/(?:^|\s)(\d{4,})\s*$/u', trim( $name ), $m ) ) {
			$codes[] = $m[1];
		}

		// مدل‌های حرف+رقم مثل A1234 / SM-G991 / RTX3060.
		// مرز با lookbehind/lookahead است تا «SM-G991» کامل گرفته شود، نه «G991»؛
		// این مهم است چون کد ناقص می‌تواند به SKU اشتباه وصل شود.
		if ( preg_match_all( '/(?<![\w-])([A-Za-z]{1,6}(?:-[A-Za-z]{0,4})?\d{3,6})(?![\w-])/u', $name, $m ) ) {
			$codes = array_merge( $codes, $m[1] );
		}

		$codes = array_values( array_unique( array_filter( array_map( 'trim', $codes ) ) ) );

		return array_slice( $codes, 0, 5 );
	}

	/**
	 * ایندکس عنوان محصولات (norm_title => int[]).
	 * با transient کش می‌شود و با هر ذخیره/حذف محصول باطل می‌شود.
	 *
	 * @return array<string, int[]>
	 */
	private static function title_index(): array {
		$cached = get_transient( self::INDEX_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
		);

		$index = [];
		foreach ( (array) $rows as $row ) {
			$key = self::normalize( (string) $row->post_title );
			if ( '' === $key ) {
				continue;
			}
			$index[ $key ][] = (int) $row->ID;
		}

		set_transient( self::INDEX_TRANSIENT, $index, HOUR_IN_SECONDS );

		return $index;
	}

	public static function flush_index(): void {
		delete_transient( self::INDEX_TRANSIENT );
	}

	/**
	 * محاسبه‌ی شباهت دو مجموعه‌ی واژه: میانگین Jaccard و «شامل‌بودن».
	 */
	public static function token_similarity( string $a, string $b ): float {
		$ta = array_values( array_unique( preg_split( '/\s+/u', $a, -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) );
		$tb = array_values( array_unique( preg_split( '/\s+/u', $b, -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) );

		if ( ! $ta || ! $tb ) {
			return 0.0;
		}

		$inter = count( array_intersect( $ta, $tb ) );
		if ( 0 === $inter ) {
			return 0.0;
		}

		$union      = count( $ta ) + count( $tb ) - $inter;
		$jaccard    = $inter / $union;
		$contain    = $inter / min( count( $ta ), count( $tb ) );

		// «شامل‌بودن» وزن بیشتری دارد: عنوان ترب معمولاً چند واژه‌ی اضافه دارد
		// («کد …»، «گارانتی …»).
		return round( 0.4 * $jaccard + 0.6 * $contain, 4 );
	}

	/**
	 * پیدا کردن محصول ووکامرس متناظر، با تشخیص ابهام.
	 *
	 * @return array{id:int, how:string, score:float, ambiguous:bool, candidate:int}
	 *         id=0 یعنی تطبیق مطمئن پیدا نشد.
	 */
	public static function match_product( string $torob_name, string $prk = '' ): array {
		$none = [ 'id' => 0, 'how' => '', 'score' => 0.0, 'ambiguous' => false, 'candidate' => 0 ];

		// ۱) اگر همین prk قبلاً به محصولی وصل شده، همان را برگردان (پایداری تطبیق).
		if ( '' !== $prk ) {
			$by_prk = self::find_product_by_prk( $prk );
			if ( $by_prk ) {
				return [ 'id' => $by_prk, 'how' => 'existing', 'score' => 1.0, 'ambiguous' => false, 'candidate' => 0 ];
			}
		}

		if ( '' === trim( $torob_name ) ) {
			return $none;
		}

		$index = self::title_index();
		$norm  = self::normalize( $torob_name );

		// ۲) تطبیق دقیق عنوان.
		if ( isset( $index[ $norm ] ) ) {
			$ids = array_values( array_unique( $index[ $norm ] ) );
			if ( 1 === count( $ids ) ) {
				// حتی با عنوان دقیقاً یکسان، اگر آن محصول به محصول دیگری در ترب
				// وصل است، آن را نمی‌دزدیم؛ وگرنه همگام‌سازی بعدی، prk محصول قبلی
				// را بازنویسی می‌کرد و داده‌ی دو محصول قاطی می‌شد.
				if ( ! self::has_prk( (int) $ids[0] ) ) {
					return [ 'id' => (int) $ids[0], 'how' => 'exact', 'score' => 1.0, 'ambiguous' => false, 'candidate' => 0 ];
				}
				return [ 'id' => 0, 'how' => 'occupied', 'score' => 1.0, 'ambiguous' => false, 'candidate' => (int) $ids[0] ];
			}
			// چند محصول با عنوان یکسان: هر کدام به prk دیگری وصل نباشند کاندیدند.
			$free = array_values( array_filter( $ids, fn( $id ) => ! self::has_prk( (int) $id ) ) );
			if ( 1 === count( $free ) ) {
				return [ 'id' => (int) $free[0], 'how' => 'exact', 'score' => 1.0, 'ambiguous' => false, 'candidate' => 0 ];
			}
			if ( count( $free ) > 1 ) {
				return [ 'id' => 0, 'how' => 'ambiguous', 'score' => 1.0, 'ambiguous' => true, 'candidate' => (int) $free[0] ];
			}
		}

		// ۳) تطبیق با کد کالا / SKU.
		foreach ( self::extract_codes( $torob_name ) as $code ) {
			// SKU در ووکامرس ممکن است با حروف بزرگ/کوچک دیگری ذخیره شده باشد.
			foreach ( array_unique( [ $code, strtoupper( $code ), strtolower( $code ) ] ) as $sku_variant ) {
				$by_sku = wc_get_product_id_by_sku( $sku_variant );
				if ( $by_sku && ! self::has_prk( (int) $by_sku ) ) {
					return [ 'id' => (int) $by_sku, 'how' => 'sku', 'score' => 1.0, 'ambiguous' => false, 'candidate' => 0 ];
				}
			}

			// عنوان‌های ایندکس نرمال‌شده‌اند (حروف کوچک، خط تیره → فاصله)؛ پس کد
			// هم باید نرمال شود، وگرنه کدهای لاتین هرگز در عنوان پیدا نمی‌شدند.
			$code_norm = self::normalize( $code );
			if ( '' === $code_norm ) {
				continue;
			}

			$code_hits = [];
			foreach ( $index as $title => $ids ) {
				// مرز واژه‌ای، نه زیررشته‌ی خام: «1234» نباید داخل «12345» پیدا شود.
				if ( preg_match( '/(^|\D)' . preg_quote( (string) $code_norm, '/' ) . '(\D|$)/u', (string) $title ) ) {
					foreach ( $ids as $id ) {
						if ( ! self::has_prk( (int) $id ) ) {
							$code_hits[ (int) $id ] = true;
						}
					}
				}
			}
			if ( 1 === count( $code_hits ) ) {
				return [ 'id' => (int) array_key_first( $code_hits ), 'how' => 'code', 'score' => 1.0, 'ambiguous' => false, 'candidate' => 0 ];
			}
			if ( count( $code_hits ) > 1 ) {
				return [ 'id' => 0, 'how' => 'ambiguous', 'score' => 1.0, 'ambiguous' => true, 'candidate' => (int) array_key_first( $code_hits ) ];
			}
		}

		// ۴) تطبیق فازی — فقط با اطمینان کافی و بدون رقیب نزدیک.
		$tokens = preg_split( '/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( count( $tokens ) < 2 ) {
			// عنوان تک‌واژه‌ای (مثلاً «آیفون») خیلی مبهم است؛ خودکار وصل نمی‌کنیم.
			return $none;
		}

		$best_score = 0.0;
		$best_ids   = [];
		$second     = 0.0;

		foreach ( $index as $title => $ids ) {
			$score = self::token_similarity( $norm, (string) $title );
			if ( $score < 0.5 ) {
				continue;
			}
			if ( $score > $best_score ) {
				$second     = $best_score;
				$best_score = $score;
				$best_ids   = $ids;
			} elseif ( $score > $second ) {
				$second = $score;
			}
		}

		$threshold = 0.90; // بالا عمداً: اتصال اشتباه بدتر از تطبیق‌نشدن است.
		if ( $best_score >= $threshold && ( $best_score - $second ) >= 0.05 ) {
			$free = array_values( array_filter( $best_ids, fn( $id ) => ! self::has_prk( (int) $id ) ) );
			if ( 1 === count( $free ) ) {
				return [ 'id' => (int) $free[0], 'how' => 'fuzzy', 'score' => $best_score, 'ambiguous' => false, 'candidate' => 0 ];
			}
		}

		$candidate = 0;
		foreach ( $best_ids as $id ) {
			if ( ! self::has_prk( (int) $id ) ) {
				$candidate = (int) $id;
				break;
			}
		}

		return [
			'id'        => 0,
			'how'       => $best_score >= 0.5 ? 'weak' : '',
			'score'     => $best_score,
			'ambiguous' => $best_score >= 0.5,
			'candidate' => $candidate,
		];
	}

	private static function has_prk( int $product_id ): bool {
		return '' !== (string) get_post_meta( $product_id, self::META_PRK, true );
	}

	public static function find_product_by_prk( string $prk ): int {
		if ( '' === $prk ) {
			return 0;
		}
		$q = new WP_Query( [
			'post_type'      => [ 'product', 'product_variation' ],
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'meta_query'     => [ [ 'key' => self::META_PRK, 'value' => $prk, 'compare' => '=' ] ],
		] );
		return $q->posts ? (int) $q->posts[0] : 0;
	}

	/**
	 * اتصال دستی یک محصول به prk ترب (بالاترین اولویت در تطبیق).
	 */
	public static function link_product( int $product_id, string $prk, string $name = '' ): bool {
		$product = wc_get_product( $product_id );
		if ( ! $product || '' === trim( $prk ) ) {
			return false;
		}

		// ترب محصول را در سطح والد مقایسه می‌کند (کمینه‌ی قیمت واریشن‌ها). اتصال
		// prk به یک واریشن، هم داده‌ی مقایسه را بی‌معنی می‌کند و هم باعث می‌شود
		// همگام‌سازی بعدی همان ردیف را «وصل‌شده» ببیند و رد کند.
		if ( $product->is_type( 'variation' ) ) {
			return false;
		}

		// prk یکتا است: اگر محصول دیگری به همین prk وصل است، اول جدا شود.
		$existing = self::find_product_by_prk( $prk );
		if ( $existing && $existing !== $product_id ) {
			self::unlink_product( $existing );
		}

		update_post_meta( $product_id, self::META_PRK, $prk );
		update_post_meta( $product_id, self::META_LINK, 'manual' );
		update_post_meta( $product_id, self::META_URL, 'https://torob.com/p/' . rawurlencode( $prk ) . '/' );
		if ( '' !== $name ) {
			update_post_meta( $product_id, self::META_NAME, $name );
		}
		// عمداً META_CHECKED را «حالا» نمی‌گذاریم: تا داده‌ی واقعی از ترب نیاید،
		// وضعیت باید «همگام‌سازی نشده» بماند، نه «تازه».

		self::refresh_diff_meta( $product_id );
		self::remove_unmatched_by_prk( $prk );

		return true;
	}

	/**
	 * جداکردن محصول از ترب (داده‌ی قیمت رقیب هم پاک می‌شود).
	 */
	public static function unlink_product( int $product_id ): void {
		foreach ( [
			self::META_PRK,
			self::META_NAME,
			self::META_PRICE,
			self::META_OUR_PRICE,
			self::META_CARD_PRICE,
			self::META_OUR_CHECKED,
			self::META_SELLERS,
			self::META_SELLERS_LIST,
			self::META_URL,
			self::META_CHECKED,
			self::META_SOURCE,
			self::META_LINK,
			self::META_STATUS,
			self::META_DIFF_PCT,
			self::META_FEED_MISMATCH,
			self::META_UNIT_SUSPECT,
			self::META_PRICE_HISTORY,
		] as $key ) {
			delete_post_meta( $product_id, $key );
		}
	}

	/**
	 * شرط meta برای فیلتر «وضعیت ترب» — مشترک بین جدول لیست و دامنه‌ی تغییر قیمت.
	 *
	 * @param string $status any|none|pricier|cheaper|equal|no_competitor|needs_shop_id|no_price|stale|fresh
	 */
	public static function meta_clause( string $status ): ?array {
		if ( '' === $status ) {
			return null;
		}

		if ( 'any' === $status ) {
			return [ 'key' => self::META_PRK, 'compare' => 'EXISTS' ];
		}
		if ( 'none' === $status ) {
			return [ 'key' => self::META_PRK, 'compare' => 'NOT EXISTS' ];
		}

		// پرچم‌های هشدار: مقدار 1/0 در متا ذخیره شده و فیلتر مستقیم SQL است.
		if ( 'feed_mismatch' === $status || 'unit_suspect' === $status ) {
			return [
				'key'   => ( 'feed_mismatch' === $status ) ? self::META_FEED_MISMATCH : self::META_UNIT_SUSPECT,
				'value' => '1',
			];
		}

		if ( 'stale' === $status || 'fresh' === $status ) {
			$days = (int) self::settings()['fresh_days'];
			// مرز در همان قالبِ محلیِ META_CHECKED ساخته می‌شود.
			$cutoff = self::local_datetime( time() - ( $days * DAY_IN_SECONDS ) );

			if ( 'fresh' === $status ) {
				return [
					'relation' => 'AND',
					[ 'key' => self::META_PRK, 'compare' => 'EXISTS' ],
					[ 'key' => self::META_CHECKED, 'value' => $cutoff, 'compare' => '>=', 'type' => 'DATETIME' ],
				];
			}

			// «کهنه» یعنی وصل است ولی یا هرگز بررسی نشده یا بررسی‌اش قدیمی است.
			// حالت اول با مقایسه‌ی ساده در SQL پیدا نمی‌شد (متا وجود ندارد)، پس
			// فیلتر SQL لیست، ردیف‌هایی را که PHP «کهنه» می‌دانست جا می‌انداخت.
			return [
				'relation' => 'AND',
				[ 'key' => self::META_PRK, 'compare' => 'EXISTS' ],
				[
					'relation' => 'OR',
					[ 'key' => self::META_CHECKED, 'compare' => 'NOT EXISTS' ],
					[ 'key' => self::META_CHECKED, 'value' => $cutoff, 'compare' => '<', 'type' => 'DATETIME' ],
				],
			];
		}

		if ( in_array( $status, self::filterable_statuses(), true ) ) {
			return [ 'key' => self::META_STATUS, 'value' => $status ];
		}

		return null;
	}

	/**
	 * آیا یک محصول با فیلتر وضعیت ترب می‌خواند؟ (برای دامنه‌ی «محصولات انتخابی»)
	 */
	public static function matches_status( int $product_id, string $status ): bool {
		if ( '' === $status ) {
			return true;
		}

		$has = metadata_exists( 'post', $product_id, self::META_PRK );

		if ( 'feed_mismatch' === $status ) {
			return '1' === (string) get_post_meta( $product_id, self::META_FEED_MISMATCH, true );
		}
		if ( 'unit_suspect' === $status ) {
			return '1' === (string) get_post_meta( $product_id, self::META_UNIT_SUSPECT, true );
		}

		switch ( $status ) {
			case 'any':
				return $has;
			case 'none':
				return ! $has;
			case 'stale':
			case 'fresh':
				if ( ! $has ) {
					return false;
				}
				$checked = (string) get_post_meta( $product_id, self::META_CHECKED, true );
				$ts      = self::local_to_timestamp( $checked );
				if ( ! $ts ) {
					return 'stale' === $status;
				}
				$is_stale = ( time() - $ts ) > ( (int) self::settings()['fresh_days'] * DAY_IN_SECONDS );
				return ( 'stale' === $status ) ? $is_stale : ! $is_stale;
		}

		return $has && (string) get_post_meta( $product_id, self::META_STATUS, true ) === $status;
	}

	/* ---------- وضعیت و اختلاف قیمت ---------- */

	/**
	 * محافظ واحد: اگر قیمت ما و قیمت ترب حدوداً ۱۰ برابر فاصله داشته باشند،
	 * احتمالاً یکی ریال است و دیگری تومان (یا داده اشتباه است). در این حالت نه
	 * قیمت‌گذاری خودکار باید انجام شود و نه درصد اختلاف نمایش داده شود.
	 */
	/**
	 * پرچم‌های نمایشی/فیلتری از قیمت ووکامرس و «قیمت ما از دید ترب».
	 *
	 * چرا یک تابع مشترک: ستون جدول و فیلتر SQL باید یک چیز بگویند. اگر آستانه
	 * در دو جا جدا حساب شود، دیر یا زود فیلتر ردیفی را نشان می‌دهد که هشدار
	 * ندارد (یا برعکس).
	 *
	 * @param float      $our_price     قیمت مؤثر ووکامرس
	 * @param float|null $our_torob     قیمتی که ترب از ما نشان می‌دهد (اگر معلوم باشد)
	 * @param float      $tolerance_pct آستانه‌ی ناسازگاری فید (درصد)
	 * @return array{mismatch:bool,unit:bool}
	 */
	public static function price_flags( float $our_price, ?float $our_torob, float $tolerance_pct ): array {
		$known = ( null !== $our_torob && $our_torob > 0 );

		return [
			'mismatch' => $known && $our_price > 0 && abs( ( ( $our_price - $our_torob ) / $our_torob ) * 100 ) > $tolerance_pct,
			'unit'     => $known && self::unit_suspect( $our_price, (float) $our_torob ),
		];
	}

	public static function unit_suspect( float $our_price, float $competitor ): bool {
		if ( $our_price <= 0 || $competitor <= 0 ) {
			return false;
		}
		$ratio = $our_price / $competitor;

		return ( $ratio > 8 && $ratio < 12.5 ) || ( $ratio > 0.08 && $ratio < 0.125 );
	}

	/**
	 * درصد اختلاف قیمت ما با ارزان‌ترین رقیب.
	 * مثبت = ما گران‌تریم. null = قابل محاسبه نیست.
	 */
	public static function diff_pct( float $our_price, ?float $competitor_price ): ?float {
		if ( $our_price <= 0 || null === $competitor_price || $competitor_price <= 0 ) {
			return null;
		}
		return round( ( ( $our_price - $competitor_price ) / $competitor_price ) * 100, 2 );
	}

	/**
	 * وضعیت مقایسه (تابع خالص — قابل تست).
	 *
	 * @param array $d   ['competitor_price'=>?float,'our_price_torob'=>?float,'checked_at'=>string,'shop_known'=>bool,'has_data'=>bool]
	 * @param float $our_price قیمت فعلی فروشگاه (چیزی که قابل تغییر است)
	 */
	public static function compute_status( array $d, float $our_price, array $settings = [] ): string {
		$settings = array_merge( self::default_settings(), $settings );
		$has_data = ! empty( $d['has_data'] );

		if ( ! $has_data ) {
			return 'none';
		}

		// بدون شناسایی فروشگاه خودمان، «رقیب» قابل اعتماد نیست.
		if ( empty( $d['shop_known'] ) ) {
			return 'needs_shop_id';
		}

		$competitor = isset( $d['competitor_price'] ) && is_numeric( $d['competitor_price'] )
			? (float) $d['competitor_price']
			: null;

		// محصولی که تازه دستی وصل شده ولی هنوز داده‌ای از ترب نگرفته، «کهنه» نیست؛
		// «همگام‌سازی نشده» است. برچسب کهنه فقط برای داده‌ای است که واقعاً پیر شده.
		if ( empty( $d['checked_at'] ) ) {
			return null === $competitor ? 'none' : 'stale';
		}

		$checked = self::local_to_timestamp( (string) $d['checked_at'] );
		if ( ! $checked || ( time() - $checked ) > ( (int) $settings['fresh_days'] * DAY_IN_SECONDS ) ) {
			return 'stale';
		}

		if ( null === $competitor || $competitor <= 0 ) {
			return 'no_competitor';
		}

		if ( $our_price <= 0 ) {
			return 'no_price';
		}

		$diff      = self::diff_pct( $our_price, $competitor );
		$tolerance = (float) $settings['tolerance_pct'];

		if ( null === $diff ) {
			return 'no_price';
		}
		if ( $diff > $tolerance ) {
			return 'pricier';
		}
		if ( $diff < -$tolerance ) {
			return 'cheaper';
		}
		return 'equal';
	}

	public static function status_label( string $status ): string {
		$labels = [
			'none'           => __( 'همگام‌سازی نشده', 'wapm' ),
			'needs_shop_id'  => __( 'شناسه فروشگاه تنظیم نشده', 'wapm' ),
			'no_competitor'  => __( 'فقط ما در ترب', 'wapm' ),
			'no_price'       => __( 'قیمت نامشخص', 'wapm' ),
			'stale'          => __( 'داده کهنه', 'wapm' ),
			'pricier'        => __( 'گران‌تر از رقیب', 'wapm' ),
			'cheaper'        => __( 'ارزان‌تر از رقیب', 'wapm' ),
			'equal'          => __( 'برابر با رقیب', 'wapm' ),
			'feed_mismatch'  => __( '⚠ ناسازگاری فید ترب', 'wapm' ),
			'unit_suspect'   => __( '⚠ واحد قیمت مشکوک', 'wapm' ),
		];
		return $labels[ $status ] ?? $status;
	}

	/**
	 * برچسب فارسی «چرا این محصول خودکار قیمت نمی‌گیرد».
	 *
	 * چرا در PHP و نه JS: همان دلیلی که در لیست نمایش داده می‌شود، در پاسخ
	 * «هم‌تراز با ترب» و در آینده در CSV هم می‌آید؛ یک منبع حقیقت، یک زبان.
	 *
	 * @param string $reason کد دلیل (`skip` خروجی torob_context یا حالت‌های محلی)
	 */
	public static function skip_label( string $reason ): string {
		$labels = [
			'already_cheaper' => __( 'از ارزان‌ترین رقیب ارزان‌تریم — قیمت بالا نمی‌رود', 'wapm' ),
			'small_gap'       => __( 'اختلاف با قیمت هدف ناچیز است', 'wapm' ),
			'sanity'          => __( 'تغییر بیش از ۱۰ برابر — داده مشکوک', 'wapm' ),
			'unit_suspect'    => __( 'واحد قیمت مشکوک (تومان/ریال)', 'wapm' ),
			'no_data'         => __( 'داده‌ی تازه‌ی ترب ندارد', 'wapm' ),
			'missing'         => __( 'محصول پیدا نشد', 'wapm' ),
		];

		return $labels[ $reason ] ?? $reason;
	}

	/** وضعیت‌هایی که در فیلتر SQL قابل استفاده‌اند. */
	public static function filterable_statuses(): array {
		return [ 'pricier', 'cheaper', 'equal', 'no_competitor', 'needs_shop_id', 'no_price' ];
	}

	/**
	 * محاسبه و ذخیره‌ی اختلاف/وضعیت برای فیلتر و مرتب‌سازی در SQL.
	 * باید بعد از هر تغییر قیمت (توسط خودمان یا دستی) صدا زده شود.
	 */
	public static function refresh_diff_meta( int $product_id, ?float $our_price = null ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		// تغییر قیمت واریشن، قیمت مؤثر والد (که ترب نشان می‌دهد) را عوض می‌کند.
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id ) {
				self::refresh_diff_meta( $parent_id );
			}
			return;
		}

		if ( ! metadata_exists( 'post', $product_id, self::META_PRK ) ) {
			delete_post_meta( $product_id, self::META_STATUS );
			delete_post_meta( $product_id, self::META_DIFF_PCT );
			delete_post_meta( $product_id, self::META_FEED_MISMATCH );
			delete_post_meta( $product_id, self::META_UNIT_SUSPECT );
			return;
		}

		$competitor = get_post_meta( $product_id, self::META_PRICE, true );
		$competitor = ( '' === $competitor || null === $competitor ) ? null : (float) $competitor;

		if ( null === $our_price ) {
			$our_price = (float) $product->get_price();
		}

		$data = [
			'has_data'         => true,
			'competitor_price' => $competitor,
			'our_price_torob'  => (float) get_post_meta( $product_id, self::META_OUR_PRICE, true ),
			'checked_at'       => (string) get_post_meta( $product_id, self::META_CHECKED, true ),
			'shop_known'       => self::can_identify_shop(),
		];

		// تنظیمات (آستانه‌ی «برابر»، عمر داده) باید همان چیزی باشد که ستون جدول
		// استفاده می‌کند؛ وگرنه فیلتر SQL نتیجه‌ی متفاوتی از جدول می‌دهد.
		$status = self::compute_status( $data, (float) $our_price, self::settings() );
		$diff   = self::diff_pct( (float) $our_price, $competitor );

		update_post_meta( $product_id, self::META_STATUS, $status );
		if ( null === $diff ) {
			delete_post_meta( $product_id, self::META_DIFF_PCT );
		} else {
			update_post_meta( $product_id, self::META_DIFF_PCT, (string) $diff );
		}

		/*
		 * پرچم‌های «ناسازگاری فید» و «واحد مشکوک» به‌صورت متا ذخیره می‌شوند، نه
		 * در لحظه‌ی نمایش: فیلتر جدول باید در SQL کار کند، وگرنه روی کاتالوگ
		 * بزرگ فقط صفحه‌ی جاری فیلتر می‌شد.
		 */
		$flags = self::price_flags(
			(float) $our_price,
			(float) $data['our_price_torob'] > 0 ? (float) $data['our_price_torob'] : null,
			(float) self::settings()['tolerance_pct']
		);

		update_post_meta( $product_id, self::META_FEED_MISMATCH, $flags['mismatch'] ? '1' : '0' );
		update_post_meta( $product_id, self::META_UNIT_SUSPECT, $flags['unit'] ? '1' : '0' );
	}

	/* ---------- دریافت داده (هسته) ---------- */

	/**
	 * پردازش دسته‌ای داده‌ی دریافتی از هر منبعی (بوکمارکلت/فید/API).
	 *
	 * @param array  $items  [{name, prk, sellers:[{shop_id, price, shop_name}], our_price?}]
	 * @param array  $meta   اطلاعات آماری منبع: pages, discovered, version
	 * @param string $mode   full = عنوان + قیمت رقبا | catalog = فقط «قیمت ما از دید ترب»
	 * @return array{matched:int,checked:int,unmatched:int,skipped:int,no_sellers:int,needs_shop_id:bool,ambiguous:int,source:string}
	 *
	 * حالت `catalog` برای همگام‌سازی از روی «لیست محصولات فروشگاه» در ترب است: در آن
	 * لیست `price` قیمت خودمان است و فروشنده‌ای در دست نیست. در این حالت:
	 *  - `META_OUR_PRICE` به‌روز می‌شود → ناسازگاری فید قابل تشخیص می‌شود،
	 *  - ولی `META_CHECKED` دست نمی‌خورد؛ وگرنه قیمت کهنه‌ی رقیب «تازه» جلوه می‌کرد
	 *    و مبنای تغییر خودکار قیمت می‌شد.
	 */
	public static function ingest( array $items, string $source = 'bookmarklet', array $meta = [], string $mode = 'full' ): array {
		$catalog_only = ( 'catalog' === $mode );
		$settings  = self::settings();
		$shop_id   = (int) self::get_shop_id();
		$shop_name = self::normalize( self::get_shop_name() );
		$checked   = current_time( 'mysql' );

		$stats = [
			'matched'       => 0,
			'checked'       => 0,
			'unmatched'     => 0,
			'skipped'       => 0,
			'no_sellers'    => 0, // ردیف‌هایی که داده‌ی فروشنده نداشتند (پاک نمی‌شوند)
			'ambiguous'     => 0,
			'needs_shop_id' => ! self::can_identify_shop(),
			'source'        => $source,
		];

		$items = array_slice( $items, 0, 500 ); // سقف امنیتی در هر درخواست.

		$discovered_catalog = isset( $meta['catalog_total'] ) ? (int) $meta['catalog_total'] : count( $items );
		$pages_catalog      = isset( $meta['pages'] ) ? (int) $meta['pages'] : 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				$stats['skipped']++;
				continue;
			}

			$name = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$prk  = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $item['prk'] ?? '' ) );

			// prk کلید اصلی است؛ طول‌های غیرمعمول رد می‌شوند.
			if ( '' === $prk || strlen( $prk ) < 4 || strlen( $prk ) > 80 ) {
				$stats['skipped']++;
				continue;
			}

			if ( '' === $name ) {
				// اگر prk از قبل وصل است، نام لازم نیست.
				$existing = self::find_product_by_prk( $prk );
				if ( ! $existing ) {
					$stats['skipped']++;
					continue;
				}
				$product_id = $existing;
				$how        = 'existing';
			} else {
				$match      = self::match_product( $name, $prk );
				$product_id = (int) $match['id'];
				$how        = (string) $match['how'];

				if ( ! $product_id ) {
					if ( ! empty( $match['ambiguous'] ) ) {
						$stats['ambiguous']++;
					}
					$unmatched_sellers = self::extract_sellers( $item['sellers'] ?? [] );
					self::add_unmatched( [
						'name'      => $name,
						'prk'       => $prk,
						'candidate' => (int) ( $match['candidate'] ?? 0 ),
						'score'     => (float) ( $match['score'] ?? 0 ),
						'how'       => $how,
						'sellers'   => $unmatched_sellers['count'],
						'price'     => self::cheapest_competitor( $unmatched_sellers, $shop_id, $shop_name )['competitor'],
						'seen'      => $checked,
					] );
					$stats['unmatched']++;
					continue;
				}
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$stats['skipped']++;
				continue;
			}

			if ( $product->is_type( 'variation' ) ) {
				// به‌جای ردِ بی‌صدا، کاربر باید بداند چرا وصل نمی‌شود و والد را
				// انتخاب کند.
				self::add_unmatched( [
					'name'      => $name,
					'prk'       => $prk,
					'candidate' => (int) $product->get_parent_id(),
					'score'     => 0.0,
					'how'       => 'variation',
					'sellers'   => 0,
					'price'     => null,
					'seen'      => $checked,
				] );
				$stats['unmatched']++;
				continue;
			}

			// اتصال دستی دست‌نخورده می‌ماند.
			$link_mode = (string) get_post_meta( $product_id, self::META_LINK, true );
			if ( 'manual' === $link_mode && $prk !== (string) get_post_meta( $product_id, self::META_PRK, true ) ) {
				$stats['skipped']++;
				continue;
			}

			update_post_meta( $product_id, self::META_PRK, $prk );
			if ( '' !== $name ) {
				update_post_meta( $product_id, self::META_NAME, $name );
			}
			if ( '' === $link_mode ) {
				update_post_meta( $product_id, self::META_LINK, 'auto' );
			}
			update_post_meta( $product_id, self::META_URL, 'https://torob.com/p/' . rawurlencode( $prk ) . '/' );
			update_post_meta( $product_id, self::META_SOURCE, $source );

			$stats['matched']++;

			// ---- حالت کاتالوگ: فقط «قیمت ما از دید ترب» ----
			if ( $catalog_only ) {
				if ( isset( $item['our_price'] ) && is_numeric( $item['our_price'] ) && (float) $item['our_price'] > 0 ) {
					// قیمت کارت «تخمینی» است (برداشت ما از معنی فیلد price در لیست
					// فروشگاه). قیمت قطعی از ردیف فروشنده‌ی خودمان می‌آید؛ پس آن را
					// با این مقدار بازنویسی نمی‌کنیم.
					update_post_meta( $product_id, self::META_CARD_PRICE, (string) (float) $item['our_price'] );

					$known = (string) get_post_meta( $product_id, self::META_OUR_PRICE, true );
					if ( '' === $known ) {
						update_post_meta( $product_id, self::META_OUR_PRICE, (string) (float) $item['our_price'] );
					}
				}
				update_post_meta( $product_id, self::META_OUR_CHECKED, $checked );
				continue;
			}

			// ---- حالت کامل ----
			$sellers = self::seller_payload( $item );
			if ( null === $sellers ) {
				$stats['no_sellers']++;
				continue;
			}

			$prices = self::cheapest_competitor( $sellers, $shop_id, $shop_name );

			update_post_meta( $product_id, self::META_CHECKED, $checked );

			$our_price = $prices['ours'];
			if ( null === $our_price && isset( $item['our_price'] ) && is_numeric( $item['our_price'] ) && (float) $item['our_price'] > 0 ) {
				$our_price = (float) $item['our_price'];
			}

			update_post_meta( $product_id, self::META_OUR_PRICE, null === $our_price ? '' : (string) $our_price );
			if ( null !== $our_price ) {
				update_post_meta( $product_id, self::META_OUR_CHECKED, $checked );
			}
			update_post_meta( $product_id, self::META_SELLERS, (string) $sellers['count'] );
			update_post_meta(
				$product_id,
				self::META_SELLERS_LIST,
				wp_json_encode( self::top_sellers_for_storage( $sellers['list'] ), JSON_UNESCAPED_UNICODE )
			);

			// اگر فروشگاه خودمان قابل شناسایی نیست، «ارزان‌ترین رقیب» ممکن است
			// خودمان باشیم → ذخیره نمی‌کنیم و وضعیت needs_shop_id می‌شود.
			if ( $prices['shop_known'] ) {
				update_post_meta( $product_id, self::META_PRICE, null === $prices['competitor'] ? '' : (string) $prices['competitor'] );
				self::push_price_point( $product_id, $prices['competitor'], $checked );
			} else {
				update_post_meta( $product_id, self::META_PRICE, '' );
			}

			self::refresh_diff_meta( $product_id );

			// «قیمت بررسی‌شده» = ردیفی که واقعاً داده‌ی فروشنده داشت.
			$stats['checked']++;
		}

		// حالت کاتالوگ، آمار «قیمت رقیب» را بازنویسی نمی‌کند.
		if ( $catalog_only ) {
			update_option(
				self::OPTION_LAST_CATALOG,
				[
					'at'      => $checked,
					'items'   => $discovered_catalog,
					'pages'   => $pages_catalog,
					'matched' => (int) $stats['matched'],
					'source'  => $source,
				],
				false
			);

			self::flush_index();
			return $stats;
		}

		// ثبت آمار همگام‌سازی + تشخیص تغییر ساختار صفحه‌ی ترب.
		$previous = self::get_last_sync();

		// هر همگام‌سازی بوکمارکلتی چند درخواست است (هر ۱۵ محصول یکی). اگر آمار
		// هر درخواست آمار قبلی را بازنویسی کند، کارت وضعیت فقط «آخرین دسته» را
		// نشان می‌دهد (مثلاً ۱۲ تطبیق از ۱۰۰۰ محصول). پس شمارنده‌ها داخل یک
		// «اجرا»ی مشخص (run) جمع می‌شوند.
		$run      = sanitize_text_field( (string) ( $meta['run'] ?? '' ) );
		$same_run = '' !== $run && $run === (string) ( $previous['run'] ?? '' );
		$carry    = static function ( string $key ) use ( $previous, $same_run ): int {
			return $same_run ? (int) ( $previous[ $key ] ?? 0 ) : 0;
		};

		$discovered    = isset( $meta['discovered'] ) ? (int) $meta['discovered'] : count( $items );
		$pages         = isset( $meta['pages'] ) ? (int) $meta['pages'] : 0;
		// اگر منبع صریحاً گزارش کند «صفحه باز شد ولی هیچ محصولی نداشت» یا صفحات
		// خوانده‌شده > ۰ و محصولی پیدا نشد، یعنی احتمالاً ساختار ترب عوض شده است.
		$structure_hit = $discovered <= 0 && ( ! empty( $meta['probe'] ) || $pages > 0 );

		update_option(
			self::OPTION_LAST_SYNC,
			[
				'at'                => $checked,
				'source'            => $source,
				'run'               => $run,
				'matched'           => $carry( 'matched' ) + (int) $stats['matched'],
				'checked'           => $carry( 'checked' ) + (int) $stats['checked'],
				'unmatched'         => $carry( 'unmatched' ) + (int) $stats['unmatched'],
				'ambiguous'         => $carry( 'ambiguous' ) + (int) $stats['ambiguous'],
				'skipped'           => $carry( 'skipped' ) + (int) $stats['skipped'],
				'no_sellers'        => $carry( 'no_sellers' ) + (int) $stats['no_sellers'],
				'discovered'        => $discovered,
				'pages'             => $pages,
				'version'           => sanitize_text_field( (string) ( $meta['version'] ?? '' ) ),
				'mode'              => $mode,
				// تا وقتی همگام‌سازیِ بعدی محصولی پیدا نکند، هشدار باقی می‌ماند.
				'structure_warning' => $structure_hit || ( ! empty( $previous['structure_warning'] ) && $discovered <= 0 ),
				'unmatched_total'   => count( self::unmatched() ),
			],
			false
		);

		self::flush_index();

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return $stats;
	}

	/** سازگاری با نسخه‌ی قبلی (بوکمارکلت‌های قدیمی همین اکشن را صدا می‌زنند). */
	public static function ingest_bookmarklet_batch( array $items ): array {
		return self::ingest( $items, 'bookmarklet' );
	}

	/**
	 * داده‌ی فروشنده‌های یک ردیف ورودی، یا null وقتی که قابل اعتماد نیست.
	 *
	 * چرا null و نه آرایه‌ی خالی: یک خطای موقت (بلاک شدن ترب، قطع شبکه، پاسخ
	 * ناقص) نباید «قیمت رقیب» را پاک کند و هم‌زمان زمان بررسی را «حالا» کند؛
	 * وگرنه محصول با برچسب «فقط ما در ترب» در فهرست می‌نشیند و داده‌ی سالم قبلی
	 * هم از دست می‌رود. آرایه‌ی خالی هم «هیچ فروشنده‌ای نبود» است، نه «نمی‌دانم»؛
	 * پس همان هم null می‌شود و داده‌ی قبلی دست‌نخورده می‌ماند و با گذشت زمان
	 * خودش «کهنه» می‌شود.
	 *
	 * @return array{count:int,list:array,hidden:int}|null
	 */
	public static function seller_payload( array $item ): ?array {
		if ( ! array_key_exists( 'sellers', $item ) ) {
			return null;
		}

		$sellers = self::extract_sellers( $item['sellers'] ?? [] );

		return $sellers['count'] > 0 ? $sellers : null;
	}

	/**
	 * نرمال‌سازی لیست فروشنده‌ها به شکل یکدست.
	 *
	 * @return array{count:int, list:array<int,array{shop_id:int,shop_name:string,price:float}>}
	 */
	public static function extract_sellers( $raw ): array {
		$out = [];

		$hidden = 0; // ناموجود یا بی‌قیمت: در شمارش نمایش داده نمی‌شود.

		foreach ( (array) $raw as $s ) {
			$available  = true;
			$unreliable = false;
			$last       = '';

			if ( is_array( $s ) ) {
				$shop_id   = (int) ( $s['shop_id'] ?? $s['shopId'] ?? 0 );
				$price     = (float) ( $s['price'] ?? 0 );
				$shop_name = (string) ( $s['shop_name'] ?? $s['seller_name'] ?? $s['name'] ?? '' );

				// پرچم‌هایی که از API ترب می‌آید (فیدهای قدیمی این‌ها را ندارند).
				$available  = ! array_key_exists( 'available', $s ) || self::to_bool( $s['available'] );
				$unreliable = self::to_bool( $s['unreliable'] ?? false ) || self::to_bool( $s['is_price_unreliable'] ?? false );
				$last       = (string) ( $s['last_change'] ?? $s['last_price_change_date'] ?? '' );
			} elseif ( is_numeric( $s ) ) {
				$shop_id   = 0;
				$price     = (float) $s;
				$shop_name = '';
			} else {
				continue;
			}

			// قیمت ناموجود مبنای مقایسه نیست.
			if ( ! $available || $price <= 0 ) {
				$hidden++;
				continue;
			}

			$out[] = [
				'shop_id'    => $shop_id,
				'shop_name'  => sanitize_text_field( $shop_name ),
				'price'      => $price,
				'unreliable' => $unreliable,
				'last'       => $last,
			];
		}

		usort( $out, fn( $a, $b ) => $a['price'] <=> $b['price'] );

		return [ 'count' => count( $out ), 'list' => $out, 'hidden' => $hidden ];
	}

	/**
	 * جداکردن قیمت خودمان از ارزان‌ترین قیمت رقیب.
	 *
	 * @param array{count:int,list:array} $sellers
	 * @return array{ours:?float, competitor:?float, shop_known:bool, others:int}
	 */
	public static function cheapest_competitor( array $sellers, int $shop_id, string $normalized_shop_name = '' ): array {
		$list       = $sellers['list'] ?? [];
		$ours       = null;
		$competitor = null;
		$others     = 0;
		$unreliable_skipped = 0;

		foreach ( $list as $seller ) {
			$is_ours = false;
			if ( $shop_id > 0 && (int) $seller['shop_id'] === $shop_id ) {
				$is_ours = true;
			} elseif ( '' !== $normalized_shop_name && '' !== $seller['shop_name']
				&& self::normalize( (string) $seller['shop_name'] ) === $normalized_shop_name ) {
				$is_ours = true;
			}

			if ( $is_ours ) {
				// اگر چند رکورد برای ما باشد، کمترین قیمت خودمان (آنچه ترب نشان می‌دهد).
				$ours = null === $ours ? (float) $seller['price'] : min( $ours, (float) $seller['price'] );
				continue;
			}

			$others++;

			// خودِ ترب بعضی قیمت‌ها را «طعمه/کهنه» علامت می‌زند؛ مبنای تصمیم
			// قیمت‌گذاری نمی‌شوند، وگرنه قیمت ما را دنبال یک عدد بی‌اعتبار می‌بردیم.
			if ( ! empty( $seller['unreliable'] ) ) {
				$unreliable_skipped++;
				continue;
			}

			if ( null === $competitor || (float) $seller['price'] < $competitor ) {
				$competitor = (float) $seller['price'];
			}
		}

		$shop_known = ( $shop_id > 0 || '' !== $normalized_shop_name );

		return [
			'ours'               => $ours,
			'competitor'         => $shop_known ? $competitor : null,
			'shop_known'         => $shop_known,
			'others'             => $others,
			'unreliable_skipped' => $unreliable_skipped,
		];
	}

	private static function top_sellers_for_storage( array $list ): array {
		return array_slice( array_map( fn( $s ) => [
			'shop_id'    => (int) $s['shop_id'],
			'price'      => (float) $s['price'],
			'shop_name'  => (string) $s['shop_name'],
			'unreliable' => ! empty( $s['unreliable'] ),
		], $list ), 0, self::MAX_SELLERS_STORED );
	}

	/* ---------- لیست تطبیق‌نیافته ---------- */

	public static function unmatched(): array {
		$list = get_option( self::OPTION_UNMATCHED, [] );
		return is_array( $list ) ? $list : [];
	}

	private static function add_unmatched( array $entry ): void {
		$list = self::unmatched();
		$key  = $entry['prk'];
		$now  = (int) strtotime( (string) $entry['seen'] );

		foreach ( $list as $i => $existing ) {
			if ( $existing['prk'] === $key ) {
				$entry['first_seen'] = $existing['first_seen'] ?? ( $existing['seen'] ?? '' );
				$list[ $i ]          = array_merge( $existing, $entry );
				update_option( self::OPTION_UNMATCHED, $list, false );
				return;
			}
		}

		$entry['first_seen'] = $entry['seen'];

		$list[] = $entry;

		// قدیمی‌ترها حذف می‌شوند (سقف حجم آپشن).
		if ( count( $list ) > self::MAX_UNMATCHED ) {
			usort( $list, fn( $a, $b ) => (int) strtotime( (string) $b['seen'] ) <=> (int) strtotime( (string) $a['seen'] ) );
			$list = array_slice( $list, 0, self::MAX_UNMATCHED );
		}

		update_option( self::OPTION_UNMATCHED, $list, false );
	}

	public static function remove_unmatched_by_prk( string $prk ): void {
		self::remove_unmatched_by_prks( [ $prk ] );
	}

	/**
	 * حذف چند ردیف با یک نوشتنِ آپشن (صف تأیید تطبیق «چند مورد را رد کن»).
	 *
	 * @param string[] $prks
	 * @return int تعداد ردیف‌های حذف‌شده
	 */
	public static function remove_unmatched_by_prks( array $prks ): int {
		$prks = array_values( array_unique( array_filter( array_map(
			static fn( $p ) => preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $p ),
			$prks
		) ) ) );

		if ( ! $prks ) {
			return 0;
		}

		$before = self::unmatched();
		$set    = array_flip( $prks );
		$after  = array_values( array_filter(
			$before,
			static fn( $r ) => ! isset( $set[ (string) ( $r['prk'] ?? '' ) ] )
		) );

		$removed = count( $before ) - count( $after );
		if ( $removed > 0 ) {
			update_option( self::OPTION_UNMATCHED, $after, false );
		}

		return $removed;
	}

	/**
	 * فیلتر/جستجوی خالص روی لیست تطبیق‌نیافته‌ها (بدون دیتابیس — قابل تست).
	 *
	 * بدون فیلتر، این لیست فقط یک انبار است: کاربر مجبور است ۵۰۰ ردیف را با
	 * چشم بگردد. این تابع همان کاری را می‌کند که «صف تأیید» لازم دارد:
	 * جدا کردن ردیف‌های دارای کاندید، جستجو روی نام/prk و مرتب‌سازی.
	 *
	 * @param array  $list  خروجی unmatched()
	 * @param string $q     جستجو (نام یا prk)
	 * @param string $only  all|candidate|no_candidate|ambiguous|variation|occupied|weak
	 * @param string $sort  newest|oldest|score
	 * @return array{rows:array,counts:array,total:int}
	 */
	public static function filter_unmatched( array $list, string $q = '', string $only = 'all', string $sort = 'newest' ): array {
		$q     = trim( $q );
		$q_norm = '' !== $q ? self::normalize( $q ) : '';
		$q_prk = preg_replace( '/[^A-Za-z0-9_-]/', '', $q );
		$only  = in_array( $only, [ 'all', 'candidate', 'no_candidate', 'ambiguous', 'variation', 'occupied', 'weak' ], true ) ? $only : 'all';

		$counts = [ 'all' => 0, 'candidate' => 0, 'no_candidate' => 0, 'variation' => 0, 'occupied' => 0 ];
		$rows   = [];

		foreach ( $list as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$counts['all']++;
			$candidate = (int) ( $row['candidate'] ?? 0 );
			$how       = (string) ( $row['how'] ?? '' );

			if ( $candidate > 0 ) {
				$counts['candidate']++;
			} else {
				$counts['no_candidate']++;
			}
			if ( 'variation' === $how ) {
				$counts['variation']++;
			}
			if ( 'occupied' === $how ) {
				$counts['occupied']++;
			}

			if ( 'candidate' === $only && $candidate < 1 ) {
				continue;
			}
			if ( 'no_candidate' === $only && $candidate > 0 ) {
				continue;
			}
			if ( in_array( $only, [ 'ambiguous', 'variation', 'occupied', 'weak' ], true ) && $how !== $only ) {
				continue;
			}

			if ( '' !== $q ) {
				$hit = false;
				if ( '' !== $q_prk && false !== strpos( (string) ( $row['prk'] ?? '' ), $q_prk ) ) {
					$hit = true;
				}
				if ( ! $hit && '' !== $q_norm && '' !== self::normalize( (string) ( $row['name'] ?? '' ) ) ) {
					$hit = WAPM_Str::contains( self::normalize( (string) $row['name'] ), $q_norm );
				}
				if ( ! $hit ) {
					continue;
				}
			}

			$rows[] = $row;
		}

		$ts = static fn( $r ) => (int) strtotime( (string) ( $r['seen'] ?? '' ) );
		if ( 'score' === $sort ) {
			usort( $rows, static fn( $a, $b ) => (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 ) );
		} elseif ( 'oldest' === $sort ) {
			usort( $rows, static fn( $a, $b ) => $ts( $a ) <=> $ts( $b ) );
		} else {
			usort( $rows, static fn( $a, $b ) => $ts( $b ) <=> $ts( $a ) );
		}

		return [ 'rows' => $rows, 'counts' => $counts, 'total' => count( $rows ) ];
	}

	/**
	 * برچسب فارسیِ دلیل تطبیق‌نشدن (در PHP تا JS و API یک زبان داشته باشند).
	 */
	public static function match_reason_label( array $row ): string {
		$candidate = (int) ( $row['candidate'] ?? 0 );
		$score     = (float) ( $row['score'] ?? 0 );
		$how       = (string) ( $row['how'] ?? '' );

		if ( 'variation' === $how ) {
			return __( 'واریشن هم‌نام شد — ترب در سطح والد مقایسه می‌کند', 'wapm' );
		}
		if ( 'occupied' === $how ) {
			return __( 'همین عنوان در ووکامرس به محصول دیگری در ترب وصل است', 'wapm' );
		}
		if ( 'no_match' === $how ) {
			return __( 'هیچ محصول مشابهی پیدا نشد', 'wapm' );
		}
		if ( 'sku_mismatch' === $how ) {
			return __( 'کد/SKU پیدا شد ولی نام نمی‌خواند', 'wapm' );
		}
		if ( $candidate > 0 ) {
			return sprintf(
				/* translators: %s: similarity percentage */
				__( 'نام مشابه چند محصول — شباهت %s٪', 'wapm' ),
				number_format_i18n( $score > 0 ? round( $score * 100 ) : 0 )
			);
		}

		return __( 'به‌طور خودکار تطبیق نخورد', 'wapm' );
	}

	public static function clear_unmatched(): void {
		update_option( self::OPTION_UNMATCHED, [], false );
	}

	/* ---------- داده‌ی نمایش ---------- */

	/**
	 * داده‌ی ترب برای یک ردیف جدول (شامل وضعیت، اختلاف و اطلاعات تازگی).
	 *
	 * @param float|null $our_price قیمت مؤثر فعلی فروشگاه (برای محاسبه‌ی اختلاف)
	 */
	/**
	 * ثبت یک نقطه‌ی قیمت رقیب در تاریخچه‌ی محصول.
	 *
	 * چرا فقط «تغییر»ها: هر همگام‌سازی می‌تواند هر ساعت اجرا شود؛ اگر هر بررسی
	 * یک نقطه بنویسد، ۳۰ نقطه یعنی ۳۰ ساعت و روند واقعی دیده نمی‌شود. با ثبت
	 * فقط تغییرها، تاریخچه یعنی «تاریخ تغییر قیمت رقیب».
	 *
	 * @param string $at زمان محلی سایت (همان قالب META_CHECKED)
	 */
	public static function push_price_point( int $product_id, ?float $competitor, string $at ): void {
		if ( null === $competitor || $competitor <= 0 || '' === $at ) {
			return;
		}

		$history = self::get_price_history( $product_id );
		$last    = $history ? $history[ count( $history ) - 1 ] : null;

		if ( null !== $last && abs( (float) $last['p'] - $competitor ) < 0.01 ) {
			return;
		}

		$history[] = [ 't' => $at, 'p' => (float) $competitor ];

		if ( count( $history ) > self::HISTORY_POINTS ) {
			$history = array_slice( $history, -self::HISTORY_POINTS );
		}

		update_post_meta( $product_id, self::META_PRICE_HISTORY, wp_json_encode( $history, JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * تاریخچه‌ی قیمت رقیب (قدیمی → جدید).
	 *
	 * @return array<int, array{t:string,p:float}>
	 */
	public static function get_price_history( int $product_id ): array {
		$raw = json_decode( (string) get_post_meta( $product_id, self::META_PRICE_HISTORY, true ), true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( $raw as $point ) {
			if ( ! is_array( $point ) || ! isset( $point['t'], $point['p'] ) ) {
				continue;
			}
			$out[] = [ 't' => (string) $point['t'], 'p' => (float) $point['p'] ];
		}

		return $out;
	}

	/**
	 * خلاصه‌ی حرکت قیمت رقیب در یک بازه (پیش‌فرض ۷ روز).
	 *
	 * مبنای مقایسه، نقطه‌ای است که در ابتدای بازه «آخرین قیمت معلوم» بوده؛ اگر
	 * تاریخچه از بازه کوتاه‌تر باشد، اولین نقطه‌ی موجود. اختلاف دو زمان محلی با
	 * strtotime گرفته می‌شود؛ چون هر دو یک مبنای زمانی دارند، تفاضل درست است.
	 *
	 * @param array<int, array{t:string,p:float}> $history
	 * @return array{from:float,to:float,pct:float,days:int}|null
	 */
	public static function history_change( array $history, int $window_days = 7 ): ?array {
		if ( count( $history ) < 2 ) {
			return null;
		}

		$to     = $history[ count( $history ) - 1 ];
		$to_ts  = (int) strtotime( $to['t'] );
		$cutoff = $to_ts - ( max( 1, $window_days ) * DAY_IN_SECONDS );

		$base = $history[0];
		foreach ( $history as $point ) {
			if ( (int) strtotime( $point['t'] ) <= $cutoff ) {
				$base = $point;
			} else {
				break;
			}
		}

		if ( $base['p'] <= 0 || $base['p'] === $to['p'] ) {
			return null;
		}

		return [
			'from' => (float) $base['p'],
			'to'   => (float) $to['p'],
			'pct'  => round( ( ( $to['p'] - $base['p'] ) / $base['p'] ) * 100, 1 ),
			'days' => max( 0, (int) floor( ( $to_ts - (int) strtotime( $base['t'] ) ) / DAY_IN_SECONDS ) ),
		];
	}

	public static function get_row_data( int $product_id, ?float $our_price = null ): ?array {
		$prk = (string) get_post_meta( $product_id, self::META_PRK, true );
		if ( '' === $prk ) {
			return null;
		}

		$settings   = self::settings();
		$competitor = get_post_meta( $product_id, self::META_PRICE, true );
		$competitor = ( '' === $competitor || null === $competitor ) ? null : (float) $competitor;
		$our_torob  = get_post_meta( $product_id, self::META_OUR_PRICE, true );
		$our_torob  = ( '' === $our_torob || null === $our_torob ) ? null : (float) $our_torob;

		// منبع «قیمت ما از دید ترب»: ردیف فروشنده‌ی خودمان (دقیق) یا کارت لیست
		// فروشگاه (تخمینی). تفکیکشان برای صداقت نمایش لازم است.
		$card_price = get_post_meta( $product_id, self::META_CARD_PRICE, true );
		$card_price = ( '' === $card_price || null === $card_price ) ? null : (float) $card_price;
		$our_source = 'none';
		if ( null !== $our_torob ) {
			$our_source = ( null !== $card_price && abs( $card_price - $our_torob ) < 0.01 ) ? 'both' : 'seller';
		} elseif ( null !== $card_price ) {
			$our_source = 'card';
		}
		$checked    = (string) get_post_meta( $product_id, self::META_CHECKED, true );

		if ( null === $our_price ) {
			$product    = wc_get_product( $product_id );
			$our_price  = $product ? (float) $product->get_price() : 0.0;
		}
		$our_price = (float) $our_price;

		$status = self::compute_status(
			[
				'has_data'         => true,
				'competitor_price' => $competitor,
				'our_price_torob'  => $our_torob,
				'checked_at'       => $checked,
				'shop_known'       => self::can_identify_shop(),
			],
			$our_price,
			$settings
		);

		$diff      = self::diff_pct( $our_price, $competitor );
		$checked_ts = self::local_to_timestamp( $checked );
		$age_days   = $checked_ts ? (int) floor( ( time() - $checked_ts ) / DAY_IN_SECONDS ) : null;

		// ناسازگاری فید: قیمتی که ترب از ما نشان می‌دهد با قیمت ووکامرس یکی نیست.
		$tolerance    = (float) $settings['tolerance_pct'];
		$flags        = self::price_flags( $our_price, $our_torob, $tolerance );
		$mismatch_pct = null;
		if ( null !== $our_torob && $our_torob > 0 && $our_price > 0 ) {
			$mismatch_pct = round( ( ( $our_price - $our_torob ) / $our_torob ) * 100, 2 );
		}

		$sellers_list = json_decode( (string) get_post_meta( $product_id, self::META_SELLERS_LIST, true ), true );

		// کاتالوگ (قیمت ما از دید ترب) جدا از داده‌ی رقیب تازه‌سازی می‌شود.
		$our_checked  = (string) get_post_meta( $product_id, self::META_OUR_CHECKED, true );
		$our_checked_ts = self::local_to_timestamp( $our_checked );
		$our_age_days   = $our_checked_ts ? (int) floor( ( time() - $our_checked_ts ) / DAY_IN_SECONDS ) : null;

		// محافظ واحد: اگر قیمت ترب و ووکامرس نسبت ~۱۰ داشته باشند، یا واحد فرق
		// دارد یا داده اشتباه است. فقط هشدار نمایشی — هیچ تغییر خودکاری نمی‌شود.
		$unit_suspect = $flags['unit'];

		// تاریخچه‌ی قیمت رقیب — فقط ۳۰ نقطه‌ی آخر، برای نمایش روند در لیست/کشو.
		$history = self::get_price_history( $product_id );

		return [
			'prk'            => $prk,
			'url'            => (string) get_post_meta( $product_id, self::META_URL, true ),
			'name'           => (string) get_post_meta( $product_id, self::META_NAME, true ),
			'competitor_price' => $competitor,
			'our_price_torob'  => $our_torob,
			'card_price'       => $card_price,
			'our_source'       => $our_source,
			'our_price_wc'     => $our_price,
			'sellers'        => (int) get_post_meta( $product_id, self::META_SELLERS, true ),
			'sellers_list'   => is_array( $sellers_list ) ? $sellers_list : [],
			'checked_at'     => $checked,
			'age_days'       => $age_days,
			'our_checked_at' => $our_checked,
			'our_age_days'   => $our_age_days,
			'unit_suspect'   => $unit_suspect,
			'fresh_days'     => (int) $settings['fresh_days'],
			'status'         => $status,
			'status_label'   => self::status_label( $status ),
			'diff_pct'       => $diff,
			'mismatch_pct'   => $mismatch_pct,
			'tolerance_pct'  => $tolerance,
			'feed_mismatch'  => $flags['mismatch'],
			'source'         => (string) get_post_meta( $product_id, self::META_SOURCE, true ),
			'link'           => (string) get_post_meta( $product_id, self::META_LINK, true ),
			'history'        => $history,
			'history_change' => self::history_change( $history ),
		];
	}

	/**
	 * مرجع قیمت ترب برای قیمت‌گذاری خودکار.
	 *
	 * @param int $max_age_days 0 = مقدار پیش‌فرض تنظیمات
	 * @return array{competitor_min:float,checked_at:string,age_days:int,prk:string}|null
	 */
	public static function get_target_reference( int $product_id, int $max_age_days = 0 ): ?array {
		if ( ! self::can_identify_shop() ) {
			return null;
		}

		$prk = (string) get_post_meta( $product_id, self::META_PRK, true );
		if ( '' === $prk ) {
			return null;
		}

		$competitor = get_post_meta( $product_id, self::META_PRICE, true );
		if ( '' === $competitor || ! is_numeric( $competitor ) || (float) $competitor <= 0 ) {
			return null;
		}

		$checked    = (string) get_post_meta( $product_id, self::META_CHECKED, true );
		$checked_ts = self::local_to_timestamp( $checked );
		if ( ! $checked_ts ) {
			return null;
		}

		$max_age = $max_age_days > 0 ? $max_age_days : (int) self::settings()['fresh_days'];
		$age     = (int) floor( ( time() - $checked_ts ) / DAY_IN_SECONDS );

		if ( $age > $max_age ) {
			return null; // داده‌ی کهنه مبنای تغییر قیمت نمی‌شود.
		}

		return [
			'competitor_min' => (float) $competitor,
			'checked_at'     => $checked,
			'age_days'       => $age,
			'prk'            => $prk,
		];
	}

	/* ---------- همگام‌سازی از API ترب ---------- */

	/**
	 * یک صفحه از کاتالوگ فروشگاهِ خودمان را از API ترب می‌خواند.
	 *
	 * این تنها راه «قیمت ما از دید ترب» است: در لیست محصولات فروشگاه، فیلد
	 * `price` همان قیمتی است که ترب از فروشگاه ما نشان می‌دهد (buy box).
	 *
	 * @return array|WP_Error ['page'=>int,'has_more'=>bool,'count'=>int,'items'=>int,'stats'=>array]
	 */
	public static function sync_catalog_page( int $page = 0, int $size = 24 ) {
		$shop_id = (int) self::get_shop_id();
		if ( $shop_id <= 0 ) {
			return new WP_Error( 'torob_shop_id', __( 'اول شناسه‌ی فروشگاه در ترب را تنظیم کنید.', 'wapm' ) );
		}
		if ( ! class_exists( 'WAPM_Torob_Api' ) ) {
			return new WP_Error( 'torob_api', __( 'کلاینت API ترب بارگذاری نشده است.', 'wapm' ) );
		}

		$res = WAPM_Torob_Api::shop_products( $shop_id, $page, $size );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$items = [];
		foreach ( $res['items'] as $card ) {
			$items[] = [
				'prk'       => $card['prk'],
				'name'      => $card['name'],
				'our_price' => $card['price'],
				'sellers'   => [],
			];
		}

		$stats = self::ingest(
			$items,
			'api',
			[
				'discovered'    => count( $items ),
				'catalog_total' => (int) $res['count'],
				'pages'         => $page + 1,
				'version'       => '3.0',
			],
			'catalog'
		);

		return [
			'page'     => (int) $page,
			'has_more' => (bool) $res['has_more'],
			'count'    => (int) $res['count'],
			'items'    => count( $items ),
			'stats'    => $stats,
		];
	}

	/**
	 * بررسی فوری یک محصول از API (۱ درخواست): همه‌ی فروشنده‌ها، ارزان‌ترین رقیب.
	 *
	 * @return array|WP_Error
	 */
	public static function refresh_product( int $product_id ) {
		$prk = (string) get_post_meta( $product_id, self::META_PRK, true );
		if ( '' === $prk ) {
			return new WP_Error( 'torob_prk', __( 'این محصول به ترب وصل نیست؛ اول از لیست «تطبیق‌نیافته‌ها» یا با همگام‌سازی کاتالوگ وصلش کنید.', 'wapm' ) );
		}
		if ( ! class_exists( 'WAPM_Torob_Api' ) ) {
			return new WP_Error( 'torob_api', __( 'کلاینت API ترب بارگذاری نشده است.', 'wapm' ) );
		}

		$res = WAPM_Torob_Api::product_sellers( $prk );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 0 === $res['count'] ) {
			return new WP_Error( 'torob_empty', __( 'ترب برای این محصول فروشنده‌ای برنگرداند.', 'wapm' ) );
		}

		$name = (string) get_post_meta( $product_id, self::META_NAME, true );
		if ( '' === $name ) {
			$name = (string) get_the_title( $product_id );
		}

		$stats = self::ingest(
			[
				[
					'prk'     => $prk,
					'name'    => $name,
					'sellers' => $res['sellers'],
				],
			],
			'api',
			[ 'discovered' => 1, 'pages' => 1, 'version' => '3.0' ]
		);

		return [
			'stats'   => $stats,
			'sellers' => $res['count'],
			'row'     => self::get_row_data( $product_id ),
		];
	}

	/**
	 * تأیید شناسه‌ی فروشگاه با API و ذخیره‌ی نام/وضعیت آن.
	 *
	 * @return array|WP_Error
	 */
	public static function verify_shop( int $shop_id ) {
		if ( ! class_exists( 'WAPM_Torob_Api' ) ) {
			return new WP_Error( 'torob_api', __( 'کلاینت API ترب بارگذاری نشده است.', 'wapm' ) );
		}

		$info = WAPM_Torob_Api::shop_details( $shop_id );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		self::save_shop_id( (string) $shop_id );
		if ( '' !== $info['name'] && '' === trim( self::get_shop_name() ) ) {
			self::save_settings( [ 'shop_name' => $info['name'] ] );
		}

		if ( $info['blocked'] ) {
			return new WP_Error(
				'torob_shop_blocked',
				sprintf(
					/* translators: 1: shop name, 2: block note */
					__( 'فروشگاه «%1$s» در ترب فعال نیست (%2$s). تا فعال‌شدن، محصولات شما در ترب نمایش داده نمی‌شوند و مقایسه معنی ندارد.', 'wapm' ),
					$info['name'],
					$info['block_note']
				)
			);
		}

		return $info;
	}

	/* ---------- فید CSV ---------- */

	/**
	 * ورود داده از فایل CSV (خروجی پنل ترب یا هر منبعی).
	 * ستون‌های شناسایی‌شده: prk / name / price / shop_id / shop_name / our_price
	 *
	 * `price` می‌تواند قیمت «قیمت رقیب» باشد؛ اگر `shop_id`/`our_price` ستون داشته
	 * باشد، همان‌ها استفاده می‌شوند.
	 *
	 * دو حالت جدا پردازش می‌شود:
	 *  - فایل ستون قیمت رقیب دارد → حالت `full`؛ ردیف‌هایی که قیمت ندارند کلید
	 *    `sellers` را هم ندارند تا داده‌ی قبلی پاک نشود.
	 *  - فایل فقط قیمت خودمان دارد → حالت `catalog` (فقط `META_CARD_PRICE`/
	 *    `META_OUR_PRICE`؛ قیمت رقیب و زمان بررسی دست‌نخورده می‌ماند).
	 *
	 * @return array{items:int,stats:array,mode:string,warn_no_shop:bool,error?:string}
	 */
	public static function import_csv( string $tmp_path ): array {
		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return [ 'items' => 0, 'stats' => [], 'error' => __( 'خطا در خواندن فایل.', 'wapm' ) ];
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			return [ 'items' => 0, 'stats' => [], 'error' => __( 'فایل خالی است.', 'wapm' ) ];
		}

		$header    = array_map( fn( $h ) => strtolower( trim( str_replace( "\xEF\xBB\xBF", '', (string) $h ) ) ), $header );
		$col       = array_flip( $header );
		$col_prk   = $col['prk'] ?? $col['random_key'] ?? $col['torob_id'] ?? null;
		$col_name  = $col['name'] ?? $col['title'] ?? $col['product_name'] ?? null;
		$col_price = $col['price'] ?? $col['competitor_price'] ?? null;
		$col_ours  = $col['our_price'] ?? $col['my_price'] ?? null;
		$col_shop  = $col['shop_id'] ?? null;
		$col_shopname = $col['shop_name'] ?? null;

		if ( null === $col_prk ) {
			fclose( $handle );
			return [ 'items' => 0, 'stats' => [], 'mode' => 'full', 'warn_no_shop' => false, 'error' => __( 'ستون prk در فایل پیدا نشد.', 'wapm' ) ];
		}

		if ( null === $col_price && null === $col_ours ) {
			fclose( $handle );
			return [ 'items' => 0, 'stats' => [], 'mode' => 'full', 'warn_no_shop' => false, 'error' => __( 'نه ستون قیمت رقیب (price) در فایل هست و نه قیمت خودمان (our_price).', 'wapm' ) ];
		}

		// بدون ستون قیمت رقیب، این فایل فقط «قیمت ما از دید ترب» را می‌آورد.
		$mode         = null === $col_price ? 'catalog' : 'full';
		$warn_no_shop = null === $col_shop && null === $col_shopname;

		$rows = [];
		while ( ( $row = fgetcsv( $handle ) ) !== false && count( $rows ) < 5000 ) {
			$prk  = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $row[ $col_prk ] ?? '' ) );
			$name = null !== $col_name ? sanitize_text_field( (string) ( $row[ $col_name ] ?? '' ) ) : '';
			if ( '' === $prk ) {
				continue;
			}

			$item = [ 'prk' => $prk, 'name' => $name ];

			if ( null !== $col_price ) {
				$price = (float) str_replace( [ ',', '،' ], '', (string) ( $row[ $col_price ] ?? '' ) );
				if ( $price > 0 ) {
					// کلید sellers فقط وقتی ساخته می‌شود که قیمتی در کار باشد؛
					// ردیفِ بی‌قیمت یعنی «داده‌ی رقیب نداریم» نه «قیمت را پاک کن».
					$item['sellers'] = [
						[
							'shop_id'   => null !== $col_shop ? (int) ( $row[ $col_shop ] ?? 0 ) : 0,
							'shop_name' => null !== $col_shopname ? (string) ( $row[ $col_shopname ] ?? '' ) : '',
							'price'     => $price,
						],
					];
				}
			}

			if ( null !== $col_ours ) {
				$ours = (float) str_replace( [ ',', '،' ], '', (string) ( $row[ $col_ours ] ?? '' ) );
				if ( $ours > 0 ) {
					$item['our_price'] = $ours;
				}
			}

			$rows[] = $item;
		}
		fclose( $handle );

		$stats = self::ingest( $rows, 'feed', [ 'discovered' => count( $rows ) ], $mode );

		return [
			'items'         => count( $rows ),
			'stats'         => $stats,
			'mode'          => $mode,
			'warn_no_shop'  => $warn_no_shop,
		];
	}
}
