<?php
/**
 * WAPM_Logger — تاریخچه تغییرات قیمت + Rollback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Logger {

	const DB_VERSION = '1.0';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wapm_price_log';
	}

	/**
	 * زمان ذخیره‌شده (MySQL، به وقت محلی سایت) → رشته‌ی نمایشی.
	 *
	 * چرا mysql2date و نه strtotime+date_i18n: `created_at` با
	 * `current_time('mysql')` یعنی «زمان دیواری محلی» نوشته می‌شود. `strtotime`
	 * آن را UTC فرض می‌کند و بعد `date_i18n` دوباره offset سایت را اضافه
	 * می‌کند؛ نتیجه: تاریخچه در ایران ۳:۳۰ ساعت جلوتر نشان داده می‌شد.
	 * `mysql2date` خودش رشته را به وقت محلی سایت تفسیر می‌کند.
	 */
	public static function format_time( string $mysql_local ): string {
		if ( '' === trim( $mysql_local ) ) {
			return '—';
		}

		$formatted = mysql2date( 'Y/m/d H:i', $mysql_local, false );

		return is_string( $formatted ) && '' !== $formatted ? $formatted : $mysql_local;
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
			price_type VARCHAR(20) NOT NULL DEFAULT 'regular',
			old_price VARCHAR(30) NOT NULL DEFAULT '',
			new_price VARCHAR(30) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			operation VARCHAR(60) NOT NULL DEFAULT '',
			rolled_back TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY batch_id (batch_id),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( 'wapm_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'wapm_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * ثبت یک رکورد تغییر قیمت.
	 */
	public static function log( array $data ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			[
				'batch_id'     => sanitize_text_field( $data['batch_id'] ?? '' ),
				'product_id'   => absint( $data['product_id'] ?? 0 ),
				'parent_id'    => absint( $data['parent_id'] ?? 0 ),
				'product_name' => sanitize_text_field( $data['product_name'] ?? '' ),
				'sku'          => sanitize_text_field( $data['sku'] ?? '' ),
				'price_type'   => sanitize_key( $data['price_type'] ?? 'regular' ),
				'old_price'    => (string) ( $data['old_price'] ?? '' ),
				'new_price'    => (string) ( $data['new_price'] ?? '' ),
				'user_id'      => get_current_user_id(),
				'operation'    => sanitize_text_field( $data['operation'] ?? '' ),
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		/*
		 * مهر «آخرین تغییر قیمت» روی خود محصول.
		 * برای ستون «آخرین تغییر» در لیست: خواندنش از جدول لاگ برای هر ردیف یعنی
		 * یک کوئری سنگین در هر ردیف؛ با یک متا، ستون بدون کوئری اضافه ساخته می‌شود.
		 */
		$product_id = absint( $data['product_id'] ?? 0 );
		if ( $product_id ) {
			update_post_meta( $product_id, '_wapm_last_changed', current_time( 'mysql' ) );
		}
	}

	/**
	 * آیا این محصول در این Batch قبلاً پردازش شده؟ (برای جلوگیری از اعمال دوباره هنگام Retry)
	 */
	public static function product_done_in_batch( string $batch_id, int $product_id ): bool {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE batch_id = %s AND product_id = %d LIMIT 1",
				$batch_id,
				$product_id
			)
		);
	}

	/**
	 * تاریخچه صفحه‌بندی‌شده.
	 */
	public static function get_logs( int $paged = 1, int $per_page = 25 ): array {
		global $wpdb;
		$table  = self::table();
		$offset = max( 0, ( $paged - 1 ) * $per_page );

		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		foreach ( $rows as &$row ) {
			$user             = get_userdata( (int) $row['user_id'] );
			$row['user_name'] = $user ? $user->display_name : '—';
			$row['date_i18n'] = self::format_time( (string) $row['created_at'] );
		}

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/**
	 * شناسه آخرین Batch قابل بازگردانی.
	 */
	public static function get_last_batch_id(): string {
		global $wpdb;
		$table = self::table();
		return (string) $wpdb->get_var(
			"SELECT batch_id FROM {$table} WHERE rolled_back = 0 AND operation NOT LIKE 'rollback%' ORDER BY id DESC LIMIT 1"
		);
	}

	/**
	 * قیمت فعلی همان فیلدی که لاگ شده (regular یا sale).
	 */
	private static function current_price( WC_Product $product, string $price_type ): string {
		$value = ( 'sale' === $price_type )
			? $product->get_sale_price( 'edit' )
			: $product->get_regular_price( 'edit' );

		return ( null === $value ) ? '' : (string) $value;
	}

	/**
	 * مقایسه‌ی عددی دو قیمتِ ذخیره‌شده به‌صورت رشته ('' = بدون قیمت).
	 */
	private static function same_price( string $a, string $b ): bool {
		$a = trim( $a );
		$b = trim( $b );

		if ( '' === $a && '' === $b ) {
			return true;
		}
		if ( '' === $a || '' === $b ) {
			return false;
		}

		return abs( (float) $a - (float) $b ) < 0.000001;
	}

	/**
	 * بازگردانی یک Batch (قیمت‌ها به مقدار قبل برمی‌گردند).
	 *
	 * محافظ تداخل: اگر قیمت فعلی با «قیمتی که این Batch نوشته بود» یکی نباشد، یعنی
	 * بعد از این Batch کسی (یا عملیات جدیدتری) قیمت را عوض کرده است. در این حالت
	 * بازگردانی آن ردیف انجام نمی‌شود تا تغییر جدیدتر پاک نشود — مگر $force=true.
	 *
	 * @return array{restored:int, conflicts:array<int, array<string, mixed>>, rows:int}
	 */
	public static function rollback_batch( string $batch_id, bool $force = false ): array {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s AND rolled_back = 0", $batch_id ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [ 'restored' => 0, 'conflicts' => [], 'rows' => 0 ];
		}

		$restored        = 0;
		$conflicts       = [];
		$done_ids        = [];
		$new_batch       = 'rb_' . wp_generate_password( 12, false );
		$touched_parents = [];

		foreach ( $rows as $row ) {
			$product = wc_get_product( (int) $row['product_id'] );
			if ( ! $product ) {
				// محصول حذف شده — چیزی برای بازگردانی نیست، ولی نباید Batch را
				// «بازگردانی‌نشده» نگه دارد.
				$done_ids[] = (int) $row['id'];
				continue;
			}

			// آیا قیمت هنوز همان چیزی است که این Batch نوشته بود؟
			$current = self::current_price( $product, (string) $row['price_type'] );
			if ( ! $force && ! self::same_price( (string) $row['new_price'], $current ) ) {
				$conflicts[] = [
					'product_id'   => (int) $row['product_id'],
					'product_name' => $row['product_name'],
					'price_type'   => $row['price_type'],
					'batch_price'  => (string) $row['new_price'],
					'current'      => $current,
					'restore_to'   => (string) $row['old_price'],
				];
				continue; // rolled_back = 0 می‌ماند تا با force قابل بازگردانی باشد.
			}

			$old = $row['old_price']; // مقدار قبل از تغییر — همان چیزی که باید برگردد.

			if ( 'sale' === $row['price_type'] ) {
				$product->set_sale_price( '' === $old ? '' : wc_format_decimal( $old ) );
			} else {
				$product->set_regular_price( '' === $old ? '' : wc_format_decimal( $old ) );
			}
			$product->save();

			if ( $product->is_type( 'variation' ) ) {
				$touched_parents[ $product->get_parent_id() ] = true;
			}

			self::log( [
				'batch_id'     => $new_batch,
				'product_id'   => $product->get_id(),
				'parent_id'    => $product->get_parent_id(),
				'product_name' => $row['product_name'],
				'sku'          => $row['sku'],
				'price_type'   => $row['price_type'],
				'old_price'    => $row['new_price'],
				'new_price'    => $row['old_price'],
				'operation'    => 'rollback:' . $batch_id,
			] );

			$done_ids[] = (int) $row['id'];
			$restored++;
		}

		foreach ( array_keys( $touched_parents ) as $pid ) {
			WC_Product_Variable::sync( $pid );
		}

		// فقط ردیف‌های واقعاً بازگردانی‌شده علامت می‌خورند؛ ردیف‌های متعارض برای
		// تلاش دوباره (با force) و ردیف‌های محصول‌حذف‌شده هم دست‌نخورده می‌مانند.
		if ( $done_ids ) {
			$wpdb->query( "UPDATE {$table} SET rolled_back = 1 WHERE id IN (" . implode( ',', array_map( 'intval', $done_ids ) ) . ')' );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return [ 'restored' => $restored, 'conflicts' => $conflicts, 'rows' => count( $rows ) ];
	}
}
