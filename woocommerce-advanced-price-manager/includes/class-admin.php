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
					<h2><?php esc_html_e( 'مقایسه‌ی قیمت با ترب', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'این قابلیت فقط از صفحات عمومی ترب (صفحه‌ی فروشگاه شما و صفحه‌ی عمومی هر محصول) استفاده می‌کند — بدون نیاز به لاگین. چون این روش رسمی/مستند ترب نیست، ممکن است در آینده با تغییر ساختار سایت ترب نیاز به تعمیر داشته باشد.', 'wapm' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'شناسه فروشگاه در ترب (Shop ID)', 'wapm' ); ?></th>
							<td>
								<input type="text" id="wapm-torob-shop-id" class="regular-text" value="<?php echo esc_attr( WAPM_Torob::get_shop_id() ); ?>" placeholder="مثلاً 118417">
								<button type="button" class="button" id="wapm-torob-save-shop"><?php esc_html_e( 'ذخیره', 'wapm' ); ?></button>
								<p class="description"><?php esc_html_e( 'همان عددی است که در آدرس torob.com/shop/عدد/... می‌بینید.', 'wapm' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="wapm-card">
					<h2><?php esc_html_e( 'همگام‌سازی با Bookmarklet', 'wapm' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'خواندن مستقیم صفحات ترب از سمت سرور سایت با سیستم ضد-ربات ترب بلاک می‌شود. راه‌حل: این دکمه را به نوار بوکمارک مرورگرتان بکشید، بعد وارد صفحه‌ی محصولات فروشگاهتان در ترب شوید و رویش کلیک کنید — چون از مرورگر خودتان اجرا می‌شود، مثل یک بازدید واقعی دیده می‌شود.', 'wapm' ); ?>
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
						<li><?php esc_html_e( 'روی بوکمارکی که ساختید کلیک کنید و صبر کنید (بسته به تعداد محصولات، چند دقیقه طول می‌کشد — تب را باز نگه دارید).', 'wapm' ); ?></li>
						<li><?php esc_html_e( 'در پایان، نتیجه در ستون «قیمت ترب» تب «لیست محصولات» نمایش داده می‌شود.', 'wapm' ); ?></li>
					</ol>

					<p style="margin:20px 0">
						<a href="<?php echo esc_url( $this->torob_bookmarklet_href(), [ 'javascript', 'http', 'https' ] ); ?>"
							onclick="return false;"
							style="display:inline-block;padding:12px 24px;background:#7f54b3;color:#fff;border-radius:8px;font-weight:700;text-decoration:none;cursor:grab">
							📥 <?php esc_html_e( 'همگام‌سازی با ترب', 'wapm' ); ?>
						</a>
					</p>
					<p class="description"><?php esc_html_e( 'onclick این دکمه عمداً غیرفعال شده تا با کلیک ساده اجرا نشود — فقط باید کشیده (drag) و به نوار بوکمارک رها شود.', 'wapm' ); ?></p>

					<p>
						<span class="wapm-count"><?php echo esc_html( sprintf( __( '%d محصول match‌شده', 'wapm' ), WAPM_Torob::count_matched() ) ); ?></span>
						<?php if ( WAPM_Torob::get_last_sync_time() ) : ?>
							<span class="wapm-count"><?php echo esc_html( sprintf( __( 'آخرین همگام‌سازی: %s', 'wapm' ), WAPM_Torob::get_last_sync_time() ) ); ?></span>
						<?php endif; ?>
					</p>

					<p><button type="button" class="button wapm-danger" id="wapm-torob-regen-token"><?php esc_html_e( 'تولید دوباره‌ی توکن (بوکمارکلت قبلی از کار می‌افتد)', 'wapm' ); ?></button></p>
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
				<th><?php esc_html_e( 'نوع تغییر', 'wapm' ); ?></th>
				<td>
					<label><input type="radio" name="wapm-mode-<?php echo esc_attr( $prefix ); ?>" class="wapm-mode" value="percent" checked> <?php esc_html_e( 'درصدی', 'wapm' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="wapm-mode-<?php echo esc_attr( $prefix ); ?>" class="wapm-mode" value="fixed"> <?php esc_html_e( 'مبلغ ثابت', 'wapm' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'جهت', 'wapm' ); ?></th>
				<td>
					<select class="wapm-direction">
						<option value="increase"><?php esc_html_e( 'افزایش (+)', 'wapm' ); ?></option>
						<option value="decrease"><?php esc_html_e( 'کاهش (−)', 'wapm' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
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
			<tr>
				<th><?php esc_html_e( 'قیمت هدف', 'wapm' ); ?></th>
				<td>
					<select class="wapm-price-target">
						<option value="regular"><?php esc_html_e( 'قیمت اصلی (Regular)', 'wapm' ); ?></option>
						<option value="sale"><?php esc_html_e( 'قیمت فروش (Sale)', 'wapm' ); ?></option>
						<option value="both"><?php esc_html_e( 'هر دو', 'wapm' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
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
	var WP_ENDPOINT = "%%ENDPOINT%%";
	var TOKEN = "%%TOKEN%%";

	function showStatus(msg){
		var el = document.getElementById('wapm-bm-status');
		if(!el){
			el = document.createElement('div');
			el.id = 'wapm-bm-status';
			el.style.cssText = 'position:fixed;bottom:20px;left:20px;z-index:2147483647;background:#111;color:#fff;padding:12px 16px;border-radius:10px;font-family:sans-serif;font-size:13px;max-width:320px;direction:rtl;box-shadow:0 8px 24px rgba(0,0,0,.3)';
			document.body.appendChild(el);
		}
		el.textContent = msg;
	}

	function delay(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }

	function getNextData(html){
		var m = html.match(/<script id="__NEXT_DATA__"[^>]*>([\s\S]*?)<\/script>/);
		if(!m){ return null; }
		try { return JSON.parse(m[1]); } catch(e){ return null; }
	}

	async function fetchShopPage(shopId, page){
		var url = 'https://torob.com/shop/' + encodeURIComponent(shopId) + '/-/' + encodeURIComponent('محصولات') + '/?page=' + page + '&available=true&sort=-date';
		var res = await fetch(url, { credentials: 'omit' });
		var html = await res.text();
		var data = getNextData(html);
		if(!data){ return []; }
		var pp = data.props.pageProps;
		var listKey = Object.keys(pp).find(function(k){ return Array.isArray(pp[k]) && pp[k].length && pp[k][0] && pp[k][0].random_key; });
		if(!listKey){ return []; }
		return pp[listKey].map(function(p){ return { name: p.name1, prk: p.random_key }; });
	}

	async function fetchProductSellers(prk){
		var url = 'https://torob.com/p/' + encodeURIComponent(prk) + '/';
		var res = await fetch(url, { credentials: 'omit' });
		var html = await res.text();
		var data = getNextData(html);
		if(!data){ return []; }
		var bp = data.props.pageProps.baseProduct;
		if(!bp){ return []; }
		var result = (bp.products_info && bp.products_info.result) || [];
		return result.map(function(s){ return { shop_id: s.shop_id, price: s.price }; });
	}

	function sendBatch(items){
		var body = new URLSearchParams();
		body.set('action', 'wapm_torob_bookmarklet_sync');
		body.set('token', TOKEN);
		body.set('items', JSON.stringify(items));
		return fetch(WP_ENDPOINT, { method: 'POST', body: body }).then(function(r){ return r.json(); });
	}

	async function run(){
		var shopId = prompt('شناسه فروشگاه ترب را وارد کنید:', '%%SHOP_ID%%');
		if(!shopId){ return; }

		showStatus('در حال خواندن فهرست محصولات از ترب…');
		var all = [];
		var page = 1;
		while(true){
			var items = await fetchShopPage(shopId, page);
			if(!items.length){ break; }
			all = all.concat(items);
			showStatus('صفحه ' + page + ' — ' + all.length + ' محصول یافت شد…');
			page++;
			await delay(400);
			if(page > 200){ break; }
		}

		if(!all.length){
			showStatus('هیچ محصولی پیدا نشد. مطمئن شوید در صفحه‌ی محصولات فروشگاه خودتان در ترب هستید.');
			return;
		}

		var totalMatched = 0, totalChecked = 0;
		var batch = [];
		for(var i = 0; i < all.length; i++){
			var sellers = await fetchProductSellers(all[i].prk);
			batch.push({ name: all[i].name, prk: all[i].prk, sellers: sellers });
			showStatus('بررسی قیمت‌ها: ' + (i+1) + ' از ' + all.length);

			if(batch.length >= 25 || i === all.length - 1){
				try {
					var res = await sendBatch(batch);
					if(res && res.success){
						totalMatched += res.data.matched;
						totalChecked += res.data.checked;
					}
				} catch(e){}
				batch = [];
			}
			await delay(300);
		}

		showStatus('✓ همگام‌سازی کامل شد — ' + totalMatched + ' محصول match و ' + totalChecked + ' قیمت بررسی شد.');
		setTimeout(function(){ var el = document.getElementById('wapm-bm-status'); if(el){ el.remove(); } }, 8000);
	}

	run().catch(function(e){ showStatus('خطا: ' + e.message); });
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
