/* global jQuery, WAPM, WAPMUI */
/**
 * WAPM Admin — تب «لیست محصولات»
 *
 * جدول، فیلترها، ویرایش درجا، انتخاب گروهی، کشوی تغییر قیمت و CSV.
 */
(function ($, UI) {
	'use strict';

	var ajax = UI.ajax, esc = UI.esc, fmt = UI.fmt, wapmToast = UI.wapmToast;
	var state = UI.state;
	var readFilters = UI.readFilters, readSettings = UI.readSettings;

	/**
	 * فراخوانی تنبل تابعی از ماژول دیگر.
	 *
	 * چرا: فایل‌ها به ترتیب لود می‌شوند و بعضی ماژول‌ها به تابعی وابسته‌اند که در
	 * ماژولی *بعد* از خودشان تعریف شده (مثلاً تب «قوانین» که تاریخچه را از تب
	 * تاریخچه می‌خواند). با نگاه‌کردن در زمان کلیک، ترتیب لود بی‌اهمیت می‌شود.
	 */
	function call(name) {
		var args = Array.prototype.slice.call(arguments, 1);
		if (typeof UI[name] === 'function') { UI[name].apply(null, args); }
	}

	/* ---------------- لیست محصولات ---------------- */

	var listState = {
		search: '', category_id: 0, stock_status: '',
		per_page: 25, paged: 1, pages: 1, total: 0,
		price_min: '', price_max: '', stock_min: '', stock_max: '', product_type: '', on_sale: false,
		torob_status: '', orderby: 'id_desc',
		viewMode: 'simple', saveMode: 'auto',
		selected: {},
		allResults: false,   // «همه‌ی نتایج فیلتر» انتخاب شده، نه فقط همین صفحه
		lastRows: [],
		pending: {}
	};
	var listSearchTimer = null;
	var LIST_COLS = 15;
	var COLUMN_STORAGE_KEY = 'wapm_list_visible_columns';
	var ALL_COLUMNS = ['image', 'regular_price', 'sale_price', 'manage_stock', 'stock_qty', 'stock_status', 'categories', 'brand', 'torob', 'id', 'sku', 'last_change'];

	function loadColumnPrefs() {
		try {
			var saved = JSON.parse(window.localStorage.getItem(COLUMN_STORAGE_KEY) || 'null');
			if (saved && typeof saved === 'object') { return saved; }
		} catch (e) { /* localStorage غیرفعال یا مقدار نامعتبر — پیش‌فرض همه ستون‌ها فعال */ }
		var all = {};
		ALL_COLUMNS.forEach(function (c) { all[c] = true; });
		if (!WAPM.brand_taxonomy) { all.brand = false; }
		return all;
	}

	listState.visibleCols = loadColumnPrefs();

	function applyColumnVisibility() {
		ALL_COLUMNS.forEach(function (col) {
			var visible = listState.visibleCols[col] !== false;
			$('#wapm-list-root [data-col="' + col + '"]').toggle(visible);
		});
	}

	function stockBadge(status, label) {
		var map = { instock: 'cheaper', outofstock: 'pricier', onbackorder: 'stale' };
		return '<span class="wapm-pill wapm-pill--' + (map[status] || 'muted') + '">' + esc(label) + '</span>';
	}

	function priceCellHtml(r, field) {
		var raw = (field === 'regular_price') ? r.regular_price : r.sale_price;
		var fmtVal = (field === 'regular_price') ? r.regular_price_fmt : r.sale_price_fmt;
		var val = (raw === null || raw === undefined) ? '' : raw;

		// محصول متغیر: قیمت از واریشن‌ها می‌آید. ویرایش قیمت والد بی‌اثر است،
		// پس فقط محدوده‌ی قیمت واریشن‌ها را نشان می‌دهیم (غیرقابل ویرایش).
		if (r.is_variable) {
			if (field === 'sale_price') {
				return '<span class="text-slate-300 text-[11px]" title="قیمت ویژه‌ی هر واریشن را در ردیف همان واریشن ویرایش کنید">—</span>';
			}
			return '<div class="wapm-price-muted">' + (r.price_range ? esc(r.price_range) : '—') +
				'<span style="display:block">محدوده‌ی واریشن‌ها</span></div>';
		}

		if (listState.viewMode === 'excel') {
			return '<input type="number" step="any" min="0" class="wapm-price-input" ' +
				'data-id="' + r.id + '" data-field="' + field + '" value="' + esc(val) + '">';
		}

		var cls = 'wapm-price-display';
		if (field === 'sale_price') { cls += ' wapm-price-display--sale'; }
		if (!fmtVal) { cls += ' wapm-price-display--empty'; }

		return '<div class="' + cls + '" ' +
			'data-id="' + r.id + '" data-field="' + field + '" data-value="' + esc(val) + '">' + (fmtVal || '—') + '</div>';
	}

	function stockCellHtml(r) {
		var val = (r.stock_quantity === null || r.stock_quantity === undefined) ? '' : r.stock_quantity;
		if (!r.manage_stock) {
			return '<span class="text-slate-300 text-[11px]">—</span>';
		}
		if (listState.viewMode === 'excel') {
			return '<input type="number" step="1" min="0" class="wapm-price-input" ' +
				'data-id="' + r.id + '" data-field="stock_quantity" value="' + esc(val) + '">';
		}

		var low = (val !== '' && Number(val) <= 5);
		return '<div class="wapm-price-display' + (low ? ' wapm-price-display--low' : '') + '" ' +
			'data-id="' + r.id + '" data-field="stock_quantity" data-value="' + esc(val) + '" ' +
			(low ? 'title="موجودی کم"' : '') + '>' + (val === '' ? '—' : fmt(val)) + '</div>';
	}

	function manageStockToggleHtml(r) {
		var on = !!r.manage_stock;
		return '<button type="button" class="wapm-switch wapm-manage-stock-toggle' + (on ? ' is-on' : '') + '" ' +
			'data-id="' + r.id + '" data-enabled="' + (on ? 1 : 0) + '" ' +
			'role="switch" aria-checked="' + (on ? 'true' : 'false') + '" ' +
			'aria-label="مدیریت انبار" title="' + (on ? 'مدیریت انبار روشن — برای خاموش‌کردن بزنید' : 'مدیریت انبار خاموش — برای روشن‌کردن بزنید') + '"></button>';
	}

	function termsCellHtml(r, taxonomy, names) {
		var label = (names || []).join('، ') || '—';
		var editable = !!r.can_edit_terms;
		var displayCls = 'text-[11px] font-bold text-slate-600 truncate px-2 py-1.5 rounded-lg' +
			(editable ? ' wapm-terms-editable cursor-pointer hover:bg-slate-50' : '');
		return '<div class="wapm-terms-cell" data-id="' + r.id + '" data-tax="' + esc(taxonomy) + '">' +
			'<div class="' + displayCls + '" data-id="' + r.id + '" data-tax="' + esc(taxonomy) + '" title="' + esc(label) + '">' + esc(label) + '</div>' +
			'<span class="wapm-terms-check" style="font-size:10px;font-weight:900;display:block;margin-top:2px"></span>' +
			'</div>';
	}

	function categoryCellHtml(r) {
		return termsCellHtml(r, 'product_cat', r.categories);
	}

	function brandCellHtml(r) {
		if (!WAPM.brand_taxonomy) {
			return '<span class="text-slate-300 text-[11px]">—</span>';
		}
		return termsCellHtml(r, WAPM.brand_taxonomy, r.brand_names);
	}

	/**
	 * ستون ترب.
	 * همه‌ی اعداد (درصد اختلاف، تازگی، ناسازگاری فید) در PHP محاسبه شده‌اند؛
	 * این تابع فقط نمایش می‌دهد تا منطق در یک جا بماند.
	 */
	function torobAgeLabel(ageDays) {
		if (ageDays === null || ageDays === undefined) { return '—'; }
		var d = Number(ageDays);
		if (d <= 0) { return 'امروز'; }
		if (d === 1) { return 'دیروز'; }
		return fmt(d) + ' روز پیش';
	}

	function torobBadge(t) {
		var map = {
			pricier:       { cls: 'pricier', text: '🔴 ' + Math.abs(Number(t.diff_pct)).toFixed(1) + '٪ گران‌تر' },
			cheaper:       { cls: 'cheaper', text: '🟢 ' + Math.abs(Number(t.diff_pct)).toFixed(1) + '٪ ارزان‌تر' },
			equal:         { cls: 'equal', text: 'برابر با رقیب' },
			stale:         { cls: 'stale', text: '⚠ داده کهنه' },
			no_competitor: { cls: 'info', text: 'فقط ما در ترب' },
			needs_shop_id: { cls: 'stale', text: 'شناسه فروشگاه تنظیم نشده' },
			no_price:      { cls: 'muted', text: 'قیمت نامشخص' },
			none:          { cls: 'muted', text: 'بدون داده' }
		};
		var b = map[t.status] || map.none;
		return '<span class="wapm-pill wapm-pill--' + b.cls + '">' + esc(b.text) + '</span>';
	}

	function torobCellHtml(r) {
		if (r.is_variation) {
			return '<span class="text-slate-300 text-[11px]">—</span>';
		}

		// بدون داده: راهنمای اقدام (شماره‌دار، مطابق تب ترب).
		if (!r.torob) {
			return '<a href="#" class="wapm-torob-go wapm-mini-link" ' +
				'title="داده‌ای از ترب برای این محصول نداریم. از تب «ترب» همگام‌سازی کنید یا دستی وصل کنید.">همگام‌سازی نشده — راهنما</a>';
		}

		var t = r.torob;
		var html = '<div style="min-width:150px">';
		html += '<div>' + torobBadge(t);
		if (t.link === 'manual') {
			html += ' <span class="wapm-pill wapm-pill--info" style="padding:1px 6px">دستی</span>';
		}
		html += '</div>';

		if (t.competitor_price !== null && t.competitor_price !== undefined) {
			html += '<div style="font-size:10px;color:#475569;font-weight:700;margin-top:3px">ارزان‌ترین رقیب: ' + fmt(t.competitor_price) + '</div>';
		}

		if (t.sellers) {
			html += '<div style="font-size:9px;color:#94a3b8;font-weight:700">' + fmt(t.sellers) + ' فروشنده</div>';
		}

		if (t.status === 'none') {
			// وصل است ولی هنوز قیمت رقیبی گرفته نشده (مثلاً فقط کاتالوگ همگام شده).
			html += '<div style="font-size:9px;color:#64748b">قیمت رقیب ندارد — «بررسی الان» را بزنید</div>';
		} else {
			html += '<div style="font-size:9px;color:#94a3b8">قیمت ترب: ' + torobAgeLabel(t.age_days) + '</div>';
		}

		// «تخمینی» یعنی قیمت از کارت لیست فروشگاه آمده، نه از ردیف فروشنده‌ی خودمان.
		var est = (t.our_source === 'card')
			? ' <span title="این قیمت از لیست محصولات فروشگاه در ترب برداشته شده است؛ قیمت قطعی از ردیف فروشنده‌ی خودتان می‌آید (دکمه‌ی بررسی الان)" style="font-size:9px;color:#94a3b8">(تخمینی)</span>'
			: '';

		// ناسازگاری فید: قیمتی که ترب از ما نشان می‌دهد با قیمت سایت یکی نیست.
		if (t.mismatch_pct !== null && t.mismatch_pct !== undefined && Math.abs(Number(t.mismatch_pct)) > Number(t.tolerance_pct || 1)) {
			html += '<div style="font-size:9px;color:#b45309;font-weight:800" title="قیمتی که ترب از فروشگاه شما نشان می‌دهد با قیمت ووکامرس نمی‌خواند — احتمالاً فید/کش ترب به‌روز نیست">⚠ فید ترب: ' +
				fmt(t.our_price_torob) + est + '</div>';
		}

		// محافظ واحد: نسبت ~۱۰ بین قیمت ترب و ووکامرس یعنی تومان/ریال قاطی شده
		// (یا داده‌ی اشتباه). فقط هشدار است؛ هیچ قیمتی خودکار عوض نمی‌شود.
		// روند قیمت رقیب (از تاریخچه‌ی ۳۰ نقطه‌ای): «رقیب ارزان شد» قبل از اینکه
		// ما واکنش نشان دهیم دیده می‌شود. تغییرهای زیر ۱٪ نویز هستند.
		if (t.history_change && Math.abs(Number(t.history_change.pct)) >= 1) {
			var hc = t.history_change;
			var down = Number(hc.pct) < 0;
			html += '<div style="font-size:9px;font-weight:800;color:' + (down ? '#067647' : '#b42318') + '" title="از ' + fmt(hc.from) + ' به ' + fmt(hc.to) + ' در ' + fmt(hc.days) + ' روز">' +
				(down ? '▼' : '▲') + ' رقیب ' + Math.abs(Number(hc.pct)).toFixed(1) + '٪ ' + (down ? 'ارزان‌تر' : 'گران‌تر') + ' شد</div>';
		}

		if (t.unit_suspect) {
			html += '<div style="font-size:9px;color:#b91c1c;font-weight:800" title="قیمت ترب و ووکامرس حدود ۱۰ برابر فاصله دارند — واحد (تومان/ریال) یا داده را بررسی کنید">⚠ واحد قیمت مشکوک</div>';
		}

		if (t.our_age_days !== null && t.our_age_days !== undefined && t.our_age_days > Number(t.fresh_days || 3)) {
			html += '<div style="font-size:9px;color:#94a3b8">قیمت ما در ترب: ' + torobAgeLabel(t.our_age_days) + '</div>';
		}

		var links = '';
		if (t.url) {
			links += '<a href="' + esc(t.url) + '" target="_blank" rel="noopener" class="wapm-mini-link">ترب</a>';
		}
		links += ' <button type="button" class="wapm-torob-check wapm-mini-link" data-id="' + r.id + '" title="همین حالا از ترب بخوان (۱ درخواست)">بررسی الان</button>';
		// «هم‌تراز» فقط وقتی معنا دارد که ارزان‌ترین رقیب را داشته باشیم؛ دکمه‌ی
		// بی‌اثر نشان دادن، کاربر را به کلیک‌های بی‌نتیجه عادت می‌دهد.
		if (t.competitor_price !== null && t.competitor_price !== undefined && Number(t.competitor_price) > 0) {
			links += ' <button type="button" class="wapm-torob-align wapm-mini-link" data-id="' + r.id + '" title="قیمت را روی ارزان‌ترین رقیب منهای درصد تعیین‌شده بگذار (با پیش‌نمایش)">هم‌تراز</button>';
		}
		links += ' <button type="button" class="wapm-torob-unlink wapm-mini-link wapm-mini-link--danger" data-id="' + r.id + '" title="جداکردن از ترب">جداکردن</button>';

		html += '<div style="margin-top:2px">' + links + '</div>';
		html += '</div>';

		return html;
	}

	/** «آخرین تغییر قیمت» — از متایی که خود لاگ روی محصول می‌گذارد. */
	function lastChangeHtml(r) {
		if (!r.last_change_label) {
			return '<span class="wapm-price-muted">—</span>';
		}
		var days = Number(r.last_change_days);
		var fresh = !isNaN(days) && days <= 7;
		return '<span class="wapm-pill wapm-pill--' + (fresh ? 'info' : 'muted') + '" title="' + esc(r.last_change) + '">' +
			esc(r.last_change_label) + '</span>';
	}

	function actionsCellHtml(r) {
		var dupBtn = r.can_duplicate
			? '<button type="button" class="wapm-action-duplicate" data-id="' + r.id + '">کپی محصول</button>'
			: '';
		return '<div class="wapm-actions-wrap">' +
			'<button type="button" class="wapm-actions-toggle" data-id="' + r.id + '" aria-label="عملیات">' +
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>' +
			'</button>' +
			'<div class="wapm-actions-menu" style="display:none">' +
			dupBtn +
			'<button type="button" class="wapm-action-delete is-danger" data-id="' + r.id + '">حذف</button>' +
			'</div></div>';
	}

	function rowHtml(r) {
		var indentStyle = r.is_variation ? 'padding-right:28px' : '';
		var nameLine = esc(r.name) + (r.variation_label ? ' <span class="text-slate-400 text-xs">— ' + esc(r.variation_label) + '</span>' : '');
		var variableBadge = r.is_variable ? ' <span class="text-[9px] bg-primary-50 text-primary-600 px-2 py-0.5 rounded-lg font-black ring-1 ring-primary-100">متغیر</span>' : '';
		var links = '<a href="' + esc(r.edit_url) + '" target="_blank" rel="noopener" class="text-blue-600 text-[10px] font-black ml-2">ویرایش</a>' +
			'<a href="' + esc(r.view_url) + '" target="_blank" rel="noopener" class="text-emerald-600 text-[10px] font-black">نمایش</a>';
		var checked = listState.selected[r.id] ? 'checked' : '';

		return '<tr class="hover:bg-slate-50 border-b border-slate-50" data-row-id="' + r.id + '">' +
			'<td class="px-3 py-2"><input type="checkbox" class="wapm-row-check" data-id="' + r.id + '" ' + checked + '></td>' +
			'<td class="px-3 py-2" data-col="image"><img src="' + esc(r.image) + '" class="w-12 h-12 rounded-lg object-cover bg-slate-100" loading="lazy" alt=""></td>' +
			'<td class="px-3 py-2" style="' + indentStyle + '">' +
				'<div class="font-black text-slate-700 text-[12px]">' + nameLine + variableBadge + '</div>' +
				'<div class="flex items-center gap-2 mt-1">' +
					(r.sku ? '<span class="text-[9px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded-lg font-mono font-bold">' + esc(r.sku) + '</span>' : '') +
					links +
				'</div>' +
			'</td>' +
			'<td class="px-3 py-2" data-col="regular_price">' + priceCellHtml(r, 'regular_price') + '</td>' +
			'<td class="px-3 py-2" data-col="sale_price">' + priceCellHtml(r, 'sale_price') + '</td>' +
			'<td class="px-3 py-2" data-col="manage_stock"><div class="flex items-center gap-2">' + manageStockToggleHtml(r) + '<span class="text-[10px] font-bold text-slate-500">' + (r.manage_stock ? 'فعال' : 'غیرفعال') + '</span></div></td>' +
			'<td class="px-3 py-2" data-col="stock_qty">' + stockCellHtml(r) + '</td>' +
			'<td class="px-3 py-2" data-col="stock_status">' + stockBadge(r.stock_status, r.stock_status_label) + '</td>' +
			'<td class="px-3 py-2" data-col="categories">' + categoryCellHtml(r) + '</td>' +
			'<td class="px-3 py-2" data-col="brand">' + brandCellHtml(r) + '</td>' +
			'<td class="px-3 py-2" data-col="torob">' + torobCellHtml(r) + '</td>' +
			'<td class="px-3 py-2 text-[11px] font-bold text-slate-600" data-col="id">' + esc(r.id) + '</td>' +
			'<td class="px-3 py-2 text-[11px] font-bold text-slate-600" data-col="sku">' + esc(r.sku || '—') + '</td>' +
			'<td class="px-3 py-2" data-col="last_change">' + lastChangeHtml(r) + '</td>' +
			'<td class="px-3 py-2">' + actionsCellHtml(r) + '</td>' +
			'</tr>';
	}

	function renderProductList(data) {
		listState.lastRows = data.rows || [];
		var $tbody = $('#wapm-list-tbody').empty();
		if (!listState.lastRows.length) {
			$tbody.append('<tr><td colspan="' + LIST_COLS + '" class="text-center py-10 text-slate-400 text-sm">' + esc(WAPM.i18n.no_targets) + '</td></tr>');
		}
		listState.lastRows.forEach(function (r) { $tbody.append(rowHtml(r)); });

		listState.pages = data.pages || 1;
		listState.total = data.total || 0;
		$('#wapm-list-summary').text('محصولات (صفحه ' + fmt(listState.paged) + ' از ' + fmt(listState.pages) + ' — مجموع ' + fmt(listState.total) + ')');
		$('#wapm-list-page-indicator').text(fmt(listState.paged));
		$('#wapm-list-prev').prop('disabled', listState.paged <= 1);
		$('#wapm-list-next').prop('disabled', listState.paged >= listState.pages);
		updateSelectAllCheckbox();
		applyColumnVisibility();
	}

	function rerenderCurrent() {
		renderProductList({ rows: listState.lastRows, pages: listState.pages, total: listState.total });
	}

	/**
	 * اسکلت بارگذاری: کاربر ساختار جدول را می‌بیند و «پرش» صفحه کمتر حس می‌شود.
	 */
	function skeletonRowsHtml() {
		var html = '';
		for (var i = 0; i < 6; i++) {
			html += '<tr><td colspan="' + LIST_COLS + '" style="padding:10px 12px">' +
				'<div style="display:flex;align-items:center;gap:10px">' +
					'<span class="wapm-skeleton" style="width:38px;height:38px;border-radius:10px"></span>' +
					'<span style="flex:1"><span class="wapm-skeleton" style="width:' + (40 + (i % 3) * 12) + '%;margin-bottom:6px"></span>' +
					'<span class="wapm-skeleton" style="width:22%;height:9px"></span></span>' +
					'<span class="wapm-skeleton" style="width:70px"></span>' +
					'<span class="wapm-skeleton" style="width:70px"></span>' +
				'</div></td></tr>';
		}
		return html;
	}

	/**
	 * فیلترهای فعال جدول — یک منبع حقیقت برای جدول و «انتخاب همه‌ی نتایج».
	 * اگر این دو جدا ساخته شوند، دیر یا زود انتخاب با آنچه دیده می‌شود فرق می‌کند.
	 */
	function listFilters(overrides) {
		return Object.assign({
			search: listState.search,
			category_id: listState.category_id,
			stock_status: listState.stock_status,
			per_page: listState.per_page,
			paged: listState.paged,
			price_min: listState.price_min,
			price_max: listState.price_max,
			stock_min: listState.stock_min,
			stock_max: listState.stock_max,
			product_type: listState.product_type,
			on_sale: listState.on_sale ? 1 : 0,
			torob_status: listState.torob_status,
			orderby: listState.orderby
		}, overrides || {});
	}

	function loadProductList(paged) {
		listState.paged = paged || 1;
		listState.selected = {};
		listState.allResults = false;
		updateBulkBar();
		$('#wapm-list-tbody').html(skeletonRowsHtml());
		ajax('list_products', { filters: JSON.stringify(listFilters()) }).done(function (res) {
			if (res.success) { renderProductList(res.data); }
			else { $('#wapm-list-tbody').html('<tr><td colspan="' + LIST_COLS + '" class="text-center py-10 text-red-500 text-sm">' + esc(WAPM.i18n.error) + '</td></tr>'); }
		}).fail(function () {
			$('#wapm-list-tbody').html('<tr><td colspan="' + LIST_COLS + '" class="text-center py-10 text-red-500 text-sm">' + esc(WAPM.i18n.error) + '</td></tr>');
		});
	}

	$('#wapm-list-search').on('input', function () {
		var val = $(this).val();
		clearTimeout(listSearchTimer);
		listSearchTimer = setTimeout(function () {
			listState.search = val;
			loadProductList(1);
		}, 400);
	});

	$('#wapm-list-category').on('change', function () {
		listState.category_id = $(this).val();
		loadProductList(1);
	});

	$('#wapm-list-stock').on('change', function () {
		listState.stock_status = $(this).val();
		loadProductList(1);
	});

	$('#wapm-list-torob').on('change', function () {
		listState.torob_status = $(this).val();
		loadProductList(1);
	});

	$('#wapm-list-orderby').on('change', function () {
		listState.orderby = $(this).val() || 'id_desc';
		loadProductList(1);
	});

	$('#wapm-list-per-page').on('change', function () {
		listState.per_page = parseInt($(this).val(), 10) || 25;
		loadProductList(1);
	});

	$('#wapm-list-prev').on('click', function () { if (listState.paged > 1) { loadProductList(listState.paged - 1); } });
	$('#wapm-list-next').on('click', function () { if (listState.paged < listState.pages) { loadProductList(listState.paged + 1); } });

	/* ---- فیلتر هوشمند ---- */

	$('#wapm-list-smart-toggle').on('click', function () {
		$('#wapm-list-smart-panel').slideToggle(150);
	});

	$('#wapm-smart-apply').on('click', function () {
		listState.price_min = $('#wapm-smart-price-min').val();
		listState.price_max = $('#wapm-smart-price-max').val();
		listState.stock_min = $('#wapm-smart-stock-min').val();
		listState.stock_max = $('#wapm-smart-stock-max').val();
		listState.product_type = $('#wapm-smart-type').val();
		listState.on_sale = $('#wapm-smart-on-sale').is(':checked');
		loadProductList(1);
	});

	/* ویو «رو به اتمام»: موجودی بین ۱ تا ۵ — محصول بدون مدیریت انبار در این
	   فهرست نمی‌آید (چون `_stock` ندارد) و همین درست است. */
	$('#wapm-smart-low-stock').on('click', function () {
		$('#wapm-smart-stock-min').val(1);
		$('#wapm-smart-stock-max').val(5);
		listState.stock_min = 1;
		listState.stock_max = 5;
		loadProductList(1);
	});

	$('#wapm-smart-clear').on('click', function () {
		$('#wapm-smart-price-min, #wapm-smart-price-max, #wapm-smart-stock-min, #wapm-smart-stock-max').val('');
		$('#wapm-smart-type').val('');
		$('#wapm-smart-on-sale').prop('checked', false);
		listState.price_min = ''; listState.price_max = ''; listState.stock_min = ''; listState.stock_max = '';
		listState.product_type = ''; listState.on_sale = false;
		loadProductList(1);
	});

	/* ---- فیلترهای ذخیره‌شده ---- */

	/**
	 * فیلترها در user meta ذخیره می‌شوند (هر کاربر نمای خودش را دارد) و در
	 * مرورگر کش می‌شوند تا انتخاب، بدون درخواست دوم اعمال شود.
	 */
	var savedFilters = [];

	function renderSavedFilters(rows) {
		savedFilters = rows || [];

		var $sel = $('#wapm-list-saved-filters');
		var current = $sel.val();

		$sel.find('option:not(:first)').remove();
		savedFilters.forEach(function (row) {
			$sel.append($('<option>').attr('value', row.id).text(row.name));
		});

		// اگر فیلتر انتخاب‌شده هنوز هست، انتخاب حفظ شود.
		if (current && savedFilters.some(function (r) { return r.id === current; })) {
			$sel.val(current);
		} else {
			$sel.val('');
		}

		$('#wapm-list-delete-filter').prop('disabled', !$sel.val());
	}

	function loadSavedFilters() {
		if (!$('#wapm-list-saved-filters').length) { return; }
		ajax('list_saved_filters').done(function (res) {
			if (res.success) { renderSavedFilters(res.data.rows); }
		});
	}

	/** اعمال یک فیلتر ذخیره‌شده روی state و کنترل‌های جدول. */
	function applySavedFilter(filters) {
		if (!filters) { return; }

		listState.search = filters.search || '';
		listState.category_id = filters.category_id || 0;
		listState.stock_status = filters.stock_status || '';
		listState.torob_status = filters.torob_status || '';
		listState.orderby = filters.orderby || 'id_desc';
		listState.per_page = filters.per_page || listState.per_page;
		listState.price_min = filters.price_min === null || filters.price_min === undefined ? '' : filters.price_min;
		listState.price_max = filters.price_max === null || filters.price_max === undefined ? '' : filters.price_max;
		listState.stock_min = filters.stock_min === null || filters.stock_min === undefined ? '' : filters.stock_min;
		listState.stock_max = filters.stock_max === null || filters.stock_max === undefined ? '' : filters.stock_max;
		listState.product_type = filters.product_type || '';
		listState.on_sale = !!filters.on_sale;

		// کنترل‌ها هم باید همان چیزی را نشان دهند که اعمال شده؛ وگرنه کاربر فکر
		// می‌کند فیلتر ذخیره‌شده اعمال نشده است.
		$('#wapm-list-search').val(listState.search);
		$('#wapm-list-category').val(String(listState.category_id));
		$('#wapm-list-stock').val(listState.stock_status);
		$('#wapm-list-torob').val(listState.torob_status);
		$('#wapm-list-orderby').val(listState.orderby);
		$('#wapm-list-per-page').val(String(listState.per_page));

		$('#wapm-smart-price-min').val(listState.price_min);
		$('#wapm-smart-price-max').val(listState.price_max);
		$('#wapm-smart-stock-min').val(listState.stock_min);
		$('#wapm-smart-stock-max').val(listState.stock_max);
		$('#wapm-smart-type').val(listState.product_type);
		$('#wapm-smart-on-sale').prop('checked', listState.on_sale);

		var smartUsed = listState.price_min !== '' || listState.price_max !== ''
			|| listState.stock_min !== '' || listState.stock_max !== ''
			|| listState.product_type !== '' || listState.on_sale;
		if (smartUsed) { $('#wapm-list-smart-panel').show(); }

		loadProductList(1);
	}

	$('#wapm-list-saved-filters').on('change', function () {
		var id = $(this).val();
		$('#wapm-list-delete-filter').prop('disabled', !id);
		if (!id) { return; }

		var row = savedFilters.filter(function (r) { return r.id === id; })[0];
		if (!row) { return; }

		applySavedFilter(row.filters);
	});

	/* نام‌گذاری داخل خود صفحه است، نه window.prompt: دیالوگ مرورگر با ظاهر پنل
	   نمی‌خواند و در برخی مرورگرها بلاک می‌شود. */
	function openSaveNamePop() {
		$('#wapm-saved-name-pop').prop('hidden', false);
		$('#wapm-saved-name').val('').trigger('focus');
	}

	function closeSaveNamePop() {
		$('#wapm-saved-name-pop').prop('hidden', true);
	}

	$('#wapm-list-save-filter').on('click', openSaveNamePop);
	$('#wapm-saved-name-cancel').on('click', closeSaveNamePop);

	$(document).on('keydown', function (e) {
		if ($('#wapm-saved-name-pop').prop('hidden')) { return; }
		if (e.key === 'Escape') { closeSaveNamePop(); }
		if (e.key === 'Enter' && $(e.target).is('#wapm-saved-name')) {
			e.preventDefault();
			$('#wapm-saved-name-ok').trigger('click');
		}
	});

	$('#wapm-saved-name-ok').on('click', function () {
		var filters = listFilters();
		var name = $.trim($('#wapm-saved-name').val());

		var $btn = $(this).prop('disabled', true);

		ajax('list_save_filter', {
			name: name,
			filters: JSON.stringify(filters)
		}).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'warning');
				return;
			}
			renderSavedFilters(res.data.rows);
			closeSaveNamePop();
			wapmToast(res.data.message || 'ذخیره شد.', 'success');
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () { $btn.prop('disabled', false); });
	});

	$('#wapm-list-delete-filter').on('click', function () {
		var id = $('#wapm-list-saved-filters').val();
		if (!id) { return; }

		var row = savedFilters.filter(function (r) { return r.id === id; })[0];
		if (!window.confirm('فیلتر «' + ((row && row.name) || id) + '» حذف شود؟')) { return; }

		var $btn = $(this).prop('disabled', true);

		ajax('list_delete_filter', { id: id }).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}
			$('#wapm-list-saved-filters').val('');
			renderSavedFilters(res.data.rows);
			wapmToast(res.data.message || 'حذف شد.', 'info');
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () { $btn.prop('disabled', false); });
	});

	loadSavedFilters();

	/* ---- حالت نمایش (ساده/اکسل) و حالت ذخیره (خودکار/دستی) ---- */

	$('.wapm-view-mode-btn').on('click', function () {
		listState.viewMode = $(this).data('view');
		$('.wapm-view-mode-btn').removeClass('bg-white text-primary-600 shadow-md').addClass('text-slate-500');
		$(this).addClass('bg-white text-primary-600 shadow-md').removeClass('text-slate-500');
		rerenderCurrent();
	});

	$('.wapm-save-mode-btn').on('click', function () {
		listState.saveMode = $(this).data('save');
		$('.wapm-save-mode-btn').removeClass('bg-white text-primary-600 shadow-md').addClass('text-slate-500');
		$(this).addClass('bg-white text-primary-600 shadow-md').removeClass('text-slate-500');
		if (listState.saveMode === 'auto' && Object.keys(listState.pending).length) {
			wapmToast('برخی تغییرات هنوز ذخیره نشده‌اند. ابتدا «ذخیره تغییرات» را بزنید.', 'warning');
		}
	});

	/* ---- ویرایش inline قیمت/موجودی ---- */

	function updateRowInDom(row) {
		for (var i = 0; i < listState.lastRows.length; i++) {
			if (String(listState.lastRows[i].id) === String(row.id)) { listState.lastRows[i] = row; break; }
		}
		var $tr = $('#wapm-list-tbody tr[data-row-id="' + row.id + '"]');
		if (!$tr.length) { return; }
		var $tds = $tr.find('td');
		$tds.eq(3).html(priceCellHtml(row, 'regular_price'));
		$tds.eq(4).html(priceCellHtml(row, 'sale_price'));
		$tds.eq(5).html('<div class="flex items-center gap-2">' + manageStockToggleHtml(row) + '<span class="text-[10px] font-bold text-slate-500">' + (row.manage_stock ? 'فعال' : 'غیرفعال') + '</span></div>');
		$tds.eq(6).html(stockCellHtml(row));
		$tds.eq(7).html(stockBadge(row.stock_status, row.stock_status_label));
		$tds.eq(8).html(categoryCellHtml(row));
		$tds.eq(9).html(brandCellHtml(row));
		$tds.eq(10).html(torobCellHtml(row));
		$tds.eq(13).html(lastChangeHtml(row));
	}

	function saveField(id, field, value) {
		ajax('update_field', { product_id: id, field: field, value: value }).done(function (res) {
			if (res.success) { updateRowInDom(res.data.row); }
			else { wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error'); rerenderCurrent(); }
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
			rerenderCurrent();
		});
	}

	function highlightPendingRows() {
		$('#wapm-list-tbody tr').css('background', '');
		Object.keys(listState.pending).forEach(function (id) {
			$('#wapm-list-tbody tr[data-row-id="' + id + '"]').css('background', '#fffbeb');
		});
	}

	function updatePendingUi() {
		var count = 0;
		Object.keys(listState.pending).forEach(function (id) { count += Object.keys(listState.pending[id]).length; });
		$('#wapm-list-pending-count').text(count);
		$('#wapm-list-save-pending').toggle(count > 0);
		highlightPendingRows();
	}

	function applyEdit(id, field, value) {
		if (listState.saveMode === 'manual') {
			listState.pending[id] = listState.pending[id] || {};
			listState.pending[id][field] = value;
			updatePendingUi();

			var row = null;
			for (var i = 0; i < listState.lastRows.length; i++) {
				if (String(listState.lastRows[i].id) === String(id)) { row = listState.lastRows[i]; break; }
			}
			if (row) {
				if (field === 'regular_price') { row.regular_price = value; row.regular_price_fmt = fmt(value) + ' ' + (WAPM.currency || ''); }
				if (field === 'sale_price') { row.sale_price = value; row.sale_price_fmt = fmt(value) + ' ' + (WAPM.currency || ''); }
				if (field === 'stock_quantity') { row.stock_quantity = value; }
				updateRowInDom(row);
				$('#wapm-list-tbody tr[data-row-id="' + id + '"]').css('background', '#fffbeb');
			}
			return;
		}
		saveField(id, field, value);
	}

	// حالت «ساده»: کلیک روی سلول تبدیل به input می‌شود
	$(document).on('click', '#wapm-list-tbody .wapm-price-display', function () {
		if (listState.viewMode !== 'simple') { return; }
		var $cell = $(this);
		var id = $cell.data('id');
		var field = $cell.data('field');
		var value = $cell.data('value');
		var $input = $('<input type="number" step="any" min="0" class="wapm-price-input">').val(value);
		$cell.replaceWith($input);
		$input.trigger('focus');
		$input[0].select();

		$input.on('blur', function () { applyEdit(id, field, $input.val()); });
		$input.on('keydown', function (e) {
			if (e.key === 'Enter') { $input.trigger('blur'); }
			if (e.key === 'Escape') { $input.off('blur'); rerenderCurrent(); }
		});
	});

	// حالت «اکسل»: تغییر مستقیم input
	$(document).on('change', '#wapm-list-tbody .wapm-price-input', function () {
		var $input = $(this);
		applyEdit($input.data('id'), $input.data('field'), $input.val());
	});

	$('#wapm-list-save-pending').on('click', function () {
		var ids = Object.keys(listState.pending);
		if (!ids.length) { return; }
		var $btn = $(this).prop('disabled', true);
		var tasks = [];
		ids.forEach(function (id) {
			Object.keys(listState.pending[id]).forEach(function (field) {
				tasks.push({ id: id, field: field, value: listState.pending[id][field] });
			});
		});

		var failed = [];

		// فقط موردِ موفق از صف حذف می‌شود؛ موارد ناموفق برای تلاش دوباره باقی می‌مانند.
		// (نسخه‌ی قبلی در خطا هم pending را کامل خالی می‌کرد و «انجام شد» نشان می‌داد،
		// یعنی تغییراتی که هرگز ذخیره نشده بودند بی‌صدا از دست می‌رفتند.)
		function dropFromQueue(id, field) {
			if (!listState.pending[id]) { return; }
			delete listState.pending[id][field];
			if (!Object.keys(listState.pending[id]).length) { delete listState.pending[id]; }
		}

		function collectFailure(t, message) {
			var row = findRowById(t.id);
			failed.push({ name: row ? row.name : ('#' + t.id), message: message || WAPM.i18n.error });
		}

		function finish() {
			updatePendingUi();
			$btn.prop('disabled', false);
			rerenderCurrent(); // ردیف‌های ذخیره‌نشده دوباره زرد می‌شوند

			if (!failed.length) {
				wapmToast(WAPM.i18n.done, 'success');
				return;
			}

			var lines = failed.slice(0, 8).map(function (f) { return '• ' + f.name + ' — ' + f.message; });
			if (failed.length > 8) { lines.push('…'); }
			wapmToast(failed.length + ' مورد ذخیره نشد و در صف ماند. جزئیات در کنسول مرورگر.', 'error', { ttl: 12000 }); console.warn(lines);
		}

		function next() {
			if (!tasks.length) { finish(); return; }
			var t = tasks.shift();
			ajax('update_field', { product_id: t.id, field: t.field, value: t.value })
				.done(function (res) {
					if (res && res.success) {
						dropFromQueue(t.id, t.field);
						updateRowInDom(res.data.row);
					} else {
						collectFailure(t, res && res.data && res.data.message);
					}
					next();
				})
				.fail(function () {
					collectFailure(t);
					next();
				});
		}
		next();
	});

	/* ---- toggle مدیریت انبار ---- */

	$(document).on('click', '.wapm-manage-stock-toggle', function () {
		var id = $(this).data('id');
		var enabled = !$(this).data('enabled');
		ajax('toggle_manage_stock', { product_id: id, enabled: enabled ? 1 : 0 }).done(function (res) {
			if (res.success) { updateRowInDom(res.data.row); }
			else { wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error'); }
		});
	});

	/* ---- ویرایش دسته‌بندی / برند (با علامت تایید واقعی بعد از تایید سرور) ---- */

	function findRowById(id) {
		for (var i = 0; i < listState.lastRows.length; i++) {
			if (String(listState.lastRows[i].id) === String(id)) { return listState.lastRows[i]; }
		}
		return null;
	}

	$(document).on('click', '.wapm-terms-editable', function (e) {
		e.stopPropagation();
		$('.wapm-terms-popover').remove();

		var $display = $(this);
		var id = $display.data('id');
		var tax = $display.data('tax');
		var row = findRowById(id);
		if (!row) { return; }

		var terms = (tax === 'product_cat') ? (WAPM.category_terms || []) : (WAPM.brand_terms || []);
		var currentIds = (tax === 'product_cat') ? (row.category_ids || []) : (row.brand_ids || []);
		var currentSet = {};
		currentIds.forEach(function (i) { currentSet[i] = true; });

		var html = '<div class="wapm-terms-popover" style="position:absolute;z-index:9999;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.15);padding:8px;max-height:240px;width:220px;overflow-y:auto">';
		if (!terms.length) {
			html += '<div style="padding:8px;font-size:12px;color:#94a3b8">موردی برای انتخاب وجود ندارد.</div>';
		}
		terms.forEach(function (t) {
			var checked = currentSet[t.id] ? 'checked' : '';
			html += '<label style="display:flex;align-items:center;gap:6px;padding:4px 6px;font-size:12px;font-weight:700;color:#475569;cursor:pointer">' +
				'<input type="checkbox" class="wapm-term-opt" value="' + t.id + '" ' + checked + '> ' + esc(t.text) + '</label>';
		});
		html += '<div style="display:flex;gap:6px;margin-top:6px;border-top:1px solid #f1f5f9;padding-top:6px;position:sticky;bottom:0;background:#fff">' +
			'<button type="button" class="wapm-terms-save" style="flex:1;background:#7f54b3;color:#fff;border:0;border-radius:8px;padding:6px;font-size:11px;font-weight:700;cursor:pointer">ثبت</button>' +
			'<button type="button" class="wapm-terms-cancel" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;font-size:11px;font-weight:700;cursor:pointer">لغو</button>' +
			'</div></div>';

		var $pop = $(html).data('productId', id).data('taxonomy', tax);
		$('body').append($pop);
		var offset = $display.offset();
		$pop.css({ top: offset.top + $display.outerHeight() + 4, left: Math.max(4, offset.left - 40) });
	});

	$(document).on('click', '.wapm-terms-popover', function (e) { e.stopPropagation(); });
	$(document).on('click', function () { $('.wapm-terms-popover').remove(); });
	$(document).on('click', '.wapm-terms-cancel', function () { $('.wapm-terms-popover').remove(); });

	$(document).on('click', '.wapm-terms-save', function () {
		var $pop = $(this).closest('.wapm-terms-popover');
		var id = $pop.data('productId');
		var tax = $pop.data('taxonomy');
		var ids = [];
		$pop.find('.wapm-term-opt:checked').each(function () { ids.push(parseInt($(this).val(), 10)); });
		$pop.remove();

		var $wrap = $('.wapm-terms-cell[data-id="' + id + '"][data-tax="' + tax + '"]');
		$wrap.find('.wapm-terms-check').css('color', '#94a3b8').text('در حال ذخیره…');

		ajax('update_terms', { product_id: id, taxonomy: tax, term_ids: ids }).done(function (res) {
			if (res.success) {
				updateRowInDom(res.data.row);
				// چک واقعی: سرور بعد از ذخیره دوباره از دیتابیس خوانده و تایید کرده — این یک علامت فرضی نیست.
				var $newCheck = $('.wapm-terms-cell[data-id="' + id + '"][data-tax="' + tax + '"]').find('.wapm-terms-check');
				$newCheck.css('color', '#059669').html('✓ ذخیره و تایید شد');
				setTimeout(function () { $newCheck.fadeOut(800, function () { $(this).text('').show(); }); }, 2200);
			} else {
				if (res.data && res.data.row) { updateRowInDom(res.data.row); }
				var $failCheck = $('.wapm-terms-cell[data-id="' + id + '"][data-tax="' + tax + '"]').find('.wapm-terms-check');
				$failCheck.css('color', '#dc2626').text('✗ ' + ((res.data && res.data.message) || WAPM.i18n.error));
			}
		}).fail(function () {
			$wrap.find('.wapm-terms-check').css('color', '#dc2626').text('✗ ' + WAPM.i18n.error);
		});
	});

	/* ---- منوی عملیات (⋮) ---- */

	$(document).on('click', '.wapm-actions-toggle', function (e) {
		e.stopPropagation();
		var $menu = $(this).siblings('.wapm-actions-menu');
		$('.wapm-actions-menu').not($menu).hide();
		$menu.toggle();
	});
	$(document).on('click', function () { $('.wapm-actions-menu').hide(); });

	$(document).on('click', '.wapm-action-duplicate', function () {
		var $btn = $(this).prop('disabled', true);

		ajax('duplicate_product', { product_id: $(this).data('id') }).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}

			var data = res.data || {};
			var published = data.status === 'publish';

			// کپی معمولاً «پیش‌نویس» است و در این لیست (فقط منتشرشده‌ها) دیده
			// نمی‌شود؛ پس به‌جای بارگذاری بی‌اثر لیست، پیام + راه ویرایش می‌دهیم.
			wapmToast(data.message || 'کپی ساخته شد.', published ? 'success' : 'warning', {
				actionLabel: data.edit_url ? 'ویرایش کپی' : null,
				action: data.edit_url ? function () { window.open(data.edit_url, '_blank'); } : null
			});

			if (published) { loadProductList(listState.paged); }
		}).always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '.wapm-action-delete', function () {
		if (!window.confirm(WAPM.i18n.confirm_delete)) { return; }
		var id = $(this).data('id');
		ajax('bulk_action', { bulk_action: 'trash', ids: [id] }).done(function (res) {
			if (res.success) {
				$('#wapm-list-tbody tr[data-row-id="' + id + '"]').remove();
				delete listState.selected[id];
				updateBulkBar();
			} else {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
			}
		});
	});

	/* ---- چک‌باکس‌های bulk و نوار عملیات گروهی ---- */

	function updateSelectAllCheckbox() {
		var $checks = $('#wapm-list-tbody .wapm-row-check');
		var all = $checks.length > 0 && $checks.filter(':checked').length === $checks.length;
		$('#wapm-list-select-all').prop('checked', all);
		syncSelectAllResults();
	}

	/**
	 * دکمه‌ی «انتخاب همه‌ی N نتیجه» فقط وقتی ظاهر می‌شود که همه‌ی ردیف‌های همین
	 * صفحه انتخاب شده باشند و نتیجه‌ی بیشتری در فیلتر باشد. این عمداً «ضمنی»
	 * نیست: کاربر باید صریح بزند تا یک کلیک، عملیات روی ۱۰۰۰ محصول نشود.
	 */
	function syncSelectAllResults() {
		var $btn = $('#wapm-list-select-results');
		var $scope = $('#wapm-list-selected-scope');
		var total = Number(listState.total) || 0;
		var pageRows = $('#wapm-list-tbody .wapm-row-check').length;

		if (listState.allResults) {
			$btn.prop('hidden', true);
			$scope.prop('hidden', false).text('— همه‌ی ' + fmt(total) + ' نتیجه‌ی فیلتر');
			return;
		}

		$scope.prop('hidden', true).text('');

		var pageAll = pageRows > 0 && $('#wapm-list-tbody .wapm-row-check:checked').length === pageRows;
		if (pageAll && total > pageRows) {
			$btn.prop('hidden', false)
				.prop('disabled', false)
				.html('انتخاب همه‌ی <b>' + fmt(total) + '</b> نتیجه');
		} else {
			$btn.prop('hidden', true);
		}
	}

	function updateBulkBar() {
		var count = Object.keys(listState.selected).length;
		$('#wapm-list-selected-count').text(count);
		$('#wapm-list-bulk-bar').toggleClass('is-visible', count > 0);
		syncSelectAllResults();
	}

	/** ردیف‌های انتخاب‌شده را با نوار رنگی مشخص می‌کند (نه فقط چک‌باکس). */
	function markRowSelected($tr, on) {
		$tr.toggleClass('is-selected', !!on);
	}

	$(document).on('change', '.wapm-row-check', function () {
		var id = $(this).data('id');
		var on = $(this).is(':checked');

		// برداشتن یک تیک در حالت «همه‌ی نتایج» یعنی دیگر «همه» نیست؛ برچسب
		// دامنه هم باید همین را بگوید، وگرنه عدد نوار با واقعیت نمی‌خواند.
		if (!on && listState.allResults) { listState.allResults = false; }

		if (on) { listState.selected[id] = true; } else { delete listState.selected[id]; }
		markRowSelected($(this).closest('tr'), on);
		updateBulkBar();
		updateSelectAllCheckbox();
	});

	$('#wapm-list-select-all').on('change', function () {
		var checked = $(this).is(':checked');
		if (!checked) { listState.allResults = false; }
		$('#wapm-list-tbody .wapm-row-check').prop('checked', checked).each(function () {
			var id = $(this).data('id');
			if (checked) { listState.selected[id] = true; } else { delete listState.selected[id]; }
			markRowSelected($(this).closest('tr'), checked);
		});
		updateBulkBar();
	});

	$('#wapm-list-select-results').on('click', function () {
		var $btn = $(this).prop('disabled', true);

		ajax('list_ids', { filters: JSON.stringify(listFilters()), limit: 1000 }).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}

			var ids = res.data.ids || [];
			if (!ids.length) {
				wapmToast('با این فیلتر محصولی پیدا نشد.', 'warning');
				return;
			}

			listState.selected = {};
			ids.forEach(function (id) { listState.selected[id] = true; });
			listState.allResults = true;

			$('#wapm-list-tbody .wapm-row-check').prop('checked', true).each(function () {
				markRowSelected($(this).closest('tr'), true);
			});

			updateBulkBar();
			updateSelectAllCheckbox();

			var msg = fmt(ids.length) + ' محصول انتخاب شد';
			if (res.data.truncated) {
				msg += ' — سقف ۱۰۰۰ محصول. برای بقیه، فیلتر را محدودتر کنید یا از تب «موجودی/قیمت» با فیلتر استفاده کنید.';
			}
			wapmToast(msg, res.data.truncated ? 'warning' : 'info', { ttl: res.data.truncated ? 12000 : 4000 });
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () { $btn.prop('disabled', false); });
	});

	$('#wapm-list-bulk-clear').on('click', function () {
		listState.selected = {};
		listState.allResults = false;
		$('#wapm-list-tbody .wapm-row-check').prop('checked', false);
		$('#wapm-list-select-all').prop('checked', false);
		updateBulkBar();
	});

	$('#wapm-list-bulk-run').on('click', function () {
		var action = $('#wapm-list-bulk-select').val();
		var ids = Object.keys(listState.selected);
		if (!action) { wapmToast('یک عملیات انتخاب کنید.', 'warning'); return; }
		if (!ids.length) { return; }

		var confirmMsg = (action === 'trash')
			? WAPM.i18n.confirm_delete
			: ('این عملیات روی ' + ids.length + ' محصول اجرا می‌شود. ادامه می‌دهید؟');
		if (!window.confirm(confirmMsg)) { return; }

		var payload = { ids: ids };
		if (action === 'trash') {
			payload.bulk_action = 'trash';
		} else if (action.indexOf('stock:') === 0) {
			payload.bulk_action = 'set_stock_status';
			payload.value = action.split(':')[1];
		}

		ajax('bulk_action', payload).done(function (res) {
			if (res.success) {
				wapmToast(res.data.message, 'success');
				listState.selected = {};
				updateBulkBar();
				loadProductList(listState.paged);
			} else {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
			}
		});
	});

	/* ---------------- تغییر قیمت انتخاب‌شده‌ها (کشو) ---------------- */

	var bpState = { rows: [], total: 0, warn: 0, ids: [], batch: '' };

	function bpSettings() {
		return {
			source: 'manual',
			mode: $('#wapm-bp-mode').val(),
			direction: $('#wapm-bp-direction').val(),
			amount: $('#wapm-bp-amount').val(),
			currency_unit: $('#wapm-bp-currency').val(),
			price_target: $('#wapm-bp-target').val(),
			sale_handling: $('#wapm-bp-sale').val(),
			round_mode: $('#wapm-bp-round').val(),
			round_base: $('#wapm-bp-round-base').val(),
			min_price: $('#wapm-bp-min').val(),
			max_price: $('#wapm-bp-max').val(),
			variable_scope: 'variations'
		};
	}

	function bpSyncUi() {
		var mode = $('#wapm-bp-mode').val();
		var absolute = mode === 'absolute';
		var money = mode === 'fixed' || mode === 'absolute';

		$('#wapm-bp-direction-wrap').toggle(!absolute);
		$('#wapm-bp-currency-wrap').toggle(money);
		$('#wapm-bp-amount-label').text(absolute ? 'قیمت هدف (همه)' : 'مقدار');

		var hints = {
			percent: 'درصد از قیمت فعلی هر محصول. مثال: ۱۰٪ کاهش روی ۱٬۰۰۰٬۰۰۰ می‌شود ۹۰۰٬۰۰۰.',
			fixed: 'مبلغ ثابت (به واحد انتخاب‌شده) از قیمت فعلی کم یا به آن اضافه می‌شود.',
			absolute: 'همه‌ی محصولات انتخاب‌شده دقیقاً این قیمت می‌شوند. «جهت» در این حالت معنی ندارد.'
		};
		$('#wapm-bp-mode-hint').text(hints[mode] || '');

		// با تغییر تنظیمات، پیش‌نمایش قبلی دیگر معتبر نیست.
		$('#wapm-bp-apply-btn').prop('disabled', true);
	}

	function bpOpen() {
		var ids = Object.keys(listState.selected || {});
		if (!ids.length) {
			wapmToast('ابتدا در جدول، محصول‌ها را تیک بزنید.', 'warning');
			return;
		}

		bpState.ids = ids;
		bpState.rows = [];
		bpState.total = 0;
		$('#wapm-bp-result').empty();
		$('#wapm-bp-summary').text('');
		$('#wapm-bp-apply-btn').prop('disabled', true);
		var note = fmt(ids.length) + ' محصول انتخاب شده';
		if (ids.length > 300) {
			note += ' — پیش‌نمایش روی ۳۰۰ محصول اول انجام می‌شود، ولی «اعمال» روی همه‌ی ' + fmt(ids.length) + ' محصول اجرا می‌شود.';
		}
		$('#wapm-bp-selected-note').text(note);
		$('#wapm-bp-progress').hide();

		$('#wapm-price-drawer-overlay').addClass('is-open');
		$('#wapm-price-drawer').addClass('is-open').attr('aria-hidden', 'false');
		bpSyncUi();
		window.setTimeout(function () { $('#wapm-bp-amount').trigger('focus').trigger('select'); }, 240);
	}

	function bpClose() {
		$('#wapm-price-drawer-overlay').removeClass('is-open');
		$('#wapm-price-drawer').removeClass('is-open').attr('aria-hidden', 'true');
	}

	$('#wapm-list-bulk-price').on('click', bpOpen);
	$('#wapm-bp-close, #wapm-bp-cancel, #wapm-price-drawer-overlay').on('click', bpClose);
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $('#wapm-price-drawer').hasClass('is-open')) { bpClose(); }
	});

	$('#wapm-bp-mode').on('change', bpSyncUi);

	$('.wapm-preset').on('click', function () {
		var parts = String($(this).data('bp-preset')).split(':');
		$('.wapm-preset').removeClass('is-active');
		$(this).addClass('is-active');

		$('#wapm-bp-mode').val(parts[0]);
		if (parts[1]) { $('#wapm-bp-direction').val(parts[1]); }
		if (parts[0] === 'absolute') {
			$('#wapm-bp-amount').val('').trigger('focus');
		} else {
			$('#wapm-bp-amount').val(parts[2] || '10');
		}
		bpSyncUi();
	});

	$('#wapm-bp-amount, #wapm-bp-direction, #wapm-bp-target, #wapm-bp-sale, #wapm-bp-round, #wapm-bp-round-base, #wapm-bp-min, #wapm-bp-max, #wapm-bp-currency')
		.on('change keyup', function () { $('#wapm-bp-apply-btn').prop('disabled', true); });

	var PRICE_TYPE_LABELS = { regular: 'قیمت اصلی', sale: 'قیمت ویژه' };

	function bpPreviewHtml(data) {
		var rows = data.rows || [];
		if (!rows.length) {
			return '<div class="wapm-empty">هیچ قیمتی تغییر نمی‌کند. مقدار/حالت را عوض کنید یا محصولات دیگری انتخاب کنید.</div>';
		}

		var html = '<table class="wapm-preview-table"><thead><tr>' +
			'<th style="text-align:right">محصول</th><th>نوع</th><th>فعلی</th><th>جدید</th><th>تغییر</th>' +
			'</tr></thead><tbody>';

		rows.forEach(function (r) {
			var removed = (r.new === null || r.new === undefined);
			var cls = removed ? '' : (Number(r.new) < Number(r.old) ? 'wapm-down' : 'wapm-up');
			var label = PRICE_TYPE_LABELS[r.price_type] || r.price_type;
			var delta = removed ? 'حذف' : (Number(r.percent) > 0 ? '+' : '') + Number(r.percent).toFixed(1) + '٪';

			html += '<tr class="' + (r.warn ? 'wapm-warn' : '') + '">' +
				'<td style="text-align:right"><div style="font-weight:800;color:#344054">' + esc(r.name) + '</div>' +
					'<div style="font-size:10px;color:#98a2b3">' + esc(r.sku || '—') + (r.type ? ' · ' + esc(r.type) : '') +
					(r.warn ? ' · <span style="color:#b42318;font-weight:900">افت شدید</span>' : '') + '</div></td>' +
				'<td>' + esc(label) + '</td>' +
				'<td>' + fmt(r.old) + '</td>' +
				'<td>' + (removed ? '—' : fmt(r.new)) + '</td>' +
				'<td class="' + cls + '">' + esc(delta) + '</td>' +
				'</tr>';
		});

		html += '</tbody></table>';

		if (data.truncated) {
			html += '<p class="description" style="margin:8px 0 0">' + fmt(data.rows_total) +
				' تغییر محاسبه شد؛ ۳۰۰ ردیف اول نمایش داده می‌شود (همه اعمال می‌شوند).</p>';
		}

		return html;
	}

	$('#wapm-bp-preview-btn').on('click', function () {
		var settings = bpSettings();
		if (settings.mode !== 'percent' && !(Number(settings.amount) > 0)) {
			wapmToast('مقدار/قیمت هدف را وارد کنید.', 'warning');
			return;
		}

		var $btn = $(this).prop('disabled', true);
		$('#wapm-bp-result').html('<div class="wapm-skeleton" style="height:60px"></div>');
		$('#wapm-bp-summary').text(WAPM.i18n.loading);
		$('#wapm-bp-apply-btn').prop('disabled', true);

		ajax('price_preview_ids', { ids: bpState.ids, settings: JSON.stringify(settings) })
			.done(function (res) {
				if (!res.success) {
					$('#wapm-bp-result').empty();
					$('#wapm-bp-summary').text('');
					wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
					return;
				}

				var d = res.data;
				bpState.rows = d.rows || [];
				bpState.total = Number(d.rows_total) || 0;
				bpState.warn = Number(d.warn) || 0;

				var summary = fmt(bpState.total) + ' تغییر قیمت روی ' + fmt(d.products) + ' محصول';
				if (d.skipped) { summary += ' — ' + fmt(d.skipped) + ' محصول بدون تغییر'; }
				if (bpState.warn) { summary += ' — ' + fmt(bpState.warn) + ' هشدار افت شدید'; }
				$('#wapm-bp-summary').text(summary);

				$('#wapm-bp-result').html(bpPreviewHtml(d));
				$('#wapm-bp-apply-btn').prop('disabled', bpState.total === 0);

				if (bpState.warn) {
					wapmToast('در پیش‌نمایش ' + fmt(bpState.warn) + ' قیمت افت بیش از ۹۰٪ دارد. قبل از اعمال نگاه کنید.', 'warning');
				}
			})
			.fail(function () {
				$('#wapm-bp-result').empty();
				$('#wapm-bp-summary').text('');
				wapmToast(WAPM.i18n.error, 'error');
			})
			.always(function () { $btn.prop('disabled', false); });
	});

	$('#wapm-bp-apply-btn').on('click', function () {
		if (!bpState.total) { return; }
		if (bpState.warn && !window.confirm('در پیش‌نمایش ' + bpState.warn + ' قیمت افت شدید دارد. ادامه می‌دهید؟')) { return; }

		var ids = bpState.ids.slice();
		if (!ids.length) { return; }

		var settings = JSON.stringify(bpSettings());
		var batch = 'bulk_' + Date.now().toString(36);
		var page = 1;
		var processed = 0;
		var changed = 0;

		var $apply = $(this).prop('disabled', true);
		var $other = $('#wapm-bp-preview-btn, #wapm-bp-cancel').prop('disabled', true);
		$('#wapm-bp-progress').show();

		function setProgress(percent, text) {
			$('#wapm-bp-progress .wapm-progress-bar span').css('width', Math.min(100, percent) + '%');
			$('#wapm-bp-progress .wapm-progress-text').text(text);
		}

		setProgress(0, 'شروع…');

		function step() {
			ajax('price_apply_ids', { ids: ids, settings: settings, page: page, batch_id: batch })
				.done(function (res) {
					if (!res.success) {
						setProgress(0, '');
						$('#wapm-bp-progress').hide();
						$apply.prop('disabled', false);
						$other.prop('disabled', false);
						wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
						return;
					}

					processed += Number(res.data.processed) || 0;
					changed += Number(res.data.changed) || 0;

					var percent = (processed / ids.length) * 100;
					setProgress(percent, 'پردازش ' + fmt(processed) + ' از ' + fmt(ids.length) + ' محصول…');

					if (!res.data.done) {
						page++;
						window.setTimeout(step, 250);
						return;
					}

					setProgress(100, 'انجام شد');
					$('#wapm-bp-progress').hide();
					$apply.prop('disabled', false);
					$other.prop('disabled', false);

					bpState.batch = res.data.batch_id;

					wapmToast(
						fmt(changed) + ' قیمت تغییر کرد (Batch ' + res.data.batch_id + ').',
						changed ? 'success' : 'info',
						{
							ttl: 12000,
							actionLabel: 'بازگردانی این تغییر',
							action: function () { bpUndo(res.data.batch_id); }
						}
					);

					// کشو بسته می‌شود تا نتیجه در جدول دیده شود.
					bpClose();
					loadProductList(listState.paged);
				})
				.fail(function () {
					$('#wapm-bp-progress').hide();
					$apply.prop('disabled', false);
					$other.prop('disabled', false);
					wapmToast(WAPM.i18n.error, 'error');
				});
		}

		step();
	});

	/** بازگردانی یک Batch قیمت (همان مسیر امن Rollback با تشخیص تداخل). */
	function bpUndo(batch) {
		ajax('rollback', { batch_id: batch }).done(function (res) {
			var msg = res.success ? res.data.message : ((res.data && res.data.message) || WAPM.i18n.error);
			wapmToast(msg, res.success ? 'success' : 'error', { ttl: 12000 });

			if (res.success && res.data.conflicts && Object.keys(res.data.conflicts).length) {
				var $t = wapmToast('برای بازگردانی اجباری (پاک‌کردن تغییرات بعدی) دوباره بزنید.', 'warning', { ttl: 15000 });
				if ($t) {
					$('<div class="wapm-toast__actions"></div>')
						.append($('<button type="button" class="wapm-toast__btn">بازگردانی اجباری</button>').on('click', function () {
							ajax('rollback', { batch_id: batch, force: 1 }).done(function (r2) {
								wapmToast(r2.success ? r2.data.message : WAPM.i18n.error, r2.success ? 'success' : 'error');
								loadProductList(listState.paged);
							});
						}))
						.appendTo($t.find('.wapm-toast__body'));
				}
			}

			loadProductList(listState.paged);
		});
	}

	/* ---- نمایش/عدم‌نمایش ستون‌ها ---- */

	$('#wapm-list-columns-toggle').on('click', function (e) {
		e.stopPropagation();
		$('#wapm-list-columns-menu').toggle();
	});
	$('#wapm-list-columns-menu').on('click', function (e) { e.stopPropagation(); });
	$(document).on('click', function () { $('#wapm-list-columns-menu').hide(); });

	$(document).on('change', '.wapm-col-toggle', function () {
		var col = $(this).data('col');
		listState.visibleCols[col] = $(this).is(':checked');
		try { window.localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(listState.visibleCols)); } catch (e) { /* localStorage غیرفعال — بی‌اثر */ }
		applyColumnVisibility();
	});

	ALL_COLUMNS.forEach(function (col) {
		$('.wapm-col-toggle[data-col="' + col + '"]').prop('checked', listState.visibleCols[col] !== false);
	});
	applyColumnVisibility();

	/* ---------------- CSV ---------------- */

	$('#wapm-export-btn').on('click', function () {
		var url = WAPM.export_url +
			'&nonce=' + encodeURIComponent(WAPM.nonce) +
			'&filters=' + encodeURIComponent(JSON.stringify(readFilters('main'))) +
			'&settings=' + encodeURIComponent(JSON.stringify(readSettings('main')));
		window.location.href = url;
	});

	// خروجی مقایسه‌ی ترب — با فیلترهای تب لیست (نه فیلترهای تب قیمت).
	$('#wapm-torob-export-btn').on('click', function () {
		var url = WAPM.torob_export_url +
			'&nonce=' + encodeURIComponent(WAPM.nonce) +
			'&filters=' + encodeURIComponent(JSON.stringify(listFilters({ per_page: 0, paged: 0 })));
		window.location.href = url;
	});

	$('#wapm-import-btn').on('click', function () {
		var file = $('#wapm-import-file')[0].files[0];
		if (!file) { wapmToast('فایلی انتخاب نشده است.', 'warning'); return; }

		var fd = new FormData();
		fd.append('action', 'wapm_import_csv');
		fd.append('nonce', WAPM.nonce);
		fd.append('csv_file', file);

		var $btn = $(this).prop('disabled', true);
		$('#wapm-import-result').html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		$.ajax({
			url: WAPM.ajax_url,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false
		}).done(function (res) {
			var msg = res.success ? res.data.message : ((res.data && res.data.message) || WAPM.i18n.error);
			$('#wapm-import-result').html('<div class="notice notice-' + (res.success ? 'success' : 'error') + ' inline"><p>' + esc(msg) + '</p></div>');
		}).fail(function () {
			$('#wapm-import-result').html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	/* ---------------- صادرات ---------------- */

	UI.listState = listState;
	UI.loadColumnPrefs = loadColumnPrefs;
	UI.applyColumnVisibility = applyColumnVisibility;
	UI.stockBadge = stockBadge;
	UI.priceCellHtml = priceCellHtml;
	UI.stockCellHtml = stockCellHtml;
	UI.manageStockToggleHtml = manageStockToggleHtml;
	UI.termsCellHtml = termsCellHtml;
	UI.categoryCellHtml = categoryCellHtml;
	UI.brandCellHtml = brandCellHtml;
	UI.torobAgeLabel = torobAgeLabel;
	UI.torobBadge = torobBadge;
	UI.torobCellHtml = torobCellHtml;
	UI.lastChangeHtml = lastChangeHtml;
	UI.actionsCellHtml = actionsCellHtml;
	UI.rowHtml = rowHtml;
	UI.renderProductList = renderProductList;
	UI.rerenderCurrent = rerenderCurrent;
	UI.skeletonRowsHtml = skeletonRowsHtml;
	UI.listFilters = listFilters;
	UI.loadProductList = loadProductList;
	UI.renderSavedFilters = renderSavedFilters;
	UI.loadSavedFilters = loadSavedFilters;
	UI.applySavedFilter = applySavedFilter;
	UI.openSaveNamePop = openSaveNamePop;
	UI.closeSaveNamePop = closeSaveNamePop;
	UI.updateRowInDom = updateRowInDom;
	UI.saveField = saveField;
	UI.highlightPendingRows = highlightPendingRows;
	UI.updatePendingUi = updatePendingUi;
	UI.applyEdit = applyEdit;
	UI.findRowById = findRowById;
	UI.updateSelectAllCheckbox = updateSelectAllCheckbox;
	UI.syncSelectAllResults = syncSelectAllResults;
	UI.updateBulkBar = updateBulkBar;
	UI.markRowSelected = markRowSelected;
	UI.bpSettings = bpSettings;
	UI.bpSyncUi = bpSyncUi;
	UI.bpOpen = bpOpen;
	UI.bpClose = bpClose;
	UI.bpPreviewHtml = bpPreviewHtml;
	UI.bpUndo = bpUndo;

})(jQuery, window.WAPMUI);
