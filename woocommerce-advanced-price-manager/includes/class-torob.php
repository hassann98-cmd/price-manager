<?php
/**
 * WAPM_Torob — همگام‌سازی کاتالوگ فروشگاه با ترب و مقایسه‌ی قیمت با رقبا.
 *
 * توجه مهم: خواندن مستقیم صفحات ترب از سمت سرور وردپرس (wp_remote_get) امتحان شد و
 * ترب این‌گونه درخواست‌ها را با سیستم ضد-ربات خودش (پاسخ HTTP 490 و صفحه‌ی
 * «آیا شما یک ربات هستید؟») بلاک می‌کند. به همین دلیل این قابلیت با یک Bookmarklet کار
 * می‌کند: کدی که در مرورگر خود شما، وقتی روی torob.com هستید اجرا می‌شود (پس مثل یک
 * بازدید واقعی از مرورگر دیده می‌شود، نه یک درخواست سرور-به-سرور) و نتیجه را برای این
 * افزونه ارسال می‌کند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Torob {

	const OPTION_SHOP_ID = 'wapm_torob_shop_id';
	const OPTION_TOKEN    = 'wapm_torob_bm_token';
	const META_PRK        = '_wapm_torob_prk';
	const META_NAME       = '_wapm_torob_name';
	const META_PRICE      = '_wapm_torob_price';
	const META_OUR_PRICE  = '_wapm_torob_our_price';
	const META_SELLERS    = '_wapm_torob_sellers';
	const META_URL        = '_wapm_torob_url';
	const META_CHECKED    = '_wapm_torob_checked_at';

	public static function get_shop_id(): string {
		return trim( (string) get_option( self::OPTION_SHOP_ID, '' ) );
	}

	public static function save_shop_id( string $shop_id ): void {
		update_option( self::OPTION_SHOP_ID, sanitize_text_field( $shop_id ), false );
	}

	/**
	 * توکن مخفیِ ورودی Bookmarklet (چون این درخواست از torob.com می‌آید و به nonce/سشن
	 * ادمین دسترسی ندارد، با یک توکن اختصاصی به‌جای nonce معمول احراز هویت می‌شود).
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

	/**
	 * تعداد محصولاتی که با ترب match شده‌اند (دارای prk هستند).
	 */
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
	 * آخرین باری که Bookmarklet داده ارسال کرده (برای نمایش در تب ترب).
	 */
	public static function get_last_sync_time(): string {
		return (string) get_option( 'wapm_torob_last_sync', '' );
	}

	/**
	 * یکسان‌سازی رشته برای تطبیق نام محصول (فاصله‌ها، حروف عربی/فارسی مشابه).
	 */
	private static function normalize( string $s ): string {
		$s = trim( $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		$s = str_replace( [ 'ي', 'ك' ], [ 'ی', 'ک' ], (string) $s );
		return mb_strtolower( (string) $s, 'UTF-8' );
	}

	/**
	 * ایندکس عنوان محصولات ووکامرس (فقط یک‌بار در طول یک درخواست ساخته می‌شود).
	 */
	private static function product_title_index(): array {
		static $index = null;
		if ( null !== $index ) {
			return $index;
		}
		$index = [];
		$ids   = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		foreach ( $ids as $id ) {
			$index[ self::normalize( get_the_title( $id ) ) ] = (int) $id;
		}
		return $index;
	}

	/**
	 * تلاش برای پیدا کردن محصول ووکامرس معادل یک عنوان ترب.
	 */
	private static function match_product( string $torob_name ): int {
		$index = self::product_title_index();
		$norm  = self::normalize( $torob_name );

		if ( isset( $index[ $norm ] ) ) {
			return (int) $index[ $norm ];
		}

		// تلاش دوم: تطبیق بر اساس «کد NNNN» انتهای عنوان با عنوان محصول یا SKU.
		if ( preg_match( '/کد\s*(\d+)/u', $torob_name, $m ) ) {
			$code = $m[1];
			foreach ( $index as $norm_title => $id ) {
				if ( false !== mb_strpos( $norm_title, $code, 0, 'UTF-8' ) ) {
					return (int) $id;
				}
			}
			$by_sku = wc_get_product_id_by_sku( $code );
			if ( $by_sku ) {
				return (int) $by_sku;
			}
		}

		return 0;
	}

	/**
	 * دریافت دسته‌ای داده از Bookmarklet: هر آیتم شامل نام، prk و لیست فروشنده‌هاست.
	 * محصول معادل در ووکامرس پیدا می‌شود و قیمت رقبا/خودمان ذخیره می‌شود.
	 *
	 * @param array $items [{name, prk, sellers:[{shop_id, price}]}]
	 * @return array{matched:int, checked:int, unmatched:array}
	 */
	public static function ingest_bookmarklet_batch( array $items ): array {
		$matched   = 0;
		$checked   = 0;
		$unmatched = [];
		$shop_id   = (int) self::get_shop_id();

		foreach ( $items as $item ) {
			$name    = sanitize_text_field( (string) ( $item['name'] ?? '' ) );
			$prk     = sanitize_text_field( (string) ( $item['prk'] ?? '' ) );
			$sellers = is_array( $item['sellers'] ?? null ) ? $item['sellers'] : [];

			if ( '' === $name || '' === $prk ) {
				continue;
			}

			$product_id = self::match_product( $name );
			if ( ! $product_id ) {
				$unmatched[] = $name;
				continue;
			}
			$matched++;

			update_post_meta( $product_id, self::META_PRK, $prk );
			update_post_meta( $product_id, self::META_NAME, $name );

			if ( ! $sellers ) {
				continue;
			}

			$our_price      = null;
			$competitor_min = null;

			foreach ( $sellers as $s ) {
				$sid   = absint( $s['shop_id'] ?? 0 );
				$price = (float) ( $s['price'] ?? 0 );
				if ( $price <= 0 ) {
					continue;
				}
				if ( $shop_id && $sid === $shop_id ) {
					$our_price = $price;
				} elseif ( null === $competitor_min || $price < $competitor_min ) {
					$competitor_min = $price;
				}
			}

			update_post_meta( $product_id, self::META_PRICE, null === $competitor_min ? '' : (string) $competitor_min );
			update_post_meta( $product_id, self::META_OUR_PRICE, null === $our_price ? '' : (string) $our_price );
			update_post_meta( $product_id, self::META_SELLERS, count( $sellers ) );
			update_post_meta( $product_id, self::META_URL, 'https://torob.com/p/' . $prk . '/' );
			update_post_meta( $product_id, self::META_CHECKED, current_time( 'mysql' ) );
			$checked++;
		}

		update_option( 'wapm_torob_last_sync', current_time( 'mysql' ), false );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return [ 'matched' => $matched, 'checked' => $checked, 'unmatched' => $unmatched ];
	}

	/**
	 * داده‌ی ترب برای نمایش در ردیف جدول لیست محصولات (استفاده در WAPM_List).
	 */
	public static function get_row_data( int $product_id ): ?array {
		$prk = get_post_meta( $product_id, self::META_PRK, true );
		if ( ! $prk ) {
			return null;
		}

		$competitor = get_post_meta( $product_id, self::META_PRICE, true );
		$our        = get_post_meta( $product_id, self::META_OUR_PRICE, true );

		return [
			'prk'              => $prk,
			'competitor_price' => ( '' !== $competitor && null !== $competitor ) ? (float) $competitor : null,
			'our_price'        => ( '' !== $our && null !== $our ) ? (float) $our : null,
			'sellers'          => (int) get_post_meta( $product_id, self::META_SELLERS, true ),
			'url'              => (string) get_post_meta( $product_id, self::META_URL, true ),
			'checked_at'       => (string) get_post_meta( $product_id, self::META_CHECKED, true ),
		];
	}
}
