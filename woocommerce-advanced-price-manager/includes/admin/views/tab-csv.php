<?php
/**
 * تب «csv» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<!-- ============ تب CSV ============ -->
			<div class="wapm-tab-content" id="wapm-tab-csv" style="display:none">
				<div class="wapm-columns">
					<div class="wapm-card">
						<h2><?php esc_html_e( 'خروجی CSV', 'wapm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'خروجی بر اساس فیلترها و تنظیمات تب «تغییر قیمت» ساخته می‌شود (ستون‌های قیمت فعلی و قیمت جدید محاسبه‌شده).', 'wapm' ); ?></p>
						<p><button type="button" class="button button-primary" id="wapm-export-btn"><?php esc_html_e( 'دانلود CSV', 'wapm' ); ?></button></p>
					</div>
					<div class="wapm-card">
						<h2><?php esc_html_e( 'خروجی مقایسه با ترب', 'wapm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'همان ردیف‌هایی که در تب «لیست» می‌بینید: قیمت ما، قیمت ما از دید ترب، ارزان‌ترین رقیب، درصد اختلاف، وضعیت، تعداد فروشنده، سن داده و تغییر ۷ روزه‌ی رقیب.', 'wapm' ); ?></p>
						<p><button type="button" class="button" id="wapm-torob-export-btn"><?php esc_html_e( 'دانلود CSV مقایسه‌ی ترب', 'wapm' ); ?></button></p>
					</div>
					<div class="wapm-card">
						<h2><?php esc_html_e( 'ورود از CSV', 'wapm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'ستون‌های لازم: Product ID یا SKU + New Price. ستون اختیاری: Price Type (regular/sale).', 'wapm' ); ?></p>
						<p><input type="file" id="wapm-import-file" accept=".csv"></p>
						<p><button type="button" class="button button-primary" id="wapm-import-btn"><?php esc_html_e( 'وارد کردن قیمت‌ها', 'wapm' ); ?></button></p>
						<div id="wapm-import-result"></div>
					</div>
				</div>
			</div>
