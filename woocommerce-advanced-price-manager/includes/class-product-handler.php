<?php
/**
 * WAPM_Product_Handler — انتخاب محصولات هدف، تشخیص برند، جستجو
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Product_Handler {

	/**
	 * تشخیص خودکار taxonomy برند (افزونه‌های رایج برند + attribute).
	 */
	public static function detect_brand_taxonomies(): array {
		$candidates = [ 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand', 'pa_برند' ];
		$found      = [];
		foreach ( $candidates as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$found[] = $tax;
			}
		}
		// هر attribute که نامش شامل brand/برند باشد.
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attr ) {
				$tax = wc_attribute_taxonomy_name( $attr->attribute_name );
				if ( ! in_array( $tax, $found, true ) &&
					( false !== stripos( $attr->attribute_label, 'برند' ) || false !== stripos( $attr->attribute_name, 'brand' ) ) ) {
					$found[] = $tax;
				}
			}
		}
		return $found;
	}

	/**
	 * فیلترهای ورودی را پاک‌سازی می‌کند.
	 */
	public static function sanitize_filters( array $raw ): array {
		$scope = in_array( $raw['scope'] ?? '', [ 'all', 'selected', 'category', 'brand', 'attribute' ], true )
			? $raw['scope'] : 'all';

		return [
			'scope'         => $scope,
			'product_ids'   => array_map( 'absint', (array) ( $raw['product_ids'] ?? [] ) ),
			'category_ids'  => array_map( 'absint', (array) ( $raw['category_ids'] ?? [] ) ),
			'brand_tax'     => sanitize_key( $raw['brand_tax'] ?? '' ),
			'brand_ids'     => array_map( 'absint', (array) ( $raw['brand_ids'] ?? [] ) ),
			'attribute_tax' => sanitize_text_field( $raw['attribute_tax'] ?? '' ),
			'attribute_ids' => array_map( 'absint', (array) ( $raw['attribute_ids'] ?? [] ) ),
			'stock_filter'  => in_array( $raw['stock_filter'] ?? '', [ 'none', 'below', 'above' ], true ) ? $raw['stock_filter'] : 'none',
			'stock_value'   => max( 0, intval( $raw['stock_value'] ?? 0 ) ),
		];
	}

	/**
	 * آرگومان‌های WP_Query برای محصولات هدف.
	 */
	private static function build_query_args( array $f, int $paged, int $per_page ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $paged ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		];

		switch ( $f['scope'] ) {
			case 'selected':
				$args['post__in']       = ! empty( $f['product_ids'] ) ? $f['product_ids'] : [ 0 ];
				$args['posts_per_page'] = -1; // انتخابی‌ها معمولاً محدودند.
				break;

			case 'category':
				$args['tax_query'] = [ [
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $f['category_ids'],
					'include_children' => true,
				] ];
				break;

			case 'brand':
				$tax = $f['brand_tax'];
				if ( $tax && taxonomy_exists( $tax ) ) {
					$args['tax_query'] = [ [
						'taxonomy' => $tax,
						'field'    => 'term_id',
						'terms'    => $f['brand_ids'],
					] ];
				}
				break;

			case 'attribute':
				$tax = $f['attribute_tax'];
				if ( $tax && taxonomy_exists( $tax ) ) {
					$args['tax_query'] = [ [
						'taxonomy' => $tax,
						'field'    => 'term_id',
						'terms'    => $f['attribute_ids'],
					] ];
				}
				break;
		}

		return $args;
	}

	/**
	 * شناسه محصولات هدف (صفحه‌بندی‌شده برای Batch Processing).
	 *
	 * @return array{ids: int[], total: int}
	 */
	public static function get_target_ids( array $f, int $paged = 1, int $per_page = 25 ): array {
		$query = new WP_Query( self::build_query_args( $f, $paged, $per_page ) );
		$ids   = array_map( 'intval', $query->posts );

		// فیلتر موجودی انبار (بعد از کوئری، چون _stock برای متغیرها روی واریشن است).
		if ( 'none' !== $f['stock_filter'] && $f['stock_value'] > 0 ) {
			$ids = array_values( array_filter( $ids, function ( $id ) use ( $f ) {
				$product = wc_get_product( $id );
				if ( ! $product ) {
					return false;
				}
				$stock = $product->get_stock_quantity();
				if ( null === $stock && $product->is_type( 'variable' ) ) {
					$stock = 0;
					foreach ( $product->get_children() as $vid ) {
						$v = wc_get_product( $vid );
						if ( $v && null !== $v->get_stock_quantity() ) {
							$stock += (int) $v->get_stock_quantity();
						}
					}
				}
				if ( null === $stock ) {
					return false;
				}
				return ( 'below' === $f['stock_filter'] ) ? $stock < $f['stock_value'] : $stock > $f['stock_value'];
			} ) );
		}

		return [
			'ids'   => $ids,
			'total' => (int) ( ( 'selected' === $f['scope'] ) ? count( $ids ) : $query->found_posts ),
		];
	}

	/**
	 * شمارش کل محصولات متاثر (برای نمایش قبل از اجرا).
	 */
	public static function count_targets( array $f ): int {
		if ( 'none' !== $f['stock_filter'] && $f['stock_value'] > 0 ) {
			// با فیلتر موجودی باید همه صفحات را بشماریم.
			$count = 0;
			$paged = 1;
			do {
				$batch = self::get_target_ids( $f, $paged, 200 );
				$count += count( $batch['ids'] );
				$paged++;
				$more = count( $batch['ids'] ) > 0 && ( $paged - 1 ) * 200 < $batch['total'];
			} while ( $more );
			return $count;
		}
		$result = self::get_target_ids( $f, 1, 1 );
		return $result['total'];
	}

	/**
	 * جستجوی محصول بر اساس نام یا SKU (برای انتخاب دستی).
	 */
	public static function search_products( string $term, int $limit = 20 ): array {
		$results = [];
		$seen    = [];

		// جستجوی SKU
		$sku_ids = wc_get_products( [
			'sku'    => $term,
			'limit'  => $limit,
			'return' => 'ids',
			'status' => 'publish',
		] );

		// جستجوی نام
		$name_query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $term,
			'fields'         => 'ids',
			'posts_per_page' => $limit,
		] );

		foreach ( array_merge( (array) $sku_ids, $name_query->posts ) as $id ) {
			$id = (int) $id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$product     = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$sku       = $product->get_sku();
			$results[] = [
				'id'   => $id,
				'text' => $product->get_name() . ( $sku ? " (SKU: {$sku})" : '' ),
			];
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * لیست termهای یک taxonomy (دسته، برند، ویژگی).
	 */
	public static function get_terms_list( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		return array_map( fn( $t ) => [ 'id' => $t->term_id, 'text' => $t->name . " ({$t->count})" ], $terms );
	}

	/**
	 * لیست attribute های ووکامرس.
	 */
	public static function get_attribute_taxonomies(): array {
		$out = [];
		foreach ( wc_get_attribute_taxonomies() as $attr ) {
			$out[] = [
				'taxonomy' => wc_attribute_taxonomy_name( $attr->attribute_name ),
				'label'    => $attr->attribute_label,
			];
		}
		return $out;
	}
}
