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
			$row['date_i18n'] = date_i18n( 'Y/m/d H:i', strtotime( $row['created_at'] ) );
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
	 * بازگردانی یک Batch (قیمت‌ها به مقدار قبل برمی‌گردند).
	 *
	 * @return int تعداد رکوردهای بازگردانی‌شده
	 */
	public static function rollback_batch( string $batch_id ): int {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s AND rolled_back = 0", $batch_id ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		$restored        = 0;
		$new_batch       = 'rb_' . wp_generate_password( 12, false );
		$touched_parents = [];

		foreach ( $rows as $row ) {
			$product = wc_get_product( (int) $row['product_id'] );
			if ( ! $product ) {
				continue;
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

			$restored++;
		}

		foreach ( array_keys( $touched_parents ) as $pid ) {
			WC_Product_Variable::sync( $pid );
		}

		$wpdb->update( $table, [ 'rolled_back' => 1 ], [ 'batch_id' => $batch_id ], [ '%d' ], [ '%s' ] );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return $restored;
	}
}
