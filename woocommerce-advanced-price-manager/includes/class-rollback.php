<?php
/**
 * WAPM_Rollback — بازگردانی عملیات (آخرین یا Batch خاص)
 * لایه نازکی روی WAPM_Logger برای مطابقت با معماری افزونه.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Rollback {

	/**
	 * بازگردانی آخرین عملیات انجام‌شده.
	 *
	 * @return array{restored:int,batch_id:string,conflicts:array,rows:int}
	 */
	public static function rollback_last( bool $force = false ): array {
		$batch_id = WAPM_Logger::get_last_batch_id();
		if ( ! $batch_id ) {
			return [ 'restored' => 0, 'batch_id' => '', 'conflicts' => [], 'rows' => 0 ];
		}
		$result = WAPM_Logger::rollback_batch( $batch_id, $force );

		return [
			'restored'  => $result['restored'],
			'batch_id'  => $batch_id,
			'conflicts' => $result['conflicts'],
			'rows'      => $result['rows'],
		];
	}

	/**
	 * بازگردانی یک Batch مشخص (مثلاً تغییر قیمت برند لیمون روی ۵۰۰ محصول).
	 *
	 * @return array{restored:int, conflicts:array, rows:int}
	 */
	public static function rollback( string $batch_id, bool $force = false ): array {
		return WAPM_Logger::rollback_batch( $batch_id, $force );
	}
}
