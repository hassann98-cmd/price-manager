<?php
/**
 * تب «rules» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<!-- ============ تب قوانین ============ -->
			<div class="wapm-tab-content" id="wapm-tab-rules" style="display:none">
				<div class="wapm-card">
					<h2><?php esc_html_e( 'قانون جدید / ویرایش', 'wapm' ); ?></h2>
					<input type="hidden" id="wapm-rule-id" value="">
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'عنوان قانون', 'wapm' ); ?></th>
							<td><input type="text" id="wapm-rule-title" class="regular-text" placeholder="<?php esc_attr_e( 'مثلاً: افزایش ۱۰٪ ظروف', 'wapm' ); ?>"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'اولویت', 'wapm' ); ?></th>
							<td><input type="number" id="wapm-rule-priority" value="10" min="1" class="small-text">
							<p class="description"><?php esc_html_e( 'عدد کمتر = اجرای زودتر', 'wapm' ); ?></p></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'وضعیت', 'wapm' ); ?></th>
							<td>
								<label><input type="checkbox" id="wapm-rule-enabled" checked> <?php esc_html_e( 'این قانون فعال باشد', 'wapm' ); ?></label>
								<p class="description"><?php esc_html_e( 'قانون غیرفعال در لیست می‌ماند ولی قابل اجرا نیست.', 'wapm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'اجرای خودکار', 'wapm' ); ?></th>
							<td>
								<label><input type="checkbox" id="wapm-rule-auto"> <?php esc_html_e( 'این قانون هر روز خودکار اجرا شود', 'wapm' ); ?></label>
								<p class="description"><?php esc_html_e( 'پیش‌فرض خاموش است: قانون فقط با دکمه‌ی «اجرا» اعمال می‌شود. با روشن‌کردن، در زمان‌بندی ساعتانه‌ی افزونه و به ترتیب اولویت اجرا می‌شود (فاصله‌ی پیش‌فرض: ۲۴ ساعت).', 'wapm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'بازه اعتبار (اختیاری)', 'wapm' ); ?></th>
							<td>
								<?php esc_html_e( 'از:', 'wapm' ); ?> <input type="datetime-local" id="wapm-rule-start">
								&nbsp;
								<?php esc_html_e( 'تا:', 'wapm' ); ?> <input type="datetime-local" id="wapm-rule-end">
								<p class="description"><?php esc_html_e( 'خالی = بدون محدودیت زمانی. قانون فقط در این بازه اجرا می‌شود.', 'wapm' ); ?></p>
							</td>
						</tr>
					</table>
					<h3><?php esc_html_e( 'شرط (محصولات هدف)', 'wapm' ); ?></h3>
					<?php $this->render_target_fields( 'rule', $brand_taxes, $attributes, $categories ); ?>
					<h3><?php esc_html_e( 'تغییر قیمت', 'wapm' ); ?></h3>
					<?php $this->render_settings_fields( 'rule' ); ?>
					<p><button type="button" class="button button-primary" id="wapm-save-rule-btn"><?php esc_html_e( 'ذخیره قانون', 'wapm' ); ?></button></p>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( 'قوانین ثبت‌شده', 'wapm' ); ?></h2>
					<?php
					$last_auto = get_option( 'wapm_rules_last_auto', [] );
					if ( is_array( $last_auto ) && ! empty( $last_auto['at'] ) ) :
						?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: date/time, 2: number of rules, 3: number of products */
								esc_html__( 'آخرین اجرای خودکار: %1$s — %2$d قانون، %3$d محصول.', 'wapm' ),
								esc_html( (string) $last_auto['at'] ),
								(int) ( $last_auto['ran'] ?? 0 ),
								(int) ( $last_auto['processed'] ?? 0 )
							);
							if ( ! empty( $last_auto['left'] ) ) {
								echo ' ' . esc_html__( 'بخشی از کار به اجرای بعدی موکول شد.', 'wapm' );
							}
							?>
						</p>
					<?php endif; ?>
					<div id="wapm-progress-rule" class="wapm-progress" style="display:none">
						<div class="wapm-progress-bar"><span style="width:0%"></span></div>
						<p class="wapm-progress-text"></p>
					</div>
					<table class="widefat striped" id="wapm-rules-table">
						<thead><tr>
							<th><?php esc_html_e( 'عنوان', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'اولویت', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'خودکار', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'wapm' ); ?></th>
						</tr></thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
