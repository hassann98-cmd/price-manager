<?php
/**
 * WAPM_Price_Engine — محاسبه و اعمال تغییر قیمت با WooCommerce CRUD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Price_Engine {

	/**
	 * نرمال‌سازی تنظیمات ورودی از فرم/قانون.
	 */
	public static function sanitize_settings( array $raw ): array {
		$mode      = in_array( $raw['mode'] ?? '', [ 'percent', 'fixed' ], true ) ? $raw['mode'] : 'percent';
		$direction = in_array( $raw['direction'] ?? '', [ 'increase', 'decrease' ], true ) ? $raw['direction'] : 'increase';
		$target    = in_array( $raw['price_target'] ?? '', [ 'regular', 'sale', 'both' ], true ) ? $raw['price_target'] : 'regular';
		$scope     = in_array( $raw['variable_scope'] ?? '', [ 'parent', 'variations', 'both' ], true ) ? $raw['variable_scope'] : 'variations';
		$round     = in_array( $raw['round_mode'] ?? '', [ 'none', 'nearest', 'up', 'down' ], true ) ? $raw['round_mode'] : 'none';
		$currency  = in_array( $raw['currency_unit'] ?? '', [ 'toman', 'rial' ], true ) ? $raw['currency_unit'] : 'toman';
		$sale_mode = in_array( $raw['sale_handling'] ?? '', [ 'independent', 'keep_discount', 'remove_sale' ], true ) ? $raw['sale_handling'] : 'independent';

		return [
			'sale_handling'  => $sale_mode,
			'mode'           => $mode,
			'direction'      => $direction,
			'amount'         => abs( floatval( $raw['amount'] ?? 0 ) ),
			'price_target'   => $target,
			'variable_scope' => $scope,
			'round_mode'     => $round,
			'round_base'     => max( 0, floatval( $raw['round_base'] ?? 0 ) ),
			'min_price'      => max( 0, floatval( $raw['min_price'] ?? 0 ) ),
			'max_price'      => max( 0, floatval( $raw['max_price'] ?? 0 ) ),
			'currency_unit'  => $currency,
		];
	}

	/**
	 * ضریب تبدیل واحد ورودی کاربر به واحد فروشگاه (برای مبلغ ثابت).
	 * فرض: واحد فروشگاه در ووکامرس تنظیم شده (IRT = تومان، IRR = ریال).
	 */
	private static function unit_factor( string $input_unit ): float {
		$store = get_woocommerce_currency(); // IRT, IRR, ...
		$store_unit = ( 'IRR' === $store ) ? 'rial' : 'toman';

		if ( $input_unit === $store_unit ) {
			return 1;
		}
		return ( 'rial' === $input_unit ) ? 0.1 : 10; // ریال→تومان یا تومان→ریال
	}

	/**
	 * محاسبه قیمت جدید از روی قیمت فعلی.
	 *
	 * @return float|null null یعنی قیمتی برای تغییر وجود ندارد.
	 */
	public static function calculate( $old_price, array $s ): ?float {
		if ( '' === $old_price || null === $old_price ) {
			return null;
		}

		$old  = (float) $old_price;
		$sign = ( 'decrease' === $s['direction'] ) ? -1 : 1;

		if ( 'percent' === $s['mode'] ) {
			$new = $old + $sign * ( $old * $s['amount'] / 100 );
		} else {
			$amount = $s['amount'] * self::unit_factor( $s['currency_unit'] );
			$new    = $old + $sign * $amount;
		}

		$new = self::round_price( $new, $s );
		$new = self::clamp_price( $new, $s );

		return max( 0, round( $new, wc_get_price_decimals() ) );
	}

	/**
	 * گرد کردن قیمت بر اساس تنظیمات مدیر (مثلاً مضرب 1000).
	 */
	private static function round_price( float $price, array $s ): float {
		if ( 'none' === $s['round_mode'] || $s['round_base'] <= 0 ) {
			return $price;
		}
		$base = $s['round_base'];
		return match ( $s['round_mode'] ) {
			'up'      => ceil( $price / $base ) * $base,
			'down'    => floor( $price / $base ) * $base,
			default   => round( $price / $base ) * $base,
		};
	}

	/**
	 * اعمال حداقل/حداکثر قیمت مجاز.
	 */
	private static function clamp_price( float $price, array $s ): float {
		if ( $s['min_price'] > 0 && $price < $s['min_price'] ) {
			$price = $s['min_price'];
		}
		if ( $s['max_price'] > 0 && $price > $s['max_price'] ) {
			$price = $s['max_price'];
		}
		return $price;
	}

	/**
	 * پیش‌نمایش تغییرات یک محصول (بدون ذخیره).
	 * برای محصول متغیر، طبق variable_scope والد/واریشن‌ها را باز می‌کند.
	 *
	 * @return array[] هر آیتم: id, parent_id, name, sku, type, price_type, old, new, percent
	 */
	public static function is_excluded( WC_Product $product ): bool {
		if ( 'yes' === $product->get_meta( '_wapm_exclude', true ) ) {
			return true;
		}
		// واریشن‌ها از والدشان هم ارث می‌برند.
		if ( $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent && 'yes' === $parent->get_meta( '_wapm_exclude', true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function preview_product( int $product_id, array $s ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || self::is_excluded( $product ) ) {
			return [];
		}

		$rows = [];

		if ( $product->is_type( 'variable' ) ) {
			if ( in_array( $s['variable_scope'], [ 'parent', 'both' ], true ) ) {
				$rows = array_merge( $rows, self::preview_single( $product, $s, __( 'متغیر (والد)', 'wapm' ) ) );
			}
			if ( in_array( $s['variable_scope'], [ 'variations', 'both' ], true ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation ) {
						$rows = array_merge( $rows, self::preview_single( $variation, $s, __( 'واریشن', 'wapm' ) ) );
					}
				}
			}
		} else {
			$rows = self::preview_single( $product, $s, __( 'ساده', 'wapm' ) );
		}

		return $rows;
	}

	/**
	 * محاسبه تغییرات یک محصول/واریشن با درنظرگرفتن حالت‌های Sale Price.
	 *
	 * @return array[] هر آیتم: price_type, old, new
	 */
	private static function compute_changes( WC_Product $product, array $s ): array {
		$changes  = [];
		$regular  = $product->get_regular_price( 'edit' );
		$sale     = $product->get_sale_price( 'edit' );
		$has_sale = ( '' !== $sale && null !== $sale );

		// حالت ۱: حفظ درصد تخفیف — فقط Regular تغییر می‌کند و Sale متناسب بازمحاسبه می‌شود.
		if ( 'keep_discount' === $s['sale_handling'] && $has_sale && '' !== $regular ) {
			$new_regular = self::calculate( $regular, $s );
			if ( null !== $new_regular && (float) $regular !== $new_regular ) {
				$ratio    = (float) $regular > 0 ? (float) $sale / (float) $regular : 0;
				$new_sale = round( $new_regular * $ratio, wc_get_price_decimals() );
				$changes[] = [ 'price_type' => 'regular', 'old' => (float) $regular, 'new' => $new_regular ];
				$changes[] = [ 'price_type' => 'sale', 'old' => (float) $sale, 'new' => $new_sale ];
			}
			return $changes;
		}

		// حالت ۲: حذف Sale Price + تغییر Regular.
		if ( 'remove_sale' === $s['sale_handling'] ) {
			if ( $has_sale ) {
				$changes[] = [ 'price_type' => 'sale', 'old' => (float) $sale, 'new' => null ]; // null = حذف
			}
			if ( '' !== $regular && null !== $regular ) {
				$new_regular = self::calculate( $regular, $s );
				if ( null !== $new_regular && (float) $regular !== $new_regular ) {
					$changes[] = [ 'price_type' => 'regular', 'old' => (float) $regular, 'new' => $new_regular ];
				}
			}
			return $changes;
		}

		// حالت پیش‌فرض: مستقل، طبق price_target.
		$targets = ( 'both' === $s['price_target'] ) ? [ 'regular', 'sale' ] : [ $s['price_target'] ];
		foreach ( $targets as $t ) {
			$old = ( 'sale' === $t ) ? $sale : $regular;
			if ( '' === $old || null === $old ) {
				continue;
			}
			$new = self::calculate( $old, $s );
			if ( null === $new || (float) $old === $new ) {
				continue;
			}
			$changes[] = [ 'price_type' => $t, 'old' => (float) $old, 'new' => $new ];
		}

		return $changes;
	}

	private static function preview_single( WC_Product $product, array $s, string $type_label ): array {
		if ( self::is_excluded( $product ) ) {
			return [];
		}

		$rows     = [];
		$image_id = $product->get_image_id() ?: ( $product->get_parent_id() ? get_post_thumbnail_id( $product->get_parent_id() ) : 0 );
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );

		foreach ( self::compute_changes( $product, $s ) as $change ) {
			$old_f   = $change['old'];
			$new_f   = $change['new'];
			$percent = ( null !== $new_f && $old_f > 0 ) ? round( ( ( $new_f - $old_f ) / $old_f ) * 100, 2 ) : 0;

			$rows[] = [
				'id'         => $product->get_id(),
				'parent_id'  => $product->get_parent_id(),
				'name'       => $product->get_name(),
				'image'      => $image,
				'sku'        => $product->get_sku(),
				'type'       => $type_label,
				'price_type' => $change['price_type'],
				'old'        => $old_f,
				'new'        => $new_f, // null = حذف Sale
				'diff'       => null !== $new_f ? round( $new_f - $old_f, wc_get_price_decimals() ) : -$old_f,
				'percent'    => $percent,
			];
		}

		return $rows;
	}

	/**
	 * اعمال واقعی تغییر روی یک محصول (و واریشن‌هایش در صورت نیاز) + ثبت لاگ.
	 *
	 * @return int تعداد قیمت‌های تغییر‌یافته.
	 */
	public static function apply_to_product( int $product_id, array $s, string $batch_id, string $operation ): int {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0;
		}

		$changed = 0;

		if ( $product->is_type( 'variable' ) ) {
			if ( in_array( $s['variable_scope'], [ 'parent', 'both' ], true ) ) {
				$changed += self::apply_single( $product, $s, $batch_id, $operation );
			}
			if ( in_array( $s['variable_scope'], [ 'variations', 'both' ], true ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation ) {
						$changed += self::apply_single( $variation, $s, $batch_id, $operation );
					}
				}
			}
			WC_Product_Variable::sync( $product->get_id() );
		} else {
			$changed += self::apply_single( $product, $s, $batch_id, $operation );
		}

		return $changed;
	}

	private static function apply_single( WC_Product $product, array $s, string $batch_id, string $operation ): int {
		if ( self::is_excluded( $product ) ) {
			return 0;
		}

		// اگر همین محصول/واریشن در همین Batch قبلاً لاگ شده (Retry بعد از قطعی)، دوباره اعمال نکن.
		if ( WAPM_Logger::product_done_in_batch( $batch_id, $product->get_id() ) ) {
			return 0;
		}

		$changed = 0;
		$dirty   = false;

		// اول Regular اعمال می‌شود تا مقایسه Sale با Regular جدید انجام شود.
		$changes = self::compute_changes( $product, $s );
		usort( $changes, fn( $a, $b ) => ( 'regular' === $a['price_type'] ? 0 : 1 ) <=> ( 'regular' === $b['price_type'] ? 0 : 1 ) );

		foreach ( $changes as $change ) {
			$t   = $change['price_type'];
			$old = $change['old'];
			$new = $change['new'];

			if ( 'sale' === $t ) {
				if ( null === $new ) {
					$product->set_sale_price( '' ); // حذف Sale Price
				} else {
					$regular = (float) $product->get_regular_price( 'edit' );
					if ( $regular > 0 && $new >= $regular ) {
						continue; // قیمت فروش نباید از قیمت اصلی بیشتر شود.
					}
					$product->set_sale_price( wc_format_decimal( $new ) );
				}
			} else {
				$product->set_regular_price( wc_format_decimal( $new ) );
			}

			$dirty = true;
			$changed++;

			WAPM_Logger::log( [
				'batch_id'     => $batch_id,
				'product_id'   => $product->get_id(),
				'parent_id'    => $product->get_parent_id(),
				'product_name' => $product->get_name(),
				'sku'          => $product->get_sku(),
				'price_type'   => $t,
				'old_price'    => (string) $old,
				'new_price'    => null === $new ? '' : (string) $new,
				'operation'    => $operation,
			] );
		}

		if ( $dirty ) {
			$product->save();
		}

		return $changed;
	}
}
