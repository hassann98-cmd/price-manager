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
		// absolute = «همه به یک قیمت ثابت» (برای ویرایش گروهی از لیست). در این
		// حالت «جهت» معنی ندارد و مقدار، خودِ قیمت است نه درصد/اختلاف.
		$mode      = in_array( $raw['mode'] ?? '', [ 'percent', 'fixed', 'absolute' ], true ) ? $raw['mode'] : 'percent';
		$direction = in_array( $raw['direction'] ?? '', [ 'increase', 'decrease' ], true ) ? $raw['direction'] : 'increase';
		$target    = in_array( $raw['price_target'] ?? '', [ 'regular', 'sale', 'both' ], true ) ? $raw['price_target'] : 'regular';
		$scope     = in_array( $raw['variable_scope'] ?? '', [ 'parent', 'variations', 'both' ], true ) ? $raw['variable_scope'] : 'variations';
		$round     = in_array( $raw['round_mode'] ?? '', [ 'none', 'nearest', 'up', 'down' ], true ) ? $raw['round_mode'] : 'none';
		$currency  = in_array( $raw['currency_unit'] ?? '', [ 'toman', 'rial' ], true ) ? $raw['currency_unit'] : 'toman';
		$sale_mode = in_array( $raw['sale_handling'] ?? '', [ 'independent', 'keep_discount', 'remove_sale' ], true ) ? $raw['sale_handling'] : 'independent';

		// منبع تعیین قیمت: دستی (درصد/مبلغ) یا ترب (نسبت به ارزان‌ترین رقیب).
		$source       = in_array( $raw['source'] ?? '', [ 'manual', 'torob' ], true ) ? $raw['source'] : 'manual';
		$undercut     = in_array( $raw['torob_undercut_mode'] ?? '', [ 'percent', 'fixed' ], true ) ? $raw['torob_undercut_mode'] : 'percent';
		$torob_cur    = in_array( $raw['torob_currency_unit'] ?? '', [ 'toman', 'rial' ], true ) ? $raw['torob_currency_unit'] : 'toman';

		// مقدار پیش‌فرض «زیربرش» فقط وقتی اعمال می‌شود که کلید ارسال نشده باشد؛
		// صفرِ عمدی کاربر باید صفر بماند.
		$torob_undercut = array_key_exists( 'torob_undercut', $raw )
			? max( 0, floatval( $raw['torob_undercut'] ) )
			: 1.0;

		return [
			'source'         => $source,
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

			// تنظیمات حالت ترب
			'torob_undercut_mode'   => $undercut,
			'torob_undercut'        => $torob_undercut,
			'torob_currency_unit'   => $torob_cur,
			'torob_min_gap_pct'     => array_key_exists( 'torob_min_gap_pct', $raw ) ? max( 0, floatval( $raw['torob_min_gap_pct'] ) ) : 1.0,
			'torob_only_if_pricier' => ! array_key_exists( 'torob_only_if_pricier', $raw ) || ! empty( $raw['torob_only_if_pricier'] ),
			'torob_max_age'         => max( 0, intval( $raw['torob_max_age'] ?? 0 ) ),
		];
	}

	/**
	 * آیا این تنظیمات از داده‌ی ترب استفاده می‌کند؟
	 */
	public static function uses_torob( array $s ): bool {
		return 'torob' === ( $s['source'] ?? 'manual' );
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
	 * تبدیل مبلغ ورودی (تومان/ریال) به واحد فروشگاه.
	 */
	public static function to_store_unit( float $amount, string $unit ): float {
		return $amount * self::unit_factor( $unit );
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

		$old = (float) $old_price;

		// «قیمت مطلق»: مقدار، خودِ قیمت هدف است (به واحد پول انتخاب‌شده)، نه
		// درصد یا مبلغ اختلاف. جهت هم بی‌اثر است.
		if ( 'absolute' === $s['mode'] ) {
			$new = self::round_price( $s['amount'] * self::unit_factor( $s['currency_unit'] ), $s );
			$new = self::clamp_price( $new, $s );
			return max( 0, round( $new, wc_get_price_decimals() ) );
		}

		$sign = ( 'decrease' === $s['direction'] ) ? -1 : 1;

		if ( 'percent' === $s['mode'] ) {
			$new = $old + $sign * ( $old * $s['amount'] / 100 );
		} else {
			$amount = $s['amount'] * self::unit_factor( $s['currency_unit'] );
			$new    = $old + $sign * $amount;
		}

		$new = self::round_price( $new, $s );
		$new = self::clamp_price( $new, $s );
		$new = round( $new, wc_get_price_decimals() );

		// محافظ پایه: کاهش قیمت هرگز نباید محصول را رایگان (صفر) کند.
		// قبلاً فقط max( 0, … ) بود، پس کاهش ۱۰۰٪ قیمت را صفر می‌کرد و محصول
		// در فروشگاه رایگان می‌شد؛ در سفارش ثبت‌شده هم برگشت‌پذیر نیست.
		if ( 'decrease' === $s['direction'] ) {
			$new = max( self::price_floor(), $new );
		}

		return max( 0, $new );
	}

	/**
	 * کف مطلق قیمت پس از کاهش.
	 *
	 * پیش‌فرض: کوچک‌ترین واحد معنادار ارز (۰ تومان ندارد → ۱؛ با ۲ رقم اعشار → ۰.۰۱).
	 * با فیلتر `wapm_price_floor` قابل تغییر است (مثلاً کف ۱۰٬۰۰۰ تومان برای یک فروشگاه).
	 */
	public static function price_floor(): float {
		$decimals = (int) wc_get_price_decimals();
		$floor    = $decimals > 0 ? 1 / ( 10 ** $decimals ) : 1;

		return (float) apply_filters( 'wapm_price_floor', $floor );
	}

	/**
	 * قیمت هدف بر اساس ترب: کمی کمتر از ارزان‌ترین رقیب.
	 *
	 * تابع خالص (بدون ووکامرس) تا قابل تست باشد.
	 *
	 * @param float $competitor_min ارزان‌ترین قیمت رقیب (بدون قیمت خودمان)
	 * @return float|null null یعنی قیمت هدف قابل محاسبه نیست
	 */
	public static function torob_target_price( float $competitor_min, array $s ): ?float {
		if ( $competitor_min <= 0 ) {
			return null;
		}

		$undercut = ( 'percent' === ( $s['torob_undercut_mode'] ?? 'percent' ) )
			? $competitor_min * ( (float) ( $s['torob_undercut'] ?? 0 ) / 100 )
			: self::to_store_unit( (float) ( $s['torob_undercut'] ?? 0 ), (string) ( $s['torob_currency_unit'] ?? 'toman' ) );

		$target = $competitor_min - $undercut;
		$target = self::round_price( $target, $s );
		$target = self::clamp_price( $target, $s );
		$target = max( self::price_floor(), round( $target, wc_get_price_decimals() ) );

		return $target > 0 ? $target : null;
	}

	/**
	 * زمینه‌ی ترب برای یک محصول: قیمت هدف، قیمت مرجع (چیزی که ترب نشان می‌دهد)
	 * و ضریبی که باید روی قیمت‌ها اعمال شود.
	 *
	 * برای محصول متغیر، ترب کمینه‌ی قیمت واریشن‌ها را نشان می‌دهد؛ بنابراین ضریب
	 * طوری ساخته می‌شود که کمینه‌ی واریشن‌ها برابر قیمت هدف شود و فاصله‌ی نسبی
	 * بین واریشن‌ها حفظ شود.
	 *
	 * @return array{competitor:float,target:float,ref:float,factor:float,checked_at:string,age_days:int,skip:?string}|null
	 */
	public static function torob_context( WC_Product $product, array $s ): ?array {
		if ( ! self::uses_torob( $s ) || ! class_exists( 'WAPM_Torob' ) ) {
			return null;
		}

		$ref_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$data   = WAPM_Torob::get_target_reference( $ref_id, (int) ( $s['torob_max_age'] ?? 0 ) );

		if ( ! $data || $data['competitor_min'] <= 0 ) {
			return null; // بدون داده‌ی تازه‌ی ترب، قیمتی تغییر نمی‌کند.
		}

		$competitor = (float) $data['competitor_min'];
		$target     = self::torob_target_price( $competitor, $s );
		if ( null === $target ) {
			return null;
		}

		// قیمت مرجع = چیزی که ترب به‌عنوان قیمت ما نشان می‌دهد.
		if ( $product->is_type( 'variable' ) ) {
			$ref = (float) $product->get_variation_price( 'min', false, false );
			if ( $ref <= 0 ) {
				$ref = (float) $product->get_price( 'edit' );
			}
			$ref_id_for_ctx = (int) $product->get_id();
		} else {
			$ref = (float) $product->get_price( 'edit' );
			$ref_id_for_ctx = (int) $ref_id;
		}

		if ( $ref <= 0 ) {
			return null;
		}

		$ctx = [
			'competitor' => $competitor,
			'target'     => $target,
			'ref'        => $ref,
			'factor'     => $target / $ref,
			'checked_at' => (string) $data['checked_at'],
			'age_days'   => (int) $data['age_days'],
			'prk'        => (string) $data['prk'],
			'skip'       => null,
		];

		// «فقط اگر از رقیب گران‌تریم» — پیش‌فرض روشن است تا با یک اجرا قیمت‌ها
		// بی‌دلیل بالا نروند.
		if ( ! empty( $s['torob_only_if_pricier'] ) && $ref <= $competitor ) {
			$ctx['skip'] = 'already_cheaper';
			return $ctx;
		}

		// تغییر ناچیز ارزش ریسک را ندارد.
		$gap = abs( $target - $ref ) / $ref * 100;
		if ( $gap < (float) ( $s['torob_min_gap_pct'] ?? 0 ) ) {
			$ctx['skip'] = 'small_gap';
			return $ctx;
		}

		// محافظ سلامت: داده‌ی اشتباه نباید قیمت را چند برابر کند.
		if ( $ctx['factor'] > 10 || $ctx['factor'] < 0.1 ) {
			$ctx['skip'] = 'sanity';
			return $ctx;
		}

		return $ctx;
	}

	/**
	 * گرد کردن قیمت بر اساس تنظیمات مدیر (مثلاً مضرب 1000).
	 */	private static function round_price( float $price, array $s ): float {
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
	 * @return array[] هر آیتم: id, parent_id, name, sku, type, price_type, old, new, diff, percent, warn
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

		// در حالت ترب، بدون داده‌ی تازه هیچ تغییری پیشنهاد نمی‌شود.
		$ctx = null;
		if ( self::uses_torob( $s ) ) {
			$ctx = self::torob_context( $product, $s );
			if ( null === $ctx || ! empty( $ctx['skip'] ) ) {
				return [];
			}
		}

		$rows = [];

		if ( $product->is_type( 'variable' ) ) {
			if ( in_array( $s['variable_scope'], [ 'parent', 'both' ], true ) ) {
				$rows = array_merge( $rows, self::preview_single( $product, $s, __( 'متغیر (والد)', 'wapm' ), $ctx ) );
			}
			if ( in_array( $s['variable_scope'], [ 'variations', 'both' ], true ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation ) {
						$rows = array_merge( $rows, self::preview_single( $variation, $s, __( 'واریشن', 'wapm' ), $ctx ) );
					}
				}
			}
		} else {
			$rows = self::preview_single( $product, $s, __( 'ساده', 'wapm' ), $ctx );
		}

		return $rows;
	}

	/**
	 * محاسبه تغییرات یک محصول/واریشن با درنظرگرفتن حالت‌های Sale Price.
	 *
	 * @return array[] هر آیتم: price_type, old, new
	 */
	private static function compute_changes( WC_Product $product, array $s, ?array $ctx = null ): array {
		$changes  = [];
		$regular  = $product->get_regular_price( 'edit' );
		$sale     = $product->get_sale_price( 'edit' );
		$has_sale = ( '' !== $sale && null !== $sale );

		// حالت ترب: هدف، «قیمت مؤثر برابر قیمت هدف» است؛ نه درصد/مبلغ روی قیمت.
		if ( self::uses_torob( $s ) ) {
			if ( null === $ctx || ! empty( $ctx['skip'] ) ) {
				return [];
			}
			return self::compute_torob_changes( $product, $s, $ctx );
		}

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

		// حالت «قیمت مطلق» + مدیریت Sale مستقل: «هر دو» اینجا معنی ندارد، چون
		// Sale باید از Regular کمتر بماند و هر دو به یک عدد برسند یعنی جفت قیمت
		// نامعتبر. پس فقط Regular تنظیم می‌شود و اگر Sale فعلی از Regular جدید
		// کمتر نباشد، Sale حذف می‌شود (در پیش‌نمایش هم دیده می‌شود).
		if ( 'absolute' === $s['mode'] ) {
			if ( 'sale' === $s['price_target'] ) {
				$new_sale = $has_sale ? self::calculate( $sale, $s ) : null;
				if ( null !== $new_sale && (float) $regular > 0 && $new_sale < (float) $regular && (float) $sale !== $new_sale ) {
					$changes[] = [ 'price_type' => 'sale', 'old' => (float) $sale, 'new' => $new_sale ];
				}
				return $changes;
			}

			$new_regular = ( '' !== $regular && null !== $regular ) ? self::calculate( $regular, $s ) : null;

			if ( null !== $new_regular && abs( (float) $regular - $new_regular ) > 0.000001 ) {
				$changes[] = [ 'price_type' => 'regular', 'old' => (float) $regular, 'new' => $new_regular ];
			}

			if ( $has_sale && null !== $new_regular && (float) $sale >= $new_regular ) {
				$changes[] = [ 'price_type' => 'sale', 'old' => (float) $sale, 'new' => null ];
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

	/**
	 * تغییرات در حالت «منبع: ترب».
	 *
	 * منطق: قیمت مؤثر (آنچه ترب نشان می‌دهد) باید برابر قیمت هدف شود. برای حفظ
	 * درصد تخفیف، هم قیمت اصلی و هم قیمت ویژه با همان ضریب مقیاس می‌شوند. برای
	 * محصول متغیر، کمینه‌ی واریشن‌ها به قیمت هدف می‌رسد و فاصله‌ی نسبی واریشن‌ها
	 * حفظ می‌شود.
	 *
	 * توجه: در این حالت `price_target` و `sale_handling` نادیده گرفته می‌شوند
	 * (در UI هم همین توضیح داده شده است).
	 *
	 * @return array[] هر آیتم: price_type, old, new
	 */
	private static function compute_torob_changes( WC_Product $product, array $s, array $ctx ): array {
		$regular = $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );
		$factor  = (float) $ctx['factor'];
		$dec     = wc_get_price_decimals();

		if ( $factor <= 0 ) {
			return [];
		}

		$floor = self::price_floor();

		$scale = static function ( float $value ) use ( $factor, $s, $floor, $dec ): float {
			$new = self::round_price( $value * $factor, $s );
			$new = self::clamp_price( $new, $s );
			return max( $floor, round( $new, $dec ) );
		};

		$changes     = [];
		$new_regular = null;

		if ( '' !== $regular && null !== $regular && (float) $regular > 0 ) {
			$new_regular = $scale( (float) $regular );
			if ( abs( $new_regular - (float) $regular ) > 0.000001 ) {
				$changes[] = [ 'price_type' => 'regular', 'old' => (float) $regular, 'new' => $new_regular ];
			} else {
				$new_regular = (float) $regular;
			}
		}

		if ( '' !== $sale && null !== $sale && (float) $sale > 0 ) {
			$new_sale   = $scale( (float) $sale );
			$check_base = null !== $new_regular ? $new_regular : (float) $regular;

			// قیمت ویژه باید از قیمت اصلی کمتر بماند؛ اگر گرد کردن این را شکست،
			// تغییر قیمت ویژه انجام نمی‌شود (به‌جای ساختن قیمت عجیب).
			$valid = ( $check_base <= 0 || $new_sale < $check_base ) && abs( $new_sale - (float) $sale ) > 0.000001;
			if ( $valid ) {
				$changes[] = [ 'price_type' => 'sale', 'old' => (float) $sale, 'new' => $new_sale ];
			}
		}

		return $changes;
	}

	private static function preview_single( WC_Product $product, array $s, string $type_label, ?array $ctx = null ): array {
		if ( self::is_excluded( $product ) ) {
			return [];
		}

		$rows     = [];
		$image_id = $product->get_image_id() ?: ( $product->get_parent_id() ? get_post_thumbnail_id( $product->get_parent_id() ) : 0 );
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );

		$torob_meta = [];
		if ( null !== $ctx ) {
			$torob_meta = [
				'torob_competitor'   => (float) $ctx['competitor'],
				'torob_target'       => (float) $ctx['target'],
				'torob_checked_at'   => (string) $ctx['checked_at'],
				'torob_age_days'     => (int) $ctx['age_days'],
			];
		}

		foreach ( self::compute_changes( $product, $s, $ctx ) as $change ) {
			$old_f   = $change['old'];
			$new_f   = $change['new'];
			$percent = ( null !== $new_f && $old_f > 0 ) ? round( ( ( $new_f - $old_f ) / $old_f ) * 100, 2 ) : 0;

			// هشدار «افت شدید»: کاهش بیش از ۹۰٪ یعنی یا تنظیمات اشتباه است یا قیمت
			// به کف مجاز خورده است. در پیش‌نمایش و Dry Run نمایش داده می‌شود.
			$warn = ( null !== $new_f && $old_f > 0 && $new_f < $old_f && $new_f <= $old_f * 0.1 );

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
				'warn'       => $warn,
			] + $torob_meta;
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

		// همان زمینه‌ای که پیش‌نمایش ساخته می‌شود تا نتیجه‌ی اجرا با پیش‌نمایش بخواند.
		$ctx = null;
		if ( self::uses_torob( $s ) ) {
			$ctx = self::torob_context( $product, $s );
			if ( null === $ctx || ! empty( $ctx['skip'] ) ) {
				return 0;
			}
		}

		$changed = 0;

		if ( $product->is_type( 'variable' ) ) {
			if ( in_array( $s['variable_scope'], [ 'parent', 'both' ], true ) ) {
				$changed += self::apply_single( $product, $s, $batch_id, $operation, $ctx );
			}
			if ( in_array( $s['variable_scope'], [ 'variations', 'both' ], true ) ) {
				foreach ( $product->get_children() as $vid ) {
					$variation = wc_get_product( $vid );
					if ( $variation ) {
						$changed += self::apply_single( $variation, $s, $batch_id, $operation, $ctx );
					}
				}
			}
			WC_Product_Variable::sync( $product->get_id() );
		} else {
			$changed += self::apply_single( $product, $s, $batch_id, $operation, $ctx );
		}

		// ستون/فیلتر «قیمت ترب» از متای ذخیره‌شده می‌خواند. اکشن save خودش این را
		// بازمحاسبه می‌کند، ولی در همان درخواست ممکن است قیمت مؤثر از کش قدیمی
		// خوانده شود (مخصوصاً والد متغیر بعد از تغییر واریشن‌ها). پس صریح و پس از
		// پاک‌کردن ترنزینت‌ها یک بار دیگر محاسبه می‌کنیم.
		if ( class_exists( 'WAPM_Torob' ) ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}
			WAPM_Torob::refresh_diff_meta( $product_id );
		}

		return $changed;
	}

	private static function apply_single( WC_Product $product, array $s, string $batch_id, string $operation, ?array $ctx = null ): int {
		if ( self::is_excluded( $product ) ) {
			return 0;
		}

		// اگر همین محصول/واریشن در همین Batch قبلاً لاگ شده (Retry بعد از قطعی)، دوباره اعمال نکن.
		if ( WAPM_Logger::product_done_in_batch( $batch_id, $product->get_id() ) ) {
			return 0;
		}

		$changed = 0;
		$dirty   = false;
		$applied = []; // برای لاگ بعد از save موفق

		// اول Regular اعمال می‌شود تا مقایسه Sale با Regular جدید انجام شود.
		$changes = self::compute_changes( $product, $s, $ctx );
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

			$dirty    = true;
			$changed++;
			$applied[] = [ 'price_type' => $t, 'old' => $old, 'new' => $new ];
		}

		if ( $dirty ) {
			$product->save();

			/*
			 * لاگ فقط بعد از save موفق نوشته می‌شود. قبلاً قبل از save نوشته
			 * می‌شد و اگر ذخیره شکست می‌خورد، تاریخچه تغییری را نشان می‌داد که
			 * هرگز ذخیره نشده بود؛ بدتر اینکه همان ردیف باعث می‌شد Retry آن
			 * محصول را «قبلاً انجام‌شده» بداند و برای همیشه رد کند (P2-1).
			 */
			foreach ( $applied as $entry ) {
				WAPM_Logger::log( [
					'batch_id'     => $batch_id,
					'product_id'   => $product->get_id(),
					'parent_id'    => $product->get_parent_id(),
					'product_name' => $product->get_name(),
					'sku'          => $product->get_sku(),
					'price_type'   => $entry['price_type'],
					'old_price'    => (string) $entry['old'],
					'new_price'    => null === $entry['new'] ? '' : (string) $entry['new'],
					'operation'    => $operation,
				] );
			}
		}

		return $changed;
	}
}
