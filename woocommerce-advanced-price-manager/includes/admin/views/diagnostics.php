<?php
/**
 * کارت «خودآزمایی و خطاها» — مارک‌آپ ته صفحه‌ی پیشخوان.
 *
 * چرا فایل جدا: مثل بقیه‌ی تب‌ها، `class-admin.php` باید فقط منطق داشته باشد
 * (بودجه‌ی اندازه‌ی فایل هم در تست‌ها چک می‌شود).
 *
 * این کارت عمداً ته صفحه است: کار روزمره نیست، ولی وقتی هاست یا ابزار تحلیلی
 * می‌گوید «خطای مهم افزونه» و متنش را نمی‌دهد، جواب این‌جاست.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wapm_checks  = WAPM_Diagnostics::checks();
$wapm_summary = WAPM_Diagnostics::summary();
$wapm_records = array_merge(
	[] !== WAPM_Diagnostics::last_error() ? [ WAPM_Diagnostics::last_error() ] : [],
	WAPM_Diagnostics::thrown()
);
$wapm_notices = WAPM_Diagnostics::notices();

$wapm_bad  = (int) $wapm_summary['fail'];
$wapm_warn = (int) $wapm_summary['warn'];
?>
<div class="wapm-card wapm-diagnostics" id="wapm-diagnostics-card">
	<h2>
		<?php esc_html_e( 'خودآزمایی و خطاها', 'wapm' ); ?>
		<span class="wapm-diagnostics__badge <?php echo $wapm_bad ? 'is-fail' : ( $wapm_warn ? 'is-warn' : 'is-ok' ); ?>">
			<?php
			if ( $wapm_bad ) {
				printf(
					/* translators: %d: number of failed checks */
					esc_html__( '%d مورد نیاز به رسیدگی', 'wapm' ),
					(int) $wapm_bad
				);
			} elseif ( $wapm_warn ) {
				printf(
					/* translators: %d: number of warnings */
					esc_html__( '%d نکته‌ی قابل بهبود', 'wapm' ),
					(int) $wapm_warn
				);
			} else {
				esc_html_e( 'همه‌چیز سالم', 'wapm' );
			}
			?>
		</span>
	</h2>
	<p class="description">
		<?php esc_html_e( 'اگر هاست یا ابزار تحلیلی از «خطای افزونه» گفته و متنش را نداده، این‌جا همان متن است. سبز = سالم، زرد = نیمه‌تنظیم، قرمز = باید رسیدگی شود.', 'wapm' ); ?>
	</p>

	<ul class="wapm-checks">
		<?php foreach ( $wapm_checks as $wapm_check ) : ?>
			<?php $wapm_status = (string) ( $wapm_check['status'] ?? 'ok' ); ?>
			<li class="wapm-check is-<?php echo esc_attr( $wapm_status ); ?>">
				<span class="wapm-check__dot" aria-hidden="true"></span>
				<span class="wapm-check__label"><?php echo esc_html( (string) $wapm_check['label'] ); ?></span>
				<span class="wapm-check__detail"><?php echo esc_html( (string) $wapm_check['detail'] ); ?></span>
			</li>
		<?php endforeach; ?>
	</ul>

	<details class="wapm-diag-details" <?php echo [] !== $wapm_records ? 'open' : ''; ?>>
		<summary><?php esc_html_e( 'خطاهای ثبت‌شده (برای ارسال به پشتیبانی)', 'wapm' ); ?></summary>

		<?php if ( [] === $wapm_records && [] === $wapm_notices ) : ?>
			<p class="description"><?php esc_html_e( 'هیچ خطایی ثبت نشده است. اگر ابزار هاست خطا نشان می‌دهد، یک بار همان صفحه را باز کنید؛ متن دقیق همین‌جا ظاهر می‌شود.', 'wapm' ); ?></p>
		<?php else : ?>
			<?php foreach ( $wapm_records as $wapm_record ) : ?>
				<pre class="wapm-diag-code"><?php echo esc_html( WAPM_Diagnostics::format_record( (array) $wapm_record ) ); ?></pre>
			<?php endforeach; ?>

			<?php foreach ( $wapm_notices as $wapm_row ) : ?>
				<pre class="wapm-diag-code"><?php
					echo esc_html(
						WAPM_Diagnostics::type_label( (int) ( $wapm_row['type'] ?? 0 ) ) . ': ' .
						(string) ( $wapm_row['message'] ?? '' ) . "\n" .
						WAPM_Diagnostics::short_file( (string) ( $wapm_row['file'] ?? '' ) ) . ':' . (string) ( $wapm_row['line'] ?? '' )
					);
				?></pre>
			<?php endforeach; ?>

			<p><button type="button" class="button" id="wapm-diag-clear-bottom"><?php esc_html_e( 'پاک کردن گزارش‌ها', 'wapm' ); ?></button></p>
		<?php endif; ?>
	</details>
</div>
