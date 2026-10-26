/* global jQuery, WAPM, WAPMUI */
/**
 * WAPM Admin — تب «موجودی» (ویرایش گروهی، تاریخچه، Undo، CSV، کشوی موجودی)
 */
(function ($, UI) {
	'use strict';

	var ajax = UI.ajax, esc = UI.esc, fmt = UI.fmt, wapmToast = UI.wapmToast;
	var state = UI.state;
	var readFilters = UI.readFilters;
	var listState = UI.listState;
	var loadProductList = UI.loadProductList;
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
		if (!window.confirm(__('موجودی ') + stockState.previewed + ' محصول تغییر می‌کند. ادامه می‌دهید؟')) { return; }

		var payload = stockTargetsPayload();
		var ids = payload.ids && payload.ids.length
			? payload.ids
			: ($('#wapm-stock-result').data('ids') || []);

		// در حالت فیلتر، پیش‌نمایش شناسه‌ها را برمی‌گرداند؛ همان‌ها را اعمال می‌کنیم
		// تا بین پیش‌نمایش و اعمال، لیست عوض نشود.
		if (!ids.length) {
			wapmToast(__('ابتدا پیش‌نمایش بگیرید.'), 'warning');
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
			call('refreshListIfLoaded');
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
			wapmToast(__('ابتدا در جدول، محصول‌ها را تیک بزنید.'), 'warning');
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
			wapmToast(__('درصد باید بین ۱ تا ۱۰۰ باشد.'), 'warning');
			return;
		}
		if (op.unit === 'count' && op.amount === '') {
			wapmToast(__('مقدار را وارد کنید.'), 'warning');
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
		if (!window.confirm(__('موجودی ') + sdState.will + ' مورد تغییر می‌کند. ادامه می‌دهید؟')) { return; }

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
			$('#wapm-stock-last-batch').text(stockState.lastBatch || __('هیچ Batch قابل بازگردانی نیست'));
			$('#wapm-stock-undo-last-btn').prop('disabled', !stockState.lastBatch);
		});
	}

	function stockUndo(batch, $btn) {
		if (!batch) { return; }
		// $btn اختیاری است: از اعلان (toast) هم صدا زده می‌شود و آن‌جا دکمه‌ای نیست.
		$btn = $btn && $btn.length ? $btn : $();
		if (!window.confirm(__('موجودی‌های این Batch به مقدار قبل برمی‌گردند. ادامه می‌دهید؟'))) { return; }

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
			call('refreshListIfLoaded');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	}

	$('#wapm-stock-undo-last-btn').on('click', function () {
		stockUndo(stockState.lastBatch, $(this));
	});

	$(document).on('click', '.wapm-stock-undo-force', function () {
		var batch = $(this).data('batch');
		if (!window.confirm(__('حتی تغییراتی که بعد از این عملیات انجام شده‌اند بازنویسی می‌شوند. مطمئن هستید؟'))) { return; }
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
			$('#wapm-stock-last-batch').text(stockState.lastBatch || __('هیچ Batch قابل بازگردانی نیست'));
			$('#wapm-stock-undo-last-btn').prop('disabled', !stockState.lastBatch);

			$tbody.empty();
			// پاسخ بدون rows نباید تب موجودی را با خطا از کار بیندازد.
			var rows = d.rows || [];
			if (!rows.length) {
				$tbody.append('<tr><td colspan="9">هنوز تغییری در موجودی ثبت نشده است.</td></tr>');
			}

			rows.forEach(function (r) {
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
		if (!file) { wapmToast(__('فایلی انتخاب نشده است.'), 'warning'); return; }

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
				call('refreshListIfLoaded');
			}
		}).fail(function () {
			$('#wapm-stock-import-result').html('<div class="notice notice-error inline"><p>' + esc(WAPM.i18n.error) + '</p></div>');
		}).always(function () {
			$btns.prop('disabled', false);
		});
	}

	$('#wapm-stock-import-preview-btn').on('click', function () { stockImport(false); });
	$('#wapm-stock-import-apply-btn').on('click', function () { stockImport(true); });

	/* ---------------- صادرات ---------------- */

	UI.stockSelectedIds = stockSelectedIds;
	UI.stockOperation = stockOperation;
	UI.stockTargetsPayload = stockTargetsPayload;
	UI.syncStockUnit = syncStockUnit;
	UI.stockReasons = stockReasons;
	UI.stockReasonLabel = stockReasonLabel;
	UI.stockRowsTable = stockRowsTable;
	UI.sdOperation = sdOperation;
	UI.sdSelectedIds = sdSelectedIds;
	UI.sdOpen = sdOpen;
	UI.sdClose = sdClose;
	UI.sdSyncUi = sdSyncUi;
	UI.refreshStockBatch = refreshStockBatch;
	UI.stockUndo = stockUndo;
	UI.loadStockLogs = loadStockLogs;
	UI.stockImport = stockImport;

})(jQuery, window.WAPMUI);
