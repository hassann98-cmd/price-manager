<?php
/**
 * تب «change» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<!-- ============ تب تغییر قیمت ============ -->
			<div class="wapm-tab-content" id="wapm-tab-change">
				<div class="wapm-columns">
					<div class="wapm-card">
						<h2><?php esc_html_e( '۱) محصولات هدف', 'wapm' ); ?></h2>
						<?php $this->render_target_fields( 'main', $brand_taxes, $attributes, $categories ); ?>
						<p>
							<button type="button" class="button" id="wapm-count-btn"><?php esc_html_e( 'شمارش محصولات متاثر', 'wapm' ); ?></button>
							<span id="wapm-count-result" class="wapm-count"></span>
						</p>
					</div>

					<div class="wapm-card">
						<h2><?php esc_html_e( '۲) نوع تغییر', 'wapm' ); ?></h2>
						<?php $this->render_settings_fields( 'main' ); ?>
					</div>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( '۳) پیش‌نمایش و اعمال', 'wapm' ); ?></h2>
					<p>
						<button type="button" class="button" id="wapm-dryrun-btn"><?php esc_html_e( 'Dry Run (گزارش بدون تغییر)', 'wapm' ); ?></button>
						<button type="button" class="button button-secondary" id="wapm-preview-btn"><?php esc_html_e( 'پیش‌نمایش تغییرات', 'wapm' ); ?></button>
						<button type="button" class="button button-primary" id="wapm-apply-btn" disabled><?php esc_html_e( 'تایید و اعمال تغییرات', 'wapm' ); ?></button>
						<button type="button" class="button wapm-danger" id="wapm-rollback-last-btn"><?php esc_html_e( 'بازگردانی آخرین عملیات', 'wapm' ); ?></button>
					</p>

					<div id="wapm-progress" class="wapm-progress" style="display:none">
						<div class="wapm-progress-bar"><span style="width:0%"></span></div>
						<p class="wapm-progress-text"></p>
					</div>

					<div id="wapm-dryrun-area" style="display:none"></div>
					<div id="wapm-preview-area"></div>
				</div>
			</div>
