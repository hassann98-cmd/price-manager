<?php
/**
 * WAPM_CSV_Handler — خروجی و ورودی CSV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_CSV_Handler {

	/**
	 * استریم خروجی CSV بر اساس فیلترها و تنظیمات (قیمت فعلی + قیمت جدید محاسبه‌شده).
	 */
	public static function stream_export( array $filters, array $settings ): void {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wapm-price-export-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM برای نمایش درست فارسی در اکسل
		fputcsv( $out, [ 'Product ID', 'Variation ID', 'Name', 'SKU', 'Price Type', 'Old Price', 'New Price' ] );

		$paged = 1;
		do {
			$targets = WAPM_Product_Handler::get_target_ids( $filters, $paged, 100 );
			foreach ( $targets['ids'] as $pid ) {
				foreach ( WAPM_Price_Engine::preview_product( $pid, $settings ) as $row ) {
					$is_variation = $row['parent_id'] > 0;
					fputcsv( $out, [
						$is_variation ? $row['parent_id'] : $row['id'],
						$is_variation ? $row['id'] : '',
						$row['name'],
						$row['sku'],
						$row['price_type'],
						$row['old'],
						null === $row['new'] ? '' : $row['new'],
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
	 * ورود قیمت جدید از CSV.
	 * ستون‌های شناسایی: Variation ID > Product ID > SKU. ستون قیمت: New Price.
	 * ستون اختیاری: Price Type (regular/sale).
	 *
	 * @return array{updated:int,skipped:int,batch_id:string}
	 */
	public static function import( string $tmp_path ): array {
		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return [ 'updated' => 0, 'skipped' => 0, 'batch_id' => '', 'error' => __( 'خطا در خواندن فایل.', 'wapm' ) ];
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 300 );
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			return [ 'updated' => 0, 'skipped' => 0, 'batch_id' => '', 'error' => __( 'فایل خالی است.', 'wapm' ) ];
		}

		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		$map       = array_change_key_case( array_flip( array_map( 'trim', $header ) ), CASE_LOWER );

		$col_vid  = $map['variation id'] ?? null;
		$col_id   = $map['product id'] ?? $map['id'] ?? null;
		$col_sku  = $map['sku'] ?? null;
		$col_new  = $map['new price'] ?? $map['price'] ?? null;
		$col_type = $map['price type'] ?? null;

		if ( null === $col_new || ( null === $col_id && null === $col_sku && null === $col_vid ) ) {
			fclose( $handle );
			return [ 'updated' => 0, 'skipped' => 0, 'batch_id' => '', 'error' => __( 'ستون‌های لازم (Product ID / Variation ID / SKU + New Price) پیدا نشد.', 'wapm' ) ];
		}

		$batch_id = 'csv_' . wp_generate_password( 12, false );
		$updated  = 0;
		$skipped  = 0;
		$parents  = [];

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			// اولویت با Variation ID، بعد Product ID، بعد SKU.
			$pid = ( null !== $col_vid && ! empty( $row[ $col_vid ] ) ) ? absint( $row[ $col_vid ] ) : 0;
			if ( ! $pid && null !== $col_id && ! empty( $row[ $col_id ] ) ) {
				$pid = absint( $row[ $col_id ] );
			}
			if ( ! $pid && null !== $col_sku && ! empty( $row[ $col_sku ] ) ) {
				$pid = wc_get_product_id_by_sku( sanitize_text_field( $row[ $col_sku ] ) );
			}

			$new_price = isset( $row[ $col_new ] ) ? wc_format_decimal( str_replace( [ ',', '،' ], '', (string) $row[ $col_new ] ) ) : '';
			$type      = ( null !== $col_type && isset( $row[ $col_type ] ) && 'sale' === strtolower( trim( (string) $row[ $col_type ] ) ) ) ? 'sale' : 'regular';

			$product = $pid ? wc_get_product( $pid ) : false;
			if ( ! $product || '' === $new_price || WAPM_Price_Engine::is_excluded( $product ) ) {
				$skipped++;
				continue;
			}

			$old = ( 'sale' === $type ) ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );

			// قیمت فروش نباید از قیمت اصلی بیشتر/مساوی باشد و قیمت اصلی نباید کمتر از قیمت فروش فعلی شود
			// (هم‌راستا با بررسی مشابه در WAPM_Price_Engine::apply_single).
			if ( 'sale' === $type ) {
				$regular = (float) $product->get_regular_price( 'edit' );
				if ( $regular > 0 && (float) $new_price >= $regular ) {
					$skipped++;
					continue;
				}
			} else {
				$sale = (float) $product->get_sale_price( 'edit' );
				if ( $sale > 0 && (float) $new_price <= $sale ) {
					$skipped++;
					continue;
				}
			}

			if ( 'sale' === $type ) {
				$product->set_sale_price( $new_price );
			} else {
				$product->set_regular_price( $new_price );
			}
			$product->save();

			if ( $product->is_type( 'variation' ) ) {
				$parents[ $product->get_parent_id() ] = true;
			}

			WAPM_Logger::log( [
				'batch_id'     => $batch_id,
				'product_id'   => $product->get_id(),
				'parent_id'    => $product->get_parent_id(),
				'product_name' => $product->get_name(),
				'sku'          => $product->get_sku(),
				'price_type'   => $type,
				'old_price'    => (string) $old,
				'new_price'    => (string) $new_price,
				'operation'    => 'csv_import',
			] );

			$updated++;
		}
		fclose( $handle );

		foreach ( array_keys( $parents ) as $p ) {
			WC_Product_Variable::sync( $p );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return [ 'updated' => $updated, 'skipped' => $skipped, 'batch_id' => $batch_id ];
	}
}
