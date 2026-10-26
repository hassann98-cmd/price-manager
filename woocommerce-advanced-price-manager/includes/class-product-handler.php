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

			// فیلتر «وضعیت ترب» — مثلاً «همه‌ی محصولاتی که از رقیب گران‌ترند».
			'torob_status'  => in_array(
				$raw['torob_status'] ?? '',
				[ 'any', 'none', 'pricier', 'cheaper', 'equal', 'no_competitor', 'needs_shop_id', 'no_price', 'stale', 'fresh' ],
				true
			) ? $raw['torob_status'] : '',
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
				// توجه: «selected» از این مسیر کوئری نمی‌شود؛ در get_selected_page()
				// با array_slice صفحه‌بندی می‌شود تا `paged` واقعاً اثر داشته باشد.
				$args['post__in'] = ! empty( $f['product_ids'] ) ? $f['product_ids'] : [ 0 ];
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

		if ( class_exists( 'WAPM_Torob' ) ) {
			$torob_clause = WAPM_Torob::meta_clause( $f['torob_status'] ?? '' );
			if ( null !== $torob_clause ) {
				$args['meta_query'] = [ $torob_clause ];
			}
		}

		return $args;
	}

	/**
	 * شناسه محصولات هدف (صفحه‌بندی‌شده برای Batch Processing).
	 *
	 * @return array{ids: int[], total: int}
	 */
	public static function get_target_ids( array $f, int $paged = 1, int $per_page = 25 ): array {
		// دامنه‌ی «محصولات انتخابی»: مجموعه کوچک و صریح است، پس یک‌بار اعتبارسنجی و
		// فیلتر می‌شود و بعد در PHP برش می‌خورد. با روش قبلی (posts_per_page = -1)
		// هر صفحه همه‌ی محصولات انتخابی را برمی‌گرداند و `paged` هیچ اثری نداشت؛ نتیجه
		// صفحه‌بندی بی‌پایان و شمارنده‌ی «۳٬۶۰۰ از ۳۰۰ محصول» بود.
		if ( 'selected' === $f['scope'] ) {
			return self::get_selected_page( $f, $paged, $per_page );
		}

		$query = new WP_Query( self::build_query_args( $f, $paged, $per_page ) );
		$ids   = array_map( 'intval', $query->posts );

		if ( self::has_stock_filter( $f ) ) {
			$ids = self::filter_by_stock( $ids, $f );
		}

		return [
			'ids'   => $ids,
			'total' => (int) $query->found_posts,
		];
	}

	/**
	 * یک صفحه از محصولات انتخابی + تعداد کل (بعد از فیلتر موجودی).
	 *
	 * @return array{ids: int[], total: int}
	 */
	private static function get_selected_page( array $f, int $paged, int $per_page ): array {
		$requested = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $f['product_ids'] ?? [] ) ) ) ) );
		if ( empty( $requested ) ) {
			return [ 'ids' => [], 'total' => 0 ];
		}

		// فقط محصولات منتشرشده‌ی موجود نگه داشته می‌شوند تا شمارش/صفحه‌بندی با واقعیت بخواند.
		$valid = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'post__in'       => $requested,
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );
		$ids = array_map( 'intval', (array) $valid );
		sort( $ids ); // ترتیب ثابت → صفحه‌بندی پایدار بین درخواست‌ها.

		if ( self::has_stock_filter( $f ) ) {
			$ids = self::filter_by_stock( $ids, $f );
		}

		// مجموعه‌ی انتخابی کوچک است؛ فیلتر ترب در PHP ارزان‌تر از کوئری دوم است.
		if ( class_exists( 'WAPM_Torob' ) && ! empty( $f['torob_status'] ) ) {
			$ids = array_values( array_filter(
				$ids,
				fn( $id ) => WAPM_Torob::matches_status( (int) $id, (string) $f['torob_status'] )
			) );
		}

		$total  = count( $ids );
		$offset = max( 0, ( max( 1, $paged ) - 1 ) * max( 1, $per_page ) );

		return [
			'ids'   => array_slice( $ids, $offset, max( 1, $per_page ) ),
			'total' => $total,
		];
	}

	private static function has_stock_filter( array $f ): bool {
		return 'none' !== $f['stock_filter'] && $f['stock_value'] > 0;
	}

	/**
	 * فیلتر موجودی انبار (بعد از کوئری، چون _stock برای متغیرها روی واریشن است).
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function filter_by_stock( array $ids, array $f ): array {
		return array_values( array_filter( $ids, function ( $id ) use ( $f ) {
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

	/**
	 * شمارش کل محصولات متاثر (برای نمایش قبل از اجرا).
	 */
	public static function count_targets( array $f ): int {
		if ( 'selected' === $f['scope'] ) {
			return self::get_selected_page( $f, 1, PHP_INT_MAX )['total'];
		}

		if ( self::has_stock_filter( $f ) ) {
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
	 * والدهای محصولاتی که SKU یکی از واریشن‌هایشان با عبارت می‌خواند.
	 *
	 * چرا لازم است: wc_get_products( [ 'sku' => … ] ) فقط روی post_type=product
	 * جستجو می‌کند و SKU واریشن‌ها (که روی product_variation است) را پیدا نمی‌کند؛
	 * نتیجه این بود که جستجوی SKU سایز/رنگ در لیست و در انتخابگر محصول «چیزی پیدا
	 * نشد» می‌داد. چون لیست و «محصولات انتخابی» بر پایه‌ی محصول والد کار می‌کنند،
	 * واریشنِ منطبق به والدش نگاشت می‌شود.
	 *
	 * @return int[] شناسه‌ی والدها (یکتا)
	 */
	public static function parent_ids_by_variation_sku( string $term, int $limit = 200 ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return [];
		}

		$variation_ids = get_posts( [
			'post_type'        => 'product_variation',
			'post_status'      => 'publish',
			'posts_per_page'   => $limit,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => [ [
				'key'     => '_sku',
				'value'   => $term,
				'compare' => 'LIKE',
			] ],
		] );

		$parents = [];
		foreach ( (array) $variation_ids as $vid ) {
			$parent_id = (int) wp_get_post_parent_id( (int) $vid );
			if ( $parent_id ) {
				$parents[ $parent_id ] = $parent_id;
			}
		}

		return array_values( $parents );
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

		// SKU واریشن‌ها → والدشان (وگرنه جستجوی SKU سایز/رنگ چیزی پیدا نمی‌کرد).
		$variation_parents = self::parent_ids_by_variation_sku( $term, $limit );

		foreach ( array_merge( (array) $sku_ids, $variation_parents, $name_query->posts ) as $id ) {
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
	 * برچسب چند محصول با شناسه (حفظ ترتیب ورودی) — برای بازگرداندن انتخاب‌ها در فرم.
	 *
	 * @param int[] $ids
	 * @return array<int, array{id:int, text:string}>
	 */
	public static function get_products_by_ids( array $ids, int $limit = 200 ): array {
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, $limit );

		$out = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$sku   = $product->get_sku();
			$out[] = [
				'id'   => $id,
				'text' => $product->get_name() . ( $sku ? " (SKU: {$sku})" : '' ),
			];
		}

		return $out;
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
