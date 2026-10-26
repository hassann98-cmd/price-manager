#!/usr/bin/env node
/**
 * smoke-js — تست دودی اسکریپت‌های پیشخوان در jsdom (بدون مرورگر).
 *
 * چرا: هیچ تستی برای `admin.js` نبود و به همین دلیل یک باگ واقعی (دو تابع
 * هم‌نام `updateBulkBar` در یک scope) فقط با کلیک دستی در مرورگر پیدا می‌شد.
 * این اسکریپت:
 *
 *   ۱) فایل‌های `assets/js/admin-*.js` را به ترتیب لود می‌کند (همان ترتیب enqueue).
 *   ۲) بررسی می‌کند هیچ خطای زمان لود رخ ندهد و همه‌ی ماژول‌ها روی `WAPMUI` باشند.
 *   ۳) جدول لیست را با یک پاسخ ساختگی رندر می‌کند.
 *   ۴) یک ردیف را تیک می‌زند و مطمئن می‌شود نوار عملیات گروهی فعال می‌شود
 *      (همان چیزی که با باگ هم‌نامی می‌شکست).
 *   ۵) کشوی تغییر قیمت/موجودی را باز و بسته می‌کند.
 *
 * استفاده: npm run smoke:js
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { JSDOM } from 'jsdom';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const jsDir = path.join(root, 'woocommerce-advanced-price-manager', 'assets', 'js');

const ORDER = [
	'admin-core.js',
	'admin-price.js',
	'admin-automation.js',
	'admin-list.js',
	'admin-stock.js',
	'admin-torob.js',
	'admin-boot.js',
];

let failures = 0;
const ok = (cond, label, detail = '') => {
	if (cond) {
		console.log(`  ✓ ${label}`);
		return;
	}
	failures++;
	console.error(`  ✗ ${label}${detail ? ' — ' + detail : ''}`);
};

/* ---------- DOM حداقلی: همان idهایی که اسکریپت‌ها به آن‌ها وصل می‌شوند ---------- */

const row = {
	id: 101,
	name: 'محصول آزمایشی',
	sku: 'SKU-101',
	image: '',
	is_variation: false,
	is_variable: false,
	variation_label: '',
	regular_price: '120000',
	sale_price: '',
	regular_price_fmt: '120,000',
	sale_price_fmt: '',
	price_range: '',
	manage_stock: true,
	stock_quantity: 7,
	stock_status: 'instock',
	stock_status_label: 'موجود',
	last_change: '',
	last_change_label: '—',
	last_change_days: null,
	categories: [{ id: 1, name: 'دسته' }],
	category_ids: [1],
	brand_names: [],
	brand_ids: [],
	edit_url: 'https://example.test/wp-admin/post.php?post=101&action=edit',
	view_url: 'https://example.test/?p=101',
	can_duplicate: true,
	can_edit_terms: true,
	torob: {
		prk: 'p-1',
		url: 'https://torob.com/p/p-1/',
		status: 'pricier',
		status_label: 'گران‌تر از رقیب',
		competitor_price: 100000,
		diff_pct: 20,
		age_days: 1,
		sellers: 3,
		our_price_wc: 120000,
		fresh_days: 3,
		link: 'manual',
	},
};

const html = `<!doctype html><html lang="fa" dir="rtl"><body>
<div class="wapm-tabs">
  <a class="nav-tab nav-tab-active" data-tab="price">قیمت</a>
  <a class="nav-tab" data-tab="list">لیست</a>
  <a class="nav-tab" data-tab="stock">موجودی</a>
  <a class="nav-tab" data-tab="torob">ترب</a>
  <a class="nav-tab" data-tab="history">تاریخچه</a>
  <a class="nav-tab" data-tab="rules">قوانین</a>
  <a class="nav-tab" data-tab="schedule">زمان‌بندی</a>
</div>
<div class="wapm-tab-content" id="wapm-tab-price"></div>
<div class="wapm-tab-content" id="wapm-tab-list" style="display:none">
  <input id="wapm-list-search" type="text">
  <select id="wapm-list-category"><option value="">همه</option></select>
  <select id="wapm-list-stock"><option value="">همه</option></select>
  <select id="wapm-list-torob"><option value="">همه</option></select>
  <select id="wapm-list-orderby"><option value="newest">جدیدترین</option></select>
  <select id="wapm-list-per-page"><option value="25">25</option></select>
  <select id="wapm-list-saved-filters"></select>
  <button id="wapm-list-save-filter"></button>
  <button id="wapm-list-delete-filter"></button>
  <div class="wapm-saved-filters__pop" id="wapm-saved-name-pop" style="display:none">
    <input id="wapm-saved-name-input"><button id="wapm-saved-name-ok"></button><button id="wapm-saved-name-cancel"></button>
  </div>
  <button id="wapm-list-smart-toggle"></button>
  <div id="wapm-list-smart-panel" style="display:none">
    <input id="wapm-smart-price-min"><input id="wapm-smart-price-max">
    <input id="wapm-smart-stock-min"><input id="wapm-smart-stock-max">
    <select id="wapm-smart-type"><option value="">همه</option></select>
    <input type="checkbox" id="wapm-smart-on-sale"><button id="wapm-smart-apply"></button>
    <button id="wapm-smart-low-stock"></button><button id="wapm-smart-clear"></button>
  </div>
  <button id="wapm-list-columns-toggle"></button>
  <div id="wapm-list-columns-menu" style="display:none"></div>
  <div id="wapm-list-bulk-bar">
    <b id="wapm-list-selected-count">0</b>
    <span id="wapm-list-selected-scope" hidden></span>
    <button id="wapm-list-select-results" hidden></button>
    <button id="wapm-list-bulk-price"></button>
    <button id="wapm-list-bulk-stock"></button>
    <button id="wapm-list-bulk-run"></button>
    <button id="wapm-list-bulk-clear"></button>
    <select id="wapm-list-bulk-action"><option value="trash">حذف</option></select>
  </div>
  <span id="wapm-list-summary"></span><span id="wapm-list-page-indicator"></span>
  <button id="wapm-list-prev"></button><button id="wapm-list-next"></button>
  <input type="checkbox" id="wapm-list-select-all">
  <table><thead><tr><th></th></tr></thead><tbody id="wapm-list-tbody"></tbody></table>
  <button id="wapm-list-save-pending" style="display:none"></button>
</div>
<div class="wapm-tab-content" id="wapm-tab-stock" style="display:none"></div>
<div class="wapm-tab-content" id="wapm-tab-torob" style="display:none"></div>
<div class="wapm-tab-content" id="wapm-tab-history" style="display:none">
  <div id="wapm-history-area"></div>
</div>
<div class="wapm-tab-content" id="wapm-tab-rules" style="display:none">
  <input id="wapm-rule-id"><input id="wapm-rule-title"><input id="wapm-rule-priority">
  <input type="checkbox" id="wapm-rule-enabled"><input type="checkbox" id="wapm-rule-auto">
  <input id="wapm-rule-start"><input id="wapm-rule-end">
  <table id="wapm-rules-table"><tbody></tbody></table>
</div>
<div class="wapm-tab-content" id="wapm-tab-schedule" style="display:none">
  <table id="wapm-schedules-table"><tbody></tbody></table>
</div>
<div id="wapm-price-drawer" style="display:none"></div><div id="wapm-price-drawer-overlay"></div>
<div id="wapm-stock-drawer" style="display:none"></div><div id="wapm-stock-drawer-overlay"></div>
<div id="wapm-align-drawer-overlay"></div>
<aside id="wapm-align-drawer" aria-hidden="true">
  <button id="wapm-ad-close"></button>
  <div id="wapm-ad-facts"></div>
  <input type="number" id="wapm-ad-value" value="1">
  <select id="wapm-ad-unit"><option value="percent">درصد</option><option value="fixed">تومان</option></select>
  <button id="wapm-ad-preview"></button>
  <div id="wapm-ad-result"></div>
  <span id="wapm-ad-note"></span>
  <button id="wapm-ad-cancel"></button>
  <button id="wapm-ad-apply" disabled></button>
</aside>
<div id="wapm-toasts"></div>
<script type="application/json" id="wapm-initial-data">{"rules":[],"schedules":[]}</script>
</body></html>`;

const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://example.test/wp-admin/admin.php?page=wapm' });
const { window } = dom;
const errors = [];
window.addEventListener('error', (e) => errors.push(e.message));

window.eval(fs.readFileSync(path.join(root, 'node_modules', 'jquery', 'dist', 'jquery.js'), 'utf8'));
const $ = window.jQuery;

window.WAPM = {
	ajax_url: 'https://example.test/wp-admin/admin-ajax.php',
	nonce: 'nonce',
	currency: 'تومان',
	brand_taxonomy: '',
	category_terms: [],
	brand_terms: [],
	i18n: {
		confirm_apply: 'اعمال %d مورد؟',
		confirm_warn: 'هشدار %d؟',
		confirm_rollback: 'بازگردانی؟',
		confirm_delete: 'حذف؟',
		no_targets: 'هیچ',
		done: 'انجام شد',
		error: 'خطا',
		loading: 'در حال بارگذاری…',
		select_products: 'جستجو…',
		retrying: 'تلاش %1$d از ۳ (%2$d)',
		resume_hint: 'دوباره',
		enabled: 'فعال',
		disabled: 'غیرفعال',
		run: 'اجرا',
		edit: 'ویرایش',
		delete: 'حذف',
	},
};

/* پاسخ ساختگی سرور برای همه‌ی اکشن‌ها */
$.post = function (url, data) {
	const deferred = $.Deferred();
	const action = String((data && data.action) || '').replace(/^wapm_/, '');
	let payload = {};

	if (action === 'list_products') {
		payload = { rows: [row], pages: 1, total: 1, paged: 1 };
	} else if (action === 'list_saved_filters') {
		payload = { rows: [], count: 0, max: 20 };
	} else if (action === 'stock_preview_ids' || action === 'price_preview_ids') {
		payload = { ids: [101], rows: [], will: 1, skipped: 0 };
	} else if (action === 'torob_get_unmatched') {
		payload = { rows: [], total: 0, counts: {} };
	} else if (action === 'torob_align') {
		const dry = String((data && data.dry_run) || '') === '1';
		payload = dry
			? { dry_run: true, skipped: [], rows: [{
				id: 101, parent_id: 0, name: 'محصول آزمایشی', sku: 'SKU-101', type: '',
				price_type: 'regular', old: 120000, new: 99000, diff: -21000, percent: -17.5, warn: false,
			}], changed: 0, batch_id: '' }
			: { dry_run: false, skipped: [], rows: [], changed: 1, batch_id: 'align_test' };
	} else if (action === 'get_logs' || action === 'stock_logs') {
		payload = { rows: [], total: 0, pages: 0, last_batch: '' };
	}
	deferred.resolve({ success: true, data: payload });
	return deferred.promise();
};

console.log('— لود ماژول‌ها');
for (const name of ORDER) {
	const file = path.join(jsDir, name);
	if (!fs.existsSync(file)) {
		ok(false, `${name} وجود دارد`);
		continue;
	}
	try {
		window.eval(fs.readFileSync(file, 'utf8'));
		ok(true, `${name} لود شد`);
	} catch (error) {
		ok(false, `${name} لود شد`, error.message);
	}
}

const UI = window.WAPMUI || {};

console.log('— صادرات مشترک');
for (const key of ['ajax', 'esc', 'fmt', 'wapmToast', 'state', 'runBatchLoop', 'readFilters', 'readSettings', 'listState', 'loadProductList', 'updateRowInDom', 'renderRules', 'renderSchedules', 'loadHistory', 'loadStockLogs', 'loadTorobUnmatched', 'refreshListIfLoaded', 'bpOpen', 'sdOpen', 'alignOpen', 'alignClose']) {
	ok(typeof UI[key] === 'function' || (key === 'state' || key === 'listState') ? key in UI : false, `WAPMUI.${key}`);
}

console.log('— رندر لیست محصولات');
try {
	UI.loadProductList(1);
	ok(true, 'loadProductList بدون خطا اجرا شد');
} catch (error) {
	ok(false, 'loadProductList بدون خطا اجرا شد', error.message);
}
ok($('#wapm-list-tbody tr').length === 1, 'یک ردیف رندر شد', String($('#wapm-list-tbody tr').length));
ok(String($('#wapm-list-summary').text()).includes('مجموع'), 'خلاصه‌ی لیست پر شد', $('#wapm-list-summary').text());

console.log('— انتخاب گروهی (رگرسیون باگ تابع هم‌نام)');
try {
	$('#wapm-list-tbody .wapm-row-check').first().prop('checked', true).trigger('change');
	ok(true, 'تیک زدن ردیف بدون خطا اجرا شد');
} catch (error) {
	ok(false, 'تیک زدن ردیف بدون خطا اجرا شد', error.message);
}
ok(String($('#wapm-list-selected-count').text()) === '1', 'شمارنده‌ی انتخاب به‌روز شد', $('#wapm-list-selected-count').text());
ok($('#wapm-list-bulk-bar').hasClass('is-visible'), 'نوار عملیات گروهی نمایان شد');

console.log('— کشوها');
try {
	UI.bpOpen();
	ok($('#wapm-price-drawer').hasClass('is-open'), 'کشوی تغییر قیمت باز شد');
	UI.bpClose();
	ok(!$('#wapm-price-drawer').hasClass('is-open'), 'کشوی تغییر قیمت بسته شد');

	UI.sdOpen();
	ok($('#wapm-stock-drawer').hasClass('is-open'), 'کشوی موجودی باز شد');
	UI.sdClose();
	ok(!$('#wapm-stock-drawer').hasClass('is-open'), 'کشوی موجودی بسته شد');
} catch (error) {
	ok(false, 'کشوها بدون خطا باز/بسته شدند', error.message);
}

console.log('— «هم‌تراز با ترب» (دکمه‌ی ستون ترب)');
try {
	ok($('#wapm-list-tbody .wapm-torob-align').length === 1, 'دکمه‌ی هم‌تراز در ستون ترب رندر شد');

	$('#wapm-list-tbody .wapm-torob-align').first().trigger('click');
	ok($('#wapm-align-drawer').hasClass('is-open'), 'کشوی هم‌تراز باز شد');
	ok(String($('#wapm-ad-facts').text()).indexOf('ارزان‌ترین رقیب') !== -1, 'وضعیت فعلی در کشو نشان داده شد');

	// پیش‌نمایش: تا وقتی محاسبه نشده، «اعمال» باید خاموش بماند.
	ok($('#wapm-ad-apply').prop('disabled') === true, 'اعمال قبل از پیش‌نمایش خاموش است');
	$('#wapm-ad-preview').trigger('click');
	ok(String($('#wapm-ad-result').text()).indexOf('۹۹') !== -1 || String($('#wapm-ad-result').text()).length > 0, 'نتیجه‌ی پیش‌نمایش نمایش داده شد');
	ok($('#wapm-ad-apply').prop('disabled') === false, 'اعمال بعد از پیش‌نمایش فعال شد');

	$('#wapm-ad-apply').trigger('click');
	ok(!$('#wapm-align-drawer').hasClass('is-open'), 'کشو بعد از اعمال بسته شد');
} catch (error) {
	ok(false, 'سناریوی هم‌تراز بدون خطا اجرا شد', error.message);
}

console.log('— تب‌ها');
try {
	$('.wapm-tabs .nav-tab[data-tab="history"]').trigger('click');
	ok(true, 'کلیک تب تاریخچه بدون خطا');
	$('.wapm-tabs .nav-tab[data-tab="list"]').trigger('click');
	// در jsdom عرض/ارتفاع عناصر صفر است و «:visible» همیشه false می‌شود؛ پس
	// نمایش را از استایل درون‌خطی می‌خوانیم (همان کاری که خود تب‌ها می‌کنند).
	ok($('#wapm-tab-list').css('display') !== 'none', 'تب لیست نمایان شد', $('#wapm-tab-list').attr('style') || '');
} catch (error) {
	ok(false, 'کلیک تب‌ها بدون خطا', error.message);
}

if (errors.length) {
	console.error('\nخطاهای زمان اجرا:');
	for (const message of errors) console.error('  - ' + message);
	failures += errors.length;
}

console.log('');
if (failures > 0) {
	console.error(`❌ ${failures} بررسی ناموفق.`);
	process.exit(1);
}
console.log('✅ تست دودی JS موفق بود.');
