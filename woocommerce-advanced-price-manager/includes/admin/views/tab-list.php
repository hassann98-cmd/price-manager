<?php
/**
 * تب «list» — مارک‌آپ صفحه‌ی پیشخوان.
 *
 * از render_page() جدا شد تا فایل اصلی قابل مرور بماند. این فایل داخل همان
 * متد و با همان متغیرها require می‌شود، پس $this و متغیرهای این تب در دسترس‌اند.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
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

						<div class="wapm-saved-filters">
							<select id="wapm-list-saved-filters" class="h-11 rounded-xl border border-slate-200 text-sm px-3" title="<?php esc_attr_e( 'فیلترهای ذخیره‌شده', 'wapm' ); ?>">
								<option value=""><?php esc_html_e( 'فیلترهای ذخیره‌شده…', 'wapm' ); ?></option>
							</select>
							<button type="button" id="wapm-list-save-filter" class="h-11 px-4 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-600" title="<?php esc_attr_e( 'ذخیره‌ی فیلتر فعلی', 'wapm' ); ?>"><?php esc_html_e( 'ذخیره', 'wapm' ); ?></button>
							<button type="button" id="wapm-list-delete-filter" class="h-11 px-3 bg-white border border-slate-200 rounded-xl text-sm font-bold text-slate-400" title="<?php esc_attr_e( 'حذف فیلتر انتخاب‌شده', 'wapm' ); ?>" disabled>×</button>

							<div id="wapm-saved-name-pop" class="wapm-saved-filters__pop" hidden>
								<label class="screen-reader-text" for="wapm-saved-name"><?php esc_html_e( 'نام فیلتر', 'wapm' ); ?></label>
								<input type="text" id="wapm-saved-name" maxlength="60" placeholder="<?php esc_attr_e( 'نام فیلتر (خالی = خودکار)', 'wapm' ); ?>">
								<button type="button" id="wapm-saved-name-ok" class="button button-primary"><?php esc_html_e( 'ذخیره', 'wapm' ); ?></button>
								<button type="button" id="wapm-saved-name-cancel" class="button"><?php esc_html_e( 'لغو', 'wapm' ); ?></button>
							</div>
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
								<label class="flex items-center gap-2 px-2 py-1.5 text-[12px] font-bold text-slate-600 hover:bg-slate-50 rounded-lg cursor-pointer"><input type="checkbox" class="wapm-col-toggle" data-col="last_change" checked> <?php esc_html_e( 'آخرین تغییر', 'wapm' ); ?></label>
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
					<div id="wapm-list-bulk-bar" class="wapm-bulkbar p-3 border-b border-slate-100 bg-primary-50 flex flex-wrap items-center gap-3">
						<span class="wapm-bulkbar__count">
							<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
							<b id="wapm-list-selected-count">0</b> <?php esc_html_e( 'مورد انتخاب شده', 'wapm' ); ?>
							<span id="wapm-list-selected-scope" hidden></span>
						</span>
						<button type="button" id="wapm-list-select-results" class="wapm-select-results" hidden></button>
						<button type="button" id="wapm-list-bulk-price" class="h-10 px-5 bg-gradient-to-l from-primary-600 to-primary-500 text-white rounded-xl text-sm font-bold inline-flex items-center gap-2 shadow-sm">
							<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"></path><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
							<?php esc_html_e( 'تغییر قیمت', 'wapm' ); ?>
						</button>
						<button type="button" id="wapm-list-bulk-stock" class="h-10 px-5 bg-white border border-slate-300 text-slate-700 rounded-xl text-sm font-bold inline-flex items-center gap-2">
							<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7 12 3 4 7v10l8 4 8-4V7Z"></path><path d="M4 7l8 4 8-4"></path><path d="M12 11v10"></path></svg>
							<?php esc_html_e( 'موجودی', 'wapm' ); ?>
						</button>
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
									<th class="px-3 py-3 text-xs font-black text-slate-400 border-b border-slate-100" style="width:110px" data-col="last_change"><?php esc_html_e( 'آخرین تغییر', 'wapm' ); ?></th>
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

				<!-- کشوی تغییر قیمت انتخاب‌شده‌ها -->
				<div id="wapm-price-drawer-overlay" class="wapm-drawer-overlay"></div>
				<aside id="wapm-price-drawer" class="wapm-drawer" role="dialog" aria-modal="true" aria-labelledby="wapm-bp-title" aria-hidden="true">
					<div class="wapm-drawer__head">
						<div>
							<h2 id="wapm-bp-title"><?php esc_html_e( 'تغییر قیمت انتخاب‌شده‌ها', 'wapm' ); ?></h2>
							<p><?php esc_html_e( 'اول پیش‌نمایش، بعد اعمال. کل تغییر یک Batch است و با یک دکمه بازگردانی می‌شود.', 'wapm' ); ?></p>
						</div>
						<button type="button" id="wapm-bp-close" class="wapm-drawer__close" aria-label="<?php esc_attr_e( 'بستن', 'wapm' ); ?>">×</button>
					</div>

					<div class="wapm-drawer__body">
						<div class="wapm-drawer__section">
							<h3><?php esc_html_e( 'میان‌برهای سریع', 'wapm' ); ?></h3>
							<div class="wapm-presets">
								<button type="button" class="wapm-preset wapm-preset--down" data-bp-preset="percent:decrease:20">−۲۰٪</button>
								<button type="button" class="wapm-preset wapm-preset--down" data-bp-preset="percent:decrease:10">−۱۰٪</button>
								<button type="button" class="wapm-preset wapm-preset--down" data-bp-preset="percent:decrease:5">−۵٪</button>
								<button type="button" class="wapm-preset" data-bp-preset="percent:increase:5">+۵٪</button>
								<button type="button" class="wapm-preset" data-bp-preset="percent:increase:10">+۱۰٪</button>
								<button type="button" class="wapm-preset" data-bp-preset="percent:increase:20">+۲۰٪</button>
								<button type="button" class="wapm-preset" data-bp-preset="absolute:increase:0"><?php esc_html_e( 'قیمت مطلق', 'wapm' ); ?></button>
							</div>
							<p class="description" style="margin:6px 0 0"><?php esc_html_e( 'با انتخاب هر میان‌بر فقط فیلدها پر می‌شوند؛ تا «پیش‌نمایش» را نزنید هیچ چیزی عوض نمی‌شود.', 'wapm' ); ?></p>
						</div>

						<div class="wapm-drawer__section">
							<h3><?php esc_html_e( 'نوع تغییر', 'wapm' ); ?></h3>
							<div class="wapm-drawer__row">
								<label class="wapm-field">
									<span><?php esc_html_e( 'حالت', 'wapm' ); ?></span>
									<select id="wapm-bp-mode">
										<option value="percent"><?php esc_html_e( 'درصدی', 'wapm' ); ?></option>
										<option value="fixed"><?php esc_html_e( 'مبلغ ثابت', 'wapm' ); ?></option>
										<option value="absolute"><?php esc_html_e( 'قیمت مطلق', 'wapm' ); ?></option>
									</select>
								</label>

								<label class="wapm-field" id="wapm-bp-direction-wrap">
									<span><?php esc_html_e( 'جهت', 'wapm' ); ?></span>
									<select id="wapm-bp-direction">
										<option value="decrease"><?php esc_html_e( 'کاهش (−)', 'wapm' ); ?></option>
										<option value="increase"><?php esc_html_e( 'افزایش (+)', 'wapm' ); ?></option>
									</select>
								</label>

								<label class="wapm-field">
									<span id="wapm-bp-amount-label"><?php esc_html_e( 'مقدار', 'wapm' ); ?></span>
									<input type="number" id="wapm-bp-amount" min="0" step="any" value="10">
								</label>

								<label class="wapm-field" id="wapm-bp-currency-wrap" style="display:none">
									<span><?php esc_html_e( 'واحد', 'wapm' ); ?></span>
									<select id="wapm-bp-currency">
										<option value="toman"><?php esc_html_e( 'تومان', 'wapm' ); ?></option>
										<option value="rial"><?php esc_html_e( 'ریال', 'wapm' ); ?></option>
									</select>
								</label>
							</div>
							<p class="description" id="wapm-bp-mode-hint" style="margin:8px 0 0"></p>
						</div>

						<div class="wapm-drawer__section">
							<h3><?php esc_html_e( 'قیمت‌های هدف و محدودیت‌ها', 'wapm' ); ?></h3>
							<div class="wapm-drawer__row">
								<label class="wapm-field">
									<span><?php esc_html_e( 'قیمت هدف', 'wapm' ); ?></span>
									<select id="wapm-bp-target">
										<option value="regular"><?php esc_html_e( 'قیمت اصلی', 'wapm' ); ?></option>
										<option value="sale"><?php esc_html_e( 'قیمت فروش', 'wapm' ); ?></option>
										<option value="both"><?php esc_html_e( 'هر دو', 'wapm' ); ?></option>
									</select>
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'مدیریت Sale', 'wapm' ); ?></span>
									<select id="wapm-bp-sale">
										<option value="independent"><?php esc_html_e( 'مستقل', 'wapm' ); ?></option>
										<option value="keep_discount"><?php esc_html_e( 'حفظ درصد تخفیف', 'wapm' ); ?></option>
										<option value="remove_sale"><?php esc_html_e( 'حذف Sale', 'wapm' ); ?></option>
									</select>
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'گرد کردن', 'wapm' ); ?></span>
									<select id="wapm-bp-round">
										<option value="none"><?php esc_html_e( 'بدون گرد کردن', 'wapm' ); ?></option>
										<option value="nearest"><?php esc_html_e( 'نزدیک‌ترین مضرب', 'wapm' ); ?></option>
										<option value="up"><?php esc_html_e( 'رو به بالا', 'wapm' ); ?></option>
										<option value="down"><?php esc_html_e( 'رو به پایین', 'wapm' ); ?></option>
									</select>
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'پایه گرد کردن', 'wapm' ); ?></span>
									<input type="number" id="wapm-bp-round-base" min="0" step="any" value="1000">
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'حداقل قیمت', 'wapm' ); ?></span>
									<input type="number" id="wapm-bp-min" min="0" step="any" value="0">
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'حداکثر قیمت', 'wapm' ); ?></span>
									<input type="number" id="wapm-bp-max" min="0" step="any" value="0">
								</label>
							</div>
						</div>

						<div class="wapm-drawer__section">
							<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
								<h3 style="margin:0"><?php esc_html_e( 'پیش‌نمایش', 'wapm' ); ?></h3>
								<button type="button" class="button" id="wapm-bp-preview-btn"><?php esc_html_e( 'محاسبه پیش‌نمایش', 'wapm' ); ?></button>
							</div>
							<p class="wapm-count" id="wapm-bp-summary" style="margin:10px 0 0"></p>
							<div id="wapm-bp-progress" class="wapm-progress" style="display:none">
								<div class="wapm-progress-bar"><span style="width:0%"></span></div>
								<p class="wapm-progress-text"></p>
							</div>
							<div id="wapm-bp-result" style="max-height:320px;overflow:auto;margin-top:8px"></div>
						</div>
					</div>

					<div class="wapm-drawer__foot">
						<span class="wapm-mini-link" id="wapm-bp-selected-note"></span>
						<div style="display:flex;gap:8px">
							<button type="button" class="button" id="wapm-bp-cancel"><?php esc_html_e( 'انصراف', 'wapm' ); ?></button>
							<button type="button" class="button button-primary" id="wapm-bp-apply-btn" disabled><?php esc_html_e( 'اعمال روی انتخاب‌شده‌ها', 'wapm' ); ?></button>
						</div>
					</div>
				</aside>

				<!-- کشوی «هم‌تراز با ترب» — قیمت یک محصول روی ارزان‌ترین رقیب منهای x -->
				<div id="wapm-align-drawer-overlay" class="wapm-drawer-overlay"></div>
				<aside id="wapm-align-drawer" class="wapm-drawer wapm-drawer--narrow" role="dialog" aria-modal="true" aria-labelledby="wapm-ad-title" aria-hidden="true">
					<div class="wapm-drawer__head">
						<div>
							<h2 id="wapm-ad-title"><?php esc_html_e( 'هم‌تراز با ترب', 'wapm' ); ?></h2>
							<p><?php esc_html_e( 'قیمت روی «ارزان‌ترین رقیب منهای مقدار زیربرش» تنظیم می‌شود. اول پیش‌نمایش، بعد اعمال.', 'wapm' ); ?></p>
						</div>
						<button type="button" id="wapm-ad-close" class="wapm-drawer__close" aria-label="<?php esc_attr_e( 'بستن', 'wapm' ); ?>">×</button>
					</div>

					<div class="wapm-drawer__body">
						<div class="wapm-drawer__section">
							<h3><?php esc_html_e( 'وضعیت فعلی', 'wapm' ); ?></h3>
							<div class="wapm-align-facts" id="wapm-ad-facts"></div>
						</div>

						<div class="wapm-drawer__section">
							<h3><?php esc_html_e( 'مقدار زیربرش', 'wapm' ); ?></h3>
							<div class="wapm-drawer__row">
								<label class="wapm-field">
									<span><?php esc_html_e( 'مقدار', 'wapm' ); ?></span>
									<input type="number" id="wapm-ad-value" min="0" step="any" value="1">
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'واحد', 'wapm' ); ?></span>
									<select id="wapm-ad-unit">
										<option value="percent"><?php esc_html_e( 'درصد', 'wapm' ); ?></option>
										<option value="fixed"><?php esc_html_e( 'تومان', 'wapm' ); ?></option>
									</select>
								</label>
							</div>
							<p class="description"><?php esc_html_e( 'مثال: «۱ درصد» یعنی یک درصد ارزان‌تر از ارزان‌ترین فروشنده‌ی رقیب. قیمت نهایی رو به پایین روی مضرب ۱٬۰۰۰ رند می‌شود و هرگز از رقیب گران‌تر نمی‌شود.', 'wapm' ); ?></p>
						</div>

						<div class="wapm-drawer__section">
							<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
								<h3 style="margin:0"><?php esc_html_e( 'پیش‌نمایش', 'wapm' ); ?></h3>
								<button type="button" class="button" id="wapm-ad-preview"><?php esc_html_e( 'محاسبه', 'wapm' ); ?></button>
							</div>
							<div id="wapm-ad-result" style="margin-top:8px"></div>
						</div>
					</div>

					<div class="wapm-drawer__foot">
						<span class="wapm-mini-link" id="wapm-ad-note"></span>
						<div style="display:flex;gap:8px">
							<button type="button" class="button" id="wapm-ad-cancel"><?php esc_html_e( 'انصراف', 'wapm' ); ?></button>
							<button type="button" class="button button-primary" id="wapm-ad-apply" disabled><?php esc_html_e( 'اعمال قیمت', 'wapm' ); ?></button>
						</div>
					</div>
				</aside>

				<!-- کشوی تغییر موجودی انتخاب‌شده‌ها -->
				<div id="wapm-stock-drawer-overlay" class="wapm-drawer-overlay"></div>
				<aside id="wapm-stock-drawer" class="wapm-drawer" role="dialog" aria-modal="true" aria-labelledby="wapm-sd-title" aria-hidden="true">
					<div class="wapm-drawer__head">
						<div>
							<h2 id="wapm-sd-title"><?php esc_html_e( 'تغییر موجودی انتخاب‌شده‌ها', 'wapm' ); ?></h2>
							<p><?php esc_html_e( 'همان قاعده‌ی قیمت: اول پیش‌نمایش، بعد اعمال؛ همه با یک Batch و یک دکمه بازگردانی.', 'wapm' ); ?></p>
						</div>
						<button type="button" id="wapm-sd-close" class="wapm-drawer__close" aria-label="<?php esc_attr_e( 'بستن', 'wapm' ); ?>">×</button>
					</div>

					<div class="wapm-drawer__body">
						<div class="wapm-drawer__section">
							<h3><?php esc_html_e( 'نوع تغییر', 'wapm' ); ?></h3>
							<div class="wapm-drawer__row">
								<label class="wapm-field">
									<span><?php esc_html_e( 'عملیات', 'wapm' ); ?></span>
									<select id="wapm-sd-mode">
										<option value="increase"><?php esc_html_e( 'افزودن (+)', 'wapm' ); ?></option>
										<option value="decrease"><?php esc_html_e( 'کاهش (−)', 'wapm' ); ?></option>
										<option value="set"><?php esc_html_e( 'تنظیم روی عدد', 'wapm' ); ?></option>
									</select>
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'واحد', 'wapm' ); ?></span>
									<select id="wapm-sd-unit">
										<option value="count"><?php esc_html_e( 'تعداد', 'wapm' ); ?></option>
										<option value="percent"><?php esc_html_e( 'درصد', 'wapm' ); ?></option>
									</select>
								</label>
								<label class="wapm-field">
									<span><?php esc_html_e( 'مقدار', 'wapm' ); ?></span>
									<input type="number" id="wapm-sd-amount" min="0" step="1" value="1">
								</label>
							</div>
							<p class="description" style="margin:8px 0 0">
								<?php esc_html_e( 'محصول متغیر به واریشن‌ها باز می‌شود؛ محصولی که «مدیریت انبار» ندارد با دلیل مشخص رد می‌شود (نه اینکه بی‌صدا صفر شود).', 'wapm' ); ?>
							</p>
						</div>

						<div class="wapm-drawer__section">
							<h3><?php esc_html_e( 'پیش‌نمایش', 'wapm' ); ?></h3>
							<p class="description" id="wapm-sd-selected-note" style="margin:0 0 10px"></p>
							<div id="wapm-sd-summary" class="wapm-progress-text" style="margin:0 0 8px"></div>
							<div id="wapm-sd-result"></div>
						</div>
					</div>

					<div class="wapm-drawer__foot">
						<span class="description" id="wapm-sd-foot-note"></span>
						<span style="display:flex;gap:8px">
							<button type="button" class="button" id="wapm-sd-preview"><?php esc_html_e( 'پیش‌نمایش', 'wapm' ); ?></button>
							<button type="button" class="button button-primary" id="wapm-sd-apply" disabled><?php esc_html_e( 'اعمال', 'wapm' ); ?></button>
						</span>
					</div>
				</aside>
			</div>
