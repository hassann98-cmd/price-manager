/* global jQuery, WAPM, WAPMUI */
/**
 * WAPM Admin — هسته‌ی مشترک
 *
 * این فایل اول لود می‌شود و ابزارهای مشترک (ajax، escape، اعلان، خواندن/نوشتن
 * فیلدهای فرم، اجرای Batch) را روی `window.WAPMUI` می‌گذارد؛ ماژول‌های بعدی
 * از همان‌جا برمی‌دارند. قبلاً همه‌چیز در یک IIFE هزارها خطی بود و دو تابع
 * هم‌نام در یک scope، بی‌صدا جای هم را می‌گرفتند (نوار انتخاب لیست از کار
 * افتاده بود).
 *
 * ترتیب لود: core → price → automation → list → stock → torob → boot
 */
window.WAPMUI = window.WAPMUI || {};

(function ($, UI) {
	'use strict';

	/**
	 * ترجمه‌ی رشته‌های داخل JS.
	 *
	 * چرا: پیام‌ها در جاوااسکریپت بودند و `.pot` فقط PHP را می‌دید، پس هیچ مترجمی
	 * نمی‌توانست آن‌ها را ترجمه کند. حالا از `wp.i18n` استفاده می‌شود (وابسته به
	 * اسکریپت wp-i18n) و `wp_set_script_translations` فایل ترجمه‌ی JSON را وصل
	 * می‌کند. اگر wp-i18n نبود (محیط تست یا نسخه‌ی قدیمی)، همان متن فارسی
	 * برگردانده می‌شود.
	 */
	function __(text, domain) {
		if (window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function') {
			return window.wp.i18n.__(text, domain || 'wapm');
		}
		return text;
	}

	/**
	 * جای‌گذاری مقدار در رشته‌ی ترجمه‌پذیر.
	 *
	 * چرا: پیام‌هایی مثل «۳ ردیف حذف شود؟» با چسباندن عدد و متن ساخته می‌شدند و
	 * در زبان‌هایی که ترتیب جمله فرق دارد قابل ترجمه نیستند. حالا قالب کامل
	 * ترجمه می‌شود و مقدار داخلش می‌نشیند. اگر wp.i18n.sprintf نبود، نسخه‌ی
	 * ساده‌ی %s/%d استفاده می‌شود.
	 */
	function __sprintf(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		if (window.wp && window.wp.i18n && typeof window.wp.i18n.sprintf === 'function') {
			return window.wp.i18n.sprintf.apply(null, [text].concat(args));
		}
		var i = 0;
		return String(text).replace(/%[sd]/g, function () {
			return i < args.length ? String(args[i++]) : '';
		});
	}

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
	 * timestamp یونیکس (ثانیه) → تاریخ/ساعت محلی کاربر.
	 * برای نمایش «آخرین اجرای خودکار» قانون.
	 */
	function runLabel(ts) {
		if (!ts) { return '—'; }
		try {
			return new Date(Number(ts) * 1000).toLocaleString('fa-IR', { dateStyle: 'short', timeStyle: 'short' });
		} catch (e) {
			return '—';
		}
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
		if ($(this).data('tab') === 'history') { call('loadHistory', 1); }
		if ($(this).data('tab') === 'list') { call('loadProductList'); }
		if ($(this).data('tab') === 'stock') { call('loadStockLogs', 1); }
	});

	/* ---------------- فیلدهای هدف (مشترک بین تب‌ها) ---------------- */

	/**
	 * آیا select2/selectWoo در دسترس است؟
	 *
	 * چرا مهم است: این کتابخانه از ووکامرس می‌آید و همیشه نیست (افزونه‌ی
	 * بهینه‌ساز JS را ادغام/تعویق می‌کند، نسخه‌ای از ووکامرس آن را ندارد، یا
	 * اسکریپت دیگری زودتر خطا داده). نسخه‌ی قبلی بدون گارد `selectWoo()` را صدا
	 * می‌زد؛ اگر نبود، کل `admin-core.js` با TypeError می‌مرد، `window.WAPMUI`
	 * هیچ‌وقت ساخته نمی‌شد و **همه‌ی** تب‌ها، دکمه‌ها و کشوها از کار می‌افتادند.
	 */
	function hasSelectWoo() {
		return !!(window.jQuery && $.fn && typeof $.fn.selectWoo === 'function');
	}

	function initTargetFields($box) {
		var i18n = (window.WAPM && window.WAPM.i18n) || {};

		// ۱) هندلرها *قبل* از زیباسازی bind می‌شوند؛ پس اگر select2 نبود یا موقع
		//    ساخت ویجت خطا داد، تغییر دامنه و بارگذاری برند/ویژگی کار می‌کند.
		$box.find('.wapm-scope').on('change', function () {
			var scope = $(this).val();
			$box.find('.wapm-scope-row').hide();
			$box.find('.wapm-scope-' + scope).show();
		});

		$box.find('.wapm-brand-tax').on('change', function () {
			loadTerms($(this).val(), $box.find('.wapm-brand-select'));
		}).trigger('change');

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

		// ۲) زیباسازی — اختیاری و بی‌خطر برای بقیه‌ی صفحه.
		if (!hasSelectWoo()) {
			$box.addClass('wapm-no-select2');
			return;
		}

		try {
			$box.find('.wapm-product-select').selectWoo({
				dir: 'rtl',
				placeholder: i18n.select_products || '',
				minimumInputLength: 2,
				ajax: {
					url: (window.WAPM && window.WAPM.ajax_url) || '',
					method: 'POST',
					dataType: 'json',
					delay: 350,
					data: function (params) {
						return {
							action: 'wapm_search_products',
							nonce: (window.WAPM && window.WAPM.nonce) || '',
							term: params.term
						};
					},
					processResults: function (res) {
						return { results: (res && res.success) ? res.data : [] };
					}
				}
			});

			$box.find('.wapm-category-select, .wapm-brand-select, .wapm-attribute-select').selectWoo({ dir: 'rtl', width: '100%' });
		} catch (e) {
			// زیباسازی شکست، ولی فرم با سلکت‌های بومی سالم است.
			$box.addClass('wapm-no-select2');
			if (window.console && console.warn) { console.warn('WAPM: selectWoo init failed', e); }
		}
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

	// هر جعبه جدا محافظت می‌شود: خطای یک فرم نباید بقیه‌ی صفحه (تب‌ها، لیست،
	// ترب) را از کار بیندازد. قبلاً یک TypeError این‌جا کل ماژول را می‌کشت.
	$('.wapm-target-fields').each(function () {
		try {
			initTargetFields($(this));
		} catch (e) {
			$(this).addClass('wapm-no-select2');
			if (window.console && console.error) { console.error('WAPM: target fields init failed', e); }
		}
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

	/* ---------------- صادرات برای ماژول‌های بعدی ---------------- */

	UI.state          = state;
	UI.ajax           = ajax;
	UI.fmt            = fmt;
	UI.runLabel       = runLabel;
	UI.esc            = esc;
	UI.__            = __;
	UI.sprintf        = __sprintf;
	UI.wapmToast      = wapmToast;
	UI.readFilters    = readFilters;
	UI.readSettings   = readSettings;
	UI.writeFilters   = writeFilters;
	UI.writeSettings  = writeSettings;
	UI.applySourceMode = applySourceMode;
	UI.runBatchLoop   = runBatchLoop;

})(jQuery, window.WAPMUI);
