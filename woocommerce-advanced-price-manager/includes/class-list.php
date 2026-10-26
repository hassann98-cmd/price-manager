<?php
/**
 * WAPM_List — لیست محصولات: جستجو، فیلتر (دسته/موجودی/قیمت/فیلتر هوشمند)، صفحه‌بندی،
 * ویرایش inline قیمت/موجودی، toggle مدیریت انبار، کپی/حذف و عملیات گروهی.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_List {

	/**
	 * پاک‌سازی فیلترهای ورودی (شامل فیلتر هوشمند).
	 */
	public static function sanitize_filters( array $raw ): array {
		$per_page = (int) ( $raw['per_page'] ?? 25 );

		return [
			'search'       => sanitize_text_field( $raw['search'] ?? '' ),
			'category_id'  => absint( $raw['category_id'] ?? 0 ),
			'stock_status' => in_array( $raw['stock_status'] ?? '', [ 'instock', 'outofstock', 'onbackorder' ], true )
				? $raw['stock_status'] : '',
			'per_page'     => in_array( $per_page, [ 25, 50, 100, 200 ], true ) ? $per_page : 25,
			'paged'        => max( 1, absint( $raw['paged'] ?? 1 ) ),

			// فیلتر هوشمند
			'price_min'    => ( isset( $raw['price_min'] ) && '' !== $raw['price_min'] ) ? max( 0, (float) $raw['price_min'] ) : null,
			'price_max'    => ( isset( $raw['price_max'] ) && '' !== $raw['price_max'] ) ? max( 0, (float) $raw['price_max'] ) : null,
			'stock_min'    => ( isset( $raw['stock_min'] ) && '' !== $raw['stock_min'] ) ? max( 0, (int) $raw['stock_min'] ) : null,
			'stock_max'    => ( isset( $raw['stock_max'] ) && '' !== $raw['stock_max'] ) ? max( 0, (int) $raw['stock_max'] ) : null,
			'product_type' => in_array( $raw['product_type'] ?? '', [ 'simple', 'variable' ], true ) ? $raw['product_type'] : '',
			'on_sale'      => ! empty( $raw['on_sale'] ),

			// فیلتر و مرتب‌سازی ترب
			'torob_status' => in_array(
				$raw['torob_status'] ?? '',
				[ 'any', 'none', 'pricier', 'cheaper', 'equal', 'no_competitor', 'needs_shop_id', 'no_price', 'stale', 'fresh' ],
				true
			) ? $raw['torob_status'] : '',
			'orderby'      => in_array(
				$raw['orderby'] ?? '',
				[ 'id_desc', 'name_asc', 'price_asc', 'price_desc', 'torob_diff_desc', 'torob_diff_asc', 'torob_asc' ],
				true
			) ? $raw['orderby'] : 'id_desc',
		];
	}

	/**
	 * کوئری محصولات هدف + تخت‌سازی واریشن‌های محصولات متغیر به‌صورت ردیف‌های جداگانه.
	 *
	 * @return array{rows: array, total: int, pages: int}
	 */
	public static function query( array $f ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => $f['per_page'],
			'paged'          => $f['paged'],
			'orderby'        => 'ID',
			'order'          => 'DESC',
		];

		switch ( $f['orderby'] ) {
			case 'name_asc':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;

			case 'price_asc':
			case 'price_desc':
				// همه‌ی محصولات منتشرشده _price دارند؛ این مرتب‌سازی عنصری حذف نمی‌کند.
				$args['meta_key'] = '_price';
				$args['orderby']  = 'meta_value_num';
				$args['order']    = ( 'price_asc' === $f['orderby'] ) ? 'ASC' : 'DESC';
				break;

			case 'torob_diff_desc':
			case 'torob_diff_asc':
			case 'torob_asc':
				// فقط محصولات دارای داده‌ی ترب (این مرتب‌سازی عمداً فیلترکننده است).
				$args['meta_key'] = WAPM_Torob::META_DIFF_PCT;
				$args['orderby']  = 'meta_value_num';
				$args['order']    = ( 'torob_diff_asc' === $f['orderby'] ) ? 'ASC' : 'DESC';
				break;
		}

		$post_in = null;

		if ( '' !== $f['search'] ) {
			// جستجوی SKU (تطبیق جزئی) + جستجوی نام محصول.
			$sku_ids = wc_get_products( [
				'sku'    => $f['search'],
				'limit'  => -1,
				'return' => 'ids',
				'status' => 'publish',
			] );

			$title_query = new WP_Query( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				's'              => $f['search'],
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] );

			// SKU واریشن‌ها: به والدشان نگاشت می‌شود تا ردیف‌های همان واریشن در لیست
			// نمایش داده شوند. (پیش‌تر جستجوی SKU سایز/رنگ هیچ نتیجه‌ای نداشت.)
			$variation_parents = WAPM_Product_Handler::parent_ids_by_variation_sku( $f['search'] );

			$post_in = array_values( array_unique( array_map( 'intval', array_merge(
				(array) $sku_ids,
				$variation_parents,
				$title_query->posts
			) ) ) );
		}

		if ( $f['on_sale'] ) {
			$on_sale_ids = array_map( 'intval', wc_get_product_ids_on_sale() );
			$post_in     = null === $post_in ? $on_sale_ids : array_values( array_intersect( $post_in, $on_sale_ids ) );
		}

		if ( null !== $post_in ) {
			$args['post__in'] = $post_in ?: [ 0 ];
		}

		if ( $f['category_id'] ) {
			$args['tax_query'][] = [
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $f['category_id'],
				'include_children' => true,
			];
		}

		if ( $f['product_type'] ) {
			$args['tax_query'][] = [
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $f['product_type'],
			];
		}

		if ( isset( $args['tax_query'] ) && count( $args['tax_query'] ) > 1 ) {
			$args['tax_query']['relation'] = 'AND';
		}

		$meta_query = [];

		if ( $f['stock_status'] ) {
			$meta_query[] = [ 'key' => '_stock_status', 'value' => $f['stock_status'] ];
		}

		if ( null !== $f['price_min'] || null !== $f['price_max'] ) {
			$q = [ 'key' => '_price', 'type' => 'NUMERIC' ];
			if ( null !== $f['price_min'] && null !== $f['price_max'] ) {
				$q['value']   = [ $f['price_min'], $f['price_max'] ];
				$q['compare'] = 'BETWEEN';
			} elseif ( null !== $f['price_min'] ) {
				$q['value']   = $f['price_min'];
				$q['compare'] = '>=';
			} else {
				$q['value']   = $f['price_max'];
				$q['compare'] = '<=';
			}
			$meta_query[] = $q;
		}

		if ( null !== $f['stock_min'] || null !== $f['stock_max'] ) {
			$q = [ 'key' => '_stock', 'type' => 'NUMERIC' ];
			if ( null !== $f['stock_min'] && null !== $f['stock_max'] ) {
				$q['value']   = [ $f['stock_min'], $f['stock_max'] ];
				$q['compare'] = 'BETWEEN';
			} elseif ( null !== $f['stock_min'] ) {
				$q['value']   = $f['stock_min'];
				$q['compare'] = '>=';
			} else {
				$q['value']   = $f['stock_max'];
				$q['compare'] = '<=';
			}
			$meta_query[] = $q;
		}

		$torob_clause = WAPM_Torob::meta_clause( $f['torob_status'] );
		if ( null !== $torob_clause ) {
			$meta_query[] = $torob_clause;
		}

		if ( $meta_query ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $args );
		$rows  = [];

		foreach ( $query->posts as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$rows[] = self::build_row( $product );

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation ) {
						$rows[] = self::build_row( $variation, $product );
					}
				}
			}
		}

		return [
			'rows'  => $rows,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		];
	}

	/**
	 * تبدیل یک محصول (یا واریشن) به ردیف نمایشی.
	 */
	public static function build_row( WC_Product $product, ?WC_Product $parent = null ): array {
		$is_variation = $product->is_type( 'variation' );

		$image_id = $product->get_image_id();
		if ( ! $image_id && $parent ) {
			$image_id = $parent->get_image_id();
		}
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );

		$categories   = [];
		$category_ids = [];
		if ( ! $is_variation ) {
			$terms = get_the_terms( $product->get_id(), 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$categories   = wp_list_pluck( $terms, 'name' );
				$category_ids = wp_list_pluck( $terms, 'term_id' );
			}
		}

		$brand_tax   = self::get_brand_taxonomy();
		$brand_names = [];
		$brand_ids   = [];
		if ( ! $is_variation && $brand_tax && taxonomy_exists( $brand_tax ) ) {
			$bterms = get_the_terms( $product->get_id(), $brand_tax );
			if ( $bterms && ! is_wp_error( $bterms ) ) {
				$brand_names = wp_list_pluck( $bterms, 'name' );
				$brand_ids   = wp_list_pluck( $bterms, 'term_id' );
			}
		}

		$variation_label = '';
		if ( $is_variation ) {
			$labels = [];
			foreach ( $product->get_variation_attributes() as $attr_key => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$taxonomy = str_replace( 'attribute_', '', $attr_key );
				if ( taxonomy_exists( $taxonomy ) ) {
					$term     = get_term_by( 'slug', $value, $taxonomy );
					$labels[] = $term ? $term->name : $value;
				} else {
					$labels[] = $value;
				}
			}
			$variation_label = implode( '، ', $labels );
		}

		$stock_labels = [
			'instock'     => __( 'موجود', 'wapm' ),
			'outofstock'  => __( 'ناموجود', 'wapm' ),
			'onbackorder' => __( 'پیش‌خرید', 'wapm' ),
		];
		$stock_status = $product->get_stock_status();

		$regular = $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );

		// محدوده‌ی قیمت واریشن‌ها — برای نمایش read-only در ردیف محصول متغیر.
		$price_range = '';
		if ( $product->is_type( 'variable' ) ) {
			$min = $product->get_variation_price( 'min', false, false );
			$max = $product->get_variation_price( 'max', false, false );
			if ( is_numeric( $min ) && is_numeric( $max ) ) {
				$price_range = ( (float) $min === (float) $max )
					? wp_strip_all_tags( wc_price( $min ) )
					: wp_strip_all_tags( wc_price( $min ) ) . ' – ' . wp_strip_all_tags( wc_price( $max ) );
			}
		}

		return [
			'id'                 => $product->get_id(),
			'parent_id'          => $parent ? $parent->get_id() : ( $is_variation ? $product->get_parent_id() : 0 ),
			'is_variation'       => $is_variation,
			'is_variable'        => $product->is_type( 'variable' ),
			'name'               => $parent ? $parent->get_name() : $product->get_name(),
			'variation_label'    => $variation_label,
			'sku'                => $product->get_sku() ?: ( $parent ? $parent->get_sku() : '' ),
			'image'              => $image_url,
			'regular_price'      => $regular,
			'sale_price'         => $sale,
			'regular_price_fmt'  => '' !== $regular ? wp_strip_all_tags( wc_price( $regular ) ) : '',
			'sale_price_fmt'     => '' !== $sale ? wp_strip_all_tags( wc_price( $sale ) ) : '',
			'price_range'        => $price_range,
			'manage_stock'       => (bool) $product->managing_stock(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'stock_status'       => $stock_status,
			'stock_status_label' => $stock_labels[ $stock_status ] ?? $stock_status,
			'categories'         => $categories,
			'category_ids'       => $category_ids,
			'brand_names'        => $brand_names,
			'brand_ids'          => $brand_ids,
			'edit_url'           => admin_url( 'post.php?post=' . ( $parent ? $parent->get_id() : $product->get_id() ) . '&action=edit' ),
			'view_url'           => $product->get_permalink(),
			'can_duplicate'      => ! $is_variation,
			'can_edit_terms'     => ! $is_variation,
			// قیمت مؤثر (برای محصول متغیر = کمینه‌ی واریشن‌ها) همان چیزی است که ترب
			// به‌عنوان قیمت ما نشان می‌دهد؛ مقایسه بر همین مبنا انجام می‌شود.
			'torob'              => ( ! $is_variation && class_exists( 'WAPM_Torob' ) )
				? WAPM_Torob::get_row_data( $product->get_id(), (float) $product->get_price() )
				: null,
		];
	}

	/**
	 * ردیف یک محصول برای پاسخ Ajax (build_row داخلی است).
	 */
	public static function build_row_for_ajax( WC_Product $product ): array {
		$parent = null;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
		}
		return self::build_row( $product, $parent );
	}

	/**
	 * تشخیص taxonomy برند سایت (کش‌شده در طول یک درخواست).
	 */
	public static function get_brand_taxonomy(): string {
		static $resolved = false;
		static $taxonomy = '';

		if ( ! $resolved ) {
			$resolved = true;
			if ( class_exists( 'WAPM_Product_Handler' ) ) {
				$found    = WAPM_Product_Handler::detect_brand_taxonomies();
				$taxonomy = $found ? (string) $found[0] : '';
			}
		}

		return $taxonomy;
	}

	/**
	 * ویرایش دسته‌بندی/برند یک محصول (فقط محصولات اصلی — واریشن‌ها taxonomy مستقل ندارند).
	 * برخلاف update_field، اینجا بعد از ذخیره، دوباره از دیتابیس (نه از شیء در حافظه) می‌خوانیم
	 * تا مطمئن شویم علامت موفقیت واقعاً منعکس‌کننده‌ی چیزی است که در دیتابیس ذخیره شده، نه فرض خوش‌بینانه.
	 *
	 * @return array{success: bool, message?: string, row: array}
	 */
	public static function update_terms( int $product_id, string $taxonomy, array $term_ids ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return [ 'success' => false, 'message' => __( 'این مورد قابل ویرایش نیست.', 'wapm' ) ];
		}

		if ( ! taxonomy_exists( $taxonomy ) || ! in_array( $taxonomy, [ 'product_cat', self::get_brand_taxonomy() ], true ) ) {
			return [ 'success' => false, 'message' => __( 'taxonomy نامعتبر است.', 'wapm' ) ];
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		$result   = wp_set_object_terms( $product_id, $term_ids, $taxonomy, false );

		if ( is_wp_error( $result ) ) {
			return [ 'success' => false, 'message' => $result->get_error_message(), 'row' => self::build_row( wc_get_product( $product_id ) ) ];
		}

		// بازخوانی واقعی از دیتابیس (نه از کش شیء قبلی) برای تایید واقعی ذخیره‌شدن.
		clean_object_term_cache( $product_id, $taxonomy );
		$saved_ids = wp_get_object_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] );
		$saved_ok  = ! is_wp_error( $saved_ids ) && self::same_ids( (array) $saved_ids, $term_ids );

		wc_delete_product_transients( $product_id );
		self::purge_caches( $product_id );

		// wc_get_product دوباره از دیتابیس/کش تازه می‌خواند تا row واقعاً نسخه‌ی بعد از ذخیره باشد.
		clean_post_cache( $product_id );
		$fresh_product = wc_get_product( $product_id );

		return [
			'success' => $saved_ok,
			'message' => $saved_ok ? '' : __( 'ذخیره انجام شد اما تایید نهایی از دیتابیس ناموفق بود — دوباره تلاش کنید.', 'wapm' ),
			'row'     => self::build_row( $fresh_product ),
		];
	}

	private static function same_ids( array $a, array $b ): bool {
		$a = array_map( 'intval', $a );
		$b = array_map( 'intval', $b );
		sort( $a );
		sort( $b );
		return $a === $b;
	}

	/**
	 * ویرایش inline یک فیلد (قیمت اصلی/ویژه/موجودی) برای یک محصول یا واریشن.
	 *
	 * @return array{success: bool, message?: string, row?: array}
	 */
	public static function update_field( int $product_id, string $field, $value ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [ 'success' => false, 'message' => __( 'محصول یافت نشد.', 'wapm' ) ];
		}

		// قیمت محصول متغیر از واریشن‌ها می‌آید؛ نوشتن _regular_price روی والد
		// ذخیره می‌شود ولی هیچ‌جا نمایش داده نمی‌شود (تغییر بی‌اثر و گمراه‌کننده).
		if ( $product->is_type( 'variable' ) && in_array( $field, [ 'regular_price', 'sale_price' ], true ) ) {
			return [
				'success' => false,
				'message' => __( 'قیمت محصول متغیر باید روی واریشن‌ها تغییر کند، نه روی والد.', 'wapm' ),
			];
		}

		$log_price_type = null;
		$old            = null;

		switch ( $field ) {
			case 'regular_price':
				$new     = wc_format_decimal( $value );
				$sale    = (float) $product->get_sale_price( 'edit' );
				if ( $sale > 0 && '' !== $new && (float) $new <= $sale ) {
					return [ 'success' => false, 'message' => __( 'قیمت اصلی باید از قیمت ویژه بیشتر باشد.', 'wapm' ) ];
				}
				$old = $product->get_regular_price( 'edit' );
				$product->set_regular_price( $new );
				$log_price_type = 'regular';
				break;

			case 'sale_price':
				$new     = wc_format_decimal( $value );
				$regular = (float) $product->get_regular_price( 'edit' );
				if ( '' !== $new && $regular > 0 && (float) $new >= $regular ) {
					return [ 'success' => false, 'message' => __( 'قیمت ویژه باید از قیمت اصلی کمتر باشد.', 'wapm' ) ];
				}
				$old = $product->get_sale_price( 'edit' );
				$product->set_sale_price( $new );
				$log_price_type = 'sale';
				break;

			case 'stock_quantity':
				// والد متغیر موجودی عددی ندارد؛ موجودی روی واریشن‌هاست. قبلاً
				// مقدار روی والد نوشته می‌شد و هیچ‌جا اثر نداشت.
				if ( $product->is_type( 'variable' ) ) {
					return [ 'success' => false, 'message' => __( 'موجودی محصول متغیر باید روی واریشن‌ها تغییر کند، نه روی والد.', 'wapm' ) ];
				}
				if ( ! $product->managing_stock() ) {
					return [ 'success' => false, 'message' => __( 'ابتدا مدیریت انبار را برای این محصول فعال کنید.', 'wapm' ) ];
				}
				if ( ! is_numeric( $value ) ) {
					return [ 'success' => false, 'message' => __( 'موجودی باید عدد باشد.', 'wapm' ) ];
				}

				// مسیر رسمی ووکامرس: وضعیت موجودی و قلاب‌ها را هم به‌روز می‌کند.
				// تغییر با تاریخچه ثبت می‌شود تا اشتباه قابل بازگردانی باشد.
				$stock = WAPM_Stock::set_quantity( $product->get_id(), (float) wc_stock_amount( $value ), 'inline_edit' );

				if ( empty( $stock['ok'] ) ) {
					// ذخیره‌ی همان مقدار قبلی «خطا» نیست؛ فقط ردیف تازه برمی‌گردانیم
					// تا سلول به حالت قبل برگردد و پیام قرمزی به کاربر نشان ندهیم.
					if ( 'no_change' === ( $stock['reason'] ?? '' ) ) {
						return [
							'success' => true,
							'row'     => self::build_row(
								$product,
								$product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null
							),
						];
					}

					return [
						'success' => false,
						'message' => WAPM_Stock::reason_label( (string) ( $stock['reason'] ?? '' ) ),
					];
				}

				return [ 'success' => true, 'row' => $stock['row'] ];

			default:
				return [ 'success' => false, 'message' => __( 'فیلد نامعتبر است.', 'wapm' ) ];
		}

		$product->save();

		$parent = null;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			WC_Product_Variable::sync( $product->get_parent_id() );
		}
		wc_delete_product_transients( $product->get_id() );
		self::purge_caches( $product->get_id(), $parent ? $parent->get_id() : 0 );

		if ( $log_price_type && class_exists( 'WAPM_Logger' ) ) {
			WAPM_Logger::log( [
				'batch_id'     => 'inline_' . wp_generate_password( 10, false ),
				'product_id'   => $product->get_id(),
				'parent_id'    => $product->get_parent_id(),
				'product_name' => $product->get_name(),
				'sku'          => $product->get_sku(),
				'price_type'   => $log_price_type,
				'old_price'    => (string) $old,
				'new_price'    => (string) $value,
				'operation'    => 'inline_edit',
			] );
		}

		return [ 'success' => true, 'row' => self::build_row( $product, $parent ) ];
	}

	/**
	 * روشن/خاموش کردن «مدیریت انبار» یک محصول یا واریشن.
	 */
	public static function toggle_manage_stock( int $product_id, bool $enabled ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [ 'success' => false, 'message' => __( 'محصول یافت نشد.', 'wapm' ) ];
		}

		// در ووکامرس موجودی محصول متغیر با واریشن‌ها مدیریت می‌شود و
		// manage_stock والد هیچ اثری ندارد؛ قبلاً کلید روشن می‌شد و «فعال»
		// نمایش داده می‌شد بدون اینکه کاری بکند (P1-10).
		if ( $product->is_type( 'variable' ) ) {
			return [
				'success' => false,
				'message' => __( 'محصول متغیر موجودی خودش را ندارد؛ مدیریت انبار را روی واریشن‌ها تنظیم کنید.', 'wapm' ),
			];
		}

		$product->set_manage_stock( $enabled );
		if ( $enabled && null === $product->get_stock_quantity() ) {
			$product->set_stock_quantity( 0 );
		}
		$product->save();

		$parent = null;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			WC_Product_Variable::sync( $product->get_parent_id() );
		}
		wc_delete_product_transients( $product->get_id() );
		self::purge_caches( $product->get_id(), $parent ? $parent->get_id() : 0 );

		return [ 'success' => true, 'row' => self::build_row( $product, $parent ) ];
	}

	/**
	 * حذف (انتقال به زباله‌دان — قابل بازگشت) یک یا چند محصول/واریشن.
	 *
	 * برای واریشن‌ها، والد هم sync می‌شود: wp_trash_post به‌تنهایی قیمت‌های
	 * min/max والد را به‌روز نمی‌کند و محصول متغیر با قیمت/محدوده‌ی کهنه می‌ماند.
	 */
	public static function trash_products( array $ids ): int {
		$count   = 0;
		$parents = [];

		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id ) {
				continue;
			}

			$product   = wc_get_product( $id );
			$parent_id = ( $product && $product->is_type( 'variation' ) ) ? (int) $product->get_parent_id() : 0;

			if ( ! wp_trash_post( $id ) ) {
				continue;
			}

			$count++;
			if ( $parent_id ) {
				$parents[ $parent_id ] = true;
			}
			self::purge_caches( $id, $parent_id );
		}

		foreach ( array_keys( $parents ) as $parent_id ) {
			WC_Product_Variable::sync( $parent_id );
			self::purge_caches( $parent_id );
		}

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		return $count;
	}

	/**
	 * تغییر وضعیت موجودی برای چند محصول/واریشن به‌صورت گروهی.
	 */
	/**
	 * تغییر گروهی وضعیت موجودی.
	 *
	 * محصولی که «مدیریت انبار» دارد، وضعیتش از تعداد می‌آید؛ ست کردن دستی وضعیت
	 * برایش بی‌اثر است (با اولین تغییر موجودی بازنویسی می‌شود). قبلاً همان وضعیت
	 * روی همه نوشته می‌شد و کاربر فکر می‌کرد همه ناموجود شده‌اند.
	 *
	 * @return array{count:int,skipped:int,reasons:array<string,int>,rows:array}
	 */
	public static function bulk_set_stock_status( array $ids, string $status ): array {
		$out = [ 'count' => 0, 'skipped' => 0, 'reasons' => [], 'rows' => [] ];

		if ( ! in_array( $status, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
			return $out;
		}

		$batch = 'status_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false );

		foreach ( array_slice( array_values( array_unique( array_map( 'absint', $ids ) ) ), 0, WAPM_Stock::CHUNK ) as $id ) {
			$result = WAPM_Stock::set_status( $id, $status, 'status', $batch );

			if ( ! empty( $result['ok'] ) ) {
				$out['count']++;
				$out['rows'][] = $result['row'];
			} else {
				$out['skipped']++;
				$reason = 'managed_status' === ( $result['reason'] ?? '' ) ? 'not_managed' : (string) ( $result['reason'] ?? '' );
				$out['reasons'][ $reason ] = ( $out['reasons'][ $reason ] ?? 0 ) + 1;
			}
		}

		$out['batch_id'] = $batch;

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return $out;
	}

	/**
	 * کپی یک محصول (فقط محصولات اصلی، نه واریشن — از هسته ووکامرس استفاده می‌شود).
	 */
	public static function duplicate_product( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return [ 'success' => false, 'message' => __( 'این آیتم قابل کپی نیست.', 'wapm' ) ];
		}

		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) && defined( 'WC_ABSPATH' ) ) {
			$file = WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
			return [ 'success' => false, 'message' => __( 'امکان کپی محصول در این نسخه ووکامرس فراهم نیست.', 'wapm' ) ];
		}

		$duplicator = new WC_Admin_Duplicate_Product();
		$duplicate  = $duplicator->product_duplicate( $product );

		if ( ! $duplicate instanceof WC_Product ) {
			return [ 'success' => false, 'message' => __( 'کپی محصول ناموفق بود.', 'wapm' ) ];
		}

		self::purge_caches( $duplicate->get_id() );

		return [ 'success' => true, 'row' => self::build_row( $duplicate ) ];
	}

	/**
	 * پاکسازی کش صفحه محصول بعد از هر تغییر — چون تغییر قیمت/موجودی از طریق
	 * WC_Product::save() به‌تنهایی افزونه‌های کش صفحه (LiteSpeed/WP Rocket/…) را
	 * از تغییر مطلع نمی‌کند و ممکن است نسخه قدیمی صفحه در کش باقی بماند.
	 * هر فراخوانی در صورت نبود افزونه مربوطه کاملاً بی‌اثر و بی‌خطر است.
	 */
	public static function purge_caches( int $product_id, int $parent_id = 0 ): void {
		$ids = $parent_id ? [ $product_id, $parent_id ] : [ $product_id ];

		foreach ( $ids as $id ) {
			clean_post_cache( $id );

			do_action( 'litespeed_purge_post', $id );
			do_action( 'w3tc_flush_post', $id );

			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
			}
		}
	}
}
