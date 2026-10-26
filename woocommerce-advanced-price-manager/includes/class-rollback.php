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
	 * @return array{restored:int,batch_id:string}
	 */
	public static function rollback_last(): array {
		$batch_id = WAPM_Logger::get_last_batch_id();
		if ( ! $batch_id ) {
			return [ 'restored' => 0, 'batch_id' => '' ];
		}
		return [
			'restored' => WAPM_Logger::rollback_batch( $batch_id ),
			'batch_id' => $batch_id,
		];
	}

	/**
	 * بازگردانی یک Batch مشخص (مثلاً تغییر قیمت برند لیمون روی ۵۰۰ محصول).
	 */
	public static function rollback( string $batch_id ): int {
		return WAPM_Logger::rollback_batch( $batch_id );
	}
}
