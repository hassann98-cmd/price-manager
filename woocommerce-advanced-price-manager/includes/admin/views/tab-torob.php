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
						<tr>
							<th><?php esc_html_e( 'به‌روزرسانی خودکار', 'wapm' ); ?></th>
							<td>
								<?php $wapm_torob_settings = WAPM_Torob::settings(); ?>
								<label>
									<input type="checkbox" id="wapm-torob-auto-refresh" <?php echo $wapm_torob_settings['auto_refresh'] ? 'checked' : ''; ?>>
									<?php esc_html_e( 'هر شب قیمت رقبا را خودکار تازه کن', 'wapm' ); ?>
								</label>
								<input type="number" id="wapm-torob-auto-limit" class="small-text" min="1" max="<?php echo esc_attr( (string) WAPM_Torob::AUTO_MAX_PER_RUN ); ?>" value="<?php echo esc_attr( (string) $wapm_torob_settings['auto_refresh_limit'] ); ?>">
								<?php esc_html_e( 'محصول در هر اجرا', 'wapm' ); ?>
								<p class="description">
									<?php esc_html_e( 'فقط محصولاتی که «قیمت رقیب» ندارند یا داده‌شان از «حداکثر عمر داده» قدیمی‌تر است بررسی می‌شوند، قدیمی‌ترها اول. پیش‌فرض خاموش است چون هر اجرا درخواست به ترب می‌فرستد (سقف سرور: ۶۰ درخواست در روز).', 'wapm' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'دسته‌های مستثنا', 'wapm' ); ?></th>
							<td>
								<?php
								$wapm_exclude_cats = (array) WAPM_Torob::settings()['exclude_categories'];
								$wapm_brand_tax    = $brand_taxes ? (string) $brand_taxes[0] : '';
								$wapm_exclude_brands = (array) WAPM_Torob::settings()['exclude_brands'];
								$wapm_brands         = $wapm_brand_tax
									? get_terms( [ 'taxonomy' => $wapm_brand_tax, 'hide_empty' => false ] )
									: [];
								?>
								<select id="wapm-torob-exclude-categories" multiple size="5" style="min-width:260px">
									<?php foreach ( (array) $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php echo in_array( (int) $cat->term_id, array_map( 'intval', $wapm_exclude_cats ), true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $cat->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'محصولات این دسته‌ها در حالت ترب قیمت خودکار نمی‌گیرند (همه‌ی جستجوها و پیش‌نمایش‌های خودکار). عملیات دستی شما دست‌نخورده می‌ماند.', 'wapm' ); ?></p>
							</td>
						</tr>
						<?php if ( ! empty( $wapm_brands ) && ! is_wp_error( $wapm_brands ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'برندهای مستثنا', 'wapm' ); ?></th>
							<td>
								<select id="wapm-torob-exclude-brands" multiple size="5" style="min-width:260px">
									<?php foreach ( (array) $wapm_brands as $brand ) : ?>
										<option value="<?php echo esc_attr( (string) $brand->term_id ); ?>" <?php echo in_array( (int) $brand->term_id, array_map( 'intval', $wapm_exclude_brands ), true ) ? 'selected' : ''; ?>>
											<?php echo esc_html( $brand->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'برای برندهایی که قیمت‌شان را دستی نگه می‌دارید.', 'wapm' ); ?></p>
							</td>
						</tr>
						<?php endif; ?>
						<tr>
							<th><?php esc_html_e( 'زیربرش مخصوص دسته', 'wapm' ); ?></th>
							<td>
								<?php $wapm_undercut_rules = (array) WAPM_Torob::settings()['category_undercuts']; ?>
								<div id="wapm-torob-cat-undercuts">
									<?php foreach ( $wapm_undercut_rules as $wapm_rule ) : ?>
										<div class="wapm-undercut-row">
											<select class="wapm-undercut-cat">
												<?php foreach ( (array) $categories as $cat ) : ?>
													<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php echo (int) $cat->term_id === (int) $wapm_rule['term_id'] ? 'selected' : ''; ?>>
														<?php echo esc_html( $cat->name ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<input type="number" class="wapm-undercut-value small-text" min="0" step="any" value="<?php echo esc_attr( (string) $wapm_rule['value'] ); ?>">
											<select class="wapm-undercut-mode">
												<option value="percent" <?php echo 'percent' === $wapm_rule['mode'] ? 'selected' : ''; ?>><?php esc_html_e( 'درصد', 'wapm' ); ?></option>
												<option value="fixed" <?php echo 'fixed' === $wapm_rule['mode'] ? 'selected' : ''; ?>><?php esc_html_e( 'تومان', 'wapm' ); ?></option>
											</select>
											<button type="button" class="button wapm-undercut-remove" aria-label="<?php esc_attr_e( 'حذف قاعده', 'wapm' ); ?>">×</button>
										</div>
									<?php endforeach; ?>
								</div>
								<p>
									<button type="button" class="button" id="wapm-torob-add-undercut"><?php esc_html_e( 'افزودن دسته', 'wapm' ); ?></button>
								</p>
								<p class="description"><?php esc_html_e( 'برای دسته‌ای که با منطق دیگری قیمت می‌خورد (مثلاً «لوازم جانبی: ۵٪ زیر رقیب»). اولین قاعده‌ای که با دسته‌های محصول بخواند اعمال می‌شود؛ بقیه‌ی محصولات همان «مقدار زیربرش» تب تغییر قیمت را می‌گیرند.', 'wapm' ); ?></p>
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
							<input type="text" id="wapm-torob-shop-query" class="regular-text" placeholder="<?php esc_attr_e( 'مثلاً ای ام موبایل', 'wapm' ); ?>">
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

					<hr style="margin:18px 0">

					<h3 style="margin:0 0 6px"><?php esc_html_e( 'بررسی گروهی قیمت رقبا', 'wapm' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'همگام‌سازی کاتالوگ بالا فقط «قیمتی که ترب از شما نشان می‌دهد» را می‌آورد و هیچ قیمت رقیبی ذخیره نمی‌کند. اگر در ستون تربِ لیست محصولات نوشته «قیمت رقیب ندارد»، همین دکمه آن‌ها را دسته‌دسته از API ترب می‌خواند: هر درخواست تا ۵ محصول، با نوار پیشرفت و دکمه‌ی توقف. برای کاتالوگ بزرگ، بوکمارکلت بخش ۳ سریع‌تر است.', 'wapm' ); ?>
					</p>
					<div id="wapm-torob-gap-banner"></div>
					<p>
						<button type="button" class="button button-primary" id="wapm-torob-bulk-refresh"><?php esc_html_e( 'به‌روزرسانی گروهی قیمت رقبا', 'wapm' ); ?></button>
						<button type="button" class="button" id="wapm-torob-bulk-stop" style="display:none"><?php esc_html_e( 'توقف', 'wapm' ); ?></button>
						<span id="wapm-torob-bulk-count" class="description" style="margin-inline-start:8px"></span>
					</p>
					<div id="wapm-torob-bulk-progress" style="display:none;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;max-width:520px">
						<span style="display:block;height:100%;width:0;background:#7f54b3;transition:width .2s"></span>
					</div>
					<div id="wapm-torob-bulk-result"></div>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( '۳) همگام‌سازی با Bookmarklet', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'این بوکمارکلت با API عمومی ترب (api.torob.com) کار می‌کند و فقط JSON می‌خواند — یعنی دیگر به ظاهر صفحه وابسته نیست. سه حالت دارد: «فقط محصولات بدون قیمت رقیب» (سریع‌ترین راه؛ لیست ناقص‌ها را از سایت می‌گیرد و فقط همان‌ها را واکشی می‌کند)، «کامل» (کل کاتالوگ + قیمت رقبا) و «سریع» (فقط کاتالوگ و قیمت خودتان). چون از مرورگر خودتان و روی دامنه‌ی ترب اجرا می‌شود، مثل بازدید واقعی دیده می‌شود؛ ولی با بلاک ۴۹۰ فوراً متوقف می‌شود.', 'wapm' ); ?>
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
