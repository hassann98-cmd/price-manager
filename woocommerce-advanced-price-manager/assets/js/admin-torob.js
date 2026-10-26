/* global jQuery, WAPM, WAPMUI */
/**
 * WAPM Admin — تب «ترب»
 *
 * تنظیمات، جستجوی فروشگاه، همگام‌سازی کاتالوگ از API، فید CSV، صف تأیید تطبیق
 * و بررسی فوری قیمت.
 */
(function ($, UI) {
	'use strict';

	var ajax = UI.ajax, esc = UI.esc, fmt = UI.fmt, wapmToast = UI.wapmToast;
	var state = UI.state;
	var listState = UI.listState;
	var loadProductList = UI.loadProductList;
	var updateRowInDom = UI.updateRowInDom;

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

	/* ---- «هم‌تراز با ترب»: قیمت = ارزان‌ترین رقیب منهای x ---- */

	var alignState = { id: 0, row: null, ready: false };

	function alignFindRow(id) {
		var rows = listState.lastRows || [];
		for (var i = 0; i < rows.length; i++) {
			if (Number(rows[i].id) === Number(id)) { return rows[i]; }
		}
		return null;
	}

	/**
	 * نمودار کوچک روند قیمت رقیب (SVG درون‌خطی، بدون کتابخانه).
	 *
	 * محور زمان راست→چپ است (سایت RTL) تا «گذشته» سمت راست و «امروز» سمت چپ
	 * دیده شود؛ همین کار با scaleX(-1) انجام می‌شود.
	 */
	function sparkline(points) {
		if (!points || points.length < 2) { return ''; }

		var w = 260, h = 34, pad = 3;
		var prices = points.map(function (p) { return Number(p.p); });
		var min = Math.min.apply(null, prices);
		var max = Math.max.apply(null, prices);
		var span = (max - min) || 1;
		var step = (w - pad * 2) / (points.length - 1);

		var d = points.map(function (p, i) {
			var x = pad + i * step;
			var y = pad + (h - pad * 2) * (1 - (Number(p.p) - min) / span);
			return (i ? 'L' : 'M') + x.toFixed(1) + ' ' + y.toFixed(1);
		}).join(' ');

		var falling = prices[prices.length - 1] < prices[0];
		var color = falling ? '#067647' : '#b42318';

		return '<svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="width:100%;height:34px;display:block;transform:scaleX(-1)" aria-hidden="true">' +
			'<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></path>' +
			'</svg>';
	}

	/** نمایش وضعیت فعلی محصول در کشو (قیمت ما، رقیب، اختلاف، تازگی). */
	function alignFactsHtml(row) {
		var t = (row && row.torob) || {};
		var facts = [];
		var current = row ? (row.regular_price !== '' && row.regular_price !== null ? row.regular_price : row.sale_price) : '';

		facts.push(['قیمت فعلی ما', fmt(current)]);
		if (t.competitor_price !== null && t.competitor_price !== undefined) {
			facts.push(['ارزان‌ترین رقیب', fmt(t.competitor_price)]);
		}
		if (t.diff_pct !== null && t.diff_pct !== undefined) {
			var d = Number(t.diff_pct);
			var color = d > 0 ? '#b42318' : (d < 0 ? '#067647' : '#475467');
			facts.push(['اختلاف با رقیب', '<span style="color:' + color + '">' + (d > 0 ? '+' : '') + d.toFixed(1) + '٪</span>']);
		}
		if (t.sellers) {
			facts.push(['تعداد فروشنده‌ها', fmt(t.sellers)]);
		}
		if (t.sellers_below !== null && t.sellers_below !== undefined) {
			facts.push([
				'رقیب زیر قیمت ما',
				Number(t.sellers_below) > 0
					? '<span style="color:#b42318">' + fmt(t.sellers_below) + ' نفر</span>'
					: '<span style="color:#067647">هیچ‌کدام</span>'
			]);
		}
		facts.push(['تازگی داده‌ی ترب', call('torobAgeLabel', t.age_days)]);

		if (t.history_change) {
			var hc = t.history_change;
			var down = Number(hc.pct) < 0;
			var color2 = down ? '#067647' : '#b42318';
			facts.push(['تغییر رقیب (۷ روز)', '<span style="color:' + color2 + '">' + (down ? '▼' : '▲') + ' ' +
				Math.abs(Number(hc.pct)).toFixed(1) + '٪</span>']);
		}

		var html = facts.map(function (f) {
			return '<div class="wapm-align-fact"><span>' + esc(f[0]) + '</span><b>' + f[1] + '</b></div>';
		}).join('');

		// نمودار روند (اگر حداقل دو نقطه‌ی تاریخی داریم).
		if (t.history && t.history.length > 1) {
			html += '<div class="wapm-align-fact"><span>روند قیمت رقیب</span><b style="font-weight:700;color:#667085">' +
				fmt(t.history[0].p) + ' → ' + fmt(t.history[t.history.length - 1].p) + '</b></div>';
			html += '<div style="grid-column:1 / -1;margin-top:2px">' + sparkline(t.history) + '</div>';
		}

		return html;
	}

	function alignOpen(id) {
		var row = alignFindRow(id);

		alignState.id = id;
		alignState.row = row;
		alignState.ready = false;

		$('#wapm-ad-facts').html(alignFactsHtml(row));
		$('#wapm-ad-result').empty();
		$('#wapm-ad-apply').prop('disabled', true);
		$('#wapm-ad-note').text(row ? esc(row.name) : '');
		$('#wapm-align-drawer-overlay').addClass('is-open');
		$('#wapm-align-drawer').addClass('is-open').attr('aria-hidden', 'false');

		window.setTimeout(function () { $('#wapm-ad-value').trigger('focus').trigger('select'); }, 240);
	}

	function alignClose() {
		$('#wapm-align-drawer-overlay').removeClass('is-open');
		$('#wapm-align-drawer').removeClass('is-open').attr('aria-hidden', 'true');
		alignState.ready = false;
	}

	function alignResultHtml(data) {
		var skipped = data.skipped || [];

		if (skipped.length) {
			var s = skipped[0];
			return '<div class="wapm-align-skip">' + esc(s.label || s.reason || 'امکان هم‌ترازی نیست') + '</div>';
		}

		var rows = data.rows || [];
		if (!rows.length) {
			return '<div class="wapm-align-skip">با این مقدار، قیمتی تغییر نمی‌کند. مقدار زیربرش را عوض کنید.</div>';
		}

		var html = '';
		rows.forEach(function (r) {
			var label = r.price_type === 'sale' ? 'قیمت ویژه' : 'قیمت اصلی';
			var delta = (Number(r.percent) > 0 ? '+' : '') + Number(r.percent).toFixed(1) + '٪';
			var color = Number(r.new) < Number(r.old) ? '#067647' : '#b42318';
			var sub = r.parent_id > 0 ? 'واریشن' : (r.type || '');

			html += '<div class="wapm-align-result" style="margin-bottom:6px">' +
				'<span>' + esc(r.name) + (sub ? ' · ' + esc(sub) : '') + '</span><b>' + esc(label) + '</b>' +
				'<span>قیمت جدید</span><b class="wapm-align-result__new">' + fmt(r.new) + '</b>' +
				'<span>تغییر</span><b style="color:' + color + '">' + delta + ' (' + fmt(Number(r.new) - Number(r.old)) + ')</b>' +
				(r.warn ? '<span>هشدار</span><b style="color:#b42318">افت بیش از ۹۰٪ — بررسی کنید</b>' : '') +
				'</div>';
		});

		return html;
	}

	function alignRequest(dryRun, $btn, onDone) {
		var payload = {
			ids: [alignState.id],
			undercut: $('#wapm-ad-value').val() || 0,
			undercut_mode: $('#wapm-ad-unit').val() || 'percent',
			dry_run: dryRun ? 1 : 0
		};

		if ($btn) { $btn.prop('disabled', true); }

		ajax('torob_align', payload).done(function (res) {
			if (!res.success) {
				wapmToast((res.data && res.data.message) || WAPM.i18n.error, 'error');
				return;
			}
			onDone(res.data || {});
		}).fail(function () {
			wapmToast(WAPM.i18n.error, 'error');
		}).always(function () {
			if ($btn) { $btn.prop('disabled', false); }
		});
	}

	$(document).on('click', '.wapm-torob-align', function () {
		alignOpen($(this).data('id'));
	});

	$('#wapm-ad-close, #wapm-ad-cancel, #wapm-align-drawer-overlay').on('click', alignClose);

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $('#wapm-align-drawer').hasClass('is-open')) { alignClose(); }
	});

	// با هر تغییر مقدار، پیش‌نمایش قبلی بی‌اعتبار است (همان قاعده‌ی کشوی قیمت).
	$('#wapm-ad-value, #wapm-ad-unit').on('change keyup', function () {
		alignState.ready = false;
		$('#wapm-ad-apply').prop('disabled', true);
	});

	$('#wapm-ad-preview').on('click', function () {
		alignRequest(true, $(this), function (data) {
			alignState.ready = !(data.skipped && data.skipped.length) && (data.rows || []).length > 0;
			$('#wapm-ad-result').html(alignResultHtml(data));
			$('#wapm-ad-apply').prop('disabled', !alignState.ready);
		});
	});

	$('#wapm-ad-apply').on('click', function () {
		if (!alignState.ready) { return; }

		alignRequest(false, $(this), function (data) {
			var changed = Number(data.changed) || 0;

			wapmToast(
				changed
					? 'قیمت هم‌تراز شد (' + fmt(changed) + ' قیمت، Batch ' + (data.batch_id || '—') + ').'
					: 'قیمتی تغییر نکرد.',
				changed ? 'success' : 'info',
				changed ? { ttl: 12000, actionLabel: 'بازگردانی', action: function () { call('bpUndo', data.batch_id); } } : {}
			);

			alignClose();
			refreshListIfLoaded();
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

	/**
	 * نوار عملیات صف تأیید تطبیق.
	 *
	 * نام قبلی این تابع `updateBulkBar` بود — همان نام نوار انتخاب محصولات.
	 * چون هر دو در یک IIFE و یک scope تعریف شده بودند، اعلان دوم (همین‌جا)
	 * اعلان اول را سایه می‌انداخت و انتخاب ردیف‌های لیست محصولات در
	 * `$area.find` روی undefined خطا می‌داد: شمارنده‌ی انتخاب و دکمه‌های نوار
	 * عملیات به‌روز نمی‌شدند.
	 */
	function updateUnmatchedBar($area) {
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
			updateUnmatchedBar($area);
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
		updateUnmatchedBar($area);
	});

	$(document).on('change', '.wapm-torob-pick, .wapm-torob-product-select', function () {
		updateUnmatchedBar($('#wapm-torob-unmatched-area'));
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
				updateUnmatchedBar($area);
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
				updateUnmatchedBar($area);
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

	/* ---------------- صادرات ---------------- */

	UI.refreshListIfLoaded = refreshListIfLoaded;
	UI.torobDate = torobDate;
	UI.renderTorobStatusHtml = renderTorobStatusHtml;
	UI.renderApiState = renderApiState;
	UI.applyTorobStats = applyTorobStats;
	UI.refreshTorobStats = refreshTorobStats;
	UI.syncCatalogPage = syncCatalogPage;
	UI.finishCatalog = finishCatalog;
	UI.diffPillHtml = diffPillHtml;
	UI.unmatchedCounts = unmatchedCounts;
	UI.renderUnmatched = renderUnmatched;
	UI.renderUnmatchedRow = renderUnmatchedRow;
	UI.initUnmatchedSelectors = initUnmatchedSelectors;
	UI.pickedRows = pickedRows;
	UI.updateUnmatchedBar = updateUnmatchedBar;
	UI.loadTorobUnmatched = loadTorobUnmatched;
	UI.alignOpen = alignOpen;
	UI.alignClose = alignClose;

})(jQuery, window.WAPMUI);
