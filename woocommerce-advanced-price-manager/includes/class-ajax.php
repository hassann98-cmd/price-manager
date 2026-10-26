<?php
/**
 * WAPM_Ajax — تمام درخواست‌های Ajax با Nonce و Capability Check
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Ajax {

	const NONCE = 'wapm_nonce';

	public function __construct() {
		$actions = [
			'search_products',
			'get_products', // برچسب محصولات ذخیره‌شده در یک قانون (برای بازگرداندن انتخاب‌ها در فرم)
			'get_terms',
			'list_products',
			'update_field',
			'update_terms',
			'toggle_manage_stock',
			'duplicate_product',
			'bulk_action',
			'torob_save_settings',
			'torob_regenerate_token',
			'torob_stats',
			'torob_get_unmatched',
			'torob_link',
			'torob_unlink',
			'torob_ignore_unmatched',
			'torob_clear_unmatched',
			'torob_import_feed',
			'torob_find_shops',
			'torob_verify_shop',
			'torob_sync_catalog',
			'torob_refresh_product',
			'torob_api_reset',
			'count_targets',
			'preview',
			'dry_run',
			'apply_batch',
			'rollback',
			'get_logs',
			'save_rule',
			'delete_rule',
			'toggle_rule',
			'run_rule',
			'save_schedule',
			'delete_schedule',
			'import_csv',
		];
		foreach ( $actions as $action ) {
			add_action( "wp_ajax_wapm_{$action}", [ $this, $action ] );
		}
		add_action( 'admin_post_wapm_export_csv', [ $this, 'export_csv' ] );

		// این یکی از torob.com (Bookmarklet) صدا زده می‌شود، نه از پنل ادمین — پس باید
		// بدون لاگین (nopriv) هم در دسترس باشد؛ احراز هویتش با توکن اختصاصی است، نه nonce.
		add_action( 'wp_ajax_wapm_torob_bookmarklet_sync', [ $this, 'torob_bookmarklet_sync' ] );
		add_action( 'wp_ajax_nopriv_wapm_torob_bookmarklet_sync', [ $this, 'torob_bookmarklet_sync' ] );

		// اکشن عمومی ورود داده از هر منبعی (بوکمارکلت جدید / فید / اسکریپت بیرونی).
		// نام قدیمی بالا برای بوکمارکلت‌هایی که قبلاً ساخته شده‌اند حفظ شده است.
		add_action( 'wp_ajax_wapm_torob_ingest', [ $this, 'torob_ingest' ] );
		add_action( 'wp_ajax_nopriv_wapm_torob_ingest', [ $this, 'torob_ingest' ] );
	}

	/**
	 * بررسی امنیتی مشترک همه درخواست‌ها.
	 */
	private function guard(): void {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'نشست شما منقضی شده است. صفحه را رفرش کنید.', 'wapm' ) ], 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'شما دسترسی لازم را ندارید.', 'wapm' ) ], 403 );
		}
	}

	private function get_filters(): array {
		$raw = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : [];
		return WAPM_Product_Handler::sanitize_filters( is_array( $raw ) ? $raw : [] );
	}

	private function get_settings(): array {
		$raw = isset( $_POST['settings'] ) ? json_decode( wp_unslash( $_POST['settings'] ), true ) : [];
		return WAPM_Price_Engine::sanitize_settings( is_array( $raw ) ? $raw : [] );
	}

	/* ---------- جستجو و لیست‌ها ---------- */

	public function search_products(): void {
		$this->guard();
		$term = sanitize_text_field( wp_unslash( $_POST['term'] ?? '' ) );
		if ( mb_strlen( $term ) < 2 ) {
			wp_send_json_success( [] );
		}
		wp_send_json_success( WAPM_Product_Handler::search_products( $term ) );
	}

	/**
	 * برچسب چند محصول با شناسه — برای بازگرداندن «محصولات انتخابی» در فرم ویرایش قانون.
	 */
	public function get_products(): void {
		$this->guard();
		$ids = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
		wp_send_json_success( WAPM_Product_Handler::get_products_by_ids( $ids ) );
	}

	public function get_terms(): void {
		$this->guard();
		$taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		wp_send_json_success( WAPM_Product_Handler::get_terms_list( $taxonomy ) );
	}

	/* ---------- لیست محصولات (تب «لیست محصولات») ---------- */

	public function list_products(): void {
		$this->guard();
		$raw     = isset( $_POST['filters'] ) ? json_decode( wp_unslash( $_POST['filters'] ), true ) : [];
		$filters = WAPM_List::sanitize_filters( is_array( $raw ) ? $raw : [] );
		wp_send_json_success( WAPM_List::query( $filters ) );
	}

	public function update_field(): void {
		$this->guard();
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$field      = sanitize_key( $_POST['field'] ?? '' );
		$value      = wp_unslash( $_POST['value'] ?? '' );

		$result = WAPM_List::update_field( $product_id, $field, $value );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ?? __( 'خطا', 'wapm' ) ] );
		}
		wp_send_json_success( $result );
	}

	public function update_terms(): void {
		$this->guard();
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$taxonomy   = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
		$term_ids   = array_map( 'absint', (array) ( $_POST['term_ids'] ?? [] ) );

		$result = WAPM_List::update_terms( $product_id, $taxonomy, $term_ids );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [
				'message' => $result['message'] ?? __( 'خطا', 'wapm' ),
				'row'     => $result['row'] ?? null,
			] );
		}
		wp_send_json_success( $result );
	}

	public function toggle_manage_stock(): void {
		$this->guard();
		$product_id = absint( $_POST['product_id'] ?? 0 );
		$enabled    = ! empty( $_POST['enabled'] ) && 'false' !== $_POST['enabled'];

		$result = WAPM_List::toggle_manage_stock( $product_id, $enabled );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ?? __( 'خطا', 'wapm' ) ] );
		}
		wp_send_json_success( $result );
	}

	public function duplicate_product(): void {
		$this->guard();
		$result = WAPM_List::duplicate_product( absint( $_POST['product_id'] ?? 0 ) );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ?? __( 'خطا', 'wapm' ) ] );
		}
		wp_send_json_success( $result );
	}

	public function bulk_action(): void {
		$this->guard();
		$action = sanitize_key( $_POST['bulk_action'] ?? '' );
		$ids    = array_values( array_filter( array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_error( [ 'message' => __( 'هیچ محصولی انتخاب نشده.', 'wapm' ) ] );
		}

		if ( 'trash' === $action ) {
			$count = WAPM_List::trash_products( $ids );
			wp_send_json_success( [
				'count'   => $count,
				'message' => sprintf( __( '%d آیتم حذف شد.', 'wapm' ), $count ),
			] );
		}

		if ( 'set_stock_status' === $action ) {
			$status = sanitize_key( $_POST['value'] ?? '' );
			$count  = WAPM_List::bulk_set_stock_status( $ids, $status );
			wp_send_json_success( [
				'count'   => $count,
				'message' => sprintf( __( 'وضعیت موجودی %d محصول تغییر کرد.', 'wapm' ), $count ),
			] );
		}

		wp_send_json_error( [ 'message' => __( 'عملیات نامعتبر است.', 'wapm' ) ] );
	}

	/* ---------- ترب ---------- */

	public function torob_save_settings(): void {
		$this->guard();

		WAPM_Torob::save_settings( [
			'shop_id'       => wp_unslash( $_POST['shop_id'] ?? '' ),
			'shop_name'     => wp_unslash( $_POST['shop_name'] ?? '' ),
			'fresh_days'    => $_POST['fresh_days'] ?? null,
			'tolerance_pct' => $_POST['tolerance_pct'] ?? null,
		] );

		wp_send_json_success( array_merge( $this->torob_stats_payload(), [
			'can_identify_shop' => WAPM_Torob::can_identify_shop(),
		] ) );
	}

	public function torob_regenerate_token(): void {
		$this->guard();
		wp_send_json_success( [ 'token' => WAPM_Torob::regenerate_bookmarklet_token() ] );
	}

	/**
	 * آمار کلی ترب (برای به‌روزرسانی کارت وضعیت بدون رفرش صفحه).
	 */
	public function torob_stats(): void {
		$this->guard();
		wp_send_json_success( $this->torob_stats_payload() );
	}

	private function torob_stats_payload(): array {
		$last = WAPM_Torob::get_last_sync();

		return [
			'matched'           => WAPM_Torob::count_matched(),
			'unmatched'         => count( WAPM_Torob::unmatched() ),
			'last_sync'         => $last,
			'last_catalog'      => WAPM_Torob::get_last_catalog(),
			'settings'          => WAPM_Torob::settings(),
			'shop_id'           => WAPM_Torob::get_shop_id(),
			'can_identify_shop' => WAPM_Torob::can_identify_shop(),
			'api'               => class_exists( 'WAPM_Torob_Api' ) ? WAPM_Torob_Api::state() : null,
		];
	}

	/**
	 * لیست محصولات تطبیق‌نیافته (برای اتصال دستی).
	 */
	public function torob_get_unmatched(): void {
		$this->guard();

		$rows = [];
		foreach ( WAPM_Torob::unmatched() as $row ) {
			$candidate = 0;
			if ( ! empty( $row['candidate'] ) ) {
				$candidate = (int) $row['candidate'];
			}
			$candidate_product = $candidate ? wc_get_product( $candidate ) : null;

			$rows[] = [
				'prk'        => (string) ( $row['prk'] ?? '' ),
				'name'       => (string) ( $row['name'] ?? '' ),
				'price'      => isset( $row['price'] ) && '' !== $row['price'] ? (float) $row['price'] : null,
				'sellers'    => (int) ( $row['sellers'] ?? 0 ),
				'seen'       => (string) ( $row['seen'] ?? '' ),
				'seen_i18n'  => ! empty( $row['seen'] ) ? date_i18n( 'Y/m/d H:i', strtotime( (string) $row['seen'] ) ) : '',
				'how'        => (string) ( $row['how'] ?? '' ),
				'score'      => (float) ( $row['score'] ?? 0 ),
				'candidate'  => $candidate_product ? [
					'id'   => $candidate_product->get_id(),
					'text' => $candidate_product->get_name() . ( $candidate_product->get_sku() ? ' (SKU: ' . $candidate_product->get_sku() . ')' : '' ),
				] : null,
				'url'        => 'https://torob.com/p/' . rawurlencode( (string) ( $row['prk'] ?? '' ) ) . '/',
			];
		}

		wp_send_json_success( [ 'rows' => $rows, 'total' => count( $rows ) ] );
	}

	/**
	 * اتصال دستی یک محصول به یک محصول ترب.
	 */
	public function torob_link(): void {
		$this->guard();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$prk        = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['prk'] ?? '' ) );
		$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( ! $product_id || '' === $prk ) {
			wp_send_json_error( [ 'message' => __( 'اطلاعات اتصال کامل نیست.', 'wapm' ) ] );
		}

		if ( ! WAPM_Torob::link_product( $product_id, $prk, $name ) ) {
			wp_send_json_error( [ 'message' => __( 'اتصال انجام نشد؛ محصول یا شناسه معتبر نیست.', 'wapm' ) ] );
		}

		$product = wc_get_product( $product_id );
		wp_send_json_success( [
			'message' => __( 'محصول به ترب متصل شد.', 'wapm' ),
			'row'     => $product ? WAPM_List::build_row_for_ajax( $product ) : null,
			'stats'   => $this->torob_stats_payload(),
		] );
	}

	public function torob_unlink(): void {
		$this->guard();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'محصول نامعتبر است.', 'wapm' ) ] );
		}

		WAPM_Torob::unlink_product( $product_id );

		$product = wc_get_product( $product_id );
		wp_send_json_success( [
			'message' => __( 'اتصال محصول به ترب حذف شد.', 'wapm' ),
			'row'     => $product ? WAPM_List::build_row_for_ajax( $product ) : null,
			'stats'   => $this->torob_stats_payload(),
		] );
	}

	public function torob_ignore_unmatched(): void {
		$this->guard();
		WAPM_Torob::remove_unmatched_by_prk( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) wp_unslash( $_POST['prk'] ?? '' ) ) );
		wp_send_json_success( $this->torob_stats_payload() );
	}

	public function torob_clear_unmatched(): void {
		$this->guard();
		WAPM_Torob::clear_unmatched();
		wp_send_json_success( $this->torob_stats_payload() );
	}

	/**
	 * ورود داده از فایل CSV (فید پنل ترب یا هر منبعی).
	 */
	public function torob_import_feed(): void {
		$this->guard();

		if ( empty( $_FILES['feed_file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'فایلی انتخاب نشده است.', 'wapm' ) ] );
		}
		if ( ! empty( $_FILES['feed_file']['error'] ) ) {
			wp_send_json_error( [ 'message' => __( 'خطا در آپلود فایل.', 'wapm' ) ] );
		}
		if ( ! is_uploaded_file( $_FILES['feed_file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'فایل آپلودشده معتبر نیست.', 'wapm' ) ], 400 );
		}

		$check = wp_check_filetype( sanitize_file_name( wp_unslash( $_FILES['feed_file']['name'] ) ), [ 'csv' => 'text/csv' ] );
		if ( 'csv' !== $check['ext'] ) {
			wp_send_json_error( [ 'message' => __( 'فقط فایل CSV مجاز است.', 'wapm' ) ] );
		}

		$result = WAPM_Torob::import_csv( $_FILES['feed_file']['tmp_name'] );
		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( [ 'message' => $result['error'] ] );
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: 1: rows in file, 2: matched, 3: unmatched */
				__( '%1$d ردیف خوانده شد — %2$d محصول تطبیق خورد، %3$d تطبیق نخورد.', 'wapm' ),
				$result['items'],
				(int) ( $result['stats']['matched'] ?? 0 ),
				(int) ( $result['stats']['unmatched'] ?? 0 )
			),
			'stats'   => $this->torob_stats_payload(),
		] );
	}

	/* ---------- ترب از طریق API (api.torob.com) ---------- */

	/**
	 * جستجوی فروشگاه با نام — برای پیدا کردن شناسه‌ی فروشگاه بدون گشتن در URL.
	 */
	public function torob_find_shops(): void {
		$this->guard();

		$q = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		if ( mb_strlen( $q ) < 2 ) {
			wp_send_json_error( [ 'message' => __( 'حداقل دو حرف از نام فروشگاه را بنویسید.', 'wapm' ) ] );
		}

		$rows = WAPM_Torob_Api::find_shops( $q );
		$state = WAPM_Torob_Api::state();

		if ( ! $rows ) {
			wp_send_json_success( [
				'rows'  => [],
				'state' => $state,
				'message' => $state['blocked']
					? sprintf( __( 'ترب درخواست را محدود کرده است؛ حدود %d دقیقه دیگر تلاش کنید.', 'wapm' ), $state['minutes_left'] )
					: __( 'فروشگاهی با این نام پیدا نشد. نام را همان‌طور که در ترب می‌بینید وارد کنید.', 'wapm' ),
			] );
		}

		wp_send_json_success( [ 'rows' => $rows, 'state' => $state ] );
	}

	/**
	 * تأیید شناسه‌ی فروشگاه با API و ذخیره‌ی آن (نام، دامنه، وضعیت فعال بودن).
	 */
	public function torob_verify_shop(): void {
		$this->guard();

		$shop_id = absint( $_POST['shop_id'] ?? 0 );
		$info    = WAPM_Torob::verify_shop( $shop_id );

		if ( is_wp_error( $info ) ) {
			wp_send_json_error( [
				'message' => $info->get_error_message(),
				'state'   => WAPM_Torob_Api::state(),
			] );
		}

		wp_send_json_success( array_merge( $this->torob_stats_payload(), [
			'shop'  => $info,
			'state' => WAPM_Torob_Api::state(),
		] ) );
	}

	/**
	 * یک صفحه از کاتالوگ فروشگاه در ترب (۱ درخواست API به‌ازای هر ۲۴ محصول).
	 * جاوااسکریپت صفحه‌به‌صفحه صدا می‌زند تا هر درخواست کوتاه بماند.
	 */
	public function torob_sync_catalog(): void {
		$this->guard();

		$page = max( 0, absint( $_POST['page'] ?? 0 ) );
		$size = min( 50, max( 1, absint( $_POST['size'] ?? 24 ) ) );

		$result = WAPM_Torob::sync_catalog_page( $page, $size );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
				'state'   => WAPM_Torob_Api::state(),
			] );
		}

		wp_send_json_success( array_merge( $result, [
			'stats_payload' => $this->torob_stats_payload(),
			'api'           => WAPM_Torob_Api::state(),
		] ) );
	}

	/**
	 * بررسی فوری یک محصول از API (۱ درخواست): ارزان‌ترین رقیب و قیمت ما در ترب.
	 */
	public function torob_refresh_product(): void {
		$this->guard();

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$result     = WAPM_Torob::refresh_product( $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [
				'message' => $result->get_error_message(),
				'state'   => WAPM_Torob_Api::state(),
			] );
		}

		wp_send_json_success( array_merge( $result, [
			'stats_payload' => $this->torob_stats_payload(),
			'api'           => WAPM_Torob_Api::state(),
			'message'       => sprintf(
				/* translators: %d: sellers */
				__( 'بررسی شد — %d فروشنده در ترب.', 'wapm' ),
				(int) $result['sellers']
			),
		] ) );
	}

	/**
	 * پاک کردن کول‌داون دستی (وقتی کاربر می‌داند بلاک تمام شده است).
	 */
	public function torob_api_reset(): void {
		$this->guard();
		WAPM_Torob_Api::clear_cooldown();
		wp_send_json_success( [ 'state' => WAPM_Torob_Api::state() ] );
	}

	/**
	 * ورود داده از منابع بیرونی (بوکمارکلت/فید/اسکریپت) — بدون سشن ادمین.
	 * احراز هویت با توکن اختصاصی + محدودیت نرخ + سقف حجم.
	 */
	public function torob_ingest(): void {
		if ( isset( $_SERVER['HTTP_ORIGIN'] ) && 'https://torob.com' === $_SERVER['HTTP_ORIGIN'] ) {
			header( 'Access-Control-Allow-Origin: https://torob.com' );
		}

		$token       = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$saved_token = WAPM_Torob::get_bookmarklet_token();

		if ( '' === $token || ! $saved_token || ! hash_equals( $saved_token, $token ) ) {
			wp_send_json_error( [ 'message' => 'توکن نامعتبر است.' ], 403 );
		}

		// محدودیت نرخ: نشت توکن نباید به یک حمله‌ی نوشتن بی‌پایان تبدیل شود.
		// سقف عمداً بالا است: بوکمارکلت هر ۲۰ محصول یک درخواست می‌فرستد، پس یک
		// فروشگاه با ۲۰۰۰ محصول در یک دقیقه به ~۱۰۰ درخواست می‌رسد و سقف پایین
		// باعث می‌شد همگام‌سازی نیمه‌کاره بماند.
		$rate_key = 'wapm_torob_rl_' . substr( hash( 'sha256', $token ), 0, 12 );
		$hits     = (int) get_transient( $rate_key );
		if ( $hits >= 180 ) {
			wp_send_json_error( [ 'message' => 'تعداد درخواست‌ها بیش از حد مجاز است؛ یک دقیقه بعد تلاش کنید.' ], 429 );
		}
		set_transient( $rate_key, $hits + 1, MINUTE_IN_SECONDS );

		$raw_items = wp_unslash( $_POST['items'] ?? '[]' );
		if ( strlen( (string) $raw_items ) > 3 * MB_IN_BYTES ) {
			wp_send_json_error( [ 'message' => 'حجم داده بیش از حد مجاز است.' ], 413 );
		}

		$items = json_decode( (string) $raw_items, true );
		if ( ! is_array( $items ) ) {
			wp_send_json_error( [ 'message' => 'داده نامعتبر است.' ] );
		}

		$source = sanitize_key( wp_unslash( $_POST['source'] ?? 'bookmarklet' ) );
		if ( ! in_array( $source, [ 'bookmarklet', 'feed', 'api' ], true ) ) {
			$source = 'api';
		}

		$meta = json_decode( (string) wp_unslash( $_POST['meta'] ?? '{}' ), true );
		$meta = is_array( $meta ) ? $meta : [];

		// بوکمارکلت حالت «فقط کاتالوگ» (قیمت خودمان از دید ترب) را هم می‌فرستد.
		$mode  = ( isset( $meta['mode'] ) && 'catalog' === $meta['mode'] ) ? 'catalog' : 'full';
		$stats = WAPM_Torob::ingest( $items, $source, $meta, $mode );

		wp_send_json_success( array_merge( $stats, [
			'unmatched_total' => count( WAPM_Torob::unmatched() ),
			'matched_total'   => WAPM_Torob::count_matched(),
		] ) );
	}

	/**
	 * سازگاری: بوکمارکلت‌های نسخه‌ی قبل همین اکشن را صدا می‌زنند.
	 */
	public function torob_bookmarklet_sync(): void {
		$_POST['source'] = 'bookmarklet';
		$this->torob_ingest();
	}

	/* ---------- شمارش و پیش‌نمایش ---------- */

	public function count_targets(): void {
		$this->guard();
		wp_send_json_success( [ 'count' => WAPM_Product_Handler::count_targets( $this->get_filters() ) ] );
	}

	public function preview(): void {
		$this->guard();
		$filters  = $this->get_filters();
		$settings = $this->get_settings();
		$paged    = max( 1, intval( $_POST['paged'] ?? 1 ) );
		$per_page = 20;

		$targets = WAPM_Product_Handler::get_target_ids( $filters, $paged, $per_page );

		$rows = [];
		foreach ( $targets['ids'] as $pid ) {
			$rows = array_merge( $rows, WAPM_Price_Engine::preview_product( $pid, $settings ) );
		}

		wp_send_json_success( [
			'rows'  => $rows,
			'total' => $targets['total'],
			'paged' => $paged,
			'pages' => (int) ceil( max( 1, $targets['total'] ) / $per_page ),
		] );
	}

	/**
	 * Dry Run — گزارش آماری بدون هیچ تغییری در دیتابیس.
	 * صفحه به صفحه اجرا می‌شود و آمار در سمت کلاینت جمع می‌شود.
	 */
	public function dry_run(): void {
		$this->guard();
		$filters  = $this->get_filters();
		$settings = $this->get_settings();
		$paged    = max( 1, intval( $_POST['paged'] ?? 1 ) );
		$per_page = 50;

		$targets = WAPM_Product_Handler::get_target_ids( $filters, $paged, $per_page );

		$is_torob = WAPM_Price_Engine::uses_torob( $settings );

		$stats = [
			'products'      => 0,
			'variations'    => 0,
			'price_points'  => 0,
			'sum_diff'      => 0.0,
			'max_increase'  => 0.0,
			'max_decrease'  => 0.0,
			'flagged'       => 0, // تعداد قیمت‌هایی که افت بیش از ۹۰٪ دارند (هشدار).
			'torob_skipped' => 0, // در حالت ترب: محصولاتی که داده‌ی تازه/شرط لازم را ندارند.
		];

		foreach ( $targets['ids'] as $pid ) {
			$rows = WAPM_Price_Engine::preview_product( $pid, $settings );

			if ( $is_torob ) {
				$product = wc_get_product( $pid );
				$ctx     = $product ? WAPM_Price_Engine::torob_context( $product, $settings ) : null;
				if ( null === $ctx || ! empty( $ctx['skip'] ) ) {
					$stats['torob_skipped']++;
				}
			}

			if ( empty( $rows ) ) {
				continue;
			}
			$stats['products']++;
			$seen_variations = [];
			foreach ( $rows as $row ) {
				if ( $row['parent_id'] > 0 && ! isset( $seen_variations[ $row['id'] ] ) ) {
					$seen_variations[ $row['id'] ] = true;
					$stats['variations']++;
				}
				if ( ! empty( $row['warn'] ) ) {
					$stats['flagged']++;
				}
				$diff = (float) $row['diff'];
				$stats['price_points']++;
				$stats['sum_diff'] += $diff;
				if ( $diff > $stats['max_increase'] ) {
					$stats['max_increase'] = $diff;
				}
				if ( $diff < $stats['max_decrease'] ) {
					$stats['max_decrease'] = $diff;
				}
			}
		}

		$done = empty( $targets['ids'] ) || ( $paged * $per_page ) >= $targets['total'];

		wp_send_json_success( [
			'stats' => $stats,
			'total' => $targets['total'],
			'paged' => $paged,
			'done'  => $done,
		] );
	}

	/* ---------- اعمال Batch ---------- */

	public function apply_batch(): void {
		$this->guard();

		$filters  = $this->get_filters();
		$settings = $this->get_settings();
		$paged    = max( 1, intval( $_POST['paged'] ?? 1 ) );
		$batch_id = sanitize_key( $_POST['batch_id'] ?? '' ) ?: 'man_' . wp_generate_password( 12, false );
		$per_page = 25;

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}

		$targets   = WAPM_Product_Handler::get_target_ids( $filters, $paged, $per_page );
		$processed = 0;
		$changed   = 0;

		foreach ( $targets['ids'] as $pid ) {
			$changed += WAPM_Price_Engine::apply_to_product( $pid, $settings, $batch_id, 'manual' );
			$processed++;
		}

		$done = empty( $targets['ids'] ) || ( $paged * $per_page ) >= $targets['total'];

		if ( $done && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		wp_send_json_success( [
			'batch_id'  => $batch_id,
			'processed' => $processed,
			'changed'   => $changed,
			'total'     => $targets['total'],
			'paged'     => $paged,
			'done'      => $done,
		] );
	}

	/* ---------- تاریخچه و Rollback ---------- */

	public function get_logs(): void {
		$this->guard();
		$paged = max( 1, intval( $_POST['paged'] ?? 1 ) );
		wp_send_json_success( WAPM_Logger::get_logs( $paged, 25 ) );
	}

	public function rollback(): void {
		$this->guard();
		$batch_id = sanitize_text_field( wp_unslash( $_POST['batch_id'] ?? '' ) );
		$force    = ! empty( $_POST['force'] ) && 'false' !== $_POST['force'];

		if ( '' === $batch_id || 'last' === $batch_id ) {
			$batch_id = WAPM_Logger::get_last_batch_id();
		}
		if ( ! $batch_id ) {
			wp_send_json_error( [ 'message' => __( 'عملیاتی برای بازگردانی یافت نشد.', 'wapm' ) ] );
		}

		$result    = WAPM_Rollback::rollback( $batch_id, $force );
		$restored  = (int) $result['restored'];
		$conflicts = $result['conflicts'];
		$rows      = (int) ( $result['rows'] ?? $restored );

		if ( $conflicts && ! $force ) {
			// ردیف‌هایی که بعد از این Batch قیمتشان عوض شده، دست‌نخورده ماندند.
			$message = sprintf(
				__( '%1$d قیمت بازگردانی شد؛ %2$d مورد بعد از این عملیات تغییر کرده بود و برای جلوگیری از پاک‌شدن تغییر جدیدتر، بازگردانی نشد.', 'wapm' ),
				$restored,
				count( $conflicts )
			);
		} else {
			$message = sprintf(
				/* translators: 1: restored rows, 2: total rows in the batch */
				__( '%1$d قیمت با موفقیت بازگردانی شد (این دسته %2$d ردیف داشت).', 'wapm' ),
				$restored,
				$rows
			);
		}

		wp_send_json_success( [
			'restored'  => $restored,
			'conflicts' => $conflicts,
			'batch_id'  => $batch_id,
			'message'   => $message,
		] );
	}

	/* ---------- قوانین ---------- */

	public function save_rule(): void {
		$this->guard();
		$raw = json_decode( wp_unslash( $_POST['rule'] ?? '' ), true );
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'داده قانون نامعتبر است.', 'wapm' ) ] );
		}
		$rule = WAPM_Rules::save_rule( $raw );
		wp_send_json_success( [ 'rule' => $rule, 'rules' => WAPM_Rules::all() ] );
	}

	public function delete_rule(): void {
		$this->guard();
		WAPM_Rules::delete_rule( sanitize_key( $_POST['rule_id'] ?? '' ) );
		wp_send_json_success( [ 'rules' => WAPM_Rules::all() ] );
	}

	public function toggle_rule(): void {
		$this->guard();
		WAPM_Rules::toggle_rule( sanitize_key( $_POST['rule_id'] ?? '' ), ! empty( $_POST['enabled'] ) );
		wp_send_json_success( [ 'rules' => WAPM_Rules::all() ] );
	}

	public function run_rule(): void {
		$this->guard();
		$rule = WAPM_Rules::get_rule( sanitize_key( $_POST['rule_id'] ?? '' ) );
		if ( ! $rule ) {
			wp_send_json_error( [ 'message' => __( 'قانون یافت نشد.', 'wapm' ) ] );
		}
		$paged    = max( 1, intval( $_POST['paged'] ?? 1 ) );
		$batch_id = sanitize_key( $_POST['batch_id'] ?? '' ) ?: 'rule_' . wp_generate_password( 12, false );
		wp_send_json_success( WAPM_Rules::run_rule_batch( $rule, $batch_id, $paged ) );
	}

	/* ---------- زمان‌بندی ---------- */

	public function save_schedule(): void {
		$this->guard();
		$raw = json_decode( wp_unslash( $_POST['schedule'] ?? '' ), true );
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( [ 'message' => __( 'داده زمان‌بندی نامعتبر است.', 'wapm' ) ] );
		}
		WAPM_Scheduler::save_schedule( $raw );
		wp_send_json_success( [ 'schedules' => WAPM_Scheduler::all() ] );
	}

	public function delete_schedule(): void {
		$this->guard();
		WAPM_Scheduler::delete_schedule( sanitize_key( $_POST['schedule_id'] ?? '' ) );
		wp_send_json_success( [ 'schedules' => WAPM_Scheduler::all() ] );
	}

	/* ---------- Export / Import CSV ---------- */

	public function export_csv(): void {
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), self::NONCE ) ) {
			wp_die( esc_html__( 'دسترسی نامعتبر.', 'wapm' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'wapm' ) );
		}

		$raw_f    = json_decode( wp_unslash( $_GET['filters'] ?? '{}' ), true );
		$raw_s    = json_decode( wp_unslash( $_GET['settings'] ?? '{}' ), true );
		$filters  = WAPM_Product_Handler::sanitize_filters( is_array( $raw_f ) ? $raw_f : [] );
		$settings = WAPM_Price_Engine::sanitize_settings( is_array( $raw_s ) ? $raw_s : [] );

		WAPM_CSV_Handler::stream_export( $filters, $settings );
	}

	/**
	 * ورود قیمت جدید از CSV.
	 * ستون‌های لازم: Product ID (یا SKU) و New Price. ستون Price Type اختیاری (regular/sale).
	 */
	public function import_csv(): void {
		$this->guard();

		if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'فایلی انتخاب نشده است.', 'wapm' ) ] );
		}

		$check = wp_check_filetype( sanitize_file_name( $_FILES['csv_file']['name'] ), [ 'csv' => 'text/csv' ] );
		if ( 'csv' !== $check['ext'] ) {
			wp_send_json_error( [ 'message' => __( 'فقط فایل CSV مجاز است.', 'wapm' ) ] );
		}

		$result = WAPM_CSV_Handler::import( $_FILES['csv_file']['tmp_name'] );

		if ( ! empty( $result['error'] ) ) {
			wp_send_json_error( [ 'message' => $result['error'] ] );
		}

		wp_send_json_success( [
			'message' => sprintf(
				__( '%1$d قیمت به‌روزرسانی شد، %2$d ردیف نادیده گرفته شد. (Batch: %3$s)', 'wapm' ),
				$result['updated'],
				$result['skipped'],
				$result['batch_id']
			),
		] );
	}
}
