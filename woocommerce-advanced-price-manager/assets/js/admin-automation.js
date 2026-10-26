/* global jQuery, WAPM, WAPMUI */
/**
 * WAPM Admin — قوانین، زمان‌بندی و تاریخچه
 */
(function ($, UI) {
	'use strict';

	var ajax = UI.ajax, esc = UI.esc, fmt = UI.fmt, wapmToast = UI.wapmToast;
	var runLabel = UI.runLabel, state = UI.state, runBatchLoop = UI.runBatchLoop;
	var readFilters = UI.readFilters, readSettings = UI.readSettings;
	var writeFilters = UI.writeFilters, writeSettings = UI.writeSettings;
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
			$tbody.append('<tr><td colspan="5">—</td></tr>');
			return;
		}
		state.rules.forEach(function (r) {
			var badge = r.enabled
				? '<span class="wapm-badge wapm-badge-on">' + WAPM.i18n.enabled + '</span>'
				: '<span class="wapm-badge wapm-badge-off">' + WAPM.i18n.disabled + '</span>';
			var dates = (r.start || r.end) ? '<br><small>' + esc(r.start || '…') + ' ← ' + esc(r.end || '…') + '</small>' : '';
			var lastRun = r.last_run ? '<br><small>' + 'آخرین اجرای خودکار: ' + esc(runLabel(r.last_run)) + '</small>' : '';
			var auto = r.auto
				? '<span class="wapm-badge wapm-badge-on" title="در زمان‌بندی ساعتانه اجرا می‌شود">خودکار</span>' + lastRun
				: '<span class="wapm-badge wapm-badge-off" title="فقط با دکمه‌ی اجرا">دستی</span>';
			$tbody.append('<tr data-id="' + esc(r.id) + '">' +
				'<td>' + esc(r.title || r.id) + legacyScopeWarning(r.settings) + dates + '</td>' +
				'<td>' + esc(r.priority) + '</td>' +
				'<td>' + badge + '</td>' +
				'<td>' + auto + '</td>' +
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
			auto: $('#wapm-rule-auto').is(':checked'),
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
				$('#wapm-rule-auto').prop('checked', false);
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
		$('#wapm-rule-auto').prop('checked', !!rule.auto);
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
		if (!schedule.start) { wapmToast(__('تاریخ شروع الزامی است.'), 'warning'); return; }
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
			// پاسخ ناقص (rows نبود) نباید کل تب تاریخچه را با خطای جاوااسکریپت
			// از کار بیندازد؛ «—» نشان می‌دهد که ردیفی برنگشته است.
			var rows = res.data.rows || [];
			if (!rows.length) {
				$tbody.append('<tr><td colspan="9">—</td></tr>');
			}
			rows.forEach(function (r) {
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

	/* ---------------- صادرات ---------------- */

	UI.renderRules     = renderRules;
	UI.renderSchedules = renderSchedules;
	UI.loadHistory     = loadHistory;

})(jQuery, window.WAPMUI);
