<?php
/**
 * WAPM_Admin — منو و صفحه مدیریت
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Admin {

	const SLUG = 'wapm';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		// چک‌باکس «خارج از تغییر قیمت گروهی» در برگه General محصول.
		add_action( 'woocommerce_product_options_pricing', [ $this, 'render_exclude_field' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_exclude_field' ] );
	}

	public function render_exclude_field(): void {
		woocommerce_wp_checkbox( [
			'id'          => '_wapm_exclude',
			'label'       => __( 'قفل قیمت (WAPM)', 'wapm' ),
			'description' => __( 'این محصول از تغییرات گروهی قیمت افزونه Advanced Price Manager خارج شود.', 'wapm' ),
		] );
	}

	public function save_exclude_field( WC_Product $product ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$product->update_meta_data( '_wapm_exclude', isset( $_POST['_wapm_exclude'] ) ? 'yes' : 'no' );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'مدیریت پیشرفته قیمت', 'wapm' ),
			__( 'Advanced Price Manager', 'wapm' ),
			'manage_woocommerce',
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		// استایل ابزارهای Tailwind از فایل محلیِ بیلدشده می‌آید (نه Play CDN):
		// ~۹ کیلوبایت فشرده در برابر ~۴۰۰ کیلوبایت جاوااسکریپت CDN، بدون کامپایل
		// در مرورگر (بدون FOUC)، بدون وابستگی به دامنه‌ی ثالث و سازگار با CSP.
		// ساخت مجدد: npm run build:css  (CI تازه بودنش را بررسی می‌کند).
		wp_enqueue_style( 'wapm-tailwind', WAPM_URL . 'assets/css/tailwind.css', [], WAPM_VERSION );
		wp_enqueue_style( 'wapm-admin', WAPM_URL . 'assets/css/admin.css', [ 'wapm-tailwind' ], WAPM_VERSION );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'select2' );

		// اسکریپت‌ها به ماژول تفکیک شده‌اند (assets/js/admin-*.js). ترتیب مهم است:
		// core ابزارهای مشترک را روی window.WAPMUI می‌گذارد، هر تب از آن برمی‌دارد
		// و boot آخرین فایل است. قبلاً یک فایل ۳۷۰۰ خطی بود که دو تابع هم‌نام در
		// آن بی‌صدا جای هم را می‌گرفتند.
		$scripts = [
			'wapm-admin-core'       => [],
			'wapm-admin-price'      => [ 'wapm-admin-core' ],
			'wapm-admin-automation' => [ 'wapm-admin-core' ],
			'wapm-admin-list'       => [ 'wapm-admin-core' ],
			'wapm-admin-stock'      => [ 'wapm-admin-core', 'wapm-admin-list' ],
			'wapm-admin-torob'      => [ 'wapm-admin-core', 'wapm-admin-list' ],
			'wapm-admin-boot'       => [ 'wapm-admin-automation', 'wapm-admin-list' ],
		];

		$deps = [ 'jquery', 'selectWoo', 'wp-i18n' ];
		foreach ( $scripts as $handle => $extra ) {
			$deps = array_merge( $deps, $extra );
			wp_enqueue_script(
				$handle,
				WAPM_URL . 'assets/js/' . substr( $handle, strlen( 'wapm-admin-' ) ) . '.js',
				array_values( array_unique( $deps ) ),
				WAPM_VERSION,
				true
			);

			// ترجمه‌ی رشته‌های داخل JS: فایل‌های JSON تولیدشده با
			// `wp i18n make-json languages/wapm.pot` در همین پوشه می‌نشینند.
			// بدون این فراخوانی، `wp.i18n.__` در فرانت همان متن اصلی را
			// برمی‌گرداند (هیچ خطایی رخ نمی‌دهد، فقط ترجمه نمی‌شود).
			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations( $handle, 'wapm', WAPM_PATH . 'languages' );
			}
		}

		$brand_taxonomy = class_exists( 'WAPM_List' ) ? WAPM_List::get_brand_taxonomy() : '';
		$category_terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$brand_terms    = $brand_taxonomy ? get_terms( [ 'taxonomy' => $brand_taxonomy, 'hide_empty' => false ] ) : [];

		wp_localize_script( 'wapm-admin-core', 'WAPM', [
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'export_url'     => admin_url( 'admin-post.php?action=wapm_export_csv' ),
			'stock_export_url' => admin_url( 'admin-post.php?action=wapm_export_stock_csv' ),
			'torob_export_url' => admin_url( 'admin-post.php?action=wapm_export_torob_csv' ),
			'nonce'          => wp_create_nonce( WAPM_Ajax::NONCE ),
			'currency'       => get_woocommerce_currency_symbol(),
			'brand_taxonomy' => $brand_taxonomy,
			'category_terms' => array_map(
				fn( $t ) => [ 'id' => $t->term_id, 'text' => $t->name ],
				is_wp_error( $category_terms ) ? [] : $category_terms
			),
			'brand_terms'    => array_map(
				fn( $t ) => [ 'id' => $t->term_id, 'text' => $t->name ],
				is_wp_error( $brand_terms ) ? [] : $brand_terms
			),
			'i18n'       => [
				'confirm_apply'    => __( 'آیا از اعمال تغییرات مطمئن هستید؟ این عملیات روی %d محصول اجرا می‌شود.', 'wapm' ),
				'confirm_warn'     => __( 'در پیش‌نمایش %d قیمت پرخطر (افت بیش از ۹۰٪) دیده شد. با آگاهی از این مورد اعمال شود؟', 'wapm' ),
				'confirm_rollback' => __( 'قیمت‌ها به مقدار قبل بازگردانده می‌شوند. ادامه می‌دهید؟', 'wapm' ),
				'confirm_delete'   => __( 'حذف شود؟', 'wapm' ),
				'no_targets'       => __( 'هیچ محصولی با این شرایط پیدا نشد.', 'wapm' ),
				'done'             => __( 'انجام شد', 'wapm' ),
				'error'            => __( 'خطایی رخ داد. دوباره تلاش کنید.', 'wapm' ),
				'retrying'         => __( 'اتصال قطع شد — تلاش مجدد %1$d از ۳ (%2$d ثانیه دیگر)…', 'wapm' ),
				'resume_hint'      => __( 'همان تنظیمات را دوباره اعمال کنید؛ محصولات انجام‌شده دوباره تغییر نمی‌کنند و می‌توانید از تب تاریخچه Batch را Rollback کنید.', 'wapm' ),
				'loading'          => __( 'در حال بارگذاری…', 'wapm' ),
				'select_products'  => __( 'جستجوی نام محصول یا SKU…', 'wapm' ),
				'increase'         => __( 'افزایش', 'wapm' ),
				'decrease'         => __( 'کاهش', 'wapm' ),
				'regular'          => __( 'قیمت اصلی', 'wapm' ),
				'sale'             => __( 'قیمت فروش', 'wapm' ),
				'enabled'          => __( 'فعال', 'wapm' ),
				'disabled'         => __( 'غیرفعال', 'wapm' ),
				'run'              => __( 'اجرا', 'wapm' ),
				'delete'           => __( 'حذف', 'wapm' ),
				'edit'             => __( 'ویرایش', 'wapm' ),
				'pending'          => __( 'در انتظار', 'wapm' ),
				'running'          => __( 'در حال اجرا', 'wapm' ),
				'applied'          => __( 'اعمال‌شده', 'wapm' ),
				'reverting'        => __( 'در حال بازگشت', 'wapm' ),
				'reverted'         => __( 'بازگشته', 'wapm' ),
			],
		] );
	}

	public function render_page(): void {
		$brand_taxes     = WAPM_Product_Handler::detect_brand_taxonomies();
		$attributes      = WAPM_Product_Handler::get_attribute_taxonomies();
		$categories      = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$rules           = WAPM_Rules::all();
		$schedules       = WAPM_Scheduler::all();
		$list_brand_tax  = $brand_taxes ? $brand_taxes[0] : '';
		$list_brand_obj  = $list_brand_tax ? get_taxonomy( $list_brand_tax ) : false;
		$list_brand_name = $list_brand_obj ? $list_brand_obj->labels->singular_name : __( 'برند', 'wapm' );
		?>
		<div class="wrap wapm-wrap" dir="rtl">
			<h1><span class="dashicons dashicons-tag"></span> <?php esc_html_e( 'مدیریت پیشرفته قیمت ووکامرس', 'wapm' ); ?></h1>

			<div class="wapm-hero">
				<div class="wapm-hero__text">
					<strong><?php esc_html_e( 'قیمت و موجودی را از یک‌جا مدیریت کنید.', 'wapm' ); ?></strong>
					<?php esc_html_e( 'همه‌ی تغییرها با پیش‌نمایش و Batch ذخیره می‌شوند و هر عملیات یک «بازگردانی» دارد.', 'wapm' ); ?>
				</div>
				<div class="wapm-hero__chips">
					<?php
					$wapm_product_count = (int) wp_count_posts( 'product' )->publish;
					$wapm_shop_id       = class_exists( 'WAPM_Torob' ) ? WAPM_Torob::get_shop_id() : '';
					$wapm_shop_name     = class_exists( 'WAPM_Torob' ) ? WAPM_Torob::get_shop_name() : '';
					?>
					<span class="wapm-chip wapm-chip--brand"><?php esc_html_e( 'نسخه', 'wapm' ); ?> <b><?php echo esc_html( WAPM_VERSION ); ?></b></span>
					<span class="wapm-chip"><?php esc_html_e( 'محصول منتشرشده', 'wapm' ); ?> <b><?php echo esc_html( number_format_i18n( $wapm_product_count ) ); ?></b></span>
					<span class="wapm-chip"><?php echo esc_html( get_woocommerce_currency() ); ?></span>
					<?php if ( $wapm_shop_id ) : ?>
						<span class="wapm-chip wapm-chip--ok" title="<?php echo esc_attr( 'شناسه فروشگاه ترب: ' . $wapm_shop_id ); ?>">
							<?php esc_html_e( 'ترب متصل', 'wapm' ); ?>
							<b><?php echo esc_html( $wapm_shop_name ? $wapm_shop_name : '#' . $wapm_shop_id ); ?></b>
						</span>
					<?php else : ?>
						<span class="wapm-chip wapm-chip--warn"><?php esc_html_e( 'شناسه فروشگاه ترب تنظیم نشده', 'wapm' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div id="wapm-toasts" class="wapm-toasts" aria-live="polite" aria-atomic="false"></div>

			<nav class="nav-tab-wrapper wapm-tabs">
				<a href="#" class="nav-tab nav-tab-active" data-tab="change"><?php esc_html_e( 'تغییر قیمت', 'wapm' ); ?></a>
				<a href="#" class="nav-tab" data-tab="list"><?php esc_html_e( 'لیست محصولات', 'wapm' ); ?></a>
				<a href="#" class="nav-tab" data-tab="stock"><?php esc_html_e( 'موجودی', 'wapm' ); ?></a>
				<a href="#" class="nav-tab" data-tab="rules"><?php esc_html_e( 'قوانین', 'wapm' ); ?></a>
				<a href="#" class="nav-tab" data-tab="schedule"><?php esc_html_e( 'زمان‌بندی', 'wapm' ); ?></a>
				<a href="#" class="nav-tab" data-tab="history"><?php esc_html_e( 'تاریخچه', 'wapm' ); ?></a>
				<a href="#" class="nav-tab" data-tab="csv"><?php esc_html_e( 'خروجی / ورودی CSV', 'wapm' ); ?></a>
				<a href="#" class="nav-tab" data-tab="torob"><?php esc_html_e( 'ترب', 'wapm' ); ?></a>
			</nav>

			<?php require WAPM_PATH . 'includes/admin/views/tab-change.php'; ?>

			<?php require WAPM_PATH . 'includes/admin/views/tab-list.php'; ?>

			<?php require WAPM_PATH . 'includes/admin/views/tab-stock.php'; ?>

			<?php require WAPM_PATH . 'includes/admin/views/tab-rules.php'; ?>

			<?php require WAPM_PATH . 'includes/admin/views/tab-schedule.php'; ?>

			<?php require WAPM_PATH . 'includes/admin/views/tab-history.php'; ?>

			<?php require WAPM_PATH . 'includes/admin/views/tab-csv.php'; ?>

			<?php require WAPM_PATH . 'includes/admin/views/tab-torob.php'; ?>

			<script type="application/json" id="wapm-initial-data"><?php
				echo wp_json_encode( [
					'rules'     => $rules,
					'schedules' => $schedules,
				] );
			?></script>
		</div>
		<?php
	}

	/**
	 * فیلدهای انتخاب محصولات هدف (با پیشوند برای استفاده در چند تب).
	 */
	private function render_target_fields( string $prefix, array $brand_taxes, array $attributes, $categories ): void {
		?>
		<table class="form-table wapm-target-fields" data-prefix="<?php echo esc_attr( $prefix ); ?>">
			<tr>
				<th><?php esc_html_e( 'دامنه اعمال', 'wapm' ); ?></th>
				<td>
					<select class="wapm-scope">
						<option value="all"><?php esc_html_e( 'همه محصولات', 'wapm' ); ?></option>
						<option value="selected"><?php esc_html_e( 'محصولات انتخابی', 'wapm' ); ?></option>
						<option value="category"><?php esc_html_e( 'دسته‌بندی خاص', 'wapm' ); ?></option>
						<?php if ( $brand_taxes ) : ?>
							<option value="brand"><?php esc_html_e( 'برند خاص', 'wapm' ); ?></option>
						<?php endif; ?>
						<option value="attribute"><?php esc_html_e( 'ویژگی خاص', 'wapm' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="wapm-scope-row wapm-scope-selected" style="display:none">
				<th><?php esc_html_e( 'انتخاب محصولات', 'wapm' ); ?></th>
				<td><select class="wapm-product-select" multiple style="width:100%"></select>
				<p class="description"><?php esc_html_e( 'جستجو بر اساس نام محصول یا SKU', 'wapm' ); ?></p></td>
			</tr>
			<tr class="wapm-scope-row wapm-scope-category" style="display:none">
				<th><?php esc_html_e( 'دسته‌بندی', 'wapm' ); ?></th>
				<td>
					<select class="wapm-category-select" multiple style="width:100%">
						<?php foreach ( (array) $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name . " ({$cat->count})" ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<?php if ( $brand_taxes ) : ?>
			<tr class="wapm-scope-row wapm-scope-brand" style="display:none">
				<th><?php esc_html_e( 'برند', 'wapm' ); ?></th>
				<td>
					<select class="wapm-brand-tax">
						<?php foreach ( $brand_taxes as $tax ) :
							$obj = get_taxonomy( $tax ); ?>
							<option value="<?php echo esc_attr( $tax ); ?>"><?php echo esc_html( $obj ? $obj->labels->name : $tax ); ?></option>
						<?php endforeach; ?>
					</select>
					<select class="wapm-brand-select" multiple style="width:100%;margin-top:6px"></select>
				</td>
			</tr>
			<?php endif; ?>
			<tr class="wapm-scope-row wapm-scope-attribute" style="display:none">
				<th><?php esc_html_e( 'ویژگی', 'wapm' ); ?></th>
				<td>
					<select class="wapm-attribute-tax">
						<option value=""><?php esc_html_e( '— انتخاب ویژگی —', 'wapm' ); ?></option>
						<?php foreach ( $attributes as $attr ) : ?>
							<option value="<?php echo esc_attr( $attr['taxonomy'] ); ?>"><?php echo esc_html( $attr['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<select class="wapm-attribute-select" multiple style="width:100%;margin-top:6px"></select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'فیلتر موجودی (اختیاری)', 'wapm' ); ?></th>
				<td>
					<select class="wapm-stock-filter">
						<option value="none"><?php esc_html_e( 'بدون فیلتر', 'wapm' ); ?></option>
						<option value="below"><?php esc_html_e( 'موجودی کمتر از', 'wapm' ); ?></option>
						<option value="above"><?php esc_html_e( 'موجودی بیشتر از', 'wapm' ); ?></option>
					</select>
					<input type="number" class="wapm-stock-value small-text" min="0" value="0">
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * فیلدهای تنظیمات تغییر قیمت.
	 */
	private function render_settings_fields( string $prefix ): void {
		?>
		<table class="form-table wapm-settings-fields" data-prefix="<?php echo esc_attr( $prefix ); ?>">
			<tr>
				<th><?php esc_html_e( 'منبع تعیین قیمت', 'wapm' ); ?></th>
				<td>
					<select class="wapm-source">
						<option value="manual"><?php esc_html_e( 'دستی (درصد یا مبلغ)', 'wapm' ); ?></option>
						<option value="torob"><?php esc_html_e( 'ترب (نسبت به ارزان‌ترین رقیب)', 'wapm' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'در حالت «ترب»، قیمت مؤثر هر محصول برابر «قیمت هدف» می‌شود؛ «نوع تغییر»، «قیمت هدف» و «مدیریت Sale» نادیده گرفته می‌شوند و درصد تخفیف حفظ می‌شود.', 'wapm' ); ?></p>
				</td>
			</tr>
			<tr class="wapm-torob-only" style="display:none">
				<th><?php esc_html_e( 'قیمت هدف', 'wapm' ); ?></th>
				<td>
					<span><?php esc_html_e( 'کمتر از ارزان‌ترین رقیب:', 'wapm' ); ?></span>
					<input type="number" class="wapm-torob-undercut" min="0" step="any" value="1" style="width:90px">
					<select class="wapm-torob-undercut-mode">
						<option value="percent"><?php esc_html_e( 'درصد', 'wapm' ); ?></option>
						<option value="fixed"><?php esc_html_e( 'مبلغ ثابت', 'wapm' ); ?></option>
					</select>
					<select class="wapm-torob-currency-unit" style="display:none">
						<option value="toman"><?php esc_html_e( 'تومان', 'wapm' ); ?></option>
						<option value="rial"><?php esc_html_e( 'ریال', 'wapm' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'مثال: ۱٪ یعنی همیشه یک درصد زیر ارزان‌ترین رقیب. حداقل/حداکثر قیمت و گرد کردنِ پایین هم روی این قیمت اعمال می‌شوند.', 'wapm' ); ?></p>
				</td>
			</tr>
			<tr class="wapm-torob-only" style="display:none">
				<th><?php esc_html_e( 'شرایط اعمال', 'wapm' ); ?></th>
				<td>
					<label style="display:block;margin-bottom:6px">
						<input type="checkbox" class="wapm-torob-only-if-pricier" checked>
						<?php esc_html_e( 'فقط وقتی از رقیب گران‌تریم (قیمت را بالا نبر)', 'wapm' ); ?>
					</label>
					<label>
						<?php esc_html_e( 'حداقل اختلاف برای تغییر:', 'wapm' ); ?>
						<input type="number" class="wapm-torob-min-gap" min="0" step="any" value="1" style="width:70px">
						<?php esc_html_e( 'درصد (کمتر از این، تغییر نمی‌کند)', 'wapm' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'حداکثر عمر داده:', 'wapm' ); ?>
						<input type="number" class="wapm-torob-max-age" min="0" step="1" value="0" style="width:60px">
						<?php esc_html_e( 'روز — صفر یعنی از تنظیمات تب ترب استفاده شود. داده‌ی کهنه مبنای تغییر قیمت نمی‌شود.', 'wapm' ); ?>
					</p>
				</td>
			</tr>
			<tr class="wapm-manual-only">
				<th><?php esc_html_e( 'نوع تغییر', 'wapm' ); ?></th>
				<td>
					<label><input type="radio" name="wapm-mode-<?php echo esc_attr( $prefix ); ?>" class="wapm-mode" value="percent" checked> <?php esc_html_e( 'درصدی', 'wapm' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="wapm-mode-<?php echo esc_attr( $prefix ); ?>" class="wapm-mode" value="fixed"> <?php esc_html_e( 'مبلغ ثابت', 'wapm' ); ?></label>
					&nbsp;&nbsp;
					<label title="<?php esc_attr_e( 'همه‌ی محصولات هدف دقیقاً این قیمت می‌شوند', 'wapm' ); ?>"><input type="radio" name="wapm-mode-<?php echo esc_attr( $prefix ); ?>" class="wapm-mode" value="absolute"> <?php esc_html_e( 'قیمت مطلق', 'wapm' ); ?></label>
				</td>
			</tr>
			<tr class="wapm-manual-only wapm-direction-row">
				<th><?php esc_html_e( 'جهت', 'wapm' ); ?></th>
				<td>
					<select class="wapm-direction">
						<option value="increase"><?php esc_html_e( 'افزایش (+)', 'wapm' ); ?></option>
						<option value="decrease"><?php esc_html_e( 'کاهش (−)', 'wapm' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="wapm-manual-only">
				<th><?php esc_html_e( 'مقدار', 'wapm' ); ?></th>
				<td>
					<input type="number" class="wapm-amount" min="0" step="any" value="10">
					<span class="wapm-amount-unit-percent">%</span>
					<select class="wapm-currency-unit" style="display:none">
						<option value="toman"><?php esc_html_e( 'تومان', 'wapm' ); ?></option>
						<option value="rial"><?php esc_html_e( 'ریال', 'wapm' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="wapm-manual-only">
				<th><?php esc_html_e( 'قیمت هدف', 'wapm' ); ?></th>
				<td>
					<select class="wapm-price-target">
						<option value="regular"><?php esc_html_e( 'قیمت اصلی (Regular)', 'wapm' ); ?></option>
						<option value="sale"><?php esc_html_e( 'قیمت فروش (Sale)', 'wapm' ); ?></option>
						<option value="both"><?php esc_html_e( 'هر دو', 'wapm' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="wapm-manual-only">
				<th><?php esc_html_e( 'مدیریت Sale Price', 'wapm' ); ?></th>
				<td>
					<select class="wapm-sale-handling">
						<option value="independent"><?php esc_html_e( 'مستقل (طبق «قیمت هدف»)', 'wapm' ); ?></option>
						<option value="keep_discount"><?php esc_html_e( 'حفظ درصد تخفیف (Regular تغییر، Sale متناسب)', 'wapm' ); ?></option>
						<option value="remove_sale"><?php esc_html_e( 'حذف Sale Price + تغییر Regular', 'wapm' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'مثال حفظ تخفیف: Regular از ۱٬۰۰۰٬۰۰۰ به ۱٬۲۰۰٬۰۰۰ → Sale از ۸۰۰٬۰۰۰ به ۹۶۰٬۰۰۰ (تخفیف ۲۰٪ حفظ می‌شود).', 'wapm' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'محصولات متغیر', 'wapm' ); ?></th>
				<td>
					<?php
					/*
					 * گزینه‌های «فقط قیمت والد» و «هر دو» حذف شدند: در ووکامرس قیمتی که
					 * مشتری می‌بیند برای محصول متغیر از واریشن‌ها می‌آید و مقدار
					 * _regular_price والد هیچ اثری روی فروشگاه ندارد؛ پس آن گزینه‌ها
					 * تغییر «بی‌اثر» بودند. مقدار برای سازگاری با قوانین ذخیره‌شده
					 * همچنان ارسال می‌شود.
					 */
					?>
					<input type="hidden" class="wapm-variable-scope" value="variations">
					<p class="description"><?php esc_html_e( 'قیمت محصولات متغیر روی واریشن‌ها اعمال می‌شود. (قیمت والد در ووکامرس قیمت فروش را تعیین نمی‌کند.)', 'wapm' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'گرد کردن قیمت', 'wapm' ); ?></th>
				<td>
					<select class="wapm-round-mode">
						<option value="none"><?php esc_html_e( 'بدون گرد کردن', 'wapm' ); ?></option>
						<option value="nearest"><?php esc_html_e( 'نزدیک‌ترین مضرب', 'wapm' ); ?></option>
						<option value="up"><?php esc_html_e( 'رو به بالا', 'wapm' ); ?></option>
						<option value="down"><?php esc_html_e( 'رو به پایین', 'wapm' ); ?></option>
					</select>
					<input type="number" class="wapm-round-base" min="0" step="any" value="1000" placeholder="1000">
					<p class="description"><?php esc_html_e( 'مثلاً مضرب ۱۰۰۰: قیمت ۲۳۵۴۳۰ می‌شود ۲۳۵۰۰۰', 'wapm' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'محدودیت قیمت', 'wapm' ); ?></th>
				<td>
					<?php esc_html_e( 'حداقل:', 'wapm' ); ?> <input type="number" class="wapm-min-price" min="0" step="any" value="0">
					&nbsp;
					<?php esc_html_e( 'حداکثر:', 'wapm' ); ?> <input type="number" class="wapm-max-price" min="0" step="any" value="0">
					<p class="description"><?php esc_html_e( 'صفر = بدون محدودیت. مثال: هیچ محصولی کمتر از ۵۰۰۰۰ نشود.', 'wapm' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * کارت وضعیت همگام‌سازی ترب (سرور-رندر؛ بعد از هر همگام‌سازی با Ajax به‌روز می‌شود).
	 */
	private function render_torob_status(): void {
		$last       = WAPM_Torob::get_last_sync();
		$matched    = WAPM_Torob::count_matched();
		$unmatched  = count( WAPM_Torob::unmatched() );
		$identify   = WAPM_Torob::can_identify_shop();
		$settings   = WAPM_Torob::settings();
		$source_map = [
			'bookmarklet' => __( 'بوکمارکلت', 'wapm' ),
			'feed'        => __( 'فید CSV', 'wapm' ),
			'api'         => __( 'ورود برنامه‌نویسی', 'wapm' ),
			'manual'      => __( 'اتصال دستی', 'wapm' ),
			''            => __( '—', 'wapm' ),
		];

		if ( ! $identify ) {
			echo '<div class="notice notice-error inline"><p><strong>' .
				esc_html__( 'شناسه یا نام فروشگاه تنظیم نشده است.', 'wapm' ) .
				'</strong> ' .
				esc_html__( 'تا وقتی مشخص نباشد کدام فروشنده خودتان هستید، «ارزان‌ترین رقیب» قابل محاسبه نیست و افزونه مقایسه را انجام نمی‌دهد.', 'wapm' ) .
				'</p></div>';
		}

		if ( ! empty( $last['structure_warning'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' .
				esc_html__( 'ساختار صفحه‌ی ترب تغییر کرده است؟', 'wapm' ) .
				'</strong> ' .
				esc_html__( 'آخرین همگام‌سازی هیچ محصولی پیدا نکرد. یعنی یا صفحه‌ی اشتباهی باز شده، یا ترب ساختار داده‌اش را عوض کرده و بوکمارکلت باید به‌روزرسانی شود.', 'wapm' ) .
				'</p></div>';
		}

		echo '<div class="wapm-stats">';
		printf(
			'<div class="wapm-stat"><b>%s</b><small>%s</small></div>',
			esc_html( number_format_i18n( $matched ) ),
			esc_html__( 'محصول متصل به ترب', 'wapm' )
		);
		printf(
			'<div class="wapm-stat"><b>%s</b><small>%s</small></div>',
			esc_html( number_format_i18n( $unmatched ) ),
			esc_html__( 'تطبیق‌نیافته', 'wapm' )
		);
		printf(
			'<div class="wapm-stat"><b>%s</b><small>%s</small></div>',
			esc_html( number_format_i18n( (int) $settings['fresh_days'] ) ),
			esc_html__( 'روز تا کهنه‌شدن داده', 'wapm' )
		);
		echo '</div>';

		if ( empty( $last['at'] ) ) {
			echo '<p class="description">' . esc_html__( 'هنوز هیچ داده‌ای از ترب دریافت نشده است.', 'wapm' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		$rows = [
			__( 'آخرین همگام‌سازی', 'wapm' ) => WAPM_Logger::format_time( (string) ( $last['at'] ?? '' ) ),
			__( 'منبع', 'wapm' )            => $source_map[ (string) $last['source'] ] ?? (string) $last['source'],
			__( 'محصولات یافت‌شده', 'wapm' ) => (int) $last['discovered'],
			__( 'صفحات خوانده‌شده', 'wapm' ) => (int) $last['pages'],
			__( 'تطبیق‌خورده', 'wapm' )     => (int) $last['matched'],
			__( 'قیمت بررسی‌شده', 'wapm' )   => (int) $last['checked'],
			__( 'بدون داده‌ی فروشنده', 'wapm' ) => (int) ( $last['no_sellers'] ?? 0 ),
			__( 'تطبیق‌نیافته', 'wapm' )    => (int) $last['unmatched'],
			__( 'مبهم (نیاز به بررسی)', 'wapm' ) => (int) ( $last['ambiguous'] ?? 0 ),
		];
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * آدرس عمومی صفحه‌ی محصولات فروشگاه در ترب (برای راهنمای Bookmarklet).
	 */
	private function torob_shop_products_url(): string {
		$shop_id = WAPM_Torob::get_shop_id();
		if ( ! $shop_id ) {
			return 'torob.com/shop/[شناسه فروشگاه]/...';
		}
		return 'https://torob.com/shop/' . $shop_id . '/-/' . rawurlencode( 'محصولات' ) . '/';
	}

}
