<?php
/**
 * تب «torob» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<!-- ============ تب ترب ============ -->
			<div class="wapm-tab-content" id="wapm-tab-torob" style="display:none">

				<div class="wapm-card">
					<h2><?php esc_html_e( '۱) تنظیمات مقایسه', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'شناسه فروشگاه برای این است که قیمت خودتان از «ارزان‌ترین رقیب» جدا شود. اگر تنظیم نشود، افزونه عمداً مقایسه را انجام نمی‌دهد (چون با قیمت خودتان مقایسه می‌شد و نتیجه گمراه‌کننده بود).', 'wapm' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'شناسه فروشگاه در ترب (Shop ID)', 'wapm' ); ?></th>
							<td>
								<input type="text" id="wapm-torob-shop-id" class="regular-text" value="<?php echo esc_attr( WAPM_Torob::get_shop_id() ); ?>" placeholder="مثلاً 118417" inputmode="numeric">
								<p class="description"><?php esc_html_e( 'همان عددی است که در آدرس torob.com/shop/عدد/... می‌بینید.', 'wapm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'نام فروشگاه در ترب (پشتیبان)', 'wapm' ); ?></th>
							<td>
								<input type="text" id="wapm-torob-shop-name" class="regular-text" value="<?php echo esc_attr( WAPM_Torob::get_shop_name() ); ?>" placeholder="مثلاً فروشگاه من">
								<p class="description"><?php esc_html_e( 'اگر داده‌ی فروشنده فقط نام داشته باشد (بدون شناسه)، با همین نام قیمت خودتان شناسایی می‌شود.', 'wapm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'حداکثر عمر داده', 'wapm' ); ?></th>
							<td>
								<input type="number" id="wapm-torob-fresh-days" class="small-text" min="1" max="120" value="<?php echo esc_attr( (string) WAPM_Torob::settings()['fresh_days'] ); ?>">
								<?php esc_html_e( 'روز', 'wapm' ); ?>
								<p class="description"><?php esc_html_e( 'داده‌ی قدیمی‌تر از این، «کهنه» علامت می‌خورد و مبنای تغییر خودکار قیمت قرار نمی‌گیرد.', 'wapm' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'آستانه «برابر»', 'wapm' ); ?></th>
							<td>
								<input type="number" id="wapm-torob-tolerance" class="small-text" min="0" max="100" step="any" value="<?php echo esc_attr( (string) WAPM_Torob::settings()['tolerance_pct'] ); ?>">
								<?php esc_html_e( 'درصد', 'wapm' ); ?>
								<p class="description"><?php esc_html_e( 'اختلاف کمتر از این مقدار، «برابر با رقیب» حساب می‌شود.', 'wapm' ); ?></p>
							</td>
						</tr>
					</table>
					<p>
						<button type="button" class="button button-primary" id="wapm-torob-save-settings"><?php esc_html_e( 'ذخیره تنظیمات', 'wapm' ); ?></button>
						<button type="button" class="button" id="wapm-torob-verify"><?php esc_html_e( 'تأیید شناسه با ترب', 'wapm' ); ?></button>
					</p>

					<details style="margin-top:10px">
						<summary style="cursor:pointer;font-weight:700"><?php esc_html_e( 'شناسه‌ام را ندارم؛ با نام فروشگاه پیدا کن', 'wapm' ); ?></summary>
						<p class="description" style="margin-top:8px">
							<?php esc_html_e( 'نام فروشگاه را همان‌طور که در ترب می‌بینید بنویسید. جستجو با دامنه کار نمی‌کند (محدودیت خود ترب) — فقط نام.', 'wapm' ); ?>
						</p>
						<p>
							<input type="text" id="wapm-torob-shop-query" class="regular-text" placeholder="مثلاً ای ام موبایل">
							<button type="button" class="button" id="wapm-torob-shop-search"><?php esc_html_e( 'جستجو', 'wapm' ); ?></button>
						</p>
						<div id="wapm-torob-shop-results"></div>
					</details>
				</div>

				<div class="wapm-card" id="wapm-torob-status-card">
					<h2><?php esc_html_e( '۲) وضعیت همگام‌سازی', 'wapm' ); ?></h2>
					<div id="wapm-torob-status-body">
						<?php $this->render_torob_status(); ?>
					</div>

					<hr style="margin:18px 0">

					<h3 style="margin:0 0 6px"><?php esc_html_e( 'همگام‌سازی کاتالوگ از API ترب', 'wapm' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'محصولات فروشگاه شما را از ترب می‌خواند و «قیمتی که ترب از شما نشان می‌دهد» را ذخیره می‌کند. با این کار ناسازگاری فید (ترب قیمت قدیمی نشان می‌دهد) برای همه‌ی محصولات مشخص می‌شود. هر کلیک صفحه‌به‌صفحه پیش می‌رود و هر صفحه یک درخواست به ترب می‌فرستد (هر درخواست تا ۲۴ محصول).', 'wapm' ); ?>
					</p>
					<p>
						<button type="button" class="button button-primary" id="wapm-torob-catalog-sync"><?php esc_html_e( 'شروع همگام‌سازی کاتالوگ', 'wapm' ); ?></button>
						<button type="button" class="button" id="wapm-torob-catalog-stop" style="display:none"><?php esc_html_e( 'توقف', 'wapm' ); ?></button>
						<button type="button" class="button" id="wapm-torob-api-reset" style="display:none"><?php esc_html_e( 'بلاک را دستی برداشتم', 'wapm' ); ?></button>
					</p>
					<div id="wapm-torob-api-state"></div>
					<div id="wapm-torob-catalog-result"></div>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( '۳) همگام‌سازی با Bookmarklet', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'این بوکمارکلت با API عمومی ترب (api.torob.com) کار می‌کند و فقط JSON می‌خواند — یعنی دیگر به ظاهر صفحه وابسته نیست. دو حالت دارد: «سریع» فقط کاتالوگ و قیمت خودتان را می‌گیرد؛ «کامل» برای هر محصول یک درخواست می‌فرستد و ارزان‌ترین رقیب را هم می‌آورد. چون از مرورگر خودتان و روی دامنه‌ی ترب اجرا می‌شود، مثل بازدید واقعی دیده می‌شود؛ ولی با بلاک ۴۹۰ فوراً متوقف می‌شود.', 'wapm' ); ?>
					</p>

					<ol style="margin-inline-start:20px">
						<li><?php esc_html_e( 'دکمه‌ی بنفش زیر را با ماوس بگیرید و به نوار بوکمارک (Bookmarks Bar) مرورگرتان بکشید.', 'wapm' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: torob shop URL */
								esc_html__( 'به این آدرس بروید: %s', 'wapm' ),
								'<code>' . esc_html( $this->torob_shop_products_url() ) . '</code>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'روی بوکمارکی که ساختید کلیک کنید و صبر کنید. پیشرفت در گوشه‌ی صفحه نشان داده می‌شود و با دکمه‌ی «توقف» می‌توانید هر وقت خواستید متوقف کنید.', 'wapm' ); ?></li>
						<li><?php esc_html_e( 'اگر وسط کار قطع شد، دوباره روی همان بوکمارک کلیک کنید؛ از همان صفحه ادامه می‌دهد.', 'wapm' ); ?></li>
						<li><?php esc_html_e( 'در پایان، در ستون «قیمت ترب» تب «لیست محصولات» می‌بینید کدام محصول از رقیب گران‌تر یا ارزان‌تر است.', 'wapm' ); ?></li>
					</ol>

					<p style="margin:20px 0">
						<a href="<?php echo esc_url( WAPM_Bookmarklet::href(), [ 'javascript', 'http', 'https' ] ); ?>"
							onclick="return false;"
							style="display:inline-block;padding:12px 24px;background:#7f54b3;color:#fff;border-radius:8px;font-weight:700;text-decoration:none;cursor:grab">
							📥 <?php esc_html_e( 'همگام‌سازی با ترب', 'wapm' ); ?>
						</a>
					</p>
					<p class="description"><?php esc_html_e( 'onclick این دکمه عمداً غیرفعال شده تا با کلیک ساده اجرا نشود — فقط باید کشیده (drag) و به نوار بوکمارک رها شود.', 'wapm' ); ?></p>

					<p><button type="button" class="button wapm-danger" id="wapm-torob-regen-token"><?php esc_html_e( 'تولید دوباره‌ی توکن (بوکمارکلت/فید قبلی از کار می‌افتد)', 'wapm' ); ?></button></p>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( '۴) ورود داده از فید CSV', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'اگر از پنل فروشندگان ترب خروجی/فید می‌گیرید، همین فایل را اینجا وارد کنید. ستون‌های شناسایی‌شده:', 'wapm' ); ?>
						<code>prk</code>, <code>name</code>, <code>price</code>, <code>shop_id</code>, <code>shop_name</code>, <code>our_price</code>
						<?php esc_html_e( '(فقط prk الزامی است) — نام ستون‌ها به بزرگی/کوچکی حروف حساس نیست و ستون‌های ناشناس نادیده گرفته می‌شوند.', 'wapm' ); ?>
					</p>
					<p><input type="file" id="wapm-torob-feed-file" accept=".csv"></p>
					<p><button type="button" class="button button-primary" id="wapm-torob-feed-import"><?php esc_html_e( 'وارد کردن فید', 'wapm' ); ?></button></p>
					<div id="wapm-torob-feed-result"></div>

					<details style="margin-top:14px">
						<summary style="cursor:pointer;font-weight:700"><?php esc_html_e( 'ورود برنامه‌نویسی (برای اتصال فید رسمی/سرور خودتان)', 'wapm' ); ?></summary>
						<p class="description" style="margin-top:8px">
							<?php esc_html_e( 'هر اسکریپتی می‌تواند با همین توکن، داده را به این آدرس POST کند. بدنه: فرم-داده با کلیدهای token، items (JSON) و اختیاری source و meta.', 'wapm' ); ?>
						</p>
						<p><code><?php echo esc_html( admin_url( 'admin-ajax.php' ) . '?action=wapm_torob_ingest' ); ?></code></p>
						<p class="description"><?php esc_html_e( 'ساختار هر آیتم: {prk, name, sellers:[{shop_id, price, shop_name}]}. حداکثر ۵۰۰ آیتم در هر درخواست و ۶۰ درخواست در دقیقه.', 'wapm' ); ?></p>
					</details>
				</div>

				<div class="wapm-card">
					<h2>
						<?php esc_html_e( '۵) صف تأیید تطبیق — محصولات ترب که خودکار وصل نشدند', 'wapm' ); ?>
						<span class="wapm-count" id="wapm-torob-unmatched-count"><?php echo esc_html( (string) count( WAPM_Torob::unmatched() ) ); ?></span>
					</h2>
					<p class="description">
						<?php esc_html_e( 'این‌ها با اعتماد کافی تطبیق داده نشدند (نام مشابه ولی مبهم، یا پیدا نشد). اگر خودکار وصل می‌شدند، ممکن بود قیمت رقیب اشتباهی به محصول شما نسبت داده شود. اینجا می‌توانید با فیلتر و جستجو، چند مورد را یک‌جا تأیید یا نادیده بگیرید — اتصال دستی با همگام‌سازی‌های بعدی بازنویسی نمی‌شود.', 'wapm' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'نکته‌ی تصمیم‌گیری: کنار هر ردیف «قیمت ووکامرس» و درصد اختلاف با ارزان‌ترین رقیب نشان داده می‌شود. اختلاف بزرگ (بیش از ۶۰٪) تقریباً همیشه یعنی این دو محصول یکی نیستند؛ پیش از اتصال، لینک «ترب ↗» را باز کنید.', 'wapm' ); ?>
					</p>
					<p>
						<button type="button" class="button" id="wapm-torob-unmatched-refresh"><?php esc_html_e( 'بازخوانی لیست', 'wapm' ); ?></button>
						<button type="button" class="button wapm-danger" id="wapm-torob-unmatched-clear"><?php esc_html_e( 'پاک کردن کل لیست', 'wapm' ); ?></button>
					</p>
					<div id="wapm-torob-unmatched-area"></div>
				</div>
			</div>
