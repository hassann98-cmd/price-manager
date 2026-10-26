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

		wp_enqueue_style( 'wapm-admin', WAPM_URL . 'assets/css/admin.css', [], WAPM_VERSION );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wapm-admin', WAPM_URL . 'assets/js/admin.js', [ 'jquery', 'selectWoo' ], WAPM_VERSION, true );
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );

		// Tailwind (Play CDN) — فقط همین صفحه، برای تب «لیست محصولات».
		// Preflight غیرفعال است تا استایل‌های پیش‌فرض wp-admin (دکمه‌ها، منو و…) به‌هم نریزد.
		wp_enqueue_script( 'wapm-tailwind-cdn', 'https://cdn.tailwindcss.com/3.4.16', [], null, false );
		wp_enqueue_script( 'wapm-tailwind-config', WAPM_URL . 'assets/js/tailwind-config.js', [ 'wapm-tailwind-cdn' ], WAPM_VERSION, false );

		$brand_taxonomy = class_exists( 'WAPM_List' ) ? WAPM_List::get_brand_taxonomy() : '';
		$category_terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$brand_terms    = $brand_taxonomy ? get_terms( [ 'taxonomy' => $brand_taxonomy, 'hide_empty' => false ] ) : [];

		wp_localize_script( 'wapm-admin', 'WAPM', [
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'export_url'     => admin_url( 'admin-post.php?action=wapm_export_csv' ),
			'stock_export_url' => admin_url( 'admin-post.php?action=wapm_export_stock_csv' ),
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

			<!-- ============ تب لیست محصولات ============ -->
			<div class="wapm-tab-content" id="wapm-tab-list" style="display:none">
				<div id="wapm-list-root" dir="rtl" class="bg-white border border-slate-200 rounded-2xl overflow-hidden">

					<div class="p-4 flex flex-wrap items-center gap-3 border-b border-slate-100 bg-slate-50/60">
						<div class="relative flex-[2] min-w-[240px]">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
							<input type="text" id="wapm-list-search" placeholder="<?php esc_attr_e( 'جستجوی نام یا SKU…', 'wapm' ); ?>" class="w-full h-11 pr-11 pl-4 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-primary-200 focus:border-primary-400">
						</div>
						<div class="min-w-[180px]">
							<select id="wapm-list-category" class="w-full h-11 rounded-xl border border-slate-200 text-sm px-3">
								<option value="0"><?php esc_html_e( 'همه دسته‌بندی‌ها', 'wapm' ); ?></option>
								<?php foreach ( (array) $categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name . " ({$cat->count})" ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="min-w-[150px]">
							<select id="wapm-list-stock" class="w-full h-11 rounded-xl border border-slate-200 text-sm px-3">
								<option value=""><?php esc_html_e( 'وضعیت موجودی', 'wapm' ); ?></option>
								<option value="instock"><?php esc_html_e( 'موجود', 'wapm' ); ?></option>
								<option value="outofstock"><?php esc_html_e( 'ناموجود', 'wapm' ); ?></option>
								<option value="onbackorder"><?php esc_html_e( 'پیش‌خرید', 'wapm' ); ?></option>
							</select>
						</div>

						<div class="min-w-[190px]">
							<select id="wapm-list-torob" class="w-full h-11 rounded-xl border border-slate-200 text-sm px-3">
								<option value=""><?php esc_html_e( 'وضعیت نسبت به ترب', 'wapm' ); ?></option>
								<option value="pricier"><?php esc_html_e( '🔴 گران‌تر از رقیب', 'wapm' ); ?></option>
								<option value="cheaper"><?php esc_html_e( '🟢 ارزان‌تر از رقیب', 'wapm' ); ?></option>
								<option value="equal"><?php esc_html_e( 'برابر با رقیب', 'wapm' ); ?></option>
								<option value="stale"><?php esc_html_e( 'داده کهنه', 'wapm' ); ?></option>
								<option value="fresh"><?php esc_html_e( 'داده تازه', 'wapm' ); ?></option>
								<option value="no_competitor"><?php esc_html_e( 'فقط ما در ترب', 'wapm' ); ?></option>
								<option value="needs_shop_id"><?php esc_html_e( 'شناسه فروشگاه تنظیم نشده', 'wapm' ); ?></option>
								<option value="any"><?php esc_html_e( 'فقط متصل‌شده به ترب', 'wapm' ); ?></option>
								<option value="none"><?php esc_html_e( 'متصل‌نشده به ترب', 'wapm' ); ?></option>
							</select>
						</div>

						<div class="min-w-[170px]">
							<select id="wapm-list-orderby" class="w-full h-11 rounded-xl border border-slate-200 text-sm px-3">
								<option value="id_desc"><?php esc_html_e( 'جدیدترین', 'wapm' ); ?></option>
								<option value="torob_diff_desc"><?php esc_html_e( 'بیشترین گرانی نسبت به ترب', 'wapm' ); ?></option>
								<option value="torob_diff_asc"><?php esc_html_e( 'بیشترین ارزانی نسبت به ترب', 'wapm' ); ?></option>
								<option value="price_asc"><?php esc_html_e( 'ارزان‌ترین محصول', 'wapm' ); ?></option>
								<option value="price_desc"><?php esc_html_e( 'گران‌ترین محصول', 'wapm' ); ?></option>
								<option value="name_asc"><?php esc_html_e( 'نام (الفبا)', 'wapm' ); ?></option>
							</select>
						</div>

						<button type="button" id="wapm-list-smart-toggle" class="h-11 px-5 bg-white border border-slate-200 rounded-xl flex items-center gap-2 text-sm font-bold text-slate-600">
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
							<?php esc_html_e( 'فیلتر هوشمند', 'wapm' ); ?>
						</button>

						<div class="relative">
							<button type="button" id="wapm-list-columns-toggle" class="h-11 px-5 bg-white border border-slate-200 rounded-xl flex items-center gap-2 text-sm font-bold text-slate-600">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"></rect><line x1="9" x2="9" y1="3" y2="21"></line><line x1="15" x2="15" y1="3" y2="21"></line></svg>
								<?php esc_html_e( 'ستون‌ها', 'wapm' ); ?>
							</button>
							<div id="wapm-list-columns-menu" class="absolute right-0 top-full mt-1 bg-white border border-slate-200 rounded-xl shadow-lg z-30 p-2" style="display:none;min-width:170px">
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="image" checked> <?php esc_html_e( 'تصویر', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="regular_price" checked> <?php esc_html_e( 'قیمت اصلی', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="sale_price" checked> <?php esc_html_e( 'قیمت ویژه', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="manage_stock" checked> <?php esc_html_e( 'مدیریت انبار', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="stock_qty" checked> <?php esc_html_e( 'موجودی', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="stock_status" checked> <?php esc_html_e( 'وضعیت موجودی', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="categories" checked> <?php esc_html_e( 'دسته‌بندی‌ها', 'wapm' ); ?></label>
								<?php if ( $list_brand_tax ) : ?>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="brand" checked> <?php echo esc_html( $list_brand_name ); ?></label>
								<?php endif; ?>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="torob" checked> <?php esc_html_e( 'قیمت ترب', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="id" checked> <?php esc_html_e( 'شناسه', 'wapm' ); ?></label>
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="sku" checked> SKU</label>
							</div>
						</div>

						<div class="flex bg-slate-100 p-1.5 rounded-2xl border border-slate-200 h-11 items-center" title="<?php esc_attr_e( 'حالت نمایش', 'wapm' ); ?>">
							<button type="button" data-view="excel" class="wapm-view-mode-btn h-full px-4 flex items-center gap-1.5 rounded-xl text-[11px] font-black transition-all text-slate-500">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18"></path><rect width="18" height="18" x="3" y="3" rx="2"></rect><path d="M3 9h18"></path><path d="M3 15h18"></path></svg>
								<?php esc_html_e( 'اکسل', 'wapm' ); ?>
							</button>
							<button type="button" data-view="simple" class="wapm-view-mode-btn h-full px-4 flex items-center gap-1.5 rounded-xl text-[11px] font-black transition-all bg-white text-primary-600 shadow-md">
								<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="3" rx="1"></rect><rect width="7" height="7" x="14" y="14" rx="1"></rect><rect width="7" height="7" x="3" y="14" rx="1"></rect></svg>
								<?php esc_html_e( 'ساده', 'wapm' ); ?>
							</button>
						</div>

						<div class="flex bg-slate-100 p-1.5 rounded-2xl border border-slate-200 h-11 items-center" title="<?php esc_attr_e( 'نحوه ذخیره‌سازی', 'wapm' ); ?>">
							<button type="button" data-save="auto" class="wapm-save-mode-btn h-full px-4 flex items-center rounded-xl text-[11px] font-black transition-all bg-white text-primary-600 shadow-md"><?php esc_html_e( 'خودکار', 'wapm' ); ?></button>
							<button type="button" data-save="manual" class="wapm-save-mode-btn h-full px-4 flex items-center rounded-xl text-[11px] font-black transition-all text-slate-500"><?php esc_html_e( 'دستی', 'wapm' ); ?></button>
						</div>

						<button type="button" id="wapm-list-save-pending" class="h-11 px-5 bg-primary-600 text-white rounded-xl text-sm font-bold" style="display:none">
							<?php esc_html_e( 'ذخیره تغییرات', 'wapm' ); ?> (<span id="wapm-list-pending-count">0</span>)
						</button>

						<div class="w-28">
							<select id="wapm-list-per-page" class="w-full h-11 rounded-xl border border-slate-200 text-sm px-2">
								<option value="25">25 <?php esc_html_e( 'ردیف', 'wapm' ); ?></option>
								<option value="50">50 <?php esc_html_e( 'ردیف', 'wapm' ); ?></option>
								<option value="100">100 <?php esc_html_e( 'ردیف', 'wapm' ); ?></option>
								<option value="200">200 <?php esc_html_e( 'ردیف', 'wapm' ); ?></option>
							</select>
						</div>
					</div>

					<!-- پنل فیلتر هوشمند -->
					<div id="wapm-list-smart-panel" class="p-4 border-b border-slate-100 bg-white flex flex-wrap items-end gap-3" style="display:none">
						<div>
							<label class="block text-[11px] font-bold text-slate-500 mb-1"><?php esc_html_e( 'قیمت از', 'wapm' ); ?></label>
							<input type="number" min="0" id="wapm-smart-price-min" class="w-32 h-10 rounded-xl border border-slate-200 text-sm px-3">
						</div>
						<div>
							<label class="block text-[11px] font-bold text-slate-500 mb-1"><?php esc_html_e( 'قیمت تا', 'wapm' ); ?></label>
							<input type="number" min="0" id="wapm-smart-price-max" class="w-32 h-10 rounded-xl border border-slate-200 text-sm px-3">
						</div>
						<div>
							<label class="block text-[11px] font-bold text-slate-500 mb-1"><?php esc_html_e( 'موجودی از', 'wapm' ); ?></label>
							<input type="number" min="0" id="wapm-smart-stock-min" class="w-28 h-10 rounded-xl border border-slate-200 text-sm px-3">
						</div>
						<div>
							<label class="block text-[11px] font-bold text-slate-500 mb-1"><?php esc_html_e( 'موجودی تا', 'wapm' ); ?></label>
							<input type="number" min="0" id="wapm-smart-stock-max" class="w-28 h-10 rounded-xl border border-slate-200 text-sm px-3">
						</div>
						<div>
							<label class="block text-[11px] font-bold text-slate-500 mb-1"><?php esc_html_e( 'نوع محصول', 'wapm' ); ?></label>
							<select id="wapm-smart-type" class="w-36 h-10 rounded-xl border border-slate-200 text-sm px-2">
								<option value=""><?php esc_html_e( 'همه', 'wapm' ); ?></option>
								<option value="simple"><?php esc_html_e( 'ساده', 'wapm' ); ?></option>
								<option value="variable"><?php esc_html_e( 'متغیر', 'wapm' ); ?></option>
							</select>
						</div>
						<label class="flex items-center gap-2 h-10 text-sm font-bold text-slate-600">
							<input type="checkbox" id="wapm-smart-on-sale">
							<?php esc_html_e( 'فقط تخفیف‌دار', 'wapm' ); ?>
						</label>
						<button type="button" id="wapm-smart-apply" class="h-10 px-5 bg-primary-600 text-white rounded-xl text-sm font-bold"><?php esc_html_e( 'اعمال فیلتر', 'wapm' ); ?></button>
						<button type="button" id="wapm-smart-clear" class="h-10 px-5 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-500"><?php esc_html_e( 'پاک کردن', 'wapm' ); ?></button>
						<button type="button" id="wapm-smart-low-stock" class="h-10 px-5 bg-amber-100 text-amber-800 rounded-xl text-sm font-bold" title="<?php esc_attr_e( 'موجودی بین ۱ تا ۵', 'wapm' ); ?>"><?php esc_html_e( 'رو به اتمام', 'wapm' ); ?></button>
					</div>

					<!-- نوار عملیات گروهی -->
					<div id="wapm-list-bulk-bar" class="p-3 border-b border-slate-100 bg-primary-50 flex flex-wrap items-center gap-3" style="display:none">
						<span class="text-sm font-bold text-primary-700"><span id="wapm-list-selected-count">0</span> <?php esc_html_e( 'مورد انتخاب شده', 'wapm' ); ?></span>
						<select id="wapm-list-bulk-select" class="h-10 rounded-xl border border-slate-200 text-sm px-3">
							<option value=""><?php esc_html_e( '— انتخاب عملیات —', 'wapm' ); ?></option>
							<option value="stock:instock"><?php esc_html_e( 'تغییر وضعیت به: موجود', 'wapm' ); ?></option>
							<option value="stock:outofstock"><?php esc_html_e( 'تغییر وضعیت به: ناموجود', 'wapm' ); ?></option>
							<option value="stock:onbackorder"><?php esc_html_e( 'تغییر وضعیت به: پیش‌خرید', 'wapm' ); ?></option>
							<option value="trash"><?php esc_html_e( 'حذف (انتقال به زباله‌دان)', 'wapm' ); ?></option>
						</select>
						<button type="button" id="wapm-list-bulk-run" class="h-10 px-5 bg-primary-600 text-white rounded-xl text-sm font-bold"><?php esc_html_e( 'اجرا', 'wapm' ); ?></button>
						<button type="button" id="wapm-list-bulk-clear" class="h-10 px-4 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-500"><?php esc_html_e( 'لغو انتخاب', 'wapm' ); ?></button>
					</div>

					<div class="overflow-auto" style="max-height:70vh">
						<table class="w-full text-right border-collapse" style="min-width:1400px">
							<thead class="bg-slate-50 sticky top-0 z-10">
								<tr>
									<th class="px-3 py-3 border-b border-slate-100" style="width:36px"><input type="checkbox" id="wapm-list-select-all"></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:64px" data-col="image"><?php esc_html_e( 'تصویر', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100"><?php esc_html_e( 'محصول', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:130px" data-col="regular_price"><?php esc_html_e( 'قیمت اصلی', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:130px" data-col="sale_price"><?php esc_html_e( 'قیمت ویژه', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:100px" data-col="manage_stock"><?php esc_html_e( 'مدیریت انبار', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:90px" data-col="stock_qty"><?php esc_html_e( 'موجودی', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:110px" data-col="stock_status"><?php esc_html_e( 'وضعیت موجودی', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:170px" data-col="categories"><?php esc_html_e( 'دسته‌بندی‌ها', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:150px" data-col="brand"><?php echo esc_html( $list_brand_name ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:160px" data-col="torob"><?php esc_html_e( 'قیمت ترب', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:70px" data-col="id"><?php esc_html_e( 'شناسه', 'wapm' ); ?></th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:110px" data-col="sku">SKU</th>
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:50px"><?php esc_html_e( 'عملیات', 'wapm' ); ?></th>
								</tr>
							</thead>
							<tbody id="wapm-list-tbody"></tbody>
						</table>
					</div>

					<div class="p-4 border-t border-slate-100 bg-slate-50/60 flex items-center justify-between">
						<div class="text-sm font-bold text-slate-500" id="wapm-list-summary">—</div>
						<div class="flex items-center gap-2">
							<button type="button" id="wapm-list-prev" class="px-5 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold disabled:opacity-40" disabled><?php esc_html_e( 'قبلی', 'wapm' ); ?></button>
							<span class="font-bold text-primary-600 px-3" id="wapm-list-page-indicator">1</span>
							<button type="button" id="wapm-list-next" class="px-5 py-2 bg-white border border-slate-200 rounded-xl text-sm font-bold disabled:opacity-40" disabled><?php esc_html_e( 'بعدی', 'wapm' ); ?></button>
						</div>
					</div>
				</div>
			</div>

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
					<div id="wapm-progress-rule" class="wapm-progress" style="display:none">
						<div class="wapm-progress-bar"><span style="width:0%"></span></div>
						<p class="wapm-progress-text"></p>
					</div>
					<table class="widefat striped" id="wapm-rules-table">
						<thead><tr>
							<th><?php esc_html_e( 'عنوان', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'اولویت', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'wapm' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'wapm' ); ?></th>
						</tr></thead>
						<tbody></tbody>
					</table>
				</div>
			</div>

			<!-- ============ تب زمان‌بندی ============ -->
			<div class="wapm-tab-content" id="wapm-tab-schedule" style="display:none">
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
							<p class="description"><?php esc_html_e( 'خالی بگذارید تا بازگشت خودکار انجام نشود. اجرا با WP-Cron حداکثر یک ساعت بعد از زمان تعیین‌شده انجام می‌شود.', 'wapm' ); ?></p></td>
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

			<!-- ============ تب CSV ============ -->
			<div class="wapm-tab-content" id="wapm-tab-csv" style="display:none">
				<div class="wapm-columns">
					<div class="wapm-card">
						<h2><?php esc_html_e( 'خروجی CSV', 'wapm' ); ?></h2>
						<p class="description"><?php esc_html_e( 'خروجی بر اساس فیلترها و تنظیمات تب «تغییر قیمت» ساخته می‌شود (ستون‌های قیمت فعلی و قیمت جدید محاسبه‌شده).', 'wapm' ); ?></p>
						<p><button type="button" class="button button-primary" id="wapm-export-btn"><?php esc_html_e( 'دانلود CSV', 'wapm' ); ?></button></p>
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
						<a href="<?php echo esc_url( $this->torob_bookmarklet_href(), [ 'javascript', 'http', 'https' ] ); ?>"
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
						<?php esc_html_e( '۵) محصولات ترب که به محصول ووکامرس وصل نشدند', 'wapm' ); ?>
						<span class="wapm-count" id="wapm-torob-unmatched-count"><?php echo esc_html( (string) count( WAPM_Torob::unmatched() ) ); ?></span>
					</h2>
					<p class="description">
						<?php esc_html_e( 'این‌ها با اعتماد کافی تطبیق داده نشدند (نام مشابه ولی مبهم، یا پیدا نشد). اگر خودکار وصل می‌شدند، ممکن بود قیمت رقیب اشتباهی به محصول شما نسبت داده شود. اینجا می‌توانید دستی وصل کنید — اتصال دستی با همگام‌سازی‌های بعدی بازنویسی نمی‌شود.', 'wapm' ); ?>
					</p>
					<p>
						<button type="button" class="button" id="wapm-torob-unmatched-refresh"><?php esc_html_e( 'بازخوانی لیست', 'wapm' ); ?></button>
						<button type="button" class="button wapm-danger" id="wapm-torob-unmatched-clear"><?php esc_html_e( 'پاک کردن کل لیست', 'wapm' ); ?></button>
					</p>
					<div id="wapm-torob-unmatched-area"></div>
				</div>
			</div>

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
				</td>
			</tr>
			<tr class="wapm-manual-only">
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
			__( 'آخرین همگام‌سازی', 'wapm' ) => $last['at'] ? date_i18n( 'Y/m/d H:i', strtotime( (string) $last['at'] ) ) : '—',
			__( 'منبع', 'wapm' )            => $source_map[ (string) $last['source'] ] ?? (string) $last['source'],
			__( 'محصولات یافت‌شده', 'wapm' ) => (int) $last['discovered'],
			__( 'صفحات خوانده‌شده', 'wapm' ) => (int) $last['pages'],
			__( 'تطبیق‌خورده', 'wapm' )     => (int) $last['matched'],
			__( 'قیمت بررسی‌شده', 'wapm' )   => (int) $last['checked'],
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

	/**
	 * کد Bookmarklet: در torob.com اجرا می‌شود، فهرست محصولات و قیمت رقبا را می‌خواند
	 * و برای این افزونه ارسال می‌کند. توکن مخفی به‌جای nonce برای احراز هویت استفاده می‌شود
	 * چون این درخواست از دامنه‌ی دیگری (torob.com) می‌آید و به سشن ادمین دسترسی ندارد.
	 */
	private function torob_bookmarklet_href(): string {
		$endpoint = admin_url( 'admin-ajax.php' );
		$token    = WAPM_Torob::get_bookmarklet_token();

		$js = <<<'JS'
(function(){
	'use strict';
	var VERSION  = '3.0';
	var API      = 'https://api.torob.com';
	var ENDPOINT = "%%ENDPOINT%%";
	var TOKEN    = "%%TOKEN%%";
	var SHOP_ID  = "%%SHOP_ID%%";
	var PAGE     = 24;      // اندازه‌ی هر صفحه‌ی کاتالوگ (خود ترب ۲۴ می‌دهد)
	var BATCH    = 15;      // هر چند محصول به سرور فرستاده شود
	var MAX_SHOP_PAGES = 400;

	var state = {
		shopId: '',
		mode: 'full',       // full | catalog
		page: 0,
		inPage: 0,
		stopped: false,
		discovered: 0,
		matched: 0,
		checked: 0,
		unmatched: 0
	};

	var ui = null;
	var cacheKey = '';

	/* ---------- ابزار ---------- */

	function el(tag, css, text){
		var n = document.createElement(tag);
		if (css) { n.style.cssText = css; }
		if (text) { n.textContent = text; }
		return n;
	}

	function delay(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }
	function jitter(base){ return base + Math.floor(Math.random() * 400); }

	function say(msg){ if (ui) { ui.status.textContent = msg; } }
	function progress(pct){ if (ui) { ui.fill.style.width = Math.max(0, Math.min(100, pct)) + '%'; } }

	function closeUi(delayMs){
		if (!ui) { return; }
		var box = ui.box;
		ui = null;
		setTimeout(function(){ if (box && box.parentNode) { box.parentNode.removeChild(box); } }, delayMs || 0);
	}

	function finish(msg, autoHide){
		say(msg);
		if (ui) { ui.stop.style.display = 'none'; }
		if (autoHide) { closeUi(15000); }
	}

	function loadState(){
		try { return JSON.parse(localStorage.getItem(cacheKey) || 'null'); } catch (e) { return null; }
	}
	function saveState(){
		try {
			localStorage.setItem(cacheKey, JSON.stringify({
				page: state.page,
				inPage: state.inPage,
				mode: state.mode,
				at: Date.now()
			}));
		} catch (e) { /* حافظه‌ی مرورگر پر است یا غیرفعال */ }
	}
	function clearState(){
		try { localStorage.removeItem(cacheKey); } catch (e) {}
	}

	/* ---------- شبکه ---------- */

	/**
	 * GET به API ترب. پاسخ JSON؛ در بلاک (۴۹۰/۴۰۳/۴۲۹) یا پاسخ غیر-JSON خطای
	 * مشخص پرتاب می‌کند تا حلقه متوقف شود (تلاش دوباره، بلاک را طولانی‌تر می‌کند).
	 */
	function apiGet(path, params){
		var qs = new URLSearchParams(params);
		qs.set('source', 'next_desktop');

		return fetch(API + path + '?' + qs.toString(), {
			credentials: 'include',
			headers: { 'Accept': 'application/json' }
		}).then(function(res){
			if ([490, 403, 429].indexOf(res.status) !== -1) {
				var e = new Error('blocked');
				e.code = res.status;
				throw e;
			}
			if (!res.ok) { throw new Error('HTTP ' + res.status); }
			return res.text();
		}).then(function(text){
			var t = (text || '').trim();
			if (t.charAt(0) !== '{') {
				var e = new Error('blocked');
				e.code = 490;
				throw e;
			}
			return JSON.parse(t);
		});
	}

	function sendBatch(items, meta){
		var body = new URLSearchParams();
		body.set('action', 'wapm_torob_ingest');
		body.set('source', 'bookmarklet');
		body.set('token', TOKEN);
		body.set('items', JSON.stringify(items));
		body.set('meta', JSON.stringify(meta || {}));

		return fetch(ENDPOINT, { method: 'POST', body: body, credentials: 'omit' })
			.then(function(r){
				if (r.status === 429) {
					return delay(6000).then(function(){
						return fetch(ENDPOINT, { method: 'POST', body: body, credentials: 'omit' });
					});
				}
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r;
			})
			.then(function(r){ return r.json(); });
	}

	/* ---------- ساخت داده ---------- */

	function cardToItem(card, sellers){
		var url = card.web_client_absolute_url || '';
		return {
			prk: card.random_key || '',
			name: card.name1 || '',
			our_price: Number(card.price) || null,
			url: url ? 'https://torob.com' + url : '',
			sellers: sellers || []
		};
	}

	/** فروشنده‌ها را به شکل سبک تبدیل می‌کند (حداکثر ۴۰ ردیف ارزان‌ترین). */
	function slimSellers(rows){
		var out = [];
		(rows || []).forEach(function(r){
			var price = Number(r.price) || 0;
			if (!r.shop_id || price <= 0) { return; }
			out.push({
				shop_id: Number(r.shop_id),
				shop_name: String(r.shop_name || '').slice(0, 80),
				price: price,
				available: r.availability !== false,
				unreliable: !!r.is_price_unreliable,
				last_change: String(r.last_price_change_date || '').slice(0, 40)
			});
		});
		out.sort(function(a, b){ return a.price - b.price; });
		return out.slice(0, 40);
	}

	/* ---------- رابط ---------- */

	function buildStartUi(){
		var box = el('div', 'position:fixed;bottom:20px;left:20px;z-index:2147483647;background:#111;color:#fff;padding:16px 18px;border-radius:14px;font-family:Tahoma,sans-serif;font-size:13px;max-width:360px;direction:rtl;box-shadow:0 12px 34px rgba(0,0,0,.4);line-height:1.9');

		box.appendChild(el('div', 'font-weight:700;margin-bottom:6px', 'همگام‌سازی با ترب (WAPM)'));

		var info = el('div', 'font-size:12px;color:#c7c7c7', 'شناسه فروشگاه: ' + state.shopId);
		box.appendChild(info);

		var full = el('button', 'margin-top:12px;width:100%;background:#7f54b3;color:#fff;border:0;border-radius:10px;padding:9px 12px;font-weight:700;cursor:pointer;font-family:inherit', 'کامل: کاتالوگ + قیمت رقبا');
		full.onclick = function(){ start('full'); };

		var catalog = el('button', 'margin-top:8px;width:100%;background:#2f2f2f;color:#fff;border:1px solid #4a4a4a;border-radius:10px;padding:9px 12px;font-weight:700;cursor:pointer;font-family:inherit', 'سریع: فقط کاتالوگ و قیمت خودمان');
		catalog.onclick = function(){ start('catalog'); };

		var hint = el('div', 'margin-top:8px;font-size:11px;color:#9a9a9a', 'حالت کامل برای هر محصول یک درخواست به ترب می‌فرستد (کندتر، ارزان‌ترین رقیب را هم می‌آورد).');
		box.appendChild(full);
		box.appendChild(catalog);
		box.appendChild(hint);

		var resume = loadState();
		if (resume) {
			var btnResume = el('button', 'margin-top:8px;width:100%;background:#1f3b2c;color:#d6ffe9;border:1px solid #2f5a44;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer;font-family:inherit', 'ادامه از صفحه ' + (Number(resume.page) + 1));
			btnResume.onclick = function(){
				state.mode = resume.mode || 'full';
				start(state.mode, resume);
			};
			box.appendChild(btnResume);
		}

		document.body.appendChild(box);
		ui = { box: box, status: el('div'), fill: el('div'), stop: el('button') };
		ui.status.style.display = 'none';
	}

	function buildProgressUi(){
		var box = el('div', 'position:fixed;bottom:20px;left:20px;z-index:2147483647;background:#111;color:#fff;padding:14px 16px;border-radius:12px;font-family:Tahoma,sans-serif;font-size:13px;max-width:340px;direction:rtl;box-shadow:0 10px 30px rgba(0,0,0,.35);line-height:1.8');

		var title = el('div', 'font-weight:700;margin-bottom:8px', 'همگام‌سازی با ترب (WAPM)');
		var status = el('div', '', 'در حال شروع…');

		var bar = el('div', 'height:6px;background:#333;border-radius:3px;margin:10px 0;overflow:hidden');
		var fill = el('div', 'height:100%;width:0;background:#7f54b3;transition:width .2s');
		bar.appendChild(fill);

		var stop = el('button', 'background:#b32d2e;color:#fff;border:0;border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer;font-family:inherit', 'توقف');
		stop.onclick = function(){
			state.stopped = true;
			stop.disabled = true;
			status.textContent = 'در حال توقف… (تا پایان درخواست جاری)';
		};

		box.appendChild(title);
		box.appendChild(status);
		box.appendChild(bar);
		box.appendChild(stop);
		document.body.appendChild(box);

		ui = { box: box, status: status, fill: fill, stop: stop };
	}

	/* ---------- اجرا ---------- */

	async function start(mode, resume){
		state.mode = mode;
		state.page = resume ? Number(resume.page || 0) : 0;
		state.inPage = resume ? Number(resume.inPage || 0) : 0;

		// رابط شروع را با رابط پیشرفت جایگزین کن.
		if (ui && ui.box && ui.box.parentNode) { ui.box.parentNode.removeChild(ui.box); }
		ui = null;
		buildProgressUi();

		cacheKey = 'wapm_bm3_' + state.shopId + '_' + mode;
		progress(2);

		try {
			await runCatalog();
			if (!state.stopped) {
				progress(100);
				clearState();
				var msg = '✓ تمام شد — ' + state.discovered + ' محصول از ترب بررسی شد.';
				if (mode === 'full') {
					msg += '\n' + state.checked + ' محصول قیمت رقیب گرفت' + (state.unmatched ? ' و ' + state.unmatched + ' مورد به لیست تطبیق‌نیافته رفت.' : '.');
				} else {
					msg += '\nقیمت خودمان از دید ترب به‌روز شد (برای قیمت رقبا، حالت کامل را اجرا کنید).';
				}
				finish(msg, true);
			}
		} catch (e) {
			if (e && e.code === 490) {
				saveState();
				finish('⚠ ترب درخواست‌ها را محدود کرد (۴۹۰). این کار را جدی بگیرید: چند دقیقه صبر کنید و بعد با دکمه‌ی «ادامه» از همان صفحه ادامه دهید. تلاش پشت‌سرهم، زمان بلاک را طولانی‌تر می‌کند.');
			} else if (e && e.message === 'blocked') {
				saveState();
				finish('⚠ ترب به‌جای داده، صفحه‌ی «ربات نیستید» برگرداند. چند دقیقه صبر کنید و از همان صفحه ادامه دهید.');
			} else {
				saveState();
				finish('خطا: ' + (e && e.message ? e.message : e) + '\nاز همان صفحه قابل ادامه است.');
			}
		}
	}

	async function runCatalog(){
		var myShop = Number(state.shopId);

		while (!state.stopped && state.page < MAX_SHOP_PAGES) {
			say('خواندن صفحه ' + (state.page + 1) + ' از کاتالوگ فروشگاه…');

			var pageData = await apiGet('/v4/internet-shop/base-product/list/', {
				shop_id: myShop,
				page: state.page,
				size: PAGE,
				sort: '-date'
			});

			var cards = (pageData.results || []).filter(function(c){ return c && c.random_key; });
			if (!cards.length) { break; }

			state.discovered += cards.length;

			var batch = [];
			var total = Number(pageData.count) || state.discovered;
			progress(Math.min(97, Math.round(((state.page * PAGE) / Math.max(total, 1)) * 100)));

			// ادامه از وسط صفحه (اگر دفعه‌ی قبل وسط کار متوقف شده باشد).
			var i = Number(state.inPage) || 0;

			for (; i < cards.length && !state.stopped; i++) {
				var card = cards[i];
				var sellers = [];

				if (state.mode === 'full') {
					say('صفحه ' + (state.page + 1) + ' — محصول ' + (i + 1) + ' از ' + cards.length +
						' (کل: ' + state.discovered + ')');

					try {
						var sdata = await apiGet('/v4/base-product/sellers/', {
							prk: card.random_key,
							list_type: 'products_info'
						});
						sellers = slimSellers(sdata.results);
					} catch (err) {
						if (err && err.code === 490) { throw err; }
						// خطای یک محصول نباید کل کار را متوقف کند.
						sellers = [];
					}

					await delay(jitter(900));
				}

				batch.push(cardToItem(card, sellers));

				if (batch.length >= BATCH || i === cards.length - 1) {
					var res = await sendBatch(batch, {
						discovered: state.discovered,
						pages: state.page + 1,
						version: VERSION,
						mode: state.mode
					});

					if (res && res.success) {
						state.matched   += res.data.matched || 0;
						state.checked   += res.data.checked || 0;
						state.unmatched += res.data.unmatched || 0;
					} else {
						throw new Error((res && res.data && res.data.message) || 'پاسخ نامعتبر از سایت');
					}

					batch = [];
					state.inPage = i + 1;
					saveState();
				}
			}

			state.page++;
			state.inPage = 0;
			saveState();

			// بستن دسته‌ی باقی‌مانده‌ی صفحه (مثلاً وقتی «توقف» وسط صفحه زده شد).
			if (batch.length) {
				await sendBatch(batch, {
					discovered: state.discovered,
					pages: state.page,
					version: VERSION,
					mode: state.mode
				});
			}

			if (state.stopped) { break; }

			var done = Math.min(97, Math.round(((state.page * PAGE) / Math.max(Number(pageData.count) || state.discovered, 1)) * 100));
			progress(done);

			await delay(jitter(1200));
		}

		if (state.stopped) {
			saveState();
			finish('متوقف شد. دفعه‌ی بعد از همین‌جا ادامه می‌دهد.\nتا اینجا: ' + state.discovered + ' محصول بررسی شد.');
		}
	}

	/* ---------- شروع ---------- */

	var shopId = String(SHOP_ID || '').replace(/[^0-9]/g, '');
	if (!shopId) {
		shopId = String(window.prompt('شناسه فروشگاه ترب را وارد کنید (عدد در آدرس torob.com/shop/عدد/...):', '') || '').replace(/[^0-9]/g, '');
	}
	if (!shopId) {
		window.alert('بدون شناسه‌ی فروشگاه نمی‌شود: افزونه باید بداند کدام فروشنده خودتان هستید تا قیمت خودتان را با رقیب اشتباه نگیرد.');
		return;
	}

	state.shopId = shopId;
	cacheKey = 'wapm_bm3_' + shopId + '_full';
	buildStartUi();
})();
JS;

		$js = str_replace(
			[ '%%ENDPOINT%%', '%%TOKEN%%', '%%SHOP_ID%%' ],
			[ esc_js( $endpoint ), esc_js( $token ), esc_js( WAPM_Torob::get_shop_id() ) ],
			$js
		);

		return 'javascript:' . rawurlencode( $js );
	}
}
