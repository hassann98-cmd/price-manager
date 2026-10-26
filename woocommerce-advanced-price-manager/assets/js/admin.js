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

	/* ---------------- تب‌ها ---------------- */

	$('.wapm-tabs .nav-tab').on('click', function (e) {
		e.preventDefault();
		$('.wapm-tabs .nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.wapm-tab-content').hide();
		$('#wapm-tab-' + $(this).data('tab')).show();
		if ($(this).data('tab') === 'history') { loadHistory(1); }
		if ($(this).data('tab') === 'list') { loadProductList(listState.paged || 1); }
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
		$settings.find('.wapm-mode').on('change', function () {
			var fixed = $settings.find('.wapm-mode:checked').val() === 'fixed';
			$settings.find('.wapm-currency-unit').toggle(fixed);
			$settings.find('.wapm-amount-unit-percent').toggle(!fixed);
		});
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
				if (!count) { window.alert(WAPM.i18n.no_targets); return; }
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
					window.alert((res.data && res.data.message) || WAPM.i18n.error);
					return;
				}
				window.alert(res.data.message);

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
			.fail(function () { window.alert(WAPM.i18n.error); })
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
				window.alert(WAPM.i18n.done);
			} else {
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
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
		if (!schedule.start) { window.alert('تاریخ شروع الزامی است.'); return; }
		ajax('save_schedule', { schedule: JSON.stringify(schedule) }).done(function (res) {
			if (res.success) {
				state.schedules = res.data.schedules;
				renderSchedules();
				$('#wapm-schedule-id').val('');
				$('#wapm-schedule-title').val('');
				window.alert(WAPM.i18n.done);
			} else {
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
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
		lastRows: [],
		pending: {}
	};
	var listSearchTimer = null;
	var LIST_COLS = 14;
	var COLUMN_STORAGE_KEY = 'wapm_list_visible_columns';
	var ALL_COLUMNS = ['image', 'regular_price', 'sale_price', 'manage_stock', 'stock_qty', 'stock_status', 'categories', 'brand', 'torob', 'id', 'sku'];

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
		var cls = {
			instock: 'background:#ecfdf5;color:#059669;border-color:#a7f3d0',
			outofstock: 'background:#fef2f2;color:#dc2626;border-color:#fecaca',
			onbackorder: 'background:#fffbeb;color:#b45309;border-color:#fde68a'
		};
		return '<span style="' + (cls[status] || 'background:#f8fafc;color:#475569;border-color:#e2e8f0') +
			'" class="px-3 py-1 rounded-full text-[10px] font-black border inline-block">' + esc(label) + '</span>';
	}

	function priceCellHtml(r, field) {
		var raw = (field === 'regular_price') ? r.regular_price : r.sale_price;
		var fmtVal = (field === 'regular_price') ? r.regular_price_fmt : r.sale_price_fmt;
		var val = (raw === null || raw === undefined) ? '' : raw;

		// محصول متغیر: قیمت از واریشن‌ها می‌آید. ویرایش قیمت والد بی‌اثر است،
		// پس فقط محدوده‌ی قیمت واریشن‌ها را نشان می‌دهیم (غیرقابل ویرایش).
		if (r.is_variable) {
			var hint = '<span style="font-size:9px;color:#94a3b8;display:block">محدوده‌ی واریشن‌ها</span>';
			if (field === 'sale_price') {
				return '<span class="text-slate-300 text-[11px]" title="قیمت ویژه‌ی هر واریشن را در ردیف همان واریشن ویرایش کنید">—</span>';
			}
			return '<div class="text-[11px] font-bold text-slate-500" style="line-height:1.6">' +
				(r.price_range ? esc(r.price_range) : '—') + hint + '</div>';
		}

		if (listState.viewMode === 'excel') {
			return '<input type="number" step="any" min="0" class="wapm-cell-input" style="width:100%;height:34px;padding:0 8px;border-radius:8px;border:1px solid #e2e8f0;font-size:12px;font-weight:700" ' +
				'data-id="' + r.id + '" data-field="' + field + '" value="' + esc(val) + '">';
		}
		return '<div class="wapm-cell-display cursor-pointer px-2 py-1.5 rounded-lg hover:bg-slate-50 text-[12px] font-black text-primary-700" ' +
			'data-id="' + r.id + '" data-field="' + field + '" data-value="' + esc(val) + '">' + (fmtVal || '—') + '</div>';
	}

	function stockCellHtml(r) {
		var val = (r.stock_quantity === null || r.stock_quantity === undefined) ? '' : r.stock_quantity;
		if (!r.manage_stock) {
			return '<span class="text-slate-300 text-[11px]">—</span>';
		}
		if (listState.viewMode === 'excel') {
			return '<input type="number" step="1" min="0" class="wapm-cell-input" style="width:100%;height:34px;padding:0 8px;border-radius:8px;border:1px solid #e2e8f0;font-size:12px;font-weight:700" ' +
				'data-id="' + r.id + '" data-field="stock_quantity" value="' + esc(val) + '">';
		}
		return '<div class="wapm-cell-display cursor-pointer px-2 py-1.5 rounded-lg hover:bg-slate-50 text-[12px] font-black text-slate-700" ' +
			'data-id="' + r.id + '" data-field="stock_quantity" data-value="' + esc(val) + '">' + (val === '' ? '—' : fmt(val)) + '</div>';
	}

	function manageStockToggleHtml(r) {
		var on = !!r.manage_stock;
		return '<button type="button" class="wapm-manage-stock-toggle" data-id="' + r.id + '" data-enabled="' + (on ? 1 : 0) + '" ' +
			'style="width:40px;height:20px;border-radius:999px;position:relative;border:0;cursor:pointer;background:' + (on ? '#10b981' : '#cbd5e1') + '">' +
			'<span style="position:absolute;top:2px;width:16px;height:16px;background:#fff;border-radius:999px;transition:right .15s;right:' + (on ? '2px' : '22px') + '"></span>' +
			'</button>';
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
			pricier:       { bg: '#fef2f2', color: '#dc2626', border: '#fecaca', text: '🔴 ' + Math.abs(Number(t.diff_pct)).toFixed(1) + '٪ گران‌تر' },
			cheaper:       { bg: '#ecfdf5', color: '#059669', border: '#a7f3d0', text: '🟢 ' + Math.abs(Number(t.diff_pct)).toFixed(1) + '٪ ارزان‌تر' },
			equal:         { bg: '#f8fafc', color: '#475569', border: '#e2e8f0', text: 'برابر با رقیب' },
			stale:         { bg: '#fffbeb', color: '#b45309', border: '#fde68a', text: '⚠ داده کهنه' },
			no_competitor: { bg: '#eff6ff', color: '#1d4ed8', border: '#bfdbfe', text: 'فقط ما در ترب' },
			needs_shop_id: { bg: '#fffbeb', color: '#b45309', border: '#fde68a', text: 'شناسه فروشگاه تنظیم نشده' },
			no_price:      { bg: '#f8fafc', color: '#475569', border: '#e2e8f0', text: 'قیمت نامشخص' },
			none:          { bg: '#f8fafc', color: '#475569', border: '#e2e8f0', text: 'بدون داده' }
		};
		var b = map[t.status] || map.none;
		return '<span style="background:' + b.bg + ';color:' + b.color + ';border:1px solid ' + b.border +
			';padding:2px 8px;border-radius:999px;font-size:10px;font-weight:900;display:inline-block">' + esc(b.text) + '</span>';
	}

	function torobCellHtml(r) {
		if (r.is_variation) {
			return '<span class="text-slate-300 text-[11px]">—</span>';
		}

		// بدون داده: راهنمای اقدام (شماره‌دار، مطابق تب ترب).
		if (!r.torob) {
			return '<a href="#" class="wapm-torob-go" style="font-size:10px;color:#7f54b3;font-weight:800;text-decoration:none" ' +
				'title="داده‌ای از ترب برای این محصول نداریم. از تب «ترب» همگام‌سازی کنید یا دستی وصل کنید.">همگام‌سازی نشده — راهنما</a>';
		}

		var t = r.torob;
		var html = '<div style="min-width:150px">';
		html += '<div>' + torobBadge(t);
		if (t.link === 'manual') {
			html += ' <span style="font-size:9px;font-weight:800;color:#7f54b3;background:#f5f1fa;border:1px solid #e2d6ef;border-radius:999px;padding:1px 6px">دستی</span>';
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

		// ناسازگاری فید: قیمتی که ترب از ما نشان می‌دهد با قیمت سایت یکی نیست.
		if (t.mismatch_pct !== null && t.mismatch_pct !== undefined && Math.abs(Number(t.mismatch_pct)) > Number(t.tolerance_pct || 1)) {
			html += '<div style="font-size:9px;color:#b45309;font-weight:800" title="قیمتی که ترب از فروشگاه شما نشان می‌دهد با قیمت ووکامرس نمی‌خواند — احتمالاً فید/کش ترب به‌روز نیست">⚠ فید ترب: ' +
				fmt(t.our_price_torob) + '</div>';
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
			links += '<a href="' + esc(t.url) + '" target="_blank" rel="noopener" style="font-size:9px;color:#2563eb;font-weight:800">ترب</a>';
		}
		links += ' <button type="button" class="wapm-torob-check" data-id="' + r.id + '" title="همین حالا از ترب بخوان (۱ درخواست)"' +
			' style="background:none;border:0;color:#7f54b3;font-size:9px;font-weight:800;cursor:pointer;padding:0">بررسی الان</button>';
		links += ' <button type="button" class="wapm-torob-unlink" data-id="' + r.id + '" title="جداکردن از ترب"' +
			' style="background:none;border:0;color:#dc2626;font-size:9px;font-weight:800;cursor:pointer;padding:0">جداکردن</button>';

		html += '<div style="margin-top:2px">' + links + '</div>';
		html += '</div>';

		return html;
	}

	function actionsCellHtml(r) {
		var dupBtn = r.can_duplicate
			? '<button type="button" class="wapm-action-duplicate" data-id="' + r.id + '" style="display:block;width:100%;text-align:right;padding:8px 12px;font-size:12px;font-weight:700;color:#475569;background:none;border:0;cursor:pointer">کپی</button>'
			: '';
		return '<div class="wapm-actions-wrap" style="position:relative">' +
			'<button type="button" class="wapm-actions-toggle" data-id="' + r.id + '" style="padding:8px;border-radius:10px;color:#94a3b8;background:none;border:0;cursor:pointer">' +
			'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>' +
			'</button>' +
			'<div class="wapm-actions-menu" style="display:none;position:absolute;left:0;top:100%;margin-top:4px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:30;min-width:110px">' +
			dupBtn +
			'<button type="button" class="wapm-action-delete" data-id="' + r.id + '" style="display:block;width:100%;text-align:right;padding:8px 12px;font-size:12px;font-weight:700;color:#dc2626;background:none;border:0;cursor:pointer">حذف</button>' +
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

	function loadProductList(paged) {
		listState.paged = paged || 1;
		listState.selected = {};
		updateBulkBar();
		$('#wapm-list-tbody').html('<tr><td colspan="' + LIST_COLS + '" class="text-center py-10 text-slate-400 text-sm">' + esc(WAPM.i18n.loading) + '</td></tr>');
		ajax('list_products', {
			filters: JSON.stringify({
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
			})
		}).done(function (res) {
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

	$('#wapm-smart-clear').on('click', function () {
		$('#wapm-smart-price-min, #wapm-smart-price-max, #wapm-smart-stock-min, #wapm-smart-stock-max').val('');
		$('#wapm-smart-type').val('');
		$('#wapm-smart-on-sale').prop('checked', false);
		listState.price_min = ''; listState.price_max = ''; listState.stock_min = ''; listState.stock_max = '';
		listState.product_type = ''; listState.on_sale = false;
		loadProductList(1);
	});

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
			window.alert('برخی تغییرات هنوز ذخیره نشده‌اند. ابتدا «ذخیره تغییرات» را بزنید.');
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
	}

	function saveField(id, field, value) {
		ajax('update_field', { product_id: id, field: field, value: value }).done(function (res) {
			if (res.success) { updateRowInDom(res.data.row); }
			else { window.alert((res.data && res.data.message) || WAPM.i18n.error); rerenderCurrent(); }
		}).fail(function () {
			window.alert(WAPM.i18n.error);
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
	$(document).on('click', '#wapm-list-tbody .wapm-cell-display', function () {
		if (listState.viewMode !== 'simple') { return; }
		var $cell = $(this);
		var id = $cell.data('id');
		var field = $cell.data('field');
		var value = $cell.data('value');
		var $input = $('<input type="number" step="any" min="0">').val(value)
			.css({ width: '100%', height: '34px', padding: '0 8px', 'border-radius': '8px', border: '1px solid #7f54b3', 'font-size': '12px', 'font-weight': 700 });
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
	$(document).on('change', '#wapm-list-tbody .wapm-cell-input', function () {
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
				window.alert(WAPM.i18n.done);
				return;
			}

			var lines = failed.slice(0, 8).map(function (f) { return '• ' + f.name + ' — ' + f.message; });
			if (failed.length > 8) { lines.push('…'); }
			window.alert(failed.length + ' مورد ذخیره نشد و در صف باقی ماند:\n' + lines.join('\n'));
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
			else { window.alert((res.data && res.data.message) || WAPM.i18n.error); }
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
		ajax('duplicate_product', { product_id: $(this).data('id') }).done(function (res) {
			if (res.success) { loadProductList(listState.paged); }
			else { window.alert((res.data && res.data.message) || WAPM.i18n.error); }
		});
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
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
			}
		});
	});

	/* ---- چک‌باکس‌های bulk و نوار عملیات گروهی ---- */

	function updateSelectAllCheckbox() {
		var $checks = $('#wapm-list-tbody .wapm-row-check');
		var all = $checks.length > 0 && $checks.filter(':checked').length === $checks.length;
		$('#wapm-list-select-all').prop('checked', all);
	}

	function updateBulkBar() {
		var count = Object.keys(listState.selected).length;
		$('#wapm-list-selected-count').text(count);
		$('#wapm-list-bulk-bar').toggle(count > 0);
	}

	$(document).on('change', '.wapm-row-check', function () {
		var id = $(this).data('id');
		if ($(this).is(':checked')) { listState.selected[id] = true; } else { delete listState.selected[id]; }
		updateBulkBar();
		updateSelectAllCheckbox();
	});

	$('#wapm-list-select-all').on('change', function () {
		var checked = $(this).is(':checked');
		$('#wapm-list-tbody .wapm-row-check').prop('checked', checked).each(function () {
			var id = $(this).data('id');
			if (checked) { listState.selected[id] = true; } else { delete listState.selected[id]; }
		});
		updateBulkBar();
	});

	$('#wapm-list-bulk-clear').on('click', function () {
		listState.selected = {};
		$('#wapm-list-tbody .wapm-row-check').prop('checked', false);
		$('#wapm-list-select-all').prop('checked', false);
		updateBulkBar();
	});

	$('#wapm-list-bulk-run').on('click', function () {
		var action = $('#wapm-list-bulk-select').val();
		var ids = Object.keys(listState.selected);
		if (!action) { window.alert('یک عملیات انتخاب کنید.'); return; }
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
				window.alert(res.data.message);
				listState.selected = {};
				updateBulkBar();
				loadProductList(listState.paged);
			} else {
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
			}
		});
	});

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
		if (!file) { window.alert('فایلی انتخاب نشده است.'); return; }

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
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
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
			window.alert(WAPM.i18n.error);
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	$('#wapm-torob-regen-token').on('click', function () {
		if (!window.confirm('بوکمارکلت فعلی از کار می‌افتد و باید دوباره ساخته شود. مطمئنید؟')) { return; }
		var $btn = $(this).prop('disabled', true);
		ajax('torob_regenerate_token', {}).done(function (res) {
			if (res.success) {
				window.alert('توکن جدید ساخته شد. صفحه را رفرش کنید تا لینک بوکمارکلت جدید را ببینید و دوباره به نوار بوکمارک بکشید.');
			} else {
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
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
			window.alert('اول شناسه‌ی فروشگاه را وارد کنید.');
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

	var catalogRun = { stop: false, running: false };

	function syncCatalogPage(page) {
		if (catalogRun.stop) { return finishCatalog('متوقف شد. هر وقت خواستید دوباره شروع کنید؛ از صفحه‌ی اول ادامه می‌یابد.'); }

		ajax('torob_sync_catalog', { page: page, size: 24 }).done(function (res) {
			if (!res.success) {
				renderApiState(res.data && res.data.state);
				finishCatalog('⚠ ' + ((res.data && res.data.message) || WAPM.i18n.error));
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
				setTimeout(function () { syncCatalogPage(page + 1); }, 3200);
			} else {
				finishCatalog('✓ همگام‌سازی کاتالوگ تمام شد — ' + fmt(catalogRun.synced) + ' محصول. ' +
					'حالا ستون «قیمت ترب» نشان می‌دهد ترب چه قیمتی از شما نشان می‌دهد.');
			}
		}).fail(function () {
			finishCatalog('خطای شبکه. اگر چند صفحه خوانده شد، دوباره شروع کنید.');
		});
	}

	function finishCatalog(msg) {
		catalogRun.running = false;
		$('#wapm-torob-catalog-sync').prop('disabled', false);
		$('#wapm-torob-catalog-stop').hide();
		$('#wapm-torob-catalog-result').prepend(
			'<div class="notice notice-info inline"><p>' + esc(msg) + '</p></div>'
		);
	}

	$('#wapm-torob-catalog-sync').on('click', function () {
		if (catalogRun.running) { return; }

		catalogRun = { stop: false, running: true, synced: 0 };

		$(this).prop('disabled', true);
		$('#wapm-torob-catalog-stop').show();
		$('#wapm-torob-catalog-result').empty();

		syncCatalogPage(0);
	});

	$('#wapm-torob-catalog-stop').on('click', function () {
		catalogRun.stop = true;
		$(this).prop('disabled', true);
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
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
				return;
			}

			renderApiState(res.data.api);
			if (res.data.stats_payload) { applyTorobStats(res.data.stats_payload); }
			if (res.data.row) { updateRowInDom(res.data.row); }
		}).fail(function () {
			window.alert(WAPM.i18n.error);
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

	/* ---- ۵) تطبیق‌نیافته‌ها ---- */

	function torobHowLabel(row) {
		if (row.candidate) {
			return 'مبهم — نام مشابه چند محصول' + (row.score ? ' (شباهت ' + Math.round(row.score * 100) + '٪)' : '');
		}
		if (row.how === 'no_match') { return 'هیچ محصول مشابهی پیدا نشد'; }
		if (row.how === 'sku_mismatch') { return 'کد/SKU پیدا شد ولی نام نمی‌خواند'; }
		return 'به‌طور خودکار تطبیق نخورد';
	}

	function renderUnmatchedRow(row) {
		var html = '<tr data-prk="' + esc(row.prk) + '">';

		html += '<td style="width:34%"><div style="font-weight:700">' + esc(row.name || '—') + '</div>' +
			'<div style="font-size:11px;color:#64748b">' + esc(torobHowLabel(row)) + '</div>' +
			'<div style="font-size:11px;color:#94a3b8;margin-top:2px">' +
			(row.sellers ? fmt(row.sellers) + ' فروشنده • ' : '') +
			'آخرین مشاهده: ' + esc(row.seen_i18n || '—') +
			'</div></td>';

		html += '<td style="width:12%">' + (row.price !== null && row.price !== undefined ? fmt(row.price) : '—') + '</td>';

		html += '<td style="width:32%">' +
			'<select class="wapm-torob-product-select" style="width:100%" data-prk="' + esc(row.prk) + '" data-name="' + esc(row.name || '') + '">' +
			(row.candidate ? '<option value="' + row.candidate.id + '" selected>' + esc(row.candidate.text) + '</option>' : '') +
			'</select></td>';

		html += '<td style="width:22%;white-space:nowrap">' +
			'<button type="button" class="button button-primary wapm-torob-link">اتصال</button> ' +
			'<button type="button" class="button wapm-torob-ignore" title="این مورد را نادیده بگیر">نادیده‌گرفتن</button> ' +
			'<a href="' + esc(row.url) + '" target="_blank" rel="noopener" class="button">ترب</a>' +
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
		});
	}

	function loadTorobUnmatched() {
		var $area = $('#wapm-torob-unmatched-area');
		if (!$area.length) { return; }

		$area.html('<p>' + esc(WAPM.i18n.loading) + '</p>');

		ajax('torob_get_unmatched').done(function (res) {
			if (!res.success) {
				$area.html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
				return;
			}

			var rows = res.data.rows || [];
			$('#wapm-torob-unmatched-count').text(fmt(res.data.total || 0));

			if (!rows.length) {
				$area.html('<p class="description">همه‌ی محصولات ترب تطبیق خوردند. 🎉</p>');
				return;
			}

			var html = '<table class="widefat striped"><thead><tr>' +
				'<th>محصول ترب</th><th>قیمت</th><th>محصول ووکامرس</th><th></th>' +
				'</tr></thead><tbody>';

			for (var i = 0; i < rows.length; i++) { html += renderUnmatchedRow(rows[i]); }
			html += '</tbody></table>';

			$area.html(html);
			initUnmatchedSelectors($area);
		}).fail(function () {
			$area.html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		});
	}

	$('#wapm-torob-unmatched-refresh').on('click', loadTorobUnmatched);

	$('#wapm-torob-unmatched-clear').on('click', function () {
		if (!window.confirm('کل لیست تطبیق‌نیافته‌ها پاک شود؟ (داده‌ی محصولات متصل‌شده دست نمی‌خورد)')) { return; }
		var $btn = $(this).prop('disabled', true);
		ajax('torob_clear_unmatched', {}).done(function (res) {
			if (res.success) { applyTorobStats(res.data); }
			loadTorobUnmatched();
		}).always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '.wapm-torob-link', function () {
		var $btn = $(this).prop('disabled', true);
		var $tr = $btn.closest('tr');
		var prk = $tr.data('prk');
		var $sel = $tr.find('.wapm-torob-product-select');
		var productId = $sel.val();

		if (!productId) {
			window.alert('اول محصول ووکامرس را انتخاب کنید.');
			$btn.prop('disabled', false);
			return;
		}

		ajax('torob_link', {
			product_id: productId,
			prk: prk,
			name: $sel.data('name')
		}).done(function (res) {
			if (!res.success) {
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
				return;
			}
			applyTorobStats(res.data.stats);
			$tr.fadeOut(200, function () { $(this).remove(); });
			if (res.data.row) { updateRowInDom(res.data.row); }
		}).fail(function () {
			window.alert(WAPM.i18n.error);
		}).always(function () { $btn.prop('disabled', false); });
	});

	$(document).on('click', '.wapm-torob-ignore', function () {
		var $btn = $(this).prop('disabled', true);
		var $tr = $btn.closest('tr');
		ajax('torob_ignore_unmatched', { prk: $tr.data('prk') }).done(function (res) {
			if (res.success) {
				applyTorobStats(res.data);
				$tr.fadeOut(200, function () { $(this).remove(); });
			}
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---- جداکردن از ترب (از جدول لیست یا هر جای دیگر) ---- */

	$(document).on('click', '.wapm-torob-unlink', function () {
		if (!window.confirm('اتصال این محصول به ترب حذف شود؟ (قیمت رقیب پاک می‌شود ولی قیمت خودتان تغییری نمی‌کند)')) { return; }

		var $btn = $(this).prop('disabled', true);
		var id = $btn.data('id');

		ajax('torob_unlink', { product_id: id }).done(function (res) {
			if (!res.success) {
				window.alert((res.data && res.data.message) || WAPM.i18n.error);
				return;
			}
			applyTorobStats(res.data.stats);
			if (res.data.row) { updateRowInDom(res.data.row); }
		}).fail(function () {
			window.alert(WAPM.i18n.error);
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
