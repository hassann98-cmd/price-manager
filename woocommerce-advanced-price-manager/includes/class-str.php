<?php
/**
 * WAPM_Str — توابع رشته‌ای امن برای متن فارسی (UTF-8) با fallback بدون mbstring
 *
 * چرا: افزونه چند جا طول/برش/جستجو روی نام محصول و فیلترهای فارسی انجام می‌دهد
 * و مستقیم `mb_*` صدا می‌زد. روی هاست‌هایی که mbstring نصب نیست (بعضی buildهای
 * سبک PHP) همان خط با «Call to undefined function» صفحه‌ی ادمین را سفید می‌کرد.
 *
 * نکته‌ی مهم: fallback از `strlen`/`substr` بایتی استفاده نمی‌کند، چون هر حرف
 * فارسی دو بایت است و برش بایتی، متن را می‌شکند (کاراکتر نامعتبر در خروجی).
 * مسیر جایگزین با `preg_split('//u')` کاراکترها را می‌شمارد.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Str {

	/**
	 * تعداد کاراکترها (نه بایت).
	 */
	public static function len( string $s ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $s, 'UTF-8' );
		}

		$count = preg_match_all( '/./us', $s );

		return false === $count ? 0 : (int) $count;
	}

	/**
	 * برش کاراکتری. $length = null یعنی تا انتها.
	 */
	public static function sub( string $s, int $start, ?int $length = null ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return (string) mb_substr( $s, $start, $length, 'UTF-8' );
		}

		$chars = self::chars( $s );
		$slice = ( null === $length )
			? array_slice( $chars, $start )
			: array_slice( $chars, $start, $length );

		return implode( '', $slice );
	}

	/**
	 * آیا $needle داخل $haystack هست؟
	 *
	 * جستجوی substring در UTF-8 نیازی به mbstring ندارد (بایت‌های یک کاراکتر
	 * هرگز پیشوند بایت‌های کاراکتر دیگر نیستند)، اما مسیر mb پیدا کردن را
	 * سریع‌تر و صریح‌تر می‌کند.
	 */
	public static function contains( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}
		if ( function_exists( 'mb_strpos' ) ) {
			return false !== mb_strpos( $haystack, $needle, 0, 'UTF-8' );
		}

		return false !== strpos( $haystack, $needle );
	}

	/**
	 * حروف کوچک. برای فارسی اثری ندارد؛ برای نام‌های لاتین لازم است.
	 */
	public static function lower( string $s ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return (string) mb_strtolower( $s, 'UTF-8' );
		}

		return strtolower( $s );
	}

	/**
	 * کاراکترهای UTF-8 (فقط برای مسیر بدون mbstring).
	 *
	 * @return string[]
	 */
	private static function chars( string $s ): array {
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $chars ) ) {
			return $chars;
		}

		// ورودی UTF-8 نامعتبر: بدترین حالت، برش بایتی (بهتر از خطای کشنده).
		return str_split( $s );
	}
}
