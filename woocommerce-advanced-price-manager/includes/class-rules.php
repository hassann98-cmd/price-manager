<?php
/**
 * WAPM_Rules — سیستم قوانین قیمت‌گذاری
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Rules {

	const OPTION = 'wapm_rules';

	public static function all(): array {
		$rules = get_option( self::OPTION, [] );
		if ( ! is_array( $rules ) ) {
			$rules = [];
		}
		usort( $rules, fn( $a, $b ) => ( $a['priority'] ?? 10 ) <=> ( $b['priority'] ?? 10 ) );
		return $rules;
	}

	public static function save_rule( array $raw ): array {
		$rules = self::all();

		$rule = [
			'id'       => sanitize_key( $raw['id'] ?? '' ) ?: 'rule_' . wp_generate_password( 8, false ),
			'title'    => sanitize_text_field( $raw['title'] ?? '' ),
			'priority' => intval( $raw['priority'] ?? 10 ),
			'enabled'  => ! empty( $raw['enabled'] ),
			'start'    => sanitize_text_field( $raw['start'] ?? '' ), // اختیاری: قبل از این تاریخ اجرا نشود
			'end'      => sanitize_text_field( $raw['end'] ?? '' ),   // اختیاری: بعد از این تاریخ اجرا نشود
			'filters'  => WAPM_Product_Handler::sanitize_filters( (array) ( $raw['filters'] ?? [] ) ),
			'settings' => WAPM_Price_Engine::sanitize_settings( (array) ( $raw['settings'] ?? [] ) ),
		];

		$replaced = false;
		foreach ( $rules as $i => $existing ) {
			if ( $existing['id'] === $rule['id'] ) {
				$rules[ $i ] = $rule;
				$replaced    = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$rules[] = $rule;
		}

		update_option( self::OPTION, $rules, false );
		return $rule;
	}

	public static function delete_rule( string $id ): void {
		$rules = array_values( array_filter( self::all(), fn( $r ) => $r['id'] !== $id ) );
		update_option( self::OPTION, $rules, false );
	}

	public static function toggle_rule( string $id, bool $enabled ): void {
		$rules = self::all();
		foreach ( $rules as &$r ) {
			if ( $r['id'] === $id ) {
				$r['enabled'] = $enabled;
			}
		}
		update_option( self::OPTION, $rules, false );
	}

	public static function get_rule( string $id ): ?array {
		foreach ( self::all() as $r ) {
			if ( $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	}

	/**
	 * اجرای یک قانون به صورت Batch (یک صفحه در هر فراخوانی Ajax).
	 *
	 * @return array{processed:int,total:int,done:bool,batch_id:string}
	 */
	/**
	 * آیا قانون در بازه زمانی مجاز است؟
	 */
	public static function is_rule_active_now( array $rule ): bool {
		$now = current_time( 'timestamp' );
		if ( ! empty( $rule['start'] ) && $now < strtotime( $rule['start'] ) ) {
			return false;
		}
		if ( ! empty( $rule['end'] ) && $now > strtotime( $rule['end'] ) ) {
			return false;
		}
		return true;
	}

	public static function run_rule_batch( array $rule, string $batch_id, int $paged, int $per_page = 25 ): array {
		if ( ! self::is_rule_active_now( $rule ) ) {
			return [ 'processed' => 0, 'total' => 0, 'done' => true, 'batch_id' => $batch_id, 'skipped' => 'date_window' ];
		}

		$targets = WAPM_Product_Handler::get_target_ids( $rule['filters'], $paged, $per_page );

		$processed = 0;
		foreach ( $targets['ids'] as $pid ) {
			WAPM_Price_Engine::apply_to_product( $pid, $rule['settings'], $batch_id, 'rule:' . $rule['id'] );
			$processed++;
		}

		$done = ( $paged * $per_page ) >= $targets['total'] || empty( $targets['ids'] );

		if ( $done && function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}

		return [
			'processed' => $processed,
			'total'     => $targets['total'],
			'done'      => $done,
			'batch_id'  => $batch_id,
		];
	}
}
