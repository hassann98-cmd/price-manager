<?php
/**
 * WAPM_Stock — موجودی واقعی: تغییر با ثبت تاریخچه، عملیات گروهی، CSV و Undo.
 *
 * ## چرا این فایل
 *
 * تا قبل از این، موجودی فقط با یک input در جدول عوض می‌شد:
 *  - هیچ ردی از تغییر باقی نمی‌ماند (اگر اشتباهی ۱۰۰۰ تا کم می‌کردید، راه برگشتی نبود)،
 *  - `set_stock_quantity()` مستقیم صدا زده می‌شد و مسیر رسمی ووکامرس
 *    (`wc_update_product_stock`) با قلاب‌ها و محاسبه‌ی وضعیت موجودی طی نمی‌شد،
 *  - امکان تغییر گروهی موجودی وجود نداشت،
 *  - برای محصول متغیر، تغییر موجودی روی «والد» اجرا می‌شد که در ووکامرس بی‌معنی است.
 *
 * ## قواعد تصمیم
 *
 * - فقط محصولی که «مدیریت انبار» دارد موجودی عددی دارد؛ بقیه در پیش‌نمایش با
 *   دلیل رد می‌شوند (نه اینکه بی‌صدا صفر شوند).
 * - والد متغیر هرگز موجودی عددی نمی‌گیرد؛ موجودی واریشن‌هاست. پس والد در
 *   عملیات گروهی به واریشن‌هایش باز می‌شود (`expand_ids`).
 * - کاهش هرگز موجودی را منفی نمی‌کند، مگر محصول پیش‌خرید (backorders) داشته باشد.
 * - وضعیت موجودی را ووکامرس از تعداد نتیجه می‌گیرد؛ ما دوباره از خودمان
 *   نمی‌نویسیم تا با منطق backorder نجنگیم.
 * - هر تغییر (چه inline چه گروهی) با `batch_id` ثبت می‌شود و فقط اگر مقدار فعلی
 *   همان چیزی باشد که ما گذاشتیم، قابل بازگردانی است (وگرنه مثل Rollback قیمت،
 *   تغییر بعدیِ دیگران را پاک می‌کند).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Stock {

	const DB_VERSION = '1.0';
	const OPT_DB     = 'wapm_stock_db_version';

	/** سقف امنیتی: یک عملیات گروهی بیش از این تعداد محصول را در یک درخواست عوض نمی‌کند. */
	const CHUNK = 50;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wapm_stock_log';
	}

	public static function create_table(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(40) NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			product_name TEXT NOT NULL,
			sku VARCHAR(120) NOT NULL DEFAULT '',
			old_qty VARCHAR(30) NOT NULL DEFAULT '',
			new_qty VARCHAR(30) NOT NULL DEFAULT '',
			delta VARCHAR(30) NOT NULL DEFAULT '',
			operation VARCHAR(60) NOT NULL DEFAULT '',
			undone TINYINT(1) NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY batch_id (batch_id),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPT_DB, self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/* ---------- محاسبه (توابع خالص — قابل تست) ---------- */

	/**
	 * موجودی جدید را حساب می‌کند.
	 *
	 * @param float  $old            موجودی فعلی (۰ اگر نامعلوم)
	 * @param string $mode           set | increase | decrease
	 * @param string $unit           count | percent
	 * @param float  $amount         مقدار (برای count، عدد؛ برای percent، درصد)
	 * @param bool   $allow_negative اجازه‌ی منفی‌شدن (محصول پیش‌خرید)
	 * @return float موجودی جدید (گردشده به ۳ رقم)
	 */
	public static function compute_qty( float $old, string $mode, string $unit, float $amount, bool $allow_negative = false ): float {
		$amount = abs( $amount );

		if ( 'percent' === $unit ) {
			// درصد از موجودی فعلی؛ روی صفر اثری ندارد.
			$amount = abs( $old ) * ( $amount / 100 );
		}

		switch ( $mode ) {
			case 'increase':
				$new = $old + $amount;
				break;
			case 'decrease':
				$new = $old - $amount;
				break;
			default: // set
				$new = $amount;
				break;
		}

		if ( ! $allow_negative && $new < 0 ) {
			$new = 0.0;
		}

		return round( $new, 3 );
	}

	/** آیا این تغییر واقعاً چیزی را عوض می‌کند؟ */
	public static function needs_change( float $old, float $new ): bool {
		return abs( $new - $old ) > 0.000001;
	}

	/** آیا مقدار موجودی معتبر است؟ (NaN/اینفینیتی/عدد بزرگ) */
	public static function is_valid_amount( float $amount, string $unit ): bool {
		if ( ! is_finite( $amount ) || $amount < 0 ) {
			return false;
		}
		if ( 'count' === $unit && $amount > 100000000 ) {
			return false;
		}
		if ( 'percent' === $unit && $amount > 1000 ) {
			return false;
		}
		return true;
	}

	/**
	 * ورودی خام کاربر → شکل یکدست و امن.
	 *
	 * @return array{mode:string,unit:string,amount:float}|null
	 */
	public static function sanitize_operation( array $raw ): ?array {
		$mode = in_array( $raw['mode'] ?? '', [ 'set', 'increase', 'decrease' ], true ) ? $raw['mode'] : '';
		$unit = in_array( $raw['unit'] ?? '', [ 'count', 'percent' ], true ) ? $raw['unit'] : 'count';

		// «تنظیم» فقط با تعداد معنی دارد؛ درصدِ یک عدد ثابت بی‌معنی است.
		if ( 'set' === $mode ) {
			$unit = 'count';
		}

		$amount = (float) ( $raw['amount'] ?? 0 );

		if ( '' === $mode || ! self::is_valid_amount( $amount, $unit ) ) {
			return null;
		}

		return [ 'mode' => $mode, 'unit' => $unit, 'amount' => $amount ];
	}

	/* ---------- قابلیت تغییر ---------- */

	/**
	 * آیا می‌توان موجودی این محصول را عددی تغییر داد؟
	 *
	 * @return array{ok:bool,reason:string,qty:float}
	 */
	public static function can_manage( $product ): array {
		if ( ! $product instanceof WC_Product ) {
			return [ 'ok' => false, 'reason' => 'not_found', 'qty' => 0.0 ];
		}

		if ( $product->is_type( 'variable' ) ) {
			return [ 'ok' => false, 'reason' => 'variable_parent', 'qty' => 0.0 ];
		}

		if ( ! $product->managing_stock() ) {
			return [ 'ok' => false, 'reason' => 'not_managed', 'qty' => 0.0 ];
		}

		$qty = $product->get_stock_quantity();
		return [ 'ok' => true, 'reason' => '', 'qty' => null === $qty ? 0.0 : (float) $qty ];
	}

	/** آیا اجازه‌ی منفی‌شدن موجودی دارد؟ */
	public static function allows_backorders( $product ): bool {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		return $product->backorders_allowed();
	}

	/**
	 * وضعیت موجودی خام (فارسی/انگلیسی/با فاصله) → اسلاگ ووکامرس.
	 *
	 * قبلاً در import_csv یک str_replace بی‌اثر بود که هر سه رشته را به خودشان
	 * نگاشت می‌کرد؛ هر مقدار دیگری هم بی‌صدا نادیده گرفته می‌شد.
	 */
	public static function sanitize_stock_status( string $raw ): string {
		$key = strtolower( trim( sanitize_text_field( $raw ) ) );
		// فاصله، خط تیره، آندرلاین و نیم‌فاصله همه یکسان‌سازی می‌شوند.
		$key = str_replace( [ ' ', '-', '_', "\xE2\x80\x8C" ], '', $key );

		$map = [
			'instock'      => 'instock',
			'موجود'         => 'instock',
			'outofstock'   => 'outofstock',
			'ناموجود'       => 'outofstock',
			'onbackorder'  => 'onbackorder',
			'backorder'    => 'onbackorder',
			'پیشخرید'       => 'onbackorder',
			'پیشفروش'       => 'onbackorder',
		];

		return $map[ $key ] ?? '';
	}

	public static function reason_label( string $reason ): string {
		$map = [
			'not_found'        => __( 'محصول پیدا نشد', 'wapm' ),
			'variable_parent'  => __( 'محصول متغیر — موجودی روی واریشن‌هاست', 'wapm' ),
			'not_managed'      => __( 'مدیریت انبار خاموش است', 'wapm' ),
			'no_change'        => __( 'تغییری لازم نیست', 'wapm' ),
			'conflict'         => __( 'بعد از این عملیات توسط جای دیگری تغییر کرده', 'wapm' ),
			'save_failed'      => __( 'ذخیره نشد', 'wapm' ),
			'invalid'          => __( 'مقدار نامعتبر', 'wapm' ),
			'managed_status'   => __( 'وضعیت این محصول از تعداد موجودی می‌آید', 'wapm' ),
			'no_status_column' => __( 'ستون Stock Status خالی یا نامعتبر است و مدیریت انبار هم خاموش است', 'wapm' ),
		];
		return $map[ $reason ] ?? $reason;
	}

	/* ---------- تغییر واقعی ---------- */

	/**
	 * موجودی یک محصول را با مسیر رسمی ووکامرس عوض می‌کند و ثبت می‌کند.
	 *
	 * @param int    $product_id
	 * @param float  $new_qty
	 * @param string $operation  inline_edit | bulk_set | bulk_increase | bulk_decrease | import | undo
	 * @param string $batch_id
	 * @return array{ok:bool,reason:string,old?:float,new?:float,row?:array}
	 */
	public static function set_quantity( int $product_id, float $new_qty, string $operation = 'inline_edit', string $batch_id = '' ): array {
		$product = wc_get_product( $product_id );
		$check   = self::can_manage( $product );

		if ( ! $check['ok'] ) {
			return [ 'ok' => false, 'reason' => $check['reason'] ];
		}

		$old = $check['qty'];

		if ( ! self::is_valid_amount( $new_qty, 'count' ) ) {
			return [ 'ok' => false, 'reason' => 'invalid' ];
		}

		if ( ! self::needs_change( $old, $new_qty ) ) {
			return [ 'ok' => false, 'reason' => 'no_change' ];
		}

		$result = wc_update_product_stock( $product, $new_qty, 'set' );

		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'reason' => 'save_failed' ];
		}

		// ترتیب مهم است: لاگ باید مقدار واقعاً ذخیره‌شده را ثبت کند، نه مقداری
		// که خواسته بودیم (ووکامرس می‌تواند با گرد کردن/فیلتر تغییرش دهد).
		$fresh = wc_get_product( $product_id );
		$fresh_qty = $fresh ? $fresh->get_stock_quantity() : null;
		$saved = null === $fresh_qty ? $new_qty : (float) $fresh_qty;

		self::log( [
			'batch_id'     => '' !== $batch_id ? $batch_id : 'stock_' . wp_generate_password( 10, false ),
			'product_id'   => $product_id,
			'parent_id'    => (int) $product->get_parent_id(),
			'product_name' => $product->get_name(),
			'sku'          => $product->get_sku(),
			'old_qty'      => $old,
			'new_qty'      => $saved,
			'operation'    => $operation,
		] );

		if ( $product->is_type( 'variation' ) ) {
			WC_Product_Variable::sync( $product->get_parent_id() );
		}

		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		if ( class_exists( 'WAPM_List' ) ) {
			WAPM_List::purge_caches( $product_id, $parent ? $parent->get_id() : 0 );
		}

		return [
			'ok'  => true,
			'old' => $old,
			'new' => $saved,
			'row' => class_exists( 'WAPM_List' ) ? WAPM_List::build_row( wc_get_product( $product_id ), $parent ) : [],
		];
	}

	/**
	 * تغییر وضعیت موجودی (موجود/ناموجود/پیش‌خرید) با ثبت تاریخچه.
	 *
	 * محصولی که مدیریت انبار دارد، وضعیتش از تعداد می‌آید؛ برای آن‌ها وضعیت دستی
	 * ست نمی‌شود (وگرنه فردا با اولین تغییر موجودی بازنویسی می‌شد).
	 *
	 * @return array{ok:bool,reason:string,row?:array}
	 */
	public static function set_status( int $product_id, string $status, string $operation = 'status', string $batch_id = '' ): array {
		if ( ! in_array( $status, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
			return [ 'ok' => false, 'reason' => 'invalid' ];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [ 'ok' => false, 'reason' => 'not_found' ];
		}

		if ( $product->managing_stock() ) {
			return [ 'ok' => false, 'reason' => 'managed_status' ];
		}

		$old = $product->get_stock_status( 'edit' );
		if ( $old === $status ) {
			return [ 'ok' => false, 'reason' => 'no_change' ];
		}

		$product->set_stock_status( $status );
		$product->save();

		self::log( [
			'batch_id'     => '' !== $batch_id ? $batch_id : 'stock_' . wp_generate_password( 10, false ),
			'product_id'   => $product_id,
			'parent_id'    => (int) $product->get_parent_id(),
			'product_name' => $product->get_name(),
			'sku'          => $product->get_sku(),
			'old_qty'      => $old,
			'new_qty'      => $status,
			'operation'    => $operation,
		] );

		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;
		if ( $parent ) {
			WC_Product_Variable::sync( $parent->get_id() );
		}
		if ( class_exists( 'WAPM_List' ) ) {
			WAPM_List::purge_caches( $product_id, $parent ? $parent->get_id() : 0 );
		}

		return [
			'ok'  => true,
			'row' => class_exists( 'WAPM_List' ) ? WAPM_List::build_row( wc_get_product( $product_id ), $parent ) : [],
		];
	}

	/* ---------- پیش‌نمایش و اجرای گروهی ---------- */

	/**
	 * والد متغیر را به واریشن‌هایش باز می‌کند.
	 *
	 * موجودی عددی در ووکامرس روی واریشن‌هاست، نه روی والد. پس اگر کاربر یک
	 * محصول متغیر را انتخاب کند، عملیات باید روی فرزندانش اجرا شود؛ در غیر این
	 * صورت همه با دلیل «variable_parent» رد می‌شدند و کارِ کاربر بی‌نتیجه می‌ماند.
	 *
	 * @param int[] $ids
	 * @return int[] شناسه‌های نهایی (بدون تکرار)
	 */
	public static function expand_ids( array $ids, bool $with_variations = true ): array {
		$out = [];

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}

			$product = wc_get_product( $id );

			if ( $with_variations && $product && $product->is_type( 'variable' ) ) {
				$children = $product->get_children();
				foreach ( (array) $children as $child_id ) {
					$out[] = absint( $child_id );
				}
				continue;
			}

			$out[] = $id;
		}

		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * محاسبه‌ی تغییر برای یک لیست محصول، بدون هیچ نوشتنی.
	 *
	 * @param int[] $ids
	 * @param array $op خروجی sanitize_operation
	 * @return array{rows:array,will_change:int,skipped:array<string,int>}
	 */
	public static function preview( array $ids, array $op, bool $with_variations = true ): array {
		$ids        = self::expand_ids( $ids, $with_variations );
		$rows       = [];
		$will       = 0;
		$skipped    = [];
		$batch_seen = [];

		foreach ( $ids as $id ) {
			$id      = absint( $id );
			$product = $id ? wc_get_product( $id ) : null;

			if ( ! $product ) {
				$skipped['not_found'] = ( $skipped['not_found'] ?? 0 ) + 1;
				continue;
			}

			// هر محصول یک ردیف (اگر هم والد و هم واریشن در لیست باشند، جدا حساب می‌شوند).
			if ( isset( $batch_seen[ $id ] ) ) {
				continue;
			}
			$batch_seen[ $id ] = true;

			$check = self::can_manage( $product );
			if ( ! $check['ok'] ) {
				$skipped[ $check['reason'] ] = ( $skipped[ $check['reason'] ] ?? 0 ) + 1;
				continue;
			}

			$new = self::compute_qty( $check['qty'], $op['mode'], $op['unit'], $op['amount'], self::allows_backorders( $product ) );

			if ( ! self::needs_change( $check['qty'], $new ) ) {
				$skipped['no_change'] = ( $skipped['no_change'] ?? 0 ) + 1;
				continue;
			}

			$will++;

			$rows[] = [
				'id'      => $id,
				'name'    => $product->get_name(),
				'sku'     => $product->get_sku(),
				'type'    => $product->get_type(),
				'old'     => $check['qty'],
				'new'     => $new,
				'delta'   => round( $new - $check['qty'], 3 ),
				'percent' => $check['qty'] > 0 ? round( ( ( $new - $check['qty'] ) / $check['qty'] ) * 100, 1 ) : null,
				'backorders' => self::allows_backorders( $product ),
			];
		}

		return [ 'rows' => $rows, 'will_change' => $will, 'skipped' => $skipped ];
	}

	/**
	 * اجرای گروهی روی حداکثر CHUNK محصول.
	 *
	 * @param int[] $ids
	 * @return array{applied:int,failed:int,rows:array,reasons:array<string,int>,batch_id:string}
	 */
	public static function apply_bulk( array $ids, array $op, string $batch_id, string $operation = '', bool $with_variations = true ): array {
		if ( ! isset( $op['mode'], $op['unit'], $op['amount'] ) ) {
			return [ 'applied' => 0, 'failed' => 0, 'rows' => [], 'reasons' => [ 'invalid' => 1 ], 'batch_id' => $batch_id ];
		}

		$ids     = array_slice( self::expand_ids( $ids, $with_variations ), 0, self::CHUNK );
		$op_name = '' !== $operation ? $operation : 'bulk_' . $op['mode'];

		$result = [
			'applied' => 0,
			'failed'  => 0,
			'rows'    => [],
			'reasons' => [],
			'batch_id' => $batch_id,
		];

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$check   = self::can_manage( $product );

			if ( ! $check['ok'] ) {
				$result['failed']++;
				$result['reasons'][ $check['reason'] ] = ( $result['reasons'][ $check['reason'] ] ?? 0 ) + 1;
				continue;
			}

			$new = self::compute_qty( $check['qty'], $op['mode'], $op['unit'], $op['amount'], self::allows_backorders( $product ) );

			$done = self::set_quantity( $id, $new, $op_name, $batch_id );

			if ( ! empty( $done['ok'] ) ) {
				$result['applied']++;
				$result['rows'][] = $done['row'];
			} else {
				$result['failed']++;
				$result['reasons'][ $done['reason'] ] = ( $result['reasons'][ $done['reason'] ] ?? 0 ) + 1;
			}
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return $result;
	}

	/* ---------- تاریخچه و Undo ---------- */

	public static function log( array $data ): void {
		global $wpdb;

		$old = $data['old_qty'] ?? '';
		$new = $data['new_qty'] ?? '';

		$delta = '';
		if ( is_numeric( $old ) && is_numeric( $new ) ) {
			$delta = (string) round( (float) $new - (float) $old, 3 );
		}

		$wpdb->insert(
			self::table(),
			[
				'batch_id'     => sanitize_text_field( (string) ( $data['batch_id'] ?? '' ) ),
				'product_id'   => absint( $data['product_id'] ?? 0 ),
				'parent_id'    => absint( $data['parent_id'] ?? 0 ),
				'product_name' => sanitize_text_field( (string) ( $data['product_name'] ?? '' ) ),
				'sku'          => sanitize_text_field( (string) ( $data['sku'] ?? '' ) ),
				'old_qty'      => (string) $old,
				'new_qty'      => (string) $new,
				'delta'        => $delta,
				'operation'    => sanitize_text_field( (string) ( $data['operation'] ?? '' ) ),
				'undone'       => 0,
				'user_id'      => get_current_user_id(),
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
		);
	}

	/**
	 * تاریخچه‌ی صفحه‌بندی‌شده.
	 *
	 * @return array{rows:array,total:int,pages:int}
	 */
	public static function get_logs( int $paged = 1, int $per_page = 25, array $args = [] ): array {
		global $wpdb;

		$table    = self::table();
		$per_page = max( 1, min( 200, $per_page ) );
		$paged    = max( 1, $paged );
		$offset   = ( $paged - 1 ) * $per_page;
		$where  = '1=1';
		$params = [];

		if ( ! empty( $args['batch_id'] ) ) {
			$where   .= ' AND batch_id = %s';
			$params[] = sanitize_text_field( (string) $args['batch_id'] );
		}

		if ( ! empty( $args['only_undoable'] ) ) {
			$where .= ' AND undone = 0';
		}

		$query = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results(
			$wpdb->prepare( $query, array_merge( $params, [ $per_page, $offset ] ) ),
			ARRAY_A
		);

		$count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total       = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_query, $params ) )
			: (int) $wpdb->get_var( $count_query );

		foreach ( $rows as &$row ) {
			$user             = get_userdata( (int) $row['user_id'] );
			$row['user_name'] = $user ? $user->display_name : '—';
			$row['date_i18n'] = date_i18n( 'Y/m/d H:i', strtotime( $row['created_at'] ) );
			$row['undoable']  = self::row_undoable( $row );
		}

		return [
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( max( 1, $total ) / max( 1, $per_page ) ),
		];
	}

	/**
	 * آیا این ردیف را می‌توان بازگرداند؟
	 * شرط: مقدار فعلی محصول همان چیزی باشد که این عملیات گذاشته است.
	 */
	private static function row_undoable( array $row ): bool {
		if ( (int) $row['undone'] ) {
			return false;
		}

		$product = wc_get_product( (int) $row['product_id'] );
		if ( ! $product ) {
			return false;
		}

		$current = self::current_value( $product, (string) $row['operation'] );
		return null !== $current && $current === (string) $row['new_qty'];
	}

	private static function current_value( $product, string $operation ): ?string {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( 'status' === $operation || 0 === strpos( $operation, 'status_' ) ) {
			return (string) $product->get_stock_status( 'edit' );
		}

		$qty = $product->get_stock_quantity();
		return null === $qty ? '0' : (string) (float) $qty;
	}

	/** شناسه‌ی آخرین Batch موجودی که قابل بازگردانی است. */
	public static function get_last_batch_id(): string {
		global $wpdb;
		$table = self::table();
		return (string) $wpdb->get_var(
			"SELECT batch_id FROM {$table} WHERE undone = 0 GROUP BY batch_id ORDER BY MAX(id) DESC LIMIT 1"
		);
	}

	/**
	 * بازگردانی یک Batch.
	 *
	 * @param bool $force اگر true، حتی وقتی مقدار فعلی با آنچه ما گذاشتیم فرق دارد.
	 * @return array{restored:int,conflicts:int,skipped:int}
	 */
	public static function undo_batch( string $batch_id, bool $force = false ): array {
		global $wpdb;

		$batch_id = sanitize_text_field( $batch_id );
		if ( '' === $batch_id ) {
			return [ 'restored' => 0, 'conflicts' => 0, 'skipped' => 0 ];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE batch_id = %s AND undone = 0 ORDER BY id ASC', $batch_id ),
			ARRAY_A
		);

		$out = [ 'restored' => 0, 'conflicts' => 0, 'skipped' => 0 ];

		foreach ( (array) $rows as $row ) {
			$product = wc_get_product( (int) $row['product_id'] );
			if ( ! $product ) {
				$out['skipped']++;
				continue;
			}

			$current = self::current_value( $product, (string) $row['operation'] );

			if ( ! $force && $current !== (string) $row['new_qty'] ) {
				$out['conflicts']++;
				continue;
			}

			$is_status = ( 'status' === $row['operation'] || 0 === strpos( (string) $row['operation'], 'status_' ) );

			if ( $is_status ) {
				$back = self::set_status( (int) $row['product_id'], (string) $row['old_qty'], 'undo', $batch_id . '_undo' );
			} else {
				$back = self::set_quantity( (int) $row['product_id'], (float) $row['old_qty'], 'undo', $batch_id . '_undo' );
			}

			if ( ! empty( $back['ok'] ) ) {
				$out['restored']++;
				$wpdb->update( self::table(), [ 'undone' => 1 ], [ 'id' => (int) $row['id'] ], [ '%d' ], [ '%d' ] );
			} else {
				$out['conflicts']++;
			}
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return $out;
	}

	/* ---------- CSV ---------- */

	/**
	 * ردیف‌های CSV موجودی برای فیلترهای داده‌شده (استریم مستقیم).
	 */
	public static function stream_export( array $filters ): void {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wapm-stock-export-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'Product ID', 'Variation ID', 'Parent ID', 'Name', 'SKU', 'Manage Stock', 'Stock Quantity', 'Stock Status', 'Backorders' ] );

		$paged = 1;
		do {
			$targets = WAPM_Product_Handler::get_target_ids( $filters, $paged, 100 );

			foreach ( $targets['ids'] as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}

				$rows = [ $product ];

				// والد متغیر: خودش موجودی ندارد، پس واریشن‌ها زیرش می‌آیند.
				if ( $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $vid ) {
						$variation = wc_get_product( $vid );
						if ( $variation ) {
							$rows[] = $variation;
						}
					}
				}

				foreach ( $rows as $p ) {
					$is_var = $p->is_type( 'variation' );

					fputcsv( $out, [
						$is_var ? $p->get_parent_id() : $p->get_id(),
						$is_var ? $p->get_id() : '',
						$p->get_parent_id(),
						$p->get_name(),
						$p->get_sku(),
						$p->managing_stock() ? 'yes' : 'no',
						null === $p->get_stock_quantity() ? '' : wc_stock_amount( $p->get_stock_quantity() ),
						$p->get_stock_status(),
						$p->get_backorders(),
					] );
				}
			}

			$paged++;
			$more = ! empty( $targets['ids'] ) && ( ( $paged - 1 ) * 100 ) < $targets['total'];
		} while ( $more );

		fclose( $out );
		exit;
	}

	/**
	 * ورود موجودی از CSV.
	 *
	 * ستون‌های شناسایی (به ترتیب اولویت): Variation ID، Product ID، SKU.
	 * ستون مقدار: Stock Quantity (اجباری). ستون اختیاری: Stock Status.
	 *
	 * @param bool $dry_run فقط گزارش، بدون تغییر
	 * @return array{rows:int,applied:int,skipped:int,errors:array<int,string>,batch_id:string,preview:array}
	 */
	public static function import_csv( string $tmp_path, bool $dry_run = true ): array {
		$out = [
			'rows'      => 0,
			'applied'   => 0,
			'skipped'   => 0,
			'unchanged' => 0,
			'errors'    => [],
			'preview'   => [],
			'batch_id'  => 'import_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false ),
		];

		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return $out;
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			return $out;
		}

		// BOM اکسل
		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );

		$map = [];
		foreach ( $header as $i => $name ) {
			$key         = strtolower( trim( preg_replace( '/[\s_\-]+/', '_', (string) $name ) ) );
			$map[ $key ] = $i;
		}

		$col = static function ( array $row, array $map, array $names ): string {
			foreach ( $names as $n ) {
				if ( isset( $map[ $n ] ) && isset( $row[ $map[ $n ] ] ) ) {
					return trim( (string) $row[ $map[ $n ] ] );
				}
			}
			return '';
		};

		$line = 1;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$line++;

			if ( ! is_array( $row ) || 0 === count( array_filter( $row, static fn( $v ) => '' !== trim( (string) $v ) ) ) ) {
				continue;
			}

			$out['rows']++;

			$variation_id = $col( $row, $map, [ 'variation_id', 'variation', 'variationid' ] );
			$product_id   = $col( $row, $map, [ 'product_id', 'product', 'id' ] );
			$sku          = $col( $row, $map, [ 'sku' ] );
			$qty_raw      = $col( $row, $map, [ 'stock_quantity', 'quantity', 'qty', 'stock' ] );
			$status_raw   = $col( $row, $map, [ 'stock_status', 'status' ] );

			$target_id = 0;
			if ( $variation_id && is_numeric( $variation_id ) ) {
				$target_id = (int) $variation_id;
			} elseif ( $product_id && is_numeric( $product_id ) ) {
				$target_id = (int) $product_id;
			} elseif ( '' !== $sku ) {
				$target_id = (int) wc_get_product_id_by_sku( $sku );
			}

			if ( ! $target_id ) {
				$out['skipped']++;
				$out['errors'][] = sprintf(
					/* translators: %d: line number */
					__( 'خط %d: محصول پیدا نشد (نه با شناسه و نه با SKU).', 'wapm' ),
					$line
				);
				continue;
			}

			$product = wc_get_product( $target_id );
			if ( ! $product ) {
				$out['skipped']++;
				$out['errors'][] = sprintf(
					/* translators: %d: line */
					__( 'خط %d: محصول پیدا نشد.', 'wapm' ),
					$line
				);
				continue;
			}

			$check      = self::can_manage( $product );
			$status_new = self::sanitize_stock_status( $status_raw );
			$has_qty    = '' !== $qty_raw && is_numeric( $qty_raw );
			$new        = $has_qty ? (float) $qty_raw : 0.0;

			/*
			 * دو چیز مستقل در یک ردیف CSV: تعداد و وضعیت.
			 *  - محصولی که «مدیریت انبار» دارد: فقط تعداد معنی دارد و وضعیت از آن
			 *    نتیجه می‌شود (ستون Stock Status در خروجی فقط اطلاعی است).
			 *  - محصولی که مدیریت انبار ندارد: فقط وضعیت دستی معنی دارد.
			 * قبلاً هر ردیفِ بدون مدیریت انبار پیش از رسیدن به بخش وضعیت رد می‌شد،
			 * پس خروجی خودِ افزونه قابل بازخوانی نبود.
			 */
			$do_qty    = $check['ok'] && $has_qty;
			$do_status = ! $product->managing_stock() && '' !== $status_new;

			if ( ! $do_qty && ! $do_status ) {
				$out['skipped']++;

				$reason = ( ! $product->managing_stock() && '' === $status_new )
					? 'no_status_column'
					: $check['reason'];

				$out['errors'][] = sprintf(
					/* translators: 1: line, 2: reason */
					__( 'خط %1$d: %2$s', 'wapm' ),
					$line,
					self::reason_label( (string) $reason )
				);
				continue;
			}

			if ( $do_qty && ! self::is_valid_amount( $new, 'count' ) ) {
				$out['skipped']++;
				$out['errors'][] = sprintf(
					/* translators: %d: line */
					__( 'خط %d: مقدار موجودی معتبر نیست.', 'wapm' ),
					$line
				);
				continue;
			}

			// ردیفی که هیچ تفاوتی ندارد نباید در شمارش «تغییر» بیاید.
			if ( $do_qty && ! self::needs_change( $check['qty'], $new ) ) {
				$out['unchanged']++;
				continue;
			}

			if ( $do_status && $product->get_stock_status( 'edit' ) === $status_new ) {
				$out['unchanged']++;
				continue;
			}

			if ( count( $out['preview'] ) < 50 ) {
				$out['preview'][] = [
					'id'   => $target_id,
					'name' => $product->get_name(),
					'sku'  => $product->get_sku(),
					'old'  => $do_qty ? $check['qty'] : $product->get_stock_status( 'edit' ),
					'new'  => $do_qty ? $new : $status_new,
					'kind' => $do_qty ? 'qty' : 'status',
				];
			}

			if ( $dry_run ) {
				$out['applied']++;
				continue;
			}

			$done = $do_qty
				? self::set_quantity( $target_id, $new, 'import', $out['batch_id'] )
				: self::set_status( $target_id, $status_new, 'status_import', $out['batch_id'] );

			if ( ! empty( $done['ok'] ) ) {
				$out['applied']++;
			} else {
				$out['skipped']++;
				$out['errors'][] = sprintf(
					/* translators: 1: line, 2: reason */
					__( 'خط %1$d: %2$s', 'wapm' ),
					$line,
					self::reason_label( (string) ( $done['reason'] ?? '' ) )
				);
			}
		}

		if ( count( $out['errors'] ) > 200 ) {
			$out['errors']    = array_slice( $out['errors'], 0, 200 );
			$out['truncated'] = true;
		}

		fclose( $handle );

		return $out;
	}
}
