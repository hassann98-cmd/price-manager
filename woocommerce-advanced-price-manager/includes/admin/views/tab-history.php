<?php
/**
 * تب «history» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<!-- ============ تب تاریخچه ============ -->
			<div class="wapm-tab-content" id="wapm-tab-history" style="display:none">
				<div class="wapm-card">
					<h2><?php esc_html_e( 'تاریخچه تغییرات قیمت', 'wapm' ); ?></h2>
					<table class="widefat striped" id="wapm-history-table">
						<thead><tr>
							<th><?php esc_html_e( 'محصول', 'wapm' ); ?></th>
							<th>SKU</th>
							<th><?php esc_html_e( 'نوع قیمت', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'قیمت قبل', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'قیمت بعد', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'کاربر', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'تاریخ', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'wapm' ); ?></th>
							<th>Batch</th>
						</tr></thead>
						<tbody></tbody>
					</table>
					<p class="wapm-pagination" id="wapm-history-pagination"></p>
				</div>
			</div>
