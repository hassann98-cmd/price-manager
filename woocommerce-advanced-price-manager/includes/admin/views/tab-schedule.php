<?php
/**
 * تب «schedule» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<!-- ============ تب زمان‌بندی ============ -->
			<div class="wapm-tab-content" id="wapm-tab-schedule" style="display:none">
				<?php $queue = WAPM_Queue::state(); ?>
				<div class="wapm-card">
					<h2><?php esc_html_e( 'موتور زمان‌بندی', 'wapm' ); ?></h2>
					<p>
						<span class="wapm-badge <?php echo 'action-scheduler' === $queue['engine'] ? 'wapm-badge-on' : 'wapm-badge-pending'; ?>">
							<?php echo esc_html( $queue['label'] ); ?>
						</span>
						<?php echo esc_html( $queue['next_label'] ); ?>
					</p>
					<p class="description">
						<?php
						echo 'action-scheduler' === $queue['engine']
							? esc_html__( 'اجراهای خودکار از صف Action Scheduler ووکامرس انجام می‌شود: در دیتابیس ثبت می‌شود، خطاها قابل پیگیری‌اند و به بازدید سایت وابسته نیست.', 'wapm' )
							: esc_html__( 'Action Scheduler در دسترس نیست، پس از WP-Cron استفاده می‌شود؛ در این حالت اجرا به بازدید سایت وابسته است و ممکن است تا یک ساعت دیر شود.', 'wapm' );
						?>
					</p>
				</div>
				<div class="wapm-card">
					<h2><?php esc_html_e( 'زمان‌بندی جدید', 'wapm' ); ?></h2>
					<input type="hidden" id="wapm-schedule-id" value="">
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'عنوان', 'wapm' ); ?></th>
							<td><input type="text" id="wapm-schedule-title" class="regular-text" placeholder="<?php esc_attr_e( 'مثلاً: حراج تابستانه', 'wapm' ); ?>"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'شروع (اعمال تغییر)', 'wapm' ); ?></th>
							<td><input type="datetime-local" id="wapm-schedule-start"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'پایان (بازگشت خودکار)', 'wapm' ); ?></th>
							<td><input type="datetime-local" id="wapm-schedule-end">
							<p class="description"><?php esc_html_e( 'خالی بگذارید تا بازگشت خودکار انجام نشود. اجرا حداکثر یک ساعت بعد از زمان تعیین‌شده انجام می‌شود.', 'wapm' ); ?></p></td>
						</tr>
					</table>
					<h3><?php esc_html_e( 'محصولات هدف', 'wapm' ); ?></h3>
					<?php $this->render_target_fields( 'schedule', $brand_taxes, $attributes, $categories ); ?>
					<h3><?php esc_html_e( 'تغییر قیمت', 'wapm' ); ?></h3>
					<?php $this->render_settings_fields( 'schedule' ); ?>
					<p><button type="button" class="button button-primary" id="wapm-save-schedule-btn"><?php esc_html_e( 'ذخیره زمان‌بندی', 'wapm' ); ?></button></p>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( 'زمان‌بندی‌های ثبت‌شده', 'wapm' ); ?></h2>
					<table class="widefat striped" id="wapm-schedules-table">
						<thead><tr>
							<th><?php esc_html_e( 'عنوان', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'شروع', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'پایان', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'wapm' ); ?></th>
						</tr></thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
