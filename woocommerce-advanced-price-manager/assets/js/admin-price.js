/* global jQuery, WAPM, WAPMUI */
/**
 * WAPM Admin — تب «تغییر قیمت» (پیش‌نمایش، Dry Run، اعمال گروهی، Rollback)
 */
(function ($, UI) {
	'use strict';

	var ajax = UI.ajax, esc = UI.esc, fmt = UI.fmt, wapmToast = UI.wapmToast;
	var state = UI.state, runBatchLoop = UI.runBatchLoop;
	var readFilters = UI.readFilters, readSettings = UI.readSettings;
	var __ = UI.__;

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
				if ($('#wapm-tab-history').is(':visible')) { call('loadHistory', 1); }
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
})(jQuery, window.WAPMUI);
