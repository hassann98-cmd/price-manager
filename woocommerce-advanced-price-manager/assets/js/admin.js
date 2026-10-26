/* global jQuery, WAPM */
/**
 * WooCommerce Advanced Price Manager — Admin JS
 * همه عملیات با Ajax و بدون رفرش صفحه انجام می‌شود.
 */
(function ($) {
	'use strict';

	var initialData = {};
	try {
		initialData = JSON.parse($('#wapm-initial-data').text() || '{}');
	} catch (e) {
		initialData = { rules: [], schedules: [] };
	}

	var state = {
		rules: initialData.rules || [],
		schedules: initialData.schedules || [],
		applying: false,
		previewWarnings: 0
	};

	/* ---------------- ابزار ---------------- */

	function ajax(action, data) {
		return $.post(WAPM.ajax_url, $.extend({ action: 'wapm_' + action, nonce: WAPM.nonce }, data || {}));
	}

	function fmt(n) {
		if (n === null || n === undefined || n === '') { return '—'; }
		return Number(n).toLocaleString('fa-IR');
	}

	/**
	 * escape کامل برای متن *و* مقدار attribute.
	 * نسخه‌ی قبلی از jQuery(.text().html()) استفاده می‌کرد که «"» و «'» را
	 * escape نمی‌کند؛ نتیجه این بود که نام دسته/برند حاوی " مارک‌آپ را می‌شکست.
	 */
	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	/**
	 * اعلان گوشه‌ی صفحه به‌جای window.alert.
	 *
	 * چرا: alert صفحه را قفل می‌کند، پیام قبلی را پاک می‌کند و در عملیات
	 * مرحله‌ای (مثل تغییر گروهی) کاربر نمی‌تواند چیزی را ببیند. این اعلان
	 * بسته می‌شود، دکمه‌ی اقدام دارد (مثل «بازگردانی») و پیام‌ها روی هم می‌مانند.
	 *
	 * @param {string} message
	 * @param {string} type success|error|warning|info
	 * @param {{actionLabel:string, action:Function, ttl:number}=} opts
	 */
	function wapmToast(message, type, opts) {
		opts = opts || {};
		var $box = $('#wapm-toasts');
		if (!$box.length) { window.alert(message); return null; }

		var icons = { success: '✓', error: '!', warning: '⚠', info: 'i' };
		var $toast = $('<div class="wapm-toast wapm-toast--' + esc(type || 'info') + '">' +
			'<span class="wapm-toast__icon">' + (icons[type] || icons.info) + '</span>' +
			'<div class="wapm-toast__body"><div>' + esc(message) + '</div></div>' +
			'</div>');

		function close() {
			$toast.addClass('is-hiding');
			window.setTimeout(function () { $toast.remove(); }, 200);
		}

		if (opts.actionLabel && typeof opts.action === 'function') {
			var $btn = $('<button type="button" class="wapm-toast__btn"></button>').text(opts.actionLabel);
			$btn.on('click', function () { opts.action(); close(); });
			$('<div class="wapm-toast__actions"></div>').append($btn).appendTo($toast.find('.wapm-toast__body'));
		}

		$toast.on('click', function (e) {
			if (!$(e.target).is('button')) { close(); }
		});

		$box.append($toast);
		window.setTimeout(close, opts.ttl || (type === 'error' ? 9000 : 6000));

		return $toast;
	}

	/* ---------------- تب‌ها ---------------- */

	$('.wapm-tabs .nav-tab').on('click', function (e) {
		e.preventDefault();
		$('.wapm-tabs .nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.wapm-tab-content').hide();
		$('#wapm-tab-' + $(this).data('tab')).show();
		if ($(this).data('tab') === 'history') { loadHistory(1); }
		if ($(this).data('tab') === 'list') { loadProductList(listState.paged || 1); }
		if ($(this).data('tab') === 'stock') { loadStockLogs(1); }
	});

	/* ---------------- فیلدهای هدف (مشترک بین تب‌ها) ---------------- */

	function initTargetFields($box) {
		// selectWoo برای جستجوی محصول
		$box.find('.wapm-product-select').selectWoo({
			dir: 'rtl',
			placeholder: WAPM.i18n.select_products,
			minimumInputLength: 2,
			ajax: {
				url: WAPM.ajax_url,
				method: 'POST',
				dataType: 'json',
				delay: 350,
				data: function (params) {
					return { action: 'wapm_search_products', nonce: WAPM.nonce, term: params.term };
				},
				processResults: function (res) {
					return { results: (res && res.success) ? res.data : [] };
				}
			}
		});

		$box.find('.wapm-category-select, .wapm-brand-select, .wapm-attribute-select').selectWoo({ dir: 'rtl', width: '100%' });

		// نمایش ردیف مربوط به هر دامنه
		$box.find('.wapm-scope').on('change', function () {
			var scope = $(this).val();
			$box.find('.wapm-scope-row').hide();
			$box.find('.wapm-scope-' + scope).show();
		});

		// بارگذاری term های برند
		$box.find('.wapm-brand-tax').on('change', function () {
			loadTerms($(this).val(), $box.find('.wapm-brand-select'));
		}).trigger('change');

		// بارگذاری term های ویژگی
		$box.find('.wapm-attribute-tax').on('change', function () {
			var tax = $(this).val();
			if (tax) { loadTerms(tax, $box.find('.wapm-attribute-select')); }
		});

		// نمایش/عدم نمایش واحد پول برای حالت مبلغ ثابت
		var $settings = $box.closest('.wapm-tab-content').find('.wapm-settings-fields[data-prefix="' + $box.data('prefix') + '"]');
		function syncModeUi() {
			var mode = $settings.find('.wapm-mode:checked').val();
			// «مبلغ ثابت» و «قیمت مطلق» هر دو عدد پول می‌گیرند؛ درصد نه.
			var money = (mode === 'fixed' || mode === 'absolute');
			$settings.find('.wapm-currency-unit').toggle(money);
			$settings.find('.wapm-amount-unit-percent').toggle(!money);
			// در قیمت مطلق، «جهت» بی‌معنی است (همه به همان عدد می‌رسند).
			$settings.find('.wapm-direction-row').toggle(mode !== 'absolute');
		}

		$settings.find('.wapm-mode').on('change', syncModeUi);
		syncModeUi();
	}

	/**
	 * بارگذاری termهای یک taxonomy در select.
	 * @param {string} taxonomy
	 * @param {jQuery} $select
	 * @param {Array=} selected شناسه‌های انتخاب‌شده (وقتی می‌خواهیم انتخاب قبلی حفظ شود)
	 */
	function loadTerms(taxonomy, $select, selected) {
		$select.empty();
		if (!taxonomy) { $select.trigger('change'); return $.Deferred().resolve().promise(); }

		return ajax('get_terms', { taxonomy: taxonomy }).done(function (res) {
			if (!res.success) { return; }
			var current = (selected || $select.val() || []).map(String);
			$select.empty();
			res.data.forEach(function (t) {
				$select.append(new Option(t.text, t.id, false, current.indexOf(String(t.id)) !== -1));
			});
			$select.trigger('change');
		});
	}

	$('.wapm-target-fields').each(function () {
		initTargetFields($(this));
	});

	// منبع قیمت (دستی/ترب) — برای هر سه فرم (تغییر قیمت، قانون، زمان‌بندی).
	$(document).on('change', '.wapm-settings-fields .wapm-source, .wapm-settings-fields .wapm-torob-undercut-mode', function () {
		applySourceMode($(this).closest('.wapm-settings-fields'));
	});
	$('.wapm-settings-fields').each(function () { applySourceMode($(this)); });

	/* ---------------- خواندن فیلترها و تنظیمات از فرم ---------------- */

	function readFilters(prefix) {
		var $box = $('.wapm-target-fields[data-prefix="' + prefix + '"]');
		return {
			scope: $box.find('.wapm-scope').val(),
			product_ids: $box.find('.wapm-product-select').val() || [],
			category_ids: $box.find('.wapm-category-select').val() || [],
			brand_tax: $box.find('.wapm-brand-tax').val() || '',
			brand_ids: $box.find('.wapm-brand-select').val() || [],
			attribute_tax: $box.find('.wapm-attribute-tax').val() || '',
			attribute_ids: $box.find('.wapm-attribute-select').val() || [],
			stock_filter: $box.find('.wapm-stock-filter').val(),
			stock_value: $box.find('.wapm-stock-value').val()
		};
	}

	function readSettings(prefix) {
		var $box = $('.wapm-settings-fields[data-prefix="' + prefix + '"]');
		return {
			source: $box.find('.wapm-source').val() || 'manual',
			torob_undercut_mode: $box.find('.wapm-torob-undercut-mode').val() || 'percent',
			torob_undercut: $box.find('.wapm-torob-undercut').val(),
			torob_currency_unit: $box.find('.wapm-torob-currency-unit').val() || 'toman',
			torob_min_gap_pct: $box.find('.wapm-torob-min-gap').val(),
			torob_only_if_pricier: $box.find('.wapm-torob-only-if-pricier').is(':checked') ? 1 : 0,
			torob_max_age: $box.find('.wapm-torob-max-age').val(),
			mode: $box.find('.wapm-mode:checked').val(),
			direction: $box.find('.wapm-direction').val(),
			amount: $box.find('.wapm-amount').val(),
			currency_unit: $box.find('.wapm-currency-unit').val(),
			price_target: $box.find('.wapm-price-target').val(),
			sale_handling: $box.find('.wapm-sale-handling').val(),
			variable_scope: $box.find('.wapm-variable-scope').val(),
			round_mode: $box.find('.wapm-round-mode').val(),
			round_base: $box.find('.wapm-round-base').val(),
			min_price: $box.find('.wapm-min-price').val(),
			max_price: $box.find('.wapm-max-price').val()
		};
	}

	/**
	 * پر کردن فرم با فیلترهای ذخیره‌شده.
	 *
	 * نسخه‌ی قبلی product_ids / brand_ids / attribute_ids را برنمی‌گرداند؛ نتیجه این بود
	 * که ویرایش یک قانون محدود، آن را به «همه‌ی محصولات» تبدیل می‌کرد (چون readFilters
	 * آرایه‌ی خالی می‌خواند و ذخیره می‌شد).
	 */
	function writeFilters(prefix, f) {
		var $box = $('.wapm-target-fields[data-prefix="' + prefix + '"]');
		f = f || {};

		$box.find('.wapm-scope').val(f.scope || 'all').trigger('change');

		// محصولات انتخابی: انتخاب‌های قبلی پاک شوند تا با قانون بعدی قاطی نشوند.
		var $products = $box.find('.wapm-product-select');
		$products.val(null).trigger('change');
		if ((f.product_ids || []).length) {
			ajax('get_products', { ids: f.product_ids }).done(function (res) {
				if (!res.success || !res.data.length) { return; }
				$products.empty();
				res.data.forEach(function (p) { $products.append(new Option(p.text, p.id, false, true)); });
				$products.trigger('change');
			});
		}

		$box.find('.wapm-category-select').val((f.category_ids || []).map(String)).trigger('change');

		// برند: val() بدون trigger تا هندلر change لیست را دوباره (بدون انتخاب) نسازد.
		var $brandTax = $box.find('.wapm-brand-tax');
		if (f.brand_tax) { $brandTax.val(f.brand_tax); }
		loadTerms($brandTax.val(), $box.find('.wapm-brand-select'), f.brand_ids || []);

		var $attrTax = $box.find('.wapm-attribute-tax');
		$attrTax.val(f.attribute_tax || '');
		loadTerms($attrTax.val(), $box.find('.wapm-attribute-select'), f.attribute_ids || []);

		$box.find('.wapm-stock-filter').val(f.stock_filter || 'none');
		$box.find('.wapm-stock-value').val(f.stock_value || 0);
	}

	function writeSettings(prefix, s) {
		var $box = $('.wapm-settings-fields[data-prefix="' + prefix + '"]');
		$box.find('.wapm-mode[value="' + (s.mode || 'percent') + '"]').prop('checked', true).trigger('change');
		$box.find('.wapm-direction').val(s.direction || 'increase');
		$box.find('.wapm-amount').val(s.amount || 0);
		$box.find('.wapm-currency-unit').val(s.currency_unit || 'toman');
		$box.find('.wapm-price-target').val(s.price_target || 'regular');
		$box.find('.wapm-sale-handling').val(s.sale_handling || 'independent');
		// «فقط قیمت والد» دیگر در فرم نیست. اگر مورد ذخیره‌شده با آن ساخته شده،
		// به کاربر هشدار می‌دهیم و مقدار را روی variations می‌گذاریم تا ذخیره‌ی
		// دوباره، آن را اصلاح کند (بدون اینکه بی‌صدا تغییر کند).
		var $scopeInput = $box.find('.wapm-variable-scope');
		$box.find('.wapm-scope-legacy-note').remove();
		$scopeInput.val('variations');
		if (s.variable_scope === 'parent') {
			$scopeInput.after('<p class="description wapm-scope-legacy-note" style="color:#996800;font-weight:600">' +
				'⚠ این مورد قبلاً با گزینه‌ی «فقط قیمت والد» ذخیره شده بود که روی قیمت فروش اثری نداشت. ' +
				'با ذخیره‌ی دوباره، تغییر روی واریشن‌ها اعمال می‌شود.</p>');
		}

		$box.find('.wapm-round-mode').val(s.round_mode || 'none');
		$box.find('.wapm-round-base').val(s.round_base || 1000);
		$box.find('.wapm-min-price').val(s.min_price || 0);
		$box.find('.wapm-max-price').val(s.max_price || 0);

		$box.find('.wapm-source').val(s.source || 'manual');
		$box.find('.wapm-torob-undercut-mode').val(s.torob_undercut_mode || 'percent');
		$box.find('.wapm-torob-undercut').val(
			(s.torob_undercut === undefined || s.torob_undercut === null) ? 1 : s.torob_undercut
		);
		$box.find('.wapm-torob-currency-unit').val(s.torob_currency_unit || 'toman');
		$box.find('.wapm-torob-min-gap').val((s.torob_min_gap_pct === undefined || s.torob_min_gap_pct === null) ? 1 : s.torob_min_gap_pct);
		$box.find('.wapm-torob-only-if-pricier').prop('checked', s.torob_only_if_pricier === undefined ? true : !!Number(s.torob_only_if_pricier));
		$box.find('.wapm-torob-max-age').val(s.torob_max_age || 0);
		applySourceMode($box);
	}

	/**
	 * نمایش/پنهان‌کردن فیلدها بر اساس منبع قیمت.
	 * در حالت ترب، «نوع تغییر/جهت/مقدار/قیمت هدف/Sale» معنایی ندارند و پنهان می‌شوند
	 * تا کاربر تنظیماتی را که اعمال نمی‌شود تغییر ندهد.
	 */
	function applySourceMode($box) {
		var isTorob = ($box.find('.wapm-source').val() === 'torob');
		$box.find('.wapm-torob-only').toggle(isTorob);
		$box.find('.wapm-manual-only').toggle(!isTorob);

		var isFixed = ($box.find('.wapm-torob-undercut-mode').val() === 'fixed');
		$box.find('.wapm-torob-currency-unit').toggle(isTorob && isFixed);
	}

	/* ---------------- شمارش ---------------- */

	$('#wapm-count-btn').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		$('#wapm-count-result').text(WAPM.i18n.loading);
		ajax('count_targets', { filters: JSON.stringify(readFilters('main')) })
			.done(function (res) {
				$('#wapm-count-result').text(res.success ? fmt(res.data.count) + ' محصول' : WAPM.i18n.error);
			})
			.fail(function () { $('#wapm-count-result').text(WAPM.i18n.error); })
			.always(function () { $btn.prop('disabled', false); });
	});

	/* ---------------- پیش‌نمایش ---------------- */

	function renderPreview(data) {
		// هشدارهای «افت شدید» — همان چیزی که در سمت سرور با warn علامت خورده است.
		var warnRows = data.rows.filter(function (r) { return !!r.warn; });
		state.previewWarnings = warnRows.length;

		var html = '';
		if (warnRows.length) {
			html += '<div class="notice notice-warning inline" style="margin:8px 0"><p><strong>⚠ ' + fmt(warnRows.length) +
				' قیمت افت بیش از ۹۰٪ دارد</strong> (یا به کف مجاز رسیده است). قبل از اعمال، ردیف‌های علامت‌خورده را بررسی کنید. ' +
				'کاهش قیمت هرگز محصول را رایگان نمی‌کند.</p></div>';
		}
		html += '<p><strong>' + fmt(data.total) + '</strong> محصول هدف — صفحه ' + fmt(data.paged) + ' از ' + fmt(data.pages) + '</p>';
		html += '<table class="widefat striped wapm-preview-table"><thead><tr>' +
			'<th></th><th>محصول</th><th>SKU</th><th>نوع</th><th>نوع قیمت</th>' +
			'<th>قیمت فعلی</th><th>قیمت جدید</th><th>تغییر</th><th>درصد</th></tr></thead><tbody>';

		if (!data.rows.length) {
			html += '<tr><td colspan="9">' + esc(WAPM.i18n.no_targets) + '</td></tr>';
		}

		data.rows.forEach(function (r) {
			var cls = (r.new !== null && r.new >= r.old) ? 'wapm-up' : 'wapm-down';
			var sign = r.percent > 0 ? '+' : '';
			html += '<tr' + (r.warn ? ' class="wapm-warn"' : '') + '>' +
				'<td><img src="' + esc(r.image) + '" alt=""></td>' +
				'<td>' + esc(r.name) + '</td>' +
				'<td>' + esc(r.sku || '—') + '</td>' +
				'<td>' + esc(r.type) + '</td>' +
				'<td>' + (r.price_type === 'sale' ? WAPM.i18n.sale : WAPM.i18n.regular) + '</td>' +
				'<td>' + fmt(r.old) + '</td>' +
				'<td class="' + cls + '">' + (r.warn ? '⚠ ' : '') + (r.new === null ? 'حذف' : fmt(r.new)) + '</td>' +
				'<td class="' + cls + '">' + fmt(r.diff) + '</td>' +
				'<td class="' + cls + '">' + (r.new === null ? '—' : sign + r.percent + '%') + '</td>' +
				'</tr>';
		});
		html += '</tbody></table>';

		// صفحه‌بندی پیش‌نمایش
		if (data.pages > 1) {
			html += '<p class="wapm-pagination">';
			if (data.paged > 1) { html += '<button type="button" class="button wapm-preview-page" data-page="' + (data.paged - 1) + '">« قبلی</button>'; }
			if (data.paged < data.pages) { html += '<button type="button" class="button wapm-preview-page" data-page="' + (data.paged + 1) + '">بعدی »</button>'; }
			html += '</p>';
		}

		$('#wapm-preview-area').html(html);
		$('#wapm-apply-btn').prop('disabled', data.total === 0);
	}

	function loadPreview(paged) {
		state.previewWarnings = 0;
		$('#wapm-dryrun-area').hide();
		$('#wapm-preview-area').html('<p>' + esc(WAPM.i18n.loading) + '</p>');
		ajax('preview', {
			filters: JSON.stringify(readFilters('main')),
			settings: JSON.stringify(readSettings('main')),
			paged: paged || 1
		}).done(function (res) {
			if (res.success) { renderPreview(res.data); }
			else { $('#wapm-preview-area').html('<p>' + esc((res.data && res.data.message) || WAPM.i18n.error) + '</p>'); }
		}).fail(function () {
			$('#wapm-preview-area').html('<p>' + esc(WAPM.i18n.error) + '</p>');
		});
	}

	$('#wapm-preview-btn').on('click', function () { loadPreview(1); });
	$(document).on('click', '.wapm-preview-page', function () { loadPreview(parseInt($(this).data('page'), 10)); });

	/* ---------------- Dry Run ---------------- */

	$('#wapm-dryrun-btn').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		var totals = { products: 0, variations: 0, price_points: 0, sum_diff: 0, max_increase: 0, max_decrease: 0, flagged: 0 };
		var filters = JSON.stringify(readFilters('main'));
		var settings = JSON.stringify(readSettings('main'));

		$('#wapm-preview-area').empty();
		$('#wapm-dryrun-area').show().html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		function step(paged, attempt) {
			attempt = attempt || 0;
			ajax('dry_run', { filters: filters, settings: settings, paged: paged }).done(function (res) {
				if (!res.success) {
					$('#wapm-dryrun-area').html('<p>' + esc(WAPM.i18n.error) + '</p>');
					$btn.prop('disabled', false);
					return;
				}
				var s = res.data.stats;
				totals.products += s.products;
				totals.variations += s.variations;
				totals.price_points += s.price_points;
				totals.sum_diff += s.sum_diff;
				totals.flagged += (s.flagged || 0);
				totals.max_increase = Math.max(totals.max_increase, s.max_increase);
				totals.max_decrease = Math.min(totals.max_decrease, s.max_decrease);

				if (!res.data.done) {
					setTimeout(function () { step(paged + 1, 0); }, 400);
					return;
				}

				state.previewWarnings = totals.flagged;

				var html = '<h3>گزارش Dry Run (هیچ تغییری ذخیره نشد)</h3><div class="wapm-stats">' +
					'<div class="wapm-stat"><b>' + fmt(totals.products) + '</b><small>محصول</small></div>' +
					'<div class="wapm-stat"><b>' + fmt(totals.variations) + '</b><small>واریشن</small></div>' +
					'<div class="wapm-stat"><b>' + fmt(totals.price_points) + '</b><small>قیمت قابل تغییر</small></div>' +
					'<div class="wapm-stat"><b>' + fmt(Math.round(totals.sum_diff)) + '</b><small>مجموع تغییر قیمت</small></div>' +
					'<div class="wapm-stat"><b>' + fmt(Math.round(totals.max_increase)) + '</b><small>بیشترین افزایش</small></div>' +
					'<div class="wapm-stat"><b>' + fmt(Math.round(totals.max_decrease)) + '</b><small>بیشترین کاهش</small></div>' +
					(totals.flagged ? '<div class="wapm-stat" style="background:#fcf9e8;border-color:#f5e6ab"><b style="color:#996800">' + fmt(totals.flagged) + '</b><small>⚠ افت بیش از ۹۰٪</small></div>' : '') +
					'</div>';
				if (totals.flagged) {
					html += '<div class="notice notice-warning inline"><p>⚠ ' + fmt(totals.flagged) +
						' قیمت افت بیش از ۹۰٪ دارد. کاهش قیمت هرگز محصول را رایگان نمی‌کند، اما این تعداد قیمت پرخطر است — تنظیمات را بازبینی کنید.</p></div>';
				}
				$('#wapm-dryrun-area').html(html);
				$btn.prop('disabled', false);
			}).fail(function () {
				if (attempt < 3) {
					setTimeout(function () { step(paged, attempt + 1); }, 2000 * (attempt + 1));
					return;
				}
				$('#wapm-dryrun-area').html('<p>' + esc(WAPM.i18n.error) + '</p>');
				$btn.prop('disabled', false);
			});
		}
		step(1, 0);
	});

	/* ---------------- اعمال Batch با نوار پیشرفت ---------------- */

	function runBatchLoop(opts) {
		// opts: { action, extra, $progress, onDone }
		var processed = 0;
		var batchId = '';
		var BATCH_DELAY = 600;          // مکث بین درخواست‌ها برای رد نشدن از Rate Limit هاست
		var RETRY_DELAYS = [2000, 5000, 12000]; // تلاش مجدد با تاخیر افزایشی

		opts.$progress.show().find('span').css('width', '0%');
		opts.$progress.find('.wapm-progress-text').text(WAPM.i18n.loading);

		function step(paged, attempt) {
			attempt = attempt || 0;
			ajax(opts.action, $.extend({ paged: paged, batch_id: batchId }, opts.extra)).done(function (res) {
				if (!res.success) {
					opts.$progress.find('.wapm-progress-text').text((res.data && res.data.message) || WAPM.i18n.error);
					state.applying = false;
					return;
				}
				var d = res.data;
				batchId = d.batch_id || batchId;
				processed += d.processed;
				var pct = d.total > 0 ? Math.min(100, Math.round((processed / d.total) * 100)) : 100;
				opts.$progress.find('.wapm-progress-bar span').css('width', pct + '%');
				opts.$progress.find('.wapm-progress-text').text(fmt(processed) + ' از ' + fmt(d.total) + ' محصول (' + pct + '%)');

				if (!d.done) {
					// مکث کوتاه بین Batch ها تا فایروال/LiteSpeed درخواست‌ها را رد نکند.
					setTimeout(function () { step(paged + 1, 0); }, BATCH_DELAY);
				} else {
					opts.$progress.find('.wapm-progress-bar span').css('width', '100%');
					opts.$progress.find('.wapm-progress-text').text(WAPM.i18n.done + ' — ' + fmt(processed) + ' محصول پردازش شد. Batch: ' + batchId);
					state.applying = false;
					if (opts.onDone) { opts.onDone(batchId); }
				}
			}).fail(function () {
				// قطع اتصال یا خطای سرور — تلاش مجدد همان صفحه با تاخیر افزایشی.
				// سمت سرور idempotent است: محصولات لاگ‌شده در همین Batch دوباره اعمال نمی‌شوند.
				if (attempt < RETRY_DELAYS.length) {
					var wait = RETRY_DELAYS[attempt];
					opts.$progress.find('.wapm-progress-text').text(
						WAPM.i18n.retrying.replace('%1$d', attempt + 1).replace('%2$d', Math.round(wait / 1000))
					);
					setTimeout(function () { step(paged, attempt + 1); }, wait);
				} else {
					opts.$progress.find('.wapm-progress-text').text(
						WAPM.i18n.error + ' (Batch: ' + (batchId || '—') + ') — ' + WAPM.i18n.resume_hint
					);
					state.applying = false;
				}
			});
		}
		step(1, 0);
	}

	$('#wapm-apply-btn').on('click', function () {
		if (state.applying) { return; }
		var filters = readFilters('main');
		var settings = readSettings('main');

		ajax('count_targets', { filters: JSON.stringify(filters) }).done(function (res) {
			var count = res.success ? res.data.count : 0;
				if (!count) { wapmToast(WAPM.i18n.no_targets, 'warning'); return; }
				if (!window.confirm(WAPM.i18n.confirm_apply.replace('%d', count))) { return; }
				// اگر در پیش‌نمایش، قیمت‌های پرخطر (افت بیش از ۹۰٪) دیده شده، یک تایید
				// صریح دیگر بگیر تا اعمال، ناخواسته نباشد.
				if (state.previewWarnings > 0 && !window.confirm(WAPM.i18n.confirm_warn.replace('%d', state.previewWarnings))) { return; }

			state.applying = true;
			runBatchLoop({
				action: 'apply_batch',
				extra: { filters: JSON.stringify(filters), settings: JSON.stringify(settings) },
				$progress: $('#wapm-progress'),
				onDone: function () { $('#wapm-preview-area').empty(); }
			});
		});
	});

	/* ---------------- Rollback آخرین عملیات ---------------- */

	/**
	 * بازگردانی با تشخیص تداخل.
	 * اگر قیمت بعضی ردیف‌ها بعد از آن Batch عوض شده باشد، سرور آن‌ها را دست‌نخورده
	 * می‌گذارد و تعدادشان را برمی‌گرداند؛ کاربر می‌تواند صریحاً «بازگردانی اجباری»
	 * را انتخاب کند (وگرنه تغییرات جدیدتر پاک می‌شدند).
	 */
	function doRollback(batchId, force, $btn) {
		if ($btn) { $btn.prop('disabled', true); }

		return ajax('rollback', { batch_id: batchId, force: force ? 1 : 0 })
			.done(function (res) {
				if (!res.success) {
					wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
					return;
				}
				wapmToast(res.data.message, 'success');

				var conflicts = res.data.conflicts || [];
				if (conflicts.length && !force) {
					if (window.confirm(conflicts.length + ' مورد بعد از این عملیات تغییر کرده و بازگردانی نشد.\n' +
						'بازگردانی اجباری انجام شود؟ (تغییرات جدیدترِ این موارد پاک می‌شوند)')) {
						doRollback(res.data.batch_id || batchId, true, null);
						return;
					}
				}
				if ($('#wapm-tab-history').is(':visible')) { loadHistory(1); }
			})
			.fail(function () { wapmToast(WAPM.i18n.error, 'error'); })
			.always(function () { if ($btn) { $btn.prop('disabled', false); } });
	}

	$('#wapm-rollback-last-btn').on('click', function () {
		if (!window.confirm(WAPM.i18n.confirm_rollback)) { return; }
		doRollback('last', false, $(this));
	});

	$(document).on('click', '.wapm-rollback-batch', function () {
		if (!window.confirm(WAPM.i18n.confirm_rollback)) { return; }
		doRollback($(this).data('batch'), false, $(this));
	});

	/* ---------------- قوانین ---------------- */

	/**
	 * هشدار برای موارد ذخیره‌شده‌ای که با گزینه‌ی حذف‌شده‌ی «فقط قیمت والد» ساخته شده‌اند.
	 * آن گزینه در ووکامرس اثری روی قیمت فروش نداشت، پس این موارد باید بازبینی شوند.
	 */
	function legacyScopeWarning(settings) {
		if (settings && settings.variable_scope === 'parent') {
			return ' <span class="wapm-badge wapm-badge-pending" title="این مورد با گزینه‌ی «فقط قیمت والد» ذخیره شده است؛ در ووکامرس قیمت فروش از واریشن‌ها می‌آید و آن گزینه اثری نداشت. آن را ویرایش و دوباره ذخیره کنید.">⚠ قیمت والد (بی‌اثر)</span>';
		}
		return '';
	}


	function renderRules() {
		var $tbody = $('#wapm-rules-table tbody').empty();
		if (!state.rules.length) {
			$tbody.append('<tr><td colspan="4">—</td></tr>');
			return;
		}
		state.rules.forEach(function (r) {
			var badge = r.enabled
				? '<span class="wapm-badge wapm-badge-on">' + WAPM.i18n.enabled + '</span>'
				: '<span class="wapm-badge wapm-badge-off">' + WAPM.i18n.disabled + '</span>';
			var dates = (r.start || r.end) ? '<br><small>' + esc(r.start || '…') + ' ← ' + esc(r.end || '…') + '</small>' : '';
			$tbody.append('<tr data-id="' + esc(r.id) + '">' +
				'<td>' + esc(r.title || r.id) + legacyScopeWarning(r.settings) + dates + '</td>' +
				'<td>' + esc(r.priority) + '</td>' +
				'<td>' + badge + '</td>' +
				'<td>' +
				'<button type="button" class="button button-small wapm-rule-run">' + WAPM.i18n.run + '</button> ' +
				'<button type="button" class="button button-small wapm-rule-toggle" data-enabled="' + (r.enabled ? 1 : 0) + '">' + (r.enabled ? WAPM.i18n.disabled : WAPM.i18n.enabled) + '</button> ' +
				'<button type="button" class="button button-small wapm-rule-edit">' + WAPM.i18n.edit + '</button> ' +
				'<button type="button" class="button button-small wapm-danger wapm-rule-delete">' + WAPM.i18n.delete + '</button>' +
				'</td></tr>');
		});
	}

	$('#wapm-save-rule-btn').on('click', function () {
		var rule = {
			id: $('#wapm-rule-id').val(),
			title: $('#wapm-rule-title').val(),
			priority: $('#wapm-rule-priority').val(),
			start: $('#wapm-rule-start').val(),
			end: $('#wapm-rule-end').val(),
			// قبلاً همیشه true بود؛ نتیجه: ویرایش یک قانون غیرفعال، آن را بی‌صدا فعال می‌کرد.
			enabled: $('#wapm-rule-enabled').is(':checked'),
			filters: readFilters('rule'),
			settings: readSettings('rule')
		};
		ajax('save_rule', { rule: JSON.stringify(rule) }).done(function (res) {
			if (res.success) {
				state.rules = res.data.rules;
				renderRules();
				$('#wapm-rule-id').val('');
				$('#wapm-rule-title').val('');
				$('#wapm-rule-enabled').prop('checked', true);
				wapmToast(WAPM.i18n.done, 'success');
			} else {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
			}
		});
	});

	$(document).on('click', '.wapm-rule-delete', function () {
		if (!window.confirm(WAPM.i18n.confirm_delete)) { return; }
		var id = $(this).closest('tr').data('id');
		ajax('delete_rule', { rule_id: id }).done(function (res) {
			if (res.success) { state.rules = res.data.rules; renderRules(); }
		});
	});

	$(document).on('click', '.wapm-rule-toggle', function () {
		var id = $(this).closest('tr').data('id');
		var enable = $(this).data('enabled') ? 0 : 1;
		ajax('toggle_rule', { rule_id: id, enabled: enable }).done(function (res) {
			if (res.success) { state.rules = res.data.rules; renderRules(); }
		});
	});

	$(document).on('click', '.wapm-rule-edit', function () {
		var id = $(this).closest('tr').data('id');
		var rule = state.rules.find(function (r) { return r.id === id; });
		if (!rule) { return; }
		$('#wapm-rule-id').val(rule.id);
		$('#wapm-rule-title').val(rule.title);
		$('#wapm-rule-priority').val(rule.priority);
		$('#wapm-rule-start').val(rule.start || '');
		$('#wapm-rule-end').val(rule.end || '');
		$('#wapm-rule-enabled').prop('checked', !!rule.enabled);
		writeFilters('rule', rule.filters || {});
		writeSettings('rule', rule.settings || {});
		$('html, body').animate({ scrollTop: $('#wapm-tab-rules').offset().top - 60 }, 300);
	});

	$(document).on('click', '.wapm-rule-run', function () {
		if (state.applying) { return; }
		var id = $(this).closest('tr').data('id');
		if (!window.confirm(WAPM.i18n.confirm_apply.replace('%d', '?'))) { return; }
		state.applying = true;
		runBatchLoop({
			action: 'run_rule',
			extra: { rule_id: id },
			$progress: $('#wapm-progress-rule')
		});
	});

	/* ---------------- زمان‌بندی ---------------- */

	function renderSchedules() {
		var $tbody = $('#wapm-schedules-table tbody').empty();
		if (!state.schedules.length) {
			$tbody.append('<tr><td colspan="5">—</td></tr>');
			return;
		}
		var statusMap = { pending: WAPM.i18n.pending, running: WAPM.i18n.running, applied: WAPM.i18n.applied, reverting: WAPM.i18n.reverting, reverted: WAPM.i18n.reverted };
		var badgeCls = { pending: 'wapm-badge-pending', running: 'wapm-badge-pending', applied: 'wapm-badge-on', reverting: 'wapm-badge-pending', reverted: 'wapm-badge-off' };
		state.schedules.forEach(function (s) {
			$tbody.append('<tr data-id="' + esc(s.id) + '">' +
				'<td>' + esc(s.title || s.id) + legacyScopeWarning(s.settings) + '</td>' +
				'<td>' + esc(s.start || '—') + '</td>' +
				'<td>' + esc(s.end || '—') + '</td>' +
				'<td><span class="wapm-badge ' + (badgeCls[s.status] || '') + '">' + (statusMap[s.status] || s.status) + '</span></td>' +
				'<td><button type="button" class="button button-small wapm-danger wapm-schedule-delete">' + WAPM.i18n.delete + '</button></td>' +
				'</tr>');
		});
	}

	$('#wapm-save-schedule-btn').on('click', function () {
		var schedule = {
			id: $('#wapm-schedule-id').val(),
			title: $('#wapm-schedule-title').val(),
			start: ($('#wapm-schedule-start').val() || '').replace('T', ' '),
			end: ($('#wapm-schedule-end').val() || '').replace('T', ' '),
			filters: readFilters('schedule'),
			settings: readSettings('schedule')
		};
		if (!schedule.start) { wapmToast('تاریخ شروع الزامی است.', 'warning'); return; }
		ajax('save_schedule', { schedule: JSON.stringify(schedule) }).done(function (res) {
			if (res.success) {
				state.schedules = res.data.schedules;
				renderSchedules();
				$('#wapm-schedule-id').val('');
				$('#wapm-schedule-title').val('');
				wapmToast(WAPM.i18n.done, 'success');
			} else {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
			}
		});
	});

	$(document).on('click', '.wapm-schedule-delete', function () {
		if (!window.confirm(WAPM.i18n.confirm_delete)) { return; }
		var id = $(this).closest('tr').data('id');
		ajax('delete_schedule', { schedule_id: id }).done(function (res) {
			if (res.success) { state.schedules = res.data.schedules; renderSchedules(); }
		});
	});

	/* ---------------- تاریخچه ---------------- */

	function loadHistory(paged) {
		var $tbody = $('#wapm-history-table tbody').html('<tr><td colspan="9">' + esc(WAPM.i18n.loading) + '</td></tr>');
		ajax('get_logs', { paged: paged }).done(function (res) {
			if (!res.success) { $tbody.html('<tr><td colspan="9">' + esc(WAPM.i18n.error) + '</td></tr>'); return; }
			$tbody.empty();
			if (!res.data.rows.length) {
				$tbody.append('<tr><td colspan="9">—</td></tr>');
			}
			res.data.rows.forEach(function (r) {
				$tbody.append('<tr>' +
					'<td>' + esc(r.product_name) + '</td>' +
					'<td>' + esc(r.sku || '—') + '</td>' +
					'<td>' + (r.price_type === 'sale' ? WAPM.i18n.sale : WAPM.i18n.regular) + '</td>' +
					'<td>' + fmt(r.old_price) + '</td>' +
					'<td>' + fmt(r.new_price) + '</td>' +
					'<td>' + esc(r.user_name) + '</td>' +
					'<td>' + esc(r.date_i18n) + '</td>' +
					'<td>' + esc(r.operation) + '</td>' +
					'<td><button type="button" class="button button-small wapm-rollback-batch" data-batch="' + esc(r.batch_id) + '" ' + (Number(r.rolled_back) ? 'disabled' : '') + '>Rollback</button></td>' +
					'</tr>');
			});

			var pages = Math.ceil(res.data.total / 25);
			var $pg = $('#wapm-history-pagination').empty();
			if (pages > 1) {
				if (paged > 1) { $pg.append('<button type="button" class="button wapm-history-page" data-page="' + (paged - 1) + '">« قبلی</button> '); }
				$pg.append('<span> صفحه ' + fmt(paged) + ' از ' + fmt(pages) + ' </span>');
				if (paged < pages) { $pg.append(' <button type="button" class="button wapm-history-page" data-page="' + (paged + 1) + '">بعدی »</button>'); }
			}
		});
	}

	$(document).on('click', '.wapm-history-page', function () {
		loadHistory(parseInt($(this).data('page'), 10));
	});

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
		if (!data.rows.length) {
			return '<div class="wapm-empty">هیچ قیمتی تغییر نمی‌کند. مقدار/حالت را عوض کنید یا محصولات دیگری انتخاب کنید.</div>';
		}

		var html = '<table class="wapm-preview-table"><thead><tr>' +
			'<th style="text-align:right">محصول</th><th>نوع</th><th>فعلی</th><th>جدید</th><th>تغییر</th>' +
			'</tr></thead><tbody>';

		data.rows.forEach(function (r) {
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

	/* ---------------- تب موجودی ---------------- */

	var stockState = { paged: 1, lastBatch: '', previewed: 0 };

	/** شناسه‌های انتخاب‌شده در تب لیست (منبع اصلی عملیات گروهی موجودی). */
	function stockSelectedIds() {
		return Object.keys(listState.selected || {});
	}

	function stockOperation() {
		return {
			mode: $('#wapm-stock-mode').val(),
			unit: $('#wapm-stock-unit').val(),
			amount: $('#wapm-stock-amount').val()
		};
	}

	function stockTargetsPayload() {
		return $.extend(stockOperation(), {
			variations: $('#wapm-stock-variations').is(':checked') ? 1 : 0,
			ids: stockSelectedIds(),
			filters: JSON.stringify(readFilters('main')),
			paged: 1,
			per_page: 50
		});
	}

	/* «تنظیم» با درصد معنی ندارد — همان‌جا در UI هم قفل می‌شود. */
	function syncStockUnit() {
		var isSet = $('#wapm-stock-mode').val() === 'set';
		$('#wapm-stock-unit').val(isSet ? 'count' : $('#wapm-stock-unit').val()).prop('disabled', isSet);
	}

	$('#wapm-stock-mode').on('change', syncStockUnit);
	syncStockUnit();

	function stockReasons(reasons) {
		if (!reasons) { return ''; }
		var parts = [];
		Object.keys(reasons).forEach(function (key) {
			parts.push(stockReasonLabel(key) + ' (' + fmt(reasons[key]) + ')');
		});
		return parts.join('، ');
	}

	function stockReasonLabel(key) {
		var map = {
			not_found: 'محصول پیدا نشد',
			variable_parent: 'محصول متغیر — موجودی روی واریشن‌هاست',
			not_managed: 'مدیریت انبار خاموش است',
			no_change: 'تغییری لازم نیست',
			conflict: 'بعد از این عملیات تغییر کرده',
			save_failed: 'ذخیره نشد',
			invalid: 'مقدار نامعتبر',
			managed_status: 'وضعیت از تعداد موجودی می‌آید'
		};
		return map[key] || key;
	}

	function stockRowsTable(rows) {
		if (!rows || !rows.length) {
			return '<p class="description">هیچ محصولی تغییر نمی‌کند.</p>';
		}
		var html = '<table class="widefat striped"><thead><tr>' +
			'<th>محصول</th><th>SKU</th><th>موجودی فعلی</th><th>موجودی جدید</th><th>تفاوت</th>' +
			'</tr></thead><tbody>';
		rows.forEach(function (r) {
			html += '<tr>' +
				'<td>' + esc(r.name) + '</td>' +
				'<td>' + esc(r.sku || '—') + '</td>' +
				'<td>' + fmt(r.old) + '</td>' +
				'<td>' + fmt(r.new) + '</td>' +
				'<td>' + (Number(r.delta) > 0 ? '+' : '') + fmt(r.delta) + '</td>' +
				'</tr>';
		});
		return html + '</tbody></table>';
	}

	$('#wapm-stock-preview-btn').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		$('#wapm-stock-result').html('<p>' + esc(WAPM.i18n.loading) + '</p>');
		$('#wapm-stock-apply-btn').prop('disabled', true);

		ajax('stock_preview', stockTargetsPayload()).done(function (res) {
			if (!res.success) {
				$('#wapm-stock-result').html('<div class="notice notice-error inline"><p>' + esc((res.data && res.data.message) || WAPM.i18n.error) + '</p></div>');
				return;
			}

			var d = res.data;
			stockState.previewed = Number(d.will) || 0;

			// شناسه‌های همان پیش‌نمایش را نگه می‌داریم تا «اعمال» دقیقاً روی همان‌ها
			// اجرا شود، نه روی لیستی که ممکن است در این فاصله عوض شده باشد.
			$('#wapm-stock-result').data('ids', d.ids || []);

			var summary = 'روی ' + fmt(stockState.previewed) + ' محصول اثر دارد';
			if (d.total) { summary += ' — مجموع هدف: ' + fmt(d.total) + ' محصول'; }
			if (d.skipped && Object.keys(d.skipped).length) { summary += ' — رد‌شده: ' + stockReasons(d.skipped); }
			$('#wapm-stock-summary').text(summary);

			var html = stockRowsTable(d.rows);
			if (stockState.previewed > 50) {
				html += '<p class="description">فقط ۵۰ محصول اول اعمال می‌شود؛ برای بقیه دوباره اجرا کنید.</p>';
			}
			$('#wapm-stock-result').html(html);
			$('#wapm-stock-apply-btn').prop('disabled', stockState.previewed === 0);
		}).fail(function () {
			$('#wapm-stock-result').html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$('#wapm-stock-apply-btn').on('click', function () {
		if (!stockState.previewed) { return; }
		if (!window.confirm('موجودی ' + stockState.previewed + ' محصول تغییر می‌کند. ادامه می‌دهید؟')) { return; }

		var payload = stockTargetsPayload();
		var ids = payload.ids && payload.ids.length
			? payload.ids
			: ($('#wapm-stock-result').data('ids') || []);

		// در حالت فیلتر، پیش‌نمایش شناسه‌ها را برمی‌گرداند؛ همان‌ها را اعمال می‌کنیم
		// تا بین پیش‌نمایش و اعمال، لیست عوض نشود.
		if (!ids.length) {
			wapmToast('ابتدا پیش‌نمایش بگیرید.', 'warning');
			return;
		}

		payload.ids = ids;
		delete payload.filters;

		var $btn = $(this).prop('disabled', true);
		$('#wapm-stock-result').html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		ajax('stock_apply', payload).done(function (res) {
			if (!res.success) {
				$('#wapm-stock-result').html('<div class="notice notice-error inline"><p>' + esc((res.data && res.data.message) || WAPM.i18n.error) + '</p></div>');
				return;
			}
			var d = res.data;
			var html = '<div class="notice notice-success inline"><p>' + esc(d.message) + '</p></div>';
			if (d.reasons && Object.keys(d.reasons).length) {
				html += '<p class="description">دلیل رد‌شدن‌ها: ' + esc(stockReasons(d.reasons)) + '</p>';
			}
			$('#wapm-stock-result').html(html);
			stockState.lastBatch = d.batch_id || '';
			refreshStockBatch();
			loadStockLogs(1);
			refreshListIfLoaded();
		}).fail(function () {
			$('#wapm-stock-result').html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	/* ---- تغییر موجودی انتخاب‌شده‌ها (کشو) ---- */

	var sdState = { ids: [], will: 0, batch: '', running: false };

	function sdOperation() {
		return {
			mode: $('#wapm-sd-mode').val(),
			unit: $('#wapm-sd-unit').val(),
			amount: $('#wapm-sd-amount').val()
		};
	}

	function sdSelectedIds() {
		return Object.keys(listState.selected || {}).map(function (x) { return parseInt(x, 10); })
			.filter(function (id) { return id > 0; });
	}

	function sdOpen() {
		if (sdState.running) { return; }

		var ids = sdSelectedIds();
		if (!ids.length) {
			wapmToast('ابتدا در جدول، محصول‌ها را تیک بزنید.', 'warning');
			return;
		}

		sdState.ids = ids;
		sdState.will = 0;
		sdState.batch = '';

		$('#wapm-sd-result').empty();
		$('#wapm-sd-summary').text('');
		$('#wapm-sd-foot-note').text('');
		$('#wapm-sd-apply').prop('disabled', true);

		var note = fmt(ids.length) + ' محصول انتخاب شده';
		if (ids.length > 300) {
			note += ' — پیش‌نمایش روی ۳۰۰ محصول اول است، ولی اعمال روی همه اجرا می‌شود.';
		}
		$('#wapm-sd-selected-note').text(note);

		sdSyncUi();
		$('#wapm-stock-drawer-overlay').addClass('is-open');
		$('#wapm-stock-drawer').addClass('is-open').attr('aria-hidden', 'false');
		window.setTimeout(function () { $('#wapm-sd-amount').trigger('focus').trigger('select'); }, 240);
	}

	function sdClose() {
		if (sdState.running) { return; }
		$('#wapm-stock-drawer-overlay').removeClass('is-open');
		$('#wapm-stock-drawer').removeClass('is-open').attr('aria-hidden', 'true');
	}

	$('#wapm-list-bulk-stock').on('click', sdOpen);
	$('#wapm-sd-close, #wapm-stock-drawer-overlay').on('click', sdClose);

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $('#wapm-stock-drawer').hasClass('is-open')) { sdClose(); }
	});

	function sdSyncUi() {
		// «تنظیم روی عدد» فقط با تعداد معنا دارد؛ درصدِ یک عدد ثابت بی‌معنی است.
		var isSet = $('#wapm-sd-mode').val() === 'set';
		$('#wapm-sd-unit').prop('disabled', isSet).val(isSet ? 'count' : $('#wapm-sd-unit').val());
		$('#wapm-sd-apply').prop('disabled', true);
	}

	$('#wapm-sd-mode, #wapm-sd-unit, #wapm-sd-amount').on('change keyup', sdSyncUi);

	$('#wapm-sd-preview').on('click', function () {
		var op = sdOperation();

		if (op.unit === 'percent' && !(Number(op.amount) > 0 && Number(op.amount) <= 100)) {
			wapmToast('درصد باید بین ۱ تا ۱۰۰ باشد.', 'warning');
			return;
		}
		if (op.unit === 'count' && op.amount === '') {
			wapmToast('مقدار را وارد کنید.', 'warning');
			return;
		}

		var $btn = $(this).prop('disabled', true);
		$('#wapm-sd-result').html('<div class="wapm-skeleton" style="height:60px"></div>');
		$('#wapm-sd-summary').text(WAPM.i18n.loading);
		$('#wapm-sd-apply').prop('disabled', true);

		ajax('stock_preview_ids', {
			ids: sdState.ids,
			mode: op.mode,
			unit: op.unit,
			amount: op.amount
		}).done(function (res) {
			if (!res.success) {
				$('#wapm-sd-result').empty();
				$('#wapm-sd-summary').text('');
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}

			var d = res.data;
			sdState.will = Number(d.will) || 0;

			var summary = 'روی ' + fmt(sdState.will) + ' مورد اثر دارد';
			if (d.rows_total > d.rows.length) {
				summary += ' — ' + fmt(d.rows_total) + ' واریشن، ' + fmt(d.rows.length) + ' ردیف اول نمایش داده شد';
			}
			if (d.skipped && Object.keys(d.skipped).length) {
				summary += ' — رد‌شده: ' + stockReasons(d.skipped);
			}
			$('#wapm-sd-summary').text(summary);

			$('#wapm-sd-result').html(stockRowsTable(d.rows));
			$('#wapm-sd-apply').prop('disabled', sdState.will === 0);
		}).fail(function () {
			$('#wapm-sd-result').empty();
			$('#wapm-sd-summary').text('');
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () { $btn.prop('disabled', false); });
	});

	$('#wapm-sd-apply').on('click', function () {
		if (sdState.running || !sdState.will) { return; }
		if (!window.confirm('موجودی ' + sdState.will + ' مورد تغییر می‌کند. ادامه می‌دهید؟')) { return; }

		var op = sdOperation();
		var ids = sdState.ids.slice();
		var batch = 'stock_' + Date.now().toString(36);
		var page = 1;
		var processed = 0;
		var applied = 0;
		var failed = 0;
		var reasons = {};

		sdState.running = true;
		sdState.batch = batch;

		var $apply = $(this).prop('disabled', true);
		var $other = $('#wapm-sd-preview, #wapm-sd-close').prop('disabled', true);

		function setNote(text) { $('#wapm-sd-foot-note').text(text); }

		function step() {
			ajax('stock_apply_ids', {
				ids: ids,
				mode: op.mode,
				unit: op.unit,
				amount: op.amount,
				page: page,
				batch_id: batch
			}).done(function (res) {
				if (!res.success) {
					sdState.running = false;
					setNote('');
					$apply.prop('disabled', false);
					$other.prop('disabled', false);
					wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
					return;
				}

				processed += Number(res.data.processed) || 0;
				applied   += Number(res.data.applied) || 0;
				failed    += Number(res.data.failed) || 0;

				Object.keys(res.data.reasons || {}).forEach(function (k) {
					reasons[k] = (reasons[k] || 0) + Number(res.data.reasons[k]);
				});

				setNote('پردازش ' + fmt(processed) + ' از ' + fmt(ids.length) + ' محصول…');

				if (!res.data.done) {
					page++;
					window.setTimeout(step, 200);
					return;
				}

				sdState.running = false;
				setNote('');
				$apply.prop('disabled', false);
				$other.prop('disabled', false);

				var msg = fmt(applied) + ' موجودی تغییر کرد';
				if (failed) { msg += ' — ' + fmt(failed) + ' مورد رد شد'; }
				if (Object.keys(reasons).length) { msg += ' (' + stockReasons(reasons) + ')'; }

				wapmToast(msg + ' — Batch ' + batch, applied ? 'success' : 'warning', {
					ttl: 12000,
					actionLabel: 'بازگردانی این تغییر',
					action: function () { stockUndo(batch); }
				});

				sdClose();
				loadProductList(listState.paged);
				loadStockLogs(1);
			}).fail(function () {
				sdState.running = false;
				setNote('');
				$apply.prop('disabled', false);
				$other.prop('disabled', false);
				wapmToast(WAPM.i18n.error, 'error');
			});
		}

		step();
	});

	/* ---- بازگردانی ---- */

	function refreshStockBatch() {
		ajax('stock_logs', { paged: 1 }).done(function (res) {
			if (!res.success) { return; }
			stockState.lastBatch = res.data.last_batch || '';
			$('#wapm-stock-last-batch').text(stockState.lastBatch || 'هیچ Batch قابل بازگردانی نیست');
			$('#wapm-stock-undo-last-btn').prop('disabled', !stockState.lastBatch);
		});
	}

	function stockUndo(batch, $btn) {
		if (!batch) { return; }
		// $btn اختیاری است: از اعلان (toast) هم صدا زده می‌شود و آن‌جا دکمه‌ای نیست.
		$btn = $btn && $btn.length ? $btn : $();
		if (!window.confirm('موجودی‌های این Batch به مقدار قبل برمی‌گردند. ادامه می‌دهید؟')) { return; }

		$btn.prop('disabled', true);
		ajax('stock_undo', { batch_id: batch }).done(function (res) {
			var msg = res.success ? res.data.message : ((res.data && res.data.message) || WAPM.i18n.error);
			$('#wapm-stock-undo-result').html('<div class="notice notice-' + (res.success ? 'success' : 'error') + ' inline"><p>' + esc(msg) + '</p></div>');

			// اگر تداخل داشت، اجازه‌ی تلاش دوباره با force بده.
			if (res.success && res.data.conflicts > 0) {
				$('#wapm-stock-undo-result').append(
					'<p><button type="button" class="button wapm-stock-undo-force" data-batch="' + esc(batch) + '">بازگردانی اجباری (تداخل‌ها هم)</button></p>'
				);
			}
			loadStockLogs(stockState.paged);
			refreshStockBatch();
			refreshListIfLoaded();
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	$('#wapm-stock-undo-last-btn').on('click', function () {
		stockUndo(stockState.lastBatch, $(this));
	});

	$(document).on('click', '.wapm-stock-undo-force', function () {
		var batch = $(this).data('batch');
		if (!window.confirm('حتی تغییراتی که بعد از این عملیات انجام شده‌اند بازنویسی می‌شوند. مطمئن هستید؟')) { return; }
		ajax('stock_undo', { batch_id: batch, force: 1 }).done(function (res) {
			var msg = res.success ? res.data.message : ((res.data && res.data.message) || WAPM.i18n.error);
			$('#wapm-stock-undo-result').html('<div class="notice notice-' + (res.success ? 'success' : 'error') + ' inline"><p>' + esc(msg) + '</p></div>');
			loadStockLogs(stockState.paged);
			refreshStockBatch();
		});
	});

	/* ---- تاریخچه ---- */

	function loadStockLogs(paged) {
		stockState.paged = paged || 1;
		var $tbody = $('#wapm-stock-log-table tbody').html('<tr><td colspan="9">' + esc(WAPM.i18n.loading) + '</td></tr>');

		ajax('stock_logs', { paged: stockState.paged }).done(function (res) {
			if (!res.success) {
				$tbody.html('<tr><td colspan="9">' + esc(WAPM.i18n.error) + '</td></tr>');
				return;
			}

			var d = res.data;
			stockState.lastBatch = d.last_batch || '';
			$('#wapm-stock-last-batch').text(stockState.lastBatch || 'هیچ Batch قابل بازگردانی نیست');
			$('#wapm-stock-undo-last-btn').prop('disabled', !stockState.lastBatch);

			$tbody.empty();
			if (!d.rows.length) {
				$tbody.append('<tr><td colspan="9">هنوز تغییری در موجودی ثبت نشده است.</td></tr>');
			}

			d.rows.forEach(function (r) {
				var delta = Number(r.delta);
				var deltaText = isNaN(delta) ? '—' : (delta > 0 ? '+' : '') + fmt(delta);
				$tbody.append('<tr>' +
					'<td>' + esc(r.product_name) + '</td>' +
					'<td>' + esc(r.sku || '—') + '</td>' +
					'<td>' + esc(r.old_qty) + '</td>' +
					'<td>' + esc(r.new_qty) + '</td>' +
					'<td>' + esc(deltaText) + '</td>' +
					'<td>' + esc(r.operation) + '</td>' +
					'<td>' + esc(r.user_name) + '</td>' +
					'<td>' + esc(r.date_i18n) + '</td>' +
					'<td><button type="button" class="button button-small wapm-stock-undo" data-batch="' + esc(r.batch_id) + '" ' +
						((Number(r.undone) || !r.undoable) ? 'disabled' : '') + '>بازگردانی</button></td>' +
					'</tr>');
			});

			var $pg = $('#wapm-stock-log-pagination').empty();
			if (d.pages > 1) {
				if (stockState.paged > 1) { $pg.append('<button type="button" class="button wapm-stock-page" data-page="' + (stockState.paged - 1) + '">« قبلی</button> '); }
				$pg.append('<span> صفحه ' + fmt(stockState.paged) + ' از ' + fmt(d.pages) + ' </span>');
				if (stockState.paged < d.pages) { $pg.append(' <button type="button" class="button wapm-stock-page" data-page="' + (stockState.paged + 1) + '">بعدی »</button>'); }
			}
		});
	}

	$(document).on('click', '.wapm-stock-page', function () {
		loadStockLogs(parseInt($(this).data('page'), 10));
	});

	$(document).on('click', '.wapm-stock-undo', function () {
		stockUndo($(this).data('batch'), $(this));
	});

	/* ---- CSV ---- */

	$('#wapm-stock-export-btn').on('click', function () {
		window.location.href = WAPM.stock_export_url +
			'&nonce=' + encodeURIComponent(WAPM.nonce) +
			'&filters=' + encodeURIComponent(JSON.stringify(readFilters('main')));
	});

	function stockImport(apply) {
		var input = $('#wapm-stock-import-file')[0];
		var file = input && input.files ? input.files[0] : null;
		if (!file) { wapmToast('فایلی انتخاب نشده است.', 'warning'); return; }

		var fd = new FormData();
		fd.append('action', 'wapm_stock_import');
		fd.append('nonce', WAPM.nonce);
		fd.append('feed_file', file);
		if (apply) { fd.append('apply', '1'); }

		var $btns = $('#wapm-stock-import-preview-btn, #wapm-stock-import-apply-btn').prop('disabled', true);
		$('#wapm-stock-import-result').html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		$.ajax({
			url: WAPM.ajax_url,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false
		}).done(function (res) {
			if (!res.success) {
				$('#wapm-stock-import-result').html('<div class="notice notice-error inline"><p>' + esc((res.data && res.data.message) || WAPM.i18n.error) + '</p></div>');
				return;
			}

			var d = res.data;
			var html = '<div class="notice notice-' + (d.dry_run ? 'info' : 'success') + ' inline"><p>' + esc(d.message) + '</p></div>';

			if (d.errors && d.errors.length) {
				html += '<details><summary>' + fmt(d.errors.length) + ' خطا</summary><ul>';
				d.errors.forEach(function (e) { html += '<li>' + esc(e) + '</li>'; });
				html += '</ul></details>';
			}

			if (d.preview && d.preview.length) {
				html += stockRowsTable(d.preview.map(function (r) {
					return { name: r.name, sku: r.sku, old: r.old, new: r.new, delta: (Number(r.new) - Number(r.old)) };
				}));
			}

			$('#wapm-stock-import-result').html(html);
			$('#wapm-stock-import-apply-btn').prop('disabled', d.dry_run ? (Number(d.applied) === 0) : true);

			if (!d.dry_run) {
				loadStockLogs(1);
				refreshStockBatch();
				refreshListIfLoaded();
			}
		}).fail(function () {
			$('#wapm-stock-import-result').html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () {
			$btns.prop('disabled', false);
		});
	}

	$('#wapm-stock-import-preview-btn').on('click', function () { stockImport(false); });
	$('#wapm-stock-import-apply-btn').on('click', function () { stockImport(true); });

	/* ---------------- تب ترب ---------------- */

	/** اگر لیست محصولات قبلاً بارگذاری شده، بعد از عملیات ترب دوباره بخوانش. */
	function refreshListIfLoaded() {
		if ($('#wapm-list-tbody tr[data-row-id]').length) {
			loadProductList(listState.paged);
		}
	}

	var TOROB_SOURCE_LABELS = {
		bookmarklet: 'بوکمارکلت',
		feed: 'فید CSV',
		api: 'ورود برنامه‌نویسی',
		manual: 'اتصال دستی'
	};

	function torobDate(value) {
		if (!value) { return '—'; }
		var d = new Date(String(value).replace(' ', 'T'));
		if (isNaN(d.getTime())) { return String(value); }
		function p(n) { return (n < 10 ? '0' : '') + n; }
		return d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
	}

	/* رندر کارت «وضعیت همگام‌سازی» — معادل render_torob_status() در PHP،
	   تا بعد از هر عملیات بتوان بدون رفرش صفحه به‌روزش کرد. */
	function renderTorobStatusHtml(stats) {
		var html = '';

		if (!stats.can_identify_shop) {
			html += '<div class="notice notice-error inline"><p><strong>شناسه یا نام فروشگاه تنظیم نشده است.</strong> ' +
				'تا وقتی مشخص نباشد کدام فروشنده خودتان هستید، «ارزان‌ترین رقیب» قابل محاسبه نیست و افزونه مقایسه را انجام نمی‌دهد.</p></div>';
		}

		var last = stats.last_sync || {};
		if (last.structure_warning) {
			html += '<div class="notice notice-warning inline"><p><strong>ساختار صفحه‌ی ترب تغییر کرده است؟</strong> ' +
				'آخرین همگام‌سازی هیچ محصولی پیدا نکرد. یعنی یا صفحه‌ی اشتباهی باز شده، یا ترب ساختار داده‌اش را عوض کرده و بوکمارکلت باید به‌روزرسانی شود.</p></div>';
		}

		html += '<div class="wapm-stats">' +
			'<div class="wapm-stat"><b>' + fmt(stats.matched || 0) + '</b><small>محصول متصل به ترب</small></div>' +
			'<div class="wapm-stat"><b>' + fmt(stats.unmatched || 0) + '</b><small>تطبیق‌نیافته</small></div>' +
			'<div class="wapm-stat"><b>' + fmt((stats.settings && stats.settings.fresh_days) || 0) + '</b><small>روز تا کهنه‌شدن داده</small></div>' +
			'</div>';

		var catalog = stats.last_catalog || {};
		if (catalog.at) {
			html += '<p class="description">آخرین همگام‌سازی کاتالوگ (قیمت ما از دید ترب): <b>' +
				torobDate(catalog.at) + '</b> — ' + fmt(catalog.items || 0) + ' محصول، ' +
				fmt(catalog.matched || 0) + ' مورد متصل.</p>';
		}

		if (!last.at) {
			html += '<p class="description">هنوز قیمت رقیبی از ترب دریافت نشده است.</p>';
			return html;
		}

		var rows = [
			['آخرین همگام‌سازی', torobDate(last.at)],
			['منبع', TOROB_SOURCE_LABELS[last.source] || last.source || '—'],
			['محصولات یافت‌شده', fmt(last.discovered || 0)],
			['صفحات خوانده‌شده', fmt(last.pages || 0)],
			['تطبیق‌خورده', fmt(last.matched || 0)],
			['قیمت بررسی‌شده', fmt(last.checked || 0)],
			['بدون داده‌ی فروشنده', fmt(last.no_sellers || 0)],
			['تطبیق‌نیافته', fmt(last.unmatched || 0)],
			['مبهم (نیاز به بررسی)', fmt(last.ambiguous || 0)]
		];

		html += '<table class="widefat striped" style="max-width:720px"><tbody>';
		for (var i = 0; i < rows.length; i++) {
			html += '<tr><th style="width:220px">' + esc(rows[i][0]) + '</th><td>' + esc(rows[i][1]) + '</td></tr>';
		}
		html += '</tbody></table>';

		return html;
	}

	/**
	 * وضعیت کلاینت API: بودجه‌ی روزانه و کول‌داون بعد از بلاک.
	 * عددها مهم‌اند: کاربر باید بداند چرا درخواست بعدی رد می‌شود.
	 */
	function renderApiState(state) {
		var $box = $('#wapm-torob-api-state');
		if (!$box.length || !state) { return; }

		var html = '';
		if (state.blocked) {
			html += '<div class="notice notice-error inline"><p><strong>ترب درخواست‌های این سرور را موقتاً بلاک کرده است.</strong> ' +
				'حدود ' + fmt(state.minutes_left) + ' دقیقه دیگر خودبه‌خود آزاد می‌شود. ' +
				'تا آن زمان هیچ درخواستی فرستاده نمی‌شود (تلاش زودتر، زمان بلاک را طولانی‌تر می‌کند).</p></div>';
			$('#wapm-torob-api-reset').show();
		} else {
			$('#wapm-torob-api-reset').hide();
		}

		html += '<p class="description">' +
			'بودجه‌ی امروز: <b>' + fmt(state.used) + '</b> از ' + fmt(state.budget) + ' درخواست • ' +
			'حداقل فاصله بین درخواست‌ها: ' + fmt(state.min_interval) + ' ثانیه' +
			'</p>';

		$box.html(html);
	}

	function applyTorobStats(stats) {
		if (!stats) { return; }
		if ($('#wapm-torob-status-body').length) {
			$('#wapm-torob-status-body').html(renderTorobStatusHtml(stats));
		}
		$('#wapm-torob-unmatched-count').text(fmt(stats.unmatched || 0));
		renderApiState(stats.api);
	}

	function refreshTorobStats() {
		ajax('torob_stats').done(function (res) {
			if (res.success) { applyTorobStats(res.data); }
		});
	}

	/* ---- ۱) تنظیمات ---- */

	$('#wapm-torob-save-settings').on('click', function () {
		var $btn = $(this).prop('disabled', true);

		ajax('torob_save_settings', {
			shop_id: $('#wapm-torob-shop-id').val(),
			shop_name: $('#wapm-torob-shop-name').val(),
			fresh_days: $('#wapm-torob-fresh-days').val(),
			tolerance_pct: $('#wapm-torob-tolerance').val()
		}).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}
			applyTorobStats(res.data);

			// اگر هشدار «شناسه تنظیم نشده» برطرف شد، کارت وضعیت به‌روز شده است.
			var note = $('<span style="color:#059669;font-weight:800;margin-inline-start:10px">✓ ذخیره شد</span>');
			$btn.after(note);
			setTimeout(function () { note.fadeOut(300, function () { $(this).remove(); }); }, 2500);

			// ستون ترب در جدول به شناسه‌ی جدید وابسته است.
			refreshListIfLoaded();
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$('#wapm-torob-regen-token').on('click', function () {
		if (!window.confirm('بوکمارکلت فعلی از کار می‌افتد و باید دوباره ساخته شود. مطمئنید؟')) { return; }
		var $btn = $(this).prop('disabled', true);
		ajax('torob_regenerate_token', {}).done(function (res) {
			if (res.success) {
				wapmToast('توکن جدید ساخته شد. صفحه را رفرش کنید تا لینک بوکمارکلت جدید را ببینید و دوباره به نوار بوکمارک بکشید.', 'info', { ttl: 12000 });
			} else {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
			}
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---- ۱-ب) جستجو و تأیید شناسه‌ی فروشگاه با API ---- */

	$('#wapm-torob-shop-search').on('click', function () {
		var q = $.trim($('#wapm-torob-shop-query').val());
		var $out = $('#wapm-torob-shop-results');

		if (q.length < 2) {
			$out.html('<p class="description">حداقل دو حرف از نام فروشگاه را بنویسید.</p>');
			return;
		}

		var $btn = $(this).prop('disabled', true);
		$out.html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		ajax('torob_find_shops', { q: q }).done(function (res) {
			if (!res.success) {
				$out.html('<div class="notice notice-error inline"><p>' + esc((res.data && res.data.message) || WAPM.i18n.error) + '</p></div>');
				return;
			}

			renderApiState(res.data.state);

			var rows = res.data.rows || [];
			if (!rows.length) {
				$out.html('<div class="notice notice-warning inline"><p>' + esc(res.data.message || 'فروشگاهی پیدا نشد.') + '</p></div>');
				return;
			}

			var html = '<table class="widefat striped" style="max-width:640px"><tbody>';
			rows.forEach(function (r) {
				html += '<tr><td><b>' + esc(r.name) + '</b>' +
					'<div class="description">' + esc(r.domain) + (r.city ? ' • ' + esc(r.city) : '') +
					' • شناسه: ' + esc(r.id) + '</div></td>' +
					'<td style="width:110px"><button type="button" class="button wapm-torob-pick-shop" data-id="' + esc(r.id) + '">انتخاب</button></td></tr>';
			});
			html += '</tbody></table>';
			$out.html(html);
		}).fail(function () {
			$out.html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '.wapm-torob-pick-shop', function () {
		$('#wapm-torob-shop-id').val($(this).data('id'));
		$('#wapm-torob-verify').trigger('click');
	});

	$('#wapm-torob-verify').on('click', function () {
		var shopId = parseInt($('#wapm-torob-shop-id').val(), 10) || 0;
		if (!shopId) {
			wapmToast('اول شناسه‌ی فروشگاه را وارد کنید.', 'warning');
			return;
		}

		var $btn = $(this).prop('disabled', true);
		$('#wapm-torob-shop-results').html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		ajax('torob_verify_shop', { shop_id: shopId }).done(function (res) {
			renderApiState(res.data && res.data.state);

			if (!res.success) {
				$('#wapm-torob-shop-results').html('<div class="notice notice-error inline"><p>' +
					esc((res.data && res.data.message) || WAPM.i18n.error) + '</p></div>');
				return;
			}

			applyTorobStats(res.data);
			if (res.data.shop && res.data.shop.name) {
				$('#wapm-torob-shop-name').val(res.data.shop.name);
			}

			var shop = res.data.shop || {};
			$('#wapm-torob-shop-results').html('<div class="notice notice-success inline"><p>' +
				'فروشگاه تأیید شد: <b>' + esc(shop.name || '') + '</b>' +
				(shop.domain ? ' (' + esc(shop.domain) + ')' : '') +
				(shop.city ? ' • ' + esc(shop.city) : '') +
				(shop.active_time ? ' • ' + esc(shop.active_time) + ' در ترب' : '') +
				'</p></div>');
		}).fail(function () {
			$('#wapm-torob-shop-results').html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---- ۲-ب) همگام‌سازی کاتالوگ از API (صفحه‌به‌صفحه) ---- */

	var catalogRun = { stop: false, running: false, timer: 0 };

	function syncCatalogPage(page) {
		if (catalogRun.stop) { return finishCatalog('متوقف شد. هر وقت خواستید دوباره شروع کنید؛ از صفحه‌ی اول ادامه می‌یابد.'); }

		ajax('torob_sync_catalog', { page: page, size: 24 }).done(function (res) {
			if (!res.success) {
				renderApiState(res.data && res.data.state);
				finishCatalog('⚠ ' + ((res.data && res.data.message) || WAPM.i18n.error));
				return;
			}

			// اگر کاربر وسط درخواست «توقف» زده، دیگر صفحه‌ی بعدی زمان‌بندی نشود.
			if (catalogRun.stop) {
				finishCatalog('متوقف شد. هر وقت خواستید دوباره شروع کنید؛ از صفحه‌ی اول ادامه می‌یابد.');
				return;
			}

			catalogRun.synced += res.data.items;
			if (res.data.stats_payload) { applyTorobStats(res.data.stats_payload); }
			renderApiState(res.data.api);

			var total = res.data.count || 0;
			var pct = total ? Math.min(100, Math.round((catalogRun.synced / total) * 100)) : 0;

			$('#wapm-torob-catalog-result').html(
				'<div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;max-width:520px;margin:8px 0">' +
				'<div style="height:100%;width:' + pct + '%;background:#7f54b3;transition:width .2s"></div></div>' +
				'<p class="description">' + fmt(catalogRun.synced) + ' از ' + fmt(total) + ' محصول — ' + pct + '٪</p>'
			);

			if (res.data.has_more) {
				// سرور خودش بین درخواست‌ها ۳ ثانیه صبر می‌کند؛ اینجا کمی بیشتر
				// تا درخواست بعدی هم بلافاصله پشت سر قبلی نباشد.
				catalogRun.timer = setTimeout(function () {
					catalogRun.timer = 0;
					syncCatalogPage(page + 1);
				}, 3200);
			} else {
				finishCatalog('✓ همگام‌سازی کاتالوگ تمام شد — ' + fmt(catalogRun.synced) + ' محصول. ' +
					'حالا ستون «قیمت ترب» نشان می‌دهد ترب چه قیمتی از شما نشان می‌دهد.');
			}
		}).fail(function () {
			finishCatalog('خطای شبکه. اگر چند صفحه خوانده شد، دوباره شروع کنید.');
		});
	}

	function finishCatalog(msg) {
		if (!catalogRun.running) { return; } // پایان دوباره = پیام تکراری

		catalogRun.running = false;
		if (catalogRun.timer) {
			clearTimeout(catalogRun.timer);
			catalogRun.timer = 0;
		}

		$('#wapm-torob-catalog-sync').prop('disabled', false);
		// دکمه‌ی توقف باید برای اجرای بعدی دوباره فعال شود؛ قبلاً بعد از اولین
		// توقف disabled می‌ماند و در همگام‌سازی دوم هیچ کاری نمی‌کرد.
		$('#wapm-torob-catalog-stop').hide().prop('disabled', false);
		$('#wapm-torob-catalog-result').prepend(
			'<div class="notice notice-info inline"><p>' + esc(msg) + '</p></div>'
		);
	}

	$('#wapm-torob-catalog-sync').on('click', function () {
		if (catalogRun.running) { return; }

		catalogRun = { stop: false, running: true, synced: 0, timer: 0 };

		$(this).prop('disabled', true);
		$('#wapm-torob-catalog-stop').show();
		$('#wapm-torob-catalog-result').empty();

		syncCatalogPage(0);
	});

	$('#wapm-torob-catalog-stop').on('click', function () {
		catalogRun.stop = true;
		$(this).prop('disabled', true);

		// اگر درخواستی در جریان نیست، همان لحظه تمام شود؛ وگرنه پاسخِ در راه،
		// با دیدن catalogRun.stop خودش جمع می‌کند.
		if (catalogRun.timer) {
			clearTimeout(catalogRun.timer);
			catalogRun.timer = 0;
			finishCatalog('متوقف شد. هر وقت خواستید دوباره شروع کنید؛ از صفحه‌ی اول ادامه می‌یابد.');
		}
	});

	$('#wapm-torob-api-reset').on('click', function () {
		var $btn = $(this).prop('disabled', true);
		ajax('torob_api_reset', {}).done(function (res) {
			if (res.success) { renderApiState(res.data.state); }
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---- بررسی فوری یک محصول (۱ درخواست) ---- */

	$(document).on('click', '.wapm-torob-check', function () {
		var $btn = $(this).prop('disabled', true);
		var id = $btn.data('id');
		var original = $btn.text();

		$btn.text('در حال بررسی…');

		ajax('torob_refresh_product', { product_id: id }).done(function (res) {
			if (!res.success) {
				renderApiState(res.data && res.data.state);
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}

			renderApiState(res.data.api);
			if (res.data.stats_payload) { applyTorobStats(res.data.stats_payload); }
			if (res.data.row) { updateRowInDom(res.data.row); }
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () {
			$btn.prop('disabled', false).text(original);
		});
	});

	/* ---- ۴) ورود فید CSV ---- */

	$('#wapm-torob-feed-import').on('click', function () {
		var file = $('#wapm-torob-feed-file')[0].files[0];
		var $out = $('#wapm-torob-feed-result');

		if (!file) {
			$out.html('<div class="notice notice-error inline"><p>اول فایل CSV را انتخاب کنید.</p></div>');
			return;
		}

		var fd = new FormData();
		fd.append('action', 'wapm_torob_import_feed');
		fd.append('nonce', WAPM.nonce);
		fd.append('feed_file', file);

		var $btn = $(this).prop('disabled', true);
		$out.html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		$.ajax({
			url: WAPM.ajax_url,
			method: 'POST',
			data: fd,
			processData: false,
			contentType: false
		}).done(function (res) {
			var ok = res.success;
			var msg = ok ? res.data.message : ((res.data && res.data.message) || WAPM.i18n.error);
			$out.html('<div class="notice notice-' + (ok ? 'success' : 'error') + ' inline"><p>' + esc(msg) + '</p></div>');
			if (ok) {
				applyTorobStats(res.data.stats);
				loadTorobUnmatched();
				refreshListIfLoaded();
			}
		}).fail(function () {
			$out.html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	/* ---- ۵) صف تأیید تطبیق (تطبیق‌نیافته‌ها) ---- */

	/**
	 * چرا صف؟ تطبیق ترب بر اساس «نام» است و همیشه درصدی مبهم می‌ماند. قبلاً این
	 * ردیف‌ها یک لیست ۵۰۰ ردیفی بود که کاربر باید تک‌تک با چشم می‌گشت. الان:
	 * فیلتر «با کاندید / بدون کاندید»، جستجو، صفحه‌بندی، انتخاب گروهی و
	 * نمایش «قیمت ووکامرس در برابر قیمت ترب» تا تطبیق اشتباه چشم بخورد.
	 */
	var unmatchedState = {
		page: 1,
		per_page: 20,
		q: '',
		only: 'all',
		sort: 'newest',
		total: 0,
		pages: 1,
		loading: false,
		running: false,
		queued: false
	};

	var onlyLabels = {
		all: 'همه',
		candidate: 'با کاندید',
		no_candidate: 'بدون کاندید',
		variation: 'واریشن',
		occupied: 'وصل‌شده به دیگری'
	};

	function diffPillHtml(cand) {
		if (!cand) { return ''; }

		var out = '';
		if (cand.price !== null && cand.price !== undefined) {
			out += '<div class="wapm-review__diff">قیمت ووکامرس: <b>' + fmt(cand.price) + '</b>';

			if (cand.diff_pct !== null && cand.diff_pct !== undefined) {
				var d = Number(cand.diff_pct);
				var cls = d > 0 ? 'wapm-pill--pricier' : (d < 0 ? 'wapm-pill--cheaper' : 'wapm-pill--equal');
				var txt = d > 0
					? 'ما ' + fmt(Math.abs(d)) + '٪ گران‌تر'
					: (d < 0 ? 'ما ' + fmt(Math.abs(d)) + '٪ ارزان‌تر' : 'برابر');
				out += ' <span class="wapm-pill ' + cls + '">' + esc(txt) + '</span>';
			}
			out += '</div>';

			if (cand.suspect) {
				out += '<div class="wapm-review__warn">اختلاف بیش از ۶۰٪ — پیش از اتصال، محصول ترب را باز کنید.</div>';
			}
		}
		return out;
	}

	function unmatchedCounts(counts) {
		counts = counts || {};
		var chips = '';
		['all', 'candidate', 'no_candidate', 'variation', 'occupied'].forEach(function (key) {
			if (key !== 'all' && !counts[key]) { return; }
			chips += '<button type="button" class="wapm-chip' + (unmatchedState.only === key ? ' is-active' : '') +
				'" data-only="' + key + '">' + esc(onlyLabels[key]) +
				' <b>' + fmt(counts[key] || 0) + '</b></button>';
		});
		return '<div class="wapm-review__chips">' + chips + '</div>';
	}

	function renderUnmatched(data) {
		var rows = data.rows || [];
		unmatchedState.total = data.total || 0;
		unmatchedState.page  = data.page || 1;
		unmatchedState.pages = data.pages || 1;

		if (!data.all) {
			return '<div class="wapm-empty">' +
				'<div class="wapm-empty__icon" aria-hidden="true">✓</div>' +
				'<p class="wapm-empty__title">همه‌ی محصولات ترب تطبیق خوردند.</p>' +
				'<p class="description">اگر باز هم «تطبیق‌نیافته» دیدید، از بوکمارکلت/فید تازه‌سازی کنید.</p>' +
				'</div>';
		}

		var html = '<div class="wapm-review">';

		/* نوار فیلتر */
		html += '<div class="wapm-review__filters">' +
			'<div class="wapm-review__search">' +
				'<input type="search" id="wapm-torob-unmatched-q" value="' + esc(data.q || '') + '" ' +
					'placeholder="جستجوی نام یا شناسه‌ی ترب…" aria-label="جستجو در تطبیق‌نیافته‌ها">' +
			'</div>' +
			unmatchedCounts(data.counts) +
			'<div class="wapm-review__sort">' +
				'<label for="wapm-torob-unmatched-sort">ترتیب</label>' +
				'<select id="wapm-torob-unmatched-sort">' +
					'<option value="newest"' + (data.sort === 'newest' ? ' selected' : '') + '>تازه‌ترین</option>' +
					'<option value="oldest"' + (data.sort === 'oldest' ? ' selected' : '') + '>قدیمی‌ترین</option>' +
					'<option value="score"' + (data.sort === 'score' ? ' selected' : '') + '>بیشترین شباهت</option>' +
				'</select>' +
			'</div>' +
		'</div>';

		/* نوار عملیات گروهی */
		html += '<div class="wapm-bulkbar" id="wapm-torob-review-bar">' +
			'<label class="wapm-bulkbar__all"><input type="checkbox" id="wapm-torob-pick-all"> همه‌ی این صفحه</label>' +
			'<span class="wapm-bulkbar__count" id="wapm-torob-pick-count">۰ انتخاب</span>' +
			'<div class="wapm-bulkbar__actions">' +
				'<button type="button" class="button button-primary" id="wapm-torob-link-selected" disabled>اتصال انتخاب‌شده‌ها</button>' +
				'<button type="button" class="button" id="wapm-torob-ignore-selected" disabled>نادیده‌گرفتن</button>' +
			'</div>' +
		'</div>';

		if (!rows.length) {
			html += '<div class="wapm-empty"><p class="wapm-empty__title">با این فیلتر چیزی پیدا نشد.</p>' +
				'<p class="description">فیلتر را روی «همه» بگذارید یا عبارت جستجو را کوتاه‌تر کنید.</p></div>';
		} else {
			html += '<table class="widefat striped wapm-review__table"><thead><tr>' +
				'<th class="wapm-review__check"><span class="screen-reader-text">انتخاب</span></th>' +
				'<th>محصول ترب</th><th>ارزان‌ترین رقیب</th><th>محصول ووکامرس</th><th class="wapm-review__actions">اقدام</th>' +
				'</tr></thead><tbody>';

			for (var i = 0; i < rows.length; i++) { html += renderUnmatchedRow(rows[i]); }
			html += '</tbody></table>';
		}

		/* صفحه‌بندی */
		if (data.pages > 1) {
			html += '<div class="wapm-review__pager">' +
				'<button type="button" class="button" data-page="' + (data.page - 1) + '"' + (data.page <= 1 ? ' disabled' : '') + '>قبلی</button>' +
				'<span>صفحه‌ی <b>' + fmt(data.page) + '</b> از ' + fmt(data.pages) +
					' — ' + fmt(data.total) + ' ردیف</span>' +
				'<button type="button" class="button" data-page="' + (data.page + 1) + '"' + (data.page >= data.pages ? ' disabled' : '') + '>بعدی</button>' +
			'</div>';
		}

		return html + '</div>';
	}

	function renderUnmatchedRow(row) {
		var cand = row.candidate;
		var html = '<tr data-prk="' + esc(row.prk) + '">';

		html += '<td class="wapm-review__check"><input type="checkbox" class="wapm-torob-pick" ' +
			'aria-label="انتخاب ' + esc(row.name || row.prk) + '"></td>';

		html += '<td>' +
			'<div class="wapm-review__name">' + esc(row.name || '—') + '</div>' +
			'<div class="wapm-review__why"><span class="wapm-pill ' + (cand ? 'wapm-pill--info' : 'wapm-pill--muted') + '">' +
				esc(row.how_label || '') + '</span></div>' +
			'<div class="wapm-review__meta">' +
				(row.sellers ? fmt(row.sellers) + ' فروشنده • ' : '') +
				'آخرین مشاهده: ' + esc(row.seen_i18n || '—') +
				' • <a href="' + esc(row.url) + '" target="_blank" rel="noopener">ترب ↗</a>' +
			'</div>' +
		'</td>';

		html += '<td class="wapm-review__price">' +
			(row.price !== null && row.price !== undefined ? fmt(row.price) : '—') + '</td>';

		html += '<td>' +
			'<div class="wapm-review__pick">' +
				'<select class="wapm-torob-product-select" data-prk="' + esc(row.prk) + '" ' +
					'data-name="' + esc(row.name || '') + '" data-theirs="' + (row.price || '') + '">' +
					(cand ? '<option value="' + cand.id + '" selected>' + esc(cand.text) + '</option>' : '') +
				'</select>' +
			'</div>' +
			'<div class="wapm-review__match">' + diffPillHtml(cand) + '</div>' +
		'</td>';

		html += '<td class="wapm-review__actions">' +
			'<button type="button" class="button button-primary wapm-torob-link">اتصال</button> ' +
			'<button type="button" class="button wapm-torob-ignore" title="این مورد را از لیست بردار">نادیده‌گرفتن</button>' +
		'</td>';

		return html + '</tr>';
	}

	function initUnmatchedSelectors($area) {
		$area.find('.wapm-torob-product-select').each(function () {
			var $sel = $(this);
			$sel.selectWoo({
				dir: 'rtl',
				width: '100%',
				placeholder: 'جستجوی محصول ووکامرس…',
				allowClear: true,
				minimumInputLength: 2,
				ajax: {
					url: WAPM.ajax_url,
					method: 'POST',
					dataType: 'json',
					delay: 350,
					data: function (params) {
						return { action: 'wapm_search_products', nonce: WAPM.nonce, term: params.term };
					},
					processResults: function (res) {
						return { results: (res && res.success) ? res.data : [] };
					}
				}
			});

			// اگر کاندید از سرور آمده، متن آن را در selectWoo نگه دار.
			if ($sel.find('option:selected').length) {
				$sel.trigger('change.select2');
			}

			// انتخاب دستی: «قیمت ووکامرس» و هشدار اختلاف را همان‌جا نشان بده تا
			// کاربر پیش از اتصال ببیند انتخابش منطقی است یا نه.
			$sel.on('select2:select', function (e) {
				var data = (e.params && e.params.data) || {};
				var $tr  = $sel.closest('tr');
				var $box = $tr.find('.wapm-review__match');

				if (data.price === undefined || data.price === null) {
					$box.empty();
					return;
				}

				var theirs = parseFloat($tr.find('.wapm-torob-product-select').data('theirs'));
				var ours   = parseFloat(data.price);
				var diff   = (theirs > 0 && ours > 0) ? ((ours - theirs) / theirs) * 100 : null;

				$box.html(diffPillHtml({
					price: ours,
					diff_pct: diff === null ? null : Math.round(diff * 10) / 10,
					suspect: diff !== null && (diff > 60 || diff < -60)
				}));
			});
		});
	}

	function pickedRows($area) {
		var rows = [];
		$area.find('.wapm-torob-pick:checked').each(function () {
			var $tr  = $(this).closest('tr');
			var $sel = $tr.find('.wapm-torob-product-select');
			rows.push({
				prk: String($tr.data('prk') || ''),
				product_id: parseInt($sel.val(), 10) || 0,
				name: String($tr.find('.wapm-review__name').text() || '')
			});
		});
		return rows;
	}

	function updateBulkBar($area) {
		var picked = $area.find('.wapm-torob-pick:checked').length;
		var all    = $area.find('.wapm-torob-pick').length;

		$('#wapm-torob-pick-count').text(fmt(picked) + ' انتخاب');
		$('#wapm-torob-link-selected, #wapm-torob-ignore-selected').prop('disabled', picked === 0);
		$('#wapm-torob-pick-all').prop('checked', all > 0 && picked === all);
		$('#wapm-torob-review-bar').toggleClass('is-active', picked > 0);
	}

	function loadTorobUnmatched(opts) {
		var $area = $('#wapm-torob-unmatched-area');
		if (!$area.length) { return; }

		// اگر کاربر سریع فیلتر را عوض کند، درخواست قبلی را دور نمی‌ریزیم ولی
		// آخرین حالت را هم از دست نمی‌دهیم: بعد از پایان، یک‌بار دیگر می‌خوانیم.
		if (unmatchedState.loading) {
			unmatchedState.queued = true;
			return;
		}

		opts = opts || {};
		if (opts.reset) { unmatchedState.page = 1; }

		unmatchedState.loading = true;
		$area.addClass('is-busy');

		if (!$area.children().length) {
			$area.html('<p class="description">' + esc(WAPM.i18n.loading) + '</p>');
		}

		ajax('torob_get_unmatched', {
			page: unmatchedState.page,
			per_page: unmatchedState.per_page,
			q: unmatchedState.q,
			only: unmatchedState.only,
			sort: unmatchedState.sort
		}).done(function (res) {
			if (!res.success) {
				$area.html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
				return;
			}

			$('#wapm-torob-unmatched-count').text(fmt(res.data.all || 0));
			$area.html(renderUnmatched(res.data));
			initUnmatchedSelectors($area);
			updateBulkBar($area);
		}).fail(function () {
			$area.html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () {
			unmatchedState.loading = false;
			$area.removeClass('is-busy');
			if (unmatchedState.queued) {
				unmatchedState.queued = false;
				loadTorobUnmatched({});
			}
		});
	}

	$('#wapm-torob-unmatched-refresh').on('click', function () { loadTorobUnmatched({ reset: true }); });

	/* فیلتر، جستجو، ترتیب، صفحه‌بندی — همه از delegation تا بعد از هر رندر کار کنند */
	var searchTimer = null;

	$(document).on('input', '#wapm-torob-unmatched-q', function () {
		var value = $.trim($(this).val());
		clearTimeout(searchTimer);
		searchTimer = setTimeout(function () {
			unmatchedState.q = value;
			loadTorobUnmatched({ reset: true });
		}, 350);
	});

	$(document).on('click', '.wapm-review__chips .wapm-chip', function () {
		unmatchedState.only = String($(this).data('only') || 'all');
		loadTorobUnmatched({ reset: true });
	});

	$(document).on('change', '#wapm-torob-unmatched-sort', function () {
		unmatchedState.sort = String($(this).val() || 'newest');
		loadTorobUnmatched({ reset: true });
	});

	$(document).on('click', '.wapm-review__pager .button', function () {
		var page = parseInt($(this).data('page'), 10);
		if (!page || $(this).prop('disabled')) { return; }
		unmatchedState.page = page;
		loadTorobUnmatched({});
	});

	/* انتخاب */
	$(document).on('change', '#wapm-torob-pick-all', function () {
		var $area = $('#wapm-torob-unmatched-area');
		$area.find('.wapm-torob-pick').prop('checked', $(this).prop('checked'));
		updateBulkBar($area);
	});

	$(document).on('change', '.wapm-torob-pick, .wapm-torob-product-select', function () {
		updateBulkBar($('#wapm-torob-unmatched-area'));
	});

	/* اتصال گروهی — دسته‌های ۲۰تایی تا هر درخواست کوتاه بماند */
	$(document).on('click', '#wapm-torob-link-selected', function () {
		if (unmatchedState.running) { return; }

		var $area = $('#wapm-torob-unmatched-area');
		var $btn  = $(this);
		var rows  = pickedRows($area).filter(function (r) { return r.product_id > 0; });

		if (!rows.length) {
			wapmToast('برای هر ردیف اول محصول ووکامرس را انتخاب کنید.', 'warning');
			return;
		}

		var skipped = $area.find('.wapm-torob-pick:checked').length - rows.length;
		var chunks  = [];
		for (var i = 0; i < rows.length; i += 20) { chunks.push(rows.slice(i, i + 20)); }

		unmatchedState.running = true;
		var original = $btn.text();
		var linked   = 0;
		var failed   = 0;
		var errors   = [];
		var index    = 0;

		$btn.prop('disabled', true);

		function runNext() {
			if (index >= chunks.length) {
				unmatchedState.running = false;
				$btn.prop('disabled', false).text(original);

				var msg = fmt(linked) + ' محصول وصل شد';
				if (failed) { msg += ' — ' + fmt(failed) + ' مورد وصل نشد'; }
				if (skipped) { msg += ' — ' + fmt(skipped) + ' ردیف بدون انتخاب محصول رد شد'; }
				wapmToast(msg + '.', failed ? 'warning' : 'success', { ttl: failed ? 12000 : 5000 });

				if (errors.length) {
					wapmToast(errors.slice(0, 3).join(' '), 'error', { ttl: 15000 });
				}

				loadTorobUnmatched({});
				refreshListIfLoaded();
				return;
			}

			$btn.text('در حال اتصال… ' + fmt(Math.min(index * 20 + chunks[index].length, rows.length)) + '/' + fmt(rows.length));

			ajax('torob_link_bulk', { pairs: JSON.stringify(chunks[index]) }).done(function (res) {
				if (res.success) {
					linked += res.data.linked || 0;
					failed += res.data.failed || 0;
					if (res.data.errors) { errors = errors.concat(res.data.errors); }
					if (res.data.stats) { applyTorobStats(res.data.stats); }
				} else {
					failed += chunks[index].length;
					errors.push((res.data && res.data.message) || WAPM.i18n.error);
				}
				index++;
				runNext();
			}).fail(function () {
				failed += chunks[index].length;
				errors.push(WAPM.i18n.error);
				index++;
				runNext();
			});
		}

		runNext();
	});

	/* نادیده‌گرفتن گروهی — یک درخواست (یک نوشتن آپشن) */
	$(document).on('click', '#wapm-torob-ignore-selected', function () {
		if (unmatchedState.running) { return; }

		var $area = $('#wapm-torob-unmatched-area');
		var prks  = pickedRows($area).map(function (r) { return r.prk; });
		if (!prks.length) { return; }

		if (!window.confirm(fmt(prks.length) + ' ردیف از لیست تطبیق‌نیافته‌ها حذف شود؟ (داده‌ی محصولات متصل‌شده دست نمی‌خورد)')) {
			return;
		}

		var $btn = $(this).prop('disabled', true);
		unmatchedState.running = true;

		ajax('torob_ignore_bulk', { prks: JSON.stringify(prks) }).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}
			applyTorobStats(res.data);
			wapmToast(res.data.message || 'حذف شد.', 'success');
			loadTorobUnmatched({});
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () {
			unmatchedState.running = false;
			$btn.prop('disabled', false);
		});
	});

	$('#wapm-torob-unmatched-clear').on('click', function () {
		if (!window.confirm('کل لیست تطبیق‌نیافته‌ها پاک شود؟ (داده‌ی محصولات متصل‌شده دست نمی‌خورد)')) { return; }
		var $btn = $(this).prop('disabled', true);
		ajax('torob_clear_unmatched', {}).done(function (res) {
			if (res.success) { applyTorobStats(res.data); }
			loadTorobUnmatched({ reset: true });
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* اتصال تک‌ردیفی (از داخل صف یا هر جای دیگری) */
	$(document).on('click', '.wapm-torob-link', function () {
		var $btn = $(this).prop('disabled', true);
		var $tr  = $btn.closest('tr');
		var prk  = $tr.data('prk');
		var $sel = $tr.find('.wapm-torob-product-select');
		var productId = $sel.val();

		if (!productId) {
			wapmToast('اول محصول ووکامرس را انتخاب کنید.', 'warning');
			$btn.prop('disabled', false);
			return;
		}

		ajax('torob_link', {
			product_id: productId,
			prk: prk,
			name: $sel.data('name')
		}).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}
			applyTorobStats(res.data.stats);
			if (res.data.row) { updateRowInDom(res.data.row); }

			// ردیف را از صف بردار و اگر صفحه خالی شد، صفحه‌ی قبل را بیاور.
			$tr.fadeOut(150, function () {
				$(this).remove();
				var $area = $('#wapm-torob-unmatched-area');
				updateBulkBar($area);
				if (!$area.find('.wapm-torob-pick').length) {
					unmatchedState.page = Math.max(1, unmatchedState.page - 1);
					loadTorobUnmatched({});
				}
			});
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '.wapm-torob-ignore', function () {
		var $btn = $(this).prop('disabled', true);
		var $tr  = $btn.closest('tr');

		ajax('torob_ignore_unmatched', { prk: $tr.data('prk') }).done(function (res) {
			if (!res.success) { return; }
			applyTorobStats(res.data);
			$tr.fadeOut(150, function () {
				$(this).remove();
				var $area = $('#wapm-torob-unmatched-area');
				updateBulkBar($area);
				if (!$area.find('.wapm-torob-pick').length) {
					unmatchedState.page = Math.max(1, unmatchedState.page - 1);
					loadTorobUnmatched({});
				}
			});
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---- جداکردن از ترب (از جدول لیست یا هر جای دیگر) ---- */

	$(document).on('click', '.wapm-torob-unlink', function () {
		if (!window.confirm('اتصال این محصول به ترب حذف شود؟ (قیمت رقیب پاک می‌شود ولی قیمت خودتان تغییری نمی‌کند)')) { return; }

		var $btn = $(this).prop('disabled', true);
		var id = $btn.data('id');

		ajax('torob_unlink', { product_id: id }).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}
			applyTorobStats(res.data.stats);
			if (res.data.row) { updateRowInDom(res.data.row); }
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---- پیوند «همگام‌سازی نشده» در ستون ترب → تب ترب ---- */

	$(document).on('click', '.wapm-torob-go', function (e) {
		e.preventDefault();
		$('.wapm-tabs .nav-tab').removeClass('nav-tab-active');
		$('.wapm-tabs .nav-tab[data-tab="torob"]').addClass('nav-tab-active');
		$('.wapm-tab-content').hide();
		$('#wapm-tab-torob').show();
		refreshTorobStats();
		loadTorobUnmatched();
		$('html, body').animate({ scrollTop: $('#wapm-tab-torob').offset().top - 60 }, 300);
	});

	// اگر کاربر مستقیم روی تب ترب آمد، هر دو بخش تازه‌سازی شوند.
	$('.wapm-tabs .nav-tab[data-tab="torob"]').on('click', function () {
		refreshTorobStats();
		loadTorobUnmatched();
	});

	// اگر تب ترب از ابتدا باز است (after reload)، لیست را هم بارگذاری کن.
	if ($('#wapm-tab-torob').is(':visible')) {
		loadTorobUnmatched();
	}

	/* ---------------- راه‌اندازی اولیه ---------------- */

	renderRules();
	renderSchedules();

})(jQuery);
