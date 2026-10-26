<?php
/**
 * تب «stock» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<!-- ============ تب موجودی ============ -->
			<div class="wapm-tab-content" id="wapm-tab-stock" style="display:none">
				<div class="wapm-card">
					<h2><?php esc_html_e( 'تغییر گروهی موجودی', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'روی محصولاتی اعمال می‌شود که در تب «لیست محصولات» تیک خورده‌اند (حداکثر ۵۰ مورد در هر مرحله). اگر چیزی انتخاب نشده باشد، فیلترهای تب «تغییر قیمت» مبنا قرار می‌گیرد.', 'wapm' ); ?>
					</p>

					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'عملیات', 'wapm' ); ?></th>
							<td>
								<select id="wapm-stock-mode">
									<option value="increase"><?php esc_html_e( 'افزودن', 'wapm' ); ?></option>
									<option value="decrease"><?php esc_html_e( 'کاهش', 'wapm' ); ?></option>
									<option value="set"><?php esc_html_e( 'تنظیم روی مقدار ثابت', 'wapm' ); ?></option>
								</select>
								<select id="wapm-stock-unit">
									<option value="count"><?php esc_html_e( 'تعداد', 'wapm' ); ?></option>
									<option value="percent"><?php esc_html_e( 'درصد از موجودی فعلی', 'wapm' ); ?></option>
								</select>
								<input type="number" id="wapm-stock-amount" min="0" step="1" value="1" class="small-text">
								<p class="description"><?php esc_html_e( '«تنظیم» همیشه با تعداد انجام می‌شود. درصد روی موجودی صفر اثری ندارد. کاهش موجودی را منفی نمی‌کند مگر محصول پیش‌خرید باشد.', 'wapm' ); ?></p>
								<p>
									<label>
										<input type="checkbox" id="wapm-stock-variations" checked>
										<?php esc_html_e( 'محصول متغیر را به واریشن‌هایش باز کن (موجودی روی واریشن‌هاست)', 'wapm' ); ?>
									</label>
								</p>
							</td>
						</tr>
					</table>

					<p>
						<button type="button" class="button" id="wapm-stock-preview-btn"><?php esc_html_e( 'پیش‌نمایش', 'wapm' ); ?></button>
						<button type="button" class="button button-primary" id="wapm-stock-apply-btn" disabled><?php esc_html_e( 'اعمال موجودی', 'wapm' ); ?></button>
						<span id="wapm-stock-summary" class="wapm-count"></span>
					</p>

					<div id="wapm-stock-result"></div>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( 'بازگردانی آخرین تغییر موجودی', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'یک Batch فقط وقتی بازگردانی می‌شود که مقدار فعلی محصول هنوز همان چیزی باشد که آن عملیات گذاشته است؛ اگر کسی بعد از آن موجودی را عوض کرده باشد، به‌عنوان تداخل گزارش می‌شود تا کار او پاک نشود.', 'wapm' ); ?>
					</p>
					<p>
						<span id="wapm-stock-last-batch">—</span>
						<button type="button" class="button wapm-danger" id="wapm-stock-undo-last-btn" disabled><?php esc_html_e( 'بازگردانی آخرین Batch', 'wapm' ); ?></button>
					</p>
					<div id="wapm-stock-undo-result"></div>
				</div>

				<div class="wapm-columns">
					<div class="wapm-card">
						<h2><?php esc_html_e( 'خروجی CSV موجودی', 'wapm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'بر اساس فیلترهای تب «تغییر قیمت». والد متغیر خودش موجودی ندارد؛ واریشن‌هایش زیر آن می‌آیند.', 'wapm' ); ?></p>
						<p><button type="button" class="button button-primary" id="wapm-stock-export-btn"><?php esc_html_e( 'دانلود CSV موجودی', 'wapm' ); ?></button></p>
					</div>
					<div class="wapm-card">
						<h2><?php esc_html_e( 'ورود CSV موجودی', 'wapm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'ستون‌های شناسایی: Variation ID یا Product ID یا SKU. ستون مقدار: Stock Quantity. ستون اختیاری: Stock Status (برای محصولی که مدیریت انبار ندارد). پیش‌نمایش هیچ چیزی را ذخیره نمی‌کند.', 'wapm' ); ?></p>
						<p><input type="file" id="wapm-stock-import-file" accept=".csv"></p>
						<p>
							<button type="button" class="button" id="wapm-stock-import-preview-btn"><?php esc_html_e( 'پیش‌نمایش', 'wapm' ); ?></button>
							<button type="button" class="button button-primary" id="wapm-stock-import-apply-btn" disabled><?php esc_html_e( 'ذخیره موجودی‌ها', 'wapm' ); ?></button>
						</p>
						<div id="wapm-stock-import-result"></div>
					</div>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( 'تاریخچه موجودی', 'wapm' ); ?></h2>
					<table class="widefat striped" id="wapm-stock-log-table">
						<thead><tr>
							<th><?php esc_html_e( 'محصول', 'wapm' ); ?></th>
							<th>SKU</th>
							<th><?php esc_html_e( 'قبل', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'بعد', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'تفاوت', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'کاربر', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'تاریخ', 'wapm' ); ?></th>
							<th>Batch</th>
						</tr></thead>
						<tbody></tbody>
					</table>
					<p class="wapm-pagination" id="wapm-stock-log-pagination"></p>
				</div>
			</div>
