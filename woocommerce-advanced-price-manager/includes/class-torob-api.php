<?php
/**
 * WAPM_Torob_Api — کلاینت API عمومی ترب (api.torob.com/v4).
 *
 * چرا این فایل وجود دارد: نسخه‌ی اول ترب، صفحه‌ی HTML را با بوکمارکلت می‌خواند و
 * داده را از `__NEXT_DATA__` بیرون می‌کشید. خودِ سایت ترب همه‌ی داده‌اش را از این
 * API عمومی JSON می‌گیرد؛ ما هم همان را می‌خوانیم: قرارداد روشن، بدون پارس HTML.
 *
 * ## محدودیت واقعی (و چرا این‌طور نوشته شده)
 *
 * ترب درخواست‌های پرشمار را با پاسخ ۴۹۰ و صفحه‌ی ArCaptcha بلاک می‌کند
 * (بر اساس تجربه: حدود ۶ تا ۱۰ درخواست در چند دقیقه از یک IP). پس:
 *
 * - **سرور**: فقط درخواست‌های کم‌حجم (کاتالوگ فروشگاه = ۱ درخواست/۲۴ محصول،
 *   بررسی فوری یک محصول). با فاصله‌ی حداقلی، بودجه‌ی روزانه و کول‌داون بعد از ۴۹۰.
 * - **مرورگر (بوکمارکلت)**: حجم بالا (قیمت رقبای همه‌ی محصولات) از IP خودِ کاربر و
 *   روی دامنه‌ی خود ترب.
 *
 * هیچ‌جا تلاش دوباره‌ی خودکار نوشته نشده: تلاش دوباره بعد از ۴۹۰ فقط کول‌داون را
 * طولانی‌تر می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Torob_Api {

	const BASE = 'https://api.torob.com';

	const OPT_COOLDOWN  = 'wapm_torob_api_cooldown';   // timestamp پایان کول‌داون
	const OPT_LAST_CALL = 'wapm_torob_api_last_call';  // timestamp آخرین درخواست
	const OPT_BUDGET    = 'wapm_torob_api_budget';     // ['date' => Y-m-d, 'count' => n]

	/** فاصله‌ی حداقلی بین دو درخواست سرور (ثانیه). */
	const MIN_INTERVAL = 3;

	/** سقف درخواست سرور در هر روز. */
	const DAILY_BUDGET = 60;

	/** مدت کول‌داون بعد از بلاک شدن (ثانیه). */
	const BLOCK_COOLDOWN = 1800;

	/** حداکثر محصول در هر صفحه‌ی کاتالوگ. */
	const PAGE_SIZE = 24;

	/* ---------- وضعیت/بودجه ---------- */

	/**
	 * وضعیت فعلی کلاینت: بلاک بودن، بودجه‌ی باقی‌مانده، آخرین تماس.
	 *
	 * @return array{blocked:bool,minutes_left:int,used:int,budget:int,left:int,last_call:int,min_interval:int}
	 */
	public static function state(): array {
		$until = (int) get_option( self::OPT_COOLDOWN, 0 );
		$left  = $until - time();

		$budget = get_option( self::OPT_BUDGET, [] );
		$today  = gmdate( 'Y-m-d' );
		if ( ! is_array( $budget ) || ( $budget['date'] ?? '' ) !== $today ) {
			$budget = [ 'date' => $today, 'count' => 0 ];
		}

		$used = (int) ( $budget['count'] ?? 0 );

		// فیلتر فقط یک بار خوانده می‌شود؛ وگرنه اگر پیاده‌سازی فیلتر پویا باشد
		// (شمارنده/زمان)، «سقف» و «باقی‌مانده» در یک پاسخ با هم نمی‌خواندند.
		$max = self::daily_budget();

		return [
			'blocked'      => $left > 0,
			'minutes_left' => $left > 0 ? (int) ceil( $left / 60 ) : 0,
			'used'         => $used,
			'budget'       => $max,
			'left'         => max( 0, $max - $used ),
			'last_call'    => (int) get_option( self::OPT_LAST_CALL, 0 ),
			'min_interval' => self::min_interval(),
		];
	}

	/** سقف درخواست روزانه (قابل تغییر با فیلتر). */
	public static function daily_budget(): int {
		return max( 1, (int) apply_filters( 'wapm_torob_api_daily_budget', self::DAILY_BUDGET ) );
	}

	private static function min_interval(): int {
		return max( 1, (int) apply_filters( 'wapm_torob_api_min_interval', self::MIN_INTERVAL ) );
	}

	private static function set_cooldown( int $seconds ): void {
		update_option( self::OPT_COOLDOWN, time() + max( 60, $seconds ), false );
	}

	private static function bump_budget(): void {
		$today  = gmdate( 'Y-m-d' );
		$budget = get_option( self::OPT_BUDGET, [] );
		if ( ! is_array( $budget ) || ( $budget['date'] ?? '' ) !== $today ) {
			$budget = [ 'date' => $today, 'count' => 0 ];
		}
		$budget['count'] = (int) ( $budget['count'] ?? 0 ) + 1;
		update_option( self::OPT_BUDGET, $budget, false );
	}

	/** صفر کردن دستی کول‌داون (دکمه‌ی «تلاش دوباره» در UI). */
	public static function clear_cooldown(): void {
		delete_option( self::OPT_COOLDOWN );
	}

	/** صفر کردن شمارنده‌ی بودجه (فقط برای تست/پشتیبانی). */
	public static function reset_budget(): void {
		delete_option( self::OPT_BUDGET );
	}

	/* ---------- درخواست ---------- */

	/**
	 * یک درخواست GET به API ترب با رعایت فاصله/بودجه/کول‌داون.
	 *
	 * @param string $path   مثل '/v4/internet-shop/base-product/list/'
	 * @param array  $params پارامترهای query
	 * @return array|WP_Error پاسخ JSON یا خطای روشن
	 */
	public static function request( string $path, array $params = [] ) {
		$state = self::state();

		if ( $state['blocked'] ) {
			return new WP_Error(
				'torob_blocked',
				sprintf(
					/* translators: %d: minutes */
					__( 'ترب درخواست‌های این سرور را موقتاً محدود کرده است. حدود %d دقیقه دیگر تلاش کنید (تلاش زودتر، زمان بلاک را طولانی‌تر می‌کند).', 'wapm' ),
					$state['minutes_left']
				)
			);
		}

		if ( $state['left'] <= 0 ) {
			return new WP_Error(
				'torob_budget',
				sprintf(
					/* translators: %d: daily budget */
					__( 'سقف درخواست‌های روزانه‌ی ترب (%d) پر شده است. برای مقایسه‌ی انبوه از بوکمارکلت استفاده کنید؛ آن از مرورگر خودتان کار می‌کند.', 'wapm' ),
					$state['budget']
				)
			);
		}

		// فاصله‌ی حداقلی: ترب به نرخ درخواست حساس است.
		$elapsed = time() - (int) $state['last_call'];
		$wait    = self::min_interval() - $elapsed;
		if ( $wait > 0 ) {
			sleep( (int) $wait );
		}

		$url = add_query_arg(
			array_merge( [ 'source' => 'next_desktop' ], $params ),
			self::BASE . $path
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => 20,
				'redirection' => 2,
				'headers'     => self::headers(),
				'user-agent'  => self::user_agent(),
			]
		);

		update_option( self::OPT_LAST_CALL, time(), false );
		self::bump_budget();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		// ۴۹۰ = صفحه‌ی ArCaptcha ترب؛ ۴۰۳/۴۲۹ هم عملاً همان معنی را دارند.
		if ( in_array( $code, [ 490, 403, 429 ], true ) ) {
			self::set_cooldown( self::BLOCK_COOLDOWN );
			return new WP_Error(
				'torob_blocked',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'ترب درخواست را بلاک کرد (کد %d). ۳۰ دقیقه صبر کنید؛ افزونه تا آن زمان درخواستی به ترب نمی‌فرستد.', 'wapm' ),
					$code
				)
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'torob_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'ترب پاسخ ناموفق داد (کد %d).', 'wapm' ),
					$code
				)
			);
		}

		$data = self::parse_json( $body );
		if ( null === $data ) {
			// پاسخ غیر-JSON تقریباً همیشه یعنی صفحه‌ی چالش/Captcha بوده است.
			self::set_cooldown( self::BLOCK_COOLDOWN );
			return new WP_Error(
				'torob_captcha',
				__( 'پاسخ ترب JSON نبود (احتمالاً صفحه‌ی «ربات نیستید»). ۳۰ دقیقه صبر کنید یا از بوکمارکلت استفاده کنید.', 'wapm' )
			);
		}

		return $data;
	}

	private static function headers(): array {
		return [
			'Accept'          => 'application/json, text/plain, */*',
			'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8',
			'Origin'          => 'https://torob.com',
			'Referer'         => 'https://torob.com/',
			'sec-fetch-dest'  => 'empty',
			'sec-fetch-mode'  => 'cors',
			'sec-fetch-site'  => 'same-site',
		];
	}

	private static function user_agent(): string {
		return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
	}

	/** JSON را با تحمل BOM/فاصله‌ی اضافه پارس می‌کند. null = JSON نبود. */
	public static function parse_json( string $body ): ?array {
		$body = trim( $body );
		if ( '' === $body || '{' !== $body[0] ) {
			return null;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	/* ---------- اندپوینت‌ها ---------- */

	/**
	 * جستجوی فروشگاه با نام (دامنه کار نمی‌کند — محدودیت خود ترب).
	 *
	 * @return array<int, array{id:int,name:string,domain:string,city:string,type:string}>
	 */
	public static function find_shops( string $query, int $size = 10 ): array {
		$query = trim( $query );
		if ( WAPM_Str::len( $query ) < 2 ) {
			return [];
		}

		$data = self::request(
			'/v4/internet-shop/list/',
			[ 'q' => $query, 'page' => 0, 'size' => $size, 'shop_type' => 'all' ]
		);
		if ( is_wp_error( $data ) ) {
			return [];
		}

		$out = [];
		foreach ( (array) ( $data['results'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$out[] = [
				'id'     => (int) $row['id'],
				'name'   => (string) ( $row['name'] ?? '' ),
				'domain' => (string) ( $row['domain'] ?? '' ),
				'city'   => (string) ( $row['city'] ?? '' ),
				'type'   => (string) ( $row['shop_type'] ?? '' ),
			];
		}

		return $out;
	}

	/**
	 * مشخصات یک فروشگاه (نام، دامنه، وضعیت فعال/بلاک، واحد قیمت).
	 *
	 * @return array|WP_Error
	 */
	public static function shop_details( int $shop_id ) {
		$data = self::request( '/v4/internet-shop/details/', [ 'id' => $shop_id ] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['error'] ) ) {
			return new WP_Error( 'torob_shop', (string) ( $data['error']['message'] ?? __( 'فروشگاه پیدا نشد.', 'wapm' ) ) );
		}

		return [
			'id'          => (int) ( $data['id'] ?? $shop_id ),
			'name'        => (string) ( $data['name'] ?? '' ),
			'domain'      => (string) ( $data['domain'] ?? '' ),
			'city'        => (string) ( $data['city'] ?? '' ),
			'blocked'     => 'blocked' === (string) ( $data['block_status'] ?? '' ),
			'block_note'  => (string) ( $data['block_description'] ?? '' ),
			'price_unit'  => (string) ( $data['price_unit_str'] ?? '' ),
			'score'       => (float) ( $data['shop_score'] ?? 0 ),
			'active_time' => (string) ( $data['active_time'] ?? '' ),
		];
	}

	/**
	 * یک صفحه از محصولات فروشگاه. `price` هر کارت، قیمت همین فروشگاه است.
	 *
	 * @return array|WP_Error ['items'=>[], 'count'=>int, 'page'=>int, 'has_more'=>bool]
	 */
	public static function shop_products( int $shop_id, int $page = 0, int $size = self::PAGE_SIZE ) {
		if ( $shop_id <= 0 ) {
			return new WP_Error( 'torob_shop_id', __( 'شناسه‌ی فروشگاه تنظیم نشده است.', 'wapm' ) );
		}

		$size = max( 1, min( 50, $size ) );
		$data = self::request(
			'/v4/internet-shop/base-product/list/',
			[
				'shop_id' => $shop_id,
				'page'    => max( 0, $page ),
				'size'    => $size,
				'sort'    => '-date',
			]
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return self::parse_shop_products( $data, $page );
	}

	/**
	 * تبدیل پاسخ خام «محصولات فروشگاه» به شکل یکدست (بدون درخواست شبکه).
	 *
	 * از shop_products جدا شده تا بدون HTTP هم قابل تست باشد.
	 *
	 * @param array $data پاسخ JSON ترب
	 * @param int   $page شماره‌ی صفحه
	 * @return array{items:array,count:int,page:int,has_more:bool}
	 */
	public static function parse_shop_products( array $data, int $page = 0 ): array {
		$raw = (array) ( $data['results'] ?? [] );

		$items = [];
		foreach ( $raw as $card ) {
			$item = self::card( $card );
			if ( $item ) {
				$items[] = $item;
			}
		}

		// «صفحه‌ی بعد» را ترب تعیین می‌کند (`next`)، نه شمارش ما. قبلاً شرط
		// `count($items) >= $size` هم داشت و چون `card()` ردیف‌های بی‌شناسه را
		// دور می‌ریزد، یک کارت خراب کافی بود تا صفحه «آخرین» شمرده شود و بقیه‌ی
		// کاتالوگ هرگز خوانده نشود — بی‌آنکه خطایی نشان داده شود.
		$has_more = ! empty( $data['next'] ) && ! empty( $raw );

		return [
			'items'    => $items,
			'count'    => (int) ( $data['count'] ?? 0 ),
			'page'     => max( 0, $page ),
			'has_more' => $has_more,
		];
	}

	/**
	 * همه‌ی فروشنده‌های یک محصول (مرتب از ارزان به گران، شامل خودمان).
	 *
	 * @return array|WP_Error ['prk'=>string,'sellers'=>[],'count'=>int]
	 */
	public static function product_sellers( string $prk ) {
		$prk = preg_replace( '/[^A-Za-z0-9_-]/', '', $prk );
		if ( '' === (string) $prk ) {
			return new WP_Error( 'torob_prk', __( 'شناسه‌ی محصول ترب معتبر نیست.', 'wapm' ) );
		}

		$data = self::request(
			'/v4/base-product/sellers/',
			[ 'prk' => $prk, 'list_type' => 'products_info' ]
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$sellers = [];
		$skipped = 0;
		foreach ( (array) ( $data['results'] ?? [] ) as $row ) {
			$seller = self::seller( $row );
			if ( null === $seller ) {
				$skipped++;
				continue;
			}
			$sellers[] = $seller;
		}

		usort( $sellers, fn( $a, $b ) => ( $a['price'] ?? PHP_INT_MAX ) <=> ( $b['price'] ?? PHP_INT_MAX ) );

		return [
			'prk'     => (string) $prk,
			'sellers' => $sellers,
			'count'   => count( $sellers ),
			'skipped' => $skipped,
		];
	}

	/* ---------- نرمال‌سازی (توابع خالص) ---------- */

	/**
	 * «از ۸٫۶۸۰٫۰۰۰ تومان» → 8680000.0
	 *
	 * ارقام فارسی/عربی، جداکننده‌ی هزارگان («٫» و «،» و «.») و متن «تومان»/«ریال»
	 * حذف می‌شوند. اگر عددی پیدا نشود، null.
	 */
	public static function parse_price_text( string $text ): ?float {
		$text = WAPM_Torob::to_ascii_digits( $text );

		$is_rial = WAPM_Str::contains( $text, 'ریال' );

		// هر چیزی جز رقم و جداکننده حذف شود، بعد جداکننده‌ها برداشته شوند.
		$digits = preg_replace( '/[^0-9]/', '', str_replace( [ '٫', '،', ',', ' ' ], '', $text ) );
		if ( '' === (string) $digits ) {
			return null;
		}

		$value = (float) $digits;

		// اگر متن «ریال» بگوید ولی API تومان بدهد، تبدیل می‌کنیم (محافظ آینده).
		return $is_rial ? $value / 10 : $value;
	}

	/**
	 * یک کارت محصول (از لیست فروشگاه یا جستجو) → شکل یکدست.
	 *
	 * @return array{prk:string,name:string,price:?float,price_text:string,shop_text:string,url:string,stock:string}|null
	 */
	public static function card( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$prk = (string) ( $row['random_key'] ?? $row['prk'] ?? '' );
		$prk = preg_replace( '/[^A-Za-z0-9_-]/', '', $prk );
		if ( '' === (string) $prk ) {
			return null;
		}

		$name       = sanitize_text_field( (string) ( $row['name1'] ?? $row['name'] ?? '' ) );
		$price      = isset( $row['price'] ) && is_numeric( $row['price'] ) ? (float) $row['price'] : null;
		$price_text = (string) ( $row['price_text'] ?? '' );

		// اگر متن قیمت با عدد نمی‌خواند (تغییر واحد یا ساختار)، عدد را دور می‌ریزیم
		// تا مقایسه‌ی اشتباه ۱۰ برابری انجام نشود.
		$from_text = '' !== $price_text ? self::parse_price_text( $price_text ) : null;
		if ( null !== $from_text && null !== $price && $price > 0 ) {
			$drift = abs( $from_text - $price ) / $price;
			if ( $drift > 0.01 ) {
				$price = null;
			}
		}

		$url = (string) ( $row['web_client_absolute_url'] ?? '' );

		return [
			'prk'        => (string) $prk,
			'name'       => $name,
			'price'      => $price,
			'price_text' => $price_text,
			'shop_text'  => (string) ( $row['shop_text'] ?? '' ),
			'url'        => '' !== $url ? 'https://torob.com' . $url : 'https://torob.com/p/' . rawurlencode( (string) $prk ) . '/',
			'stock'      => (string) ( $row['stock_status'] ?? '' ),
		];
	}

	/**
	 * یک ردیف فروشنده → شکل یکدست.
	 *
	 * @return array{shop_id:int,shop_name:string,price:float,available:bool,unreliable:bool,last_change:string}|null
	 */
	public static function seller( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$shop_id = (int) ( $row['shop_id'] ?? 0 );
		$name    = sanitize_text_field( (string) ( $row['shop_name'] ?? '' ) );
		if ( 0 === $shop_id && '' === $name ) {
			return null;
		}

		// پرچم‌ها ممکن است بولین یا رشته باشند ("false" در PHP درست‌نما است).
		$available  = ! array_key_exists( 'availability', $row ) || WAPM_Torob::to_bool( $row['availability'] );
		$unreliable = WAPM_Torob::to_bool( $row['is_price_unreliable'] ?? false );

		$price = isset( $row['price'] ) && is_numeric( $row['price'] ) ? (float) $row['price'] : 0.0;

		// بازبینی واحد: متن قیمت باید با عدد بخواند.
		$price_text = (string) ( $row['price_text'] ?? '' );
		if ( '' !== $price_text && $price > 0 ) {
			$from_text = self::parse_price_text( $price_text );
			if ( null !== $from_text && abs( $from_text - $price ) / $price > 0.01 ) {
				$unreliable = true;
			}
		}

		return [
			'shop_id'     => $shop_id,
			'shop_name'   => $name,
			'price'       => $available ? $price : 0.0,
			'available'   => $available,
			'unreliable'  => $unreliable,
			'last_change' => sanitize_text_field( (string) ( $row['last_price_change_date'] ?? '' ) ),
		];
	}
}
