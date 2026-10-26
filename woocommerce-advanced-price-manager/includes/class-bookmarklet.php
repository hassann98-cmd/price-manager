<?php
/**
 * WAPM_Bookmarklet — ساخت لینک بوکمارکلت «همگام‌سازی با ترب».
 *
 * چرا جدا شد: این قطعه ~۴۰۰ خط جاوااسکریپت داخل رشته است و هیچ وابستگی‌ای به
 * کلاس ادمین ندارد (نه `$this`، نه رندر صفحه). نگه‌داشتنش کنار رندر تب‌ها،
 * فایل را غیرقابل مرور می‌کرد و هر تغییر کوچک در رابط، خواندن بوکمارکلت را
 * سخت می‌کرد. اجرا همان است: کاربر روی لینک کلیک می‌کند و کد در مرورگر خودش
 * (روی دامنه‌ی ترب) اجرا می‌شود.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAPM_Bookmarklet {

	public static function href(): string {
		$endpoint = admin_url( 'admin-ajax.php' );
		$token    = WAPM_Torob::get_bookmarklet_token();

		$js = <<<'JS'
(function(){
	'use strict';
	var VERSION  = '3.1';
	var API      = 'https://api.torob.com';
	var ENDPOINT = "%%ENDPOINT%%";
	var TOKEN    = "%%TOKEN%%";
	var SHOP_ID  = "%%SHOP_ID%%";
	var PAGE     = 24;      // اندازه‌ی هر صفحه‌ی کاتالوگ (خود ترب ۲۴ می‌دهد)
	var BATCH    = 15;      // هر چند محصول به سرور فرستاده شود
	var MAX_SHOP_PAGES = 400;

	var state = {
		shopId: '',
		run: '',            // شناسه‌ی این اجرا (برای جمع‌شدن آمار دسته‌ها)
		mode: 'full',       // full | catalog
		page: 0,
		inPage: 0,
		stopped: false,
		discovered: 0,
		matched: 0,
		checked: 0,
		unmatched: 0
	};

	var ui = null;
	var cacheKey = '';

	/* ---------- ابزار ---------- */

	function el(tag, css, text){
		var n = document.createElement(tag);
		if (css) { n.style.cssText = css; }
		if (text) { n.textContent = text; }
		return n;
	}

	function delay(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }
	function jitter(base){ return base + Math.floor(Math.random() * 400); }

	function say(msg){ if (ui) { ui.status.textContent = msg; } }
	function progress(pct){ if (ui) { ui.fill.style.width = Math.max(0, Math.min(100, pct)) + '%'; } }

	function closeUi(delayMs){
		if (!ui) { return; }
		var box = ui.box;
		ui = null;
		setTimeout(function(){ if (box && box.parentNode) { box.parentNode.removeChild(box); } }, delayMs || 0);
	}

	function finish(msg, autoHide){
		say(msg);
		if (ui) { ui.stop.style.display = 'none'; }
		if (autoHide) { closeUi(15000); }
	}

	function loadState(){
		try { return JSON.parse(localStorage.getItem(cacheKey) || 'null'); } catch (e) { return null; }
	}
	function saveState(){
		try {
			localStorage.setItem(cacheKey, JSON.stringify({
				page: state.page,
				inPage: state.inPage,
				mode: state.mode,
				run: state.run,
				at: Date.now()
			}));
		} catch (e) { /* حافظه‌ی مرورگر پر است یا غیرفعال */ }
	}
	function clearState(){
		try { localStorage.removeItem(cacheKey); } catch (e) {}
	}

	/* ---------- شبکه ---------- */

	/**
	 * GET به API ترب. پاسخ JSON؛ در بلاک (۴۹۰/۴۰۳/۴۲۹) یا پاسخ غیر-JSON خطای
	 * مشخص پرتاب می‌کند تا حلقه متوقف شود (تلاش دوباره، بلاک را طولانی‌تر می‌کند).
	 */
	function apiGet(path, params){
		var qs = new URLSearchParams(params);
		qs.set('source', 'next_desktop');

		return fetch(API + path + '?' + qs.toString(), {
			credentials: 'include',
			headers: { 'Accept': 'application/json' }
		}).then(function(res){
			if ([490, 403, 429].indexOf(res.status) !== -1) {
				var e = new Error('blocked');
				e.code = res.status;
				throw e;
			}
			if (!res.ok) { throw new Error('HTTP ' + res.status); }
			return res.text();
		}).then(function(text){
			var t = (text || '').trim();
			if (t.charAt(0) !== '{') {
				var e = new Error('blocked');
				e.code = 490;
				throw e;
			}
			return JSON.parse(t);
		});
	}

	/**
	 * تماس با سایت خودمان (اکشن‌های مجاز با توکن) — برای گرفتن فهرست کارها.
	 * بدون credentials: احراز هویت با توکن است، نه کوکی ادمین.
	 */
	function serverCall(action, extra){
		var body = new URLSearchParams();
		body.set('action', 'wapm_' + action);
		body.set('token', TOKEN);
		if (extra) {
			Object.keys(extra).forEach(function(k){ body.set(k, extra[k]); });
		}

		return fetch(ENDPOINT, { method: 'POST', body: body, credentials: 'omit' })
			.then(function(r){
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			});
	}

	function sendBatch(items, meta){
		var body = new URLSearchParams();
		body.set('action', 'wapm_torob_ingest');
		body.set('source', 'bookmarklet');
		body.set('token', TOKEN);
		body.set('items', JSON.stringify(items));
		body.set('meta', JSON.stringify(meta || {}));

		return fetch(ENDPOINT, { method: 'POST', body: body, credentials: 'omit' })
			.then(function(r){
				if (r.status === 429) {
					return delay(6000).then(function(){
						return fetch(ENDPOINT, { method: 'POST', body: body, credentials: 'omit' });
					});
				}
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r;
			})
			.then(function(r){ return r.json(); });
	}

	/* ---------- ساخت داده ---------- */

	/**
	 * یک کارت ترب → ردیف ورودی سرور.
	 *
	 * نکته‌ی مهم: `sellers === null` یعنی «فهرست فروشنده‌ها را نگرفتم» (خطای
	 * موقت). در این حالت کلید sellers را نمی‌فرستیم تا سرور قیمت قبلیِ رقیب را
	 * پاک نکند؛ آرایه‌ی خالی یعنی «گرفتم و هیچ فروشنده‌ای نبود» و این دو یکی نیستند.
	 */
	function cardToItem(card, sellers){
		var url = card.web_client_absolute_url || '';
		var item = {
			prk: card.random_key || '',
			name: card.name1 || '',
			our_price: Number(card.price) || null,
			url: url ? 'https://torob.com' + url : ''
		};
		if (sellers) { item.sellers = sellers; }
		return item;
	}

	/**
	 * پرچم‌های ترب را به بولین تبدیل می‌کند.
	 *
	 * ترب در بعضی پاسخ‌ها `availability` را رشته می‌فرستد؛ `"false" !== false`
	 * یعنی کالای ناموجود «موجود» شمرده می‌شد. این‌جا با لیست صریح مقادیر منفی
	 * یکسان‌سازی می‌شود (هم‌خوان با WAPM_Torob::to_bool در سرور).
	 */
	function truthy(value, fallback){
		if (value === undefined || value === null || value === '') { return fallback; }
		if (typeof value === 'boolean') { return value; }
		if (typeof value === 'number') { return value > 0; }
		return ['0', 'false', 'no', 'off', 'null', 'none', 'n/a']
			.indexOf(String(value).trim().toLowerCase()) === -1;
	}

	/** فروشنده‌ها را به شکل سبک تبدیل می‌کند (حداکثر ۴۰ ردیف ارزان‌ترین). */
	function slimSellers(rows){
		var out = [];
		(rows || []).forEach(function(r){
			var price = Number(r.price) || 0;
			if (!r.shop_id || price <= 0) { return; }
			out.push({
				shop_id: Number(r.shop_id),
				shop_name: String(r.shop_name || '').slice(0, 80),
				price: price,
				available: truthy(r.availability, true),
				unreliable: truthy(r.is_price_unreliable, false),
				last_change: String(r.last_price_change_date || '').slice(0, 40)
			});
		});
		out.sort(function(a, b){ return a.price - b.price; });
		return out.slice(0, 40);
	}

	/* ---------- رابط ---------- */

	function buildStartUi(){
		var box = el('div', 'position:fixed;bottom:20px;left:20px;z-index:2147483647;background:#111;color:#fff;padding:16px 18px;border-radius:14px;font-family:Tahoma,sans-serif;font-size:13px;max-width:360px;direction:rtl;box-shadow:0 12px 34px rgba(0,0,0,.4);line-height:1.9');

		box.appendChild(el('div', 'font-weight:700;margin-bottom:6px', 'همگام‌سازی با ترب (WAPM)'));

		var info = el('div', 'font-size:12px;color:#c7c7c7', 'شناسه فروشگاه: ' + state.shopId);
		box.appendChild(info);

		var missing = el('button', 'margin-top:12px;width:100%;background:#0f766e;color:#fff;border:0;border-radius:10px;padding:9px 12px;font-weight:700;cursor:pointer;font-family:inherit', 'فقط محصولات بدون قیمت رقیب (سریع‌ترین)');
		missing.onclick = function(){ startMissing(); };

		var full = el('button', 'margin-top:8px;width:100%;background:#7f54b3;color:#fff;border:0;border-radius:10px;padding:9px 12px;font-weight:700;cursor:pointer;font-family:inherit', 'کامل: کل کاتالوگ + قیمت رقبا');
		full.onclick = function(){ start('full'); };

		var catalog = el('button', 'margin-top:8px;width:100%;background:#2f2f2f;color:#fff;border:1px solid #4a4a4a;border-radius:10px;padding:9px 12px;font-weight:700;cursor:pointer;font-family:inherit', 'سریع: فقط کاتالوگ و قیمت خودمان');
		catalog.onclick = function(){ start('catalog'); };

		var hint = el('div', 'margin-top:8px;font-size:11px;color:#9a9a9a', 'حالت اول فقط محصولاتی را می‌گیرد که هنوز قیمت رقیب ندارند یا داده‌شان کهنه است — بعد از اولین اجرا، همین دکمه سریع‌ترین راه است. «کامل» کل کاتالوگ را از نو می‌خواند.');
		box.appendChild(missing);
		box.appendChild(full);
		box.appendChild(catalog);
		box.appendChild(hint);

		var resume = loadState();
		if (resume) {
			var btnResume = el('button', 'margin-top:8px;width:100%;background:#1f3b2c;color:#d6ffe9;border:1px solid #2f5a44;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer;font-family:inherit', 'ادامه از صفحه ' + (Number(resume.page) + 1));
			btnResume.onclick = function(){
				state.mode = resume.mode || 'full';
				start(state.mode, resume);
			};
			box.appendChild(btnResume);
		}

		document.body.appendChild(box);
		ui = { box: box, status: el('div'), fill: el('div'), stop: el('button') };
		ui.status.style.display = 'none';
	}

	function buildProgressUi(){
		var box = el('div', 'position:fixed;bottom:20px;left:20px;z-index:2147483647;background:#111;color:#fff;padding:14px 16px;border-radius:12px;font-family:Tahoma,sans-serif;font-size:13px;max-width:340px;direction:rtl;box-shadow:0 10px 30px rgba(0,0,0,.35);line-height:1.8');

		var title = el('div', 'font-weight:700;margin-bottom:8px', 'همگام‌سازی با ترب (WAPM)');
		var status = el('div', '', 'در حال شروع…');

		var bar = el('div', 'height:6px;background:#333;border-radius:3px;margin:10px 0;overflow:hidden');
		var fill = el('div', 'height:100%;width:0;background:#7f54b3;transition:width .2s');
		bar.appendChild(fill);

		var stop = el('button', 'background:#b32d2e;color:#fff;border:0;border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer;font-family:inherit', 'توقف');
		stop.onclick = function(){
			state.stopped = true;
			stop.disabled = true;
			status.textContent = 'در حال توقف… (تا پایان درخواست جاری)';
		};

		box.appendChild(title);
		box.appendChild(status);
		box.appendChild(bar);
		box.appendChild(stop);
		document.body.appendChild(box);

		ui = { box: box, status: status, fill: fill, stop: stop };
	}

	/* ---------- اجرا ---------- */

	async function start(mode, resume){
		state.mode = mode;
		state.run  = (resume && resume.run) ? String(resume.run) : ('r' + Date.now() + Math.floor(Math.random() * 1000));
		state.page = resume ? Number(resume.page || 0) : 0;
		state.inPage = resume ? Number(resume.inPage || 0) : 0;

		// رابط شروع را با رابط پیشرفت جایگزین کن.
		if (ui && ui.box && ui.box.parentNode) { ui.box.parentNode.removeChild(ui.box); }
		ui = null;
		buildProgressUi();

		cacheKey = 'wapm_bm3_' + state.shopId + '_' + mode;
		progress(2);

		try {
			await runCatalog();
			if (!state.stopped) {
				progress(100);
				clearState();
				var msg = '✓ تمام شد — ' + state.discovered + ' محصول از ترب بررسی شد.';
				if (mode === 'full') {
					msg += '\n' + state.checked + ' محصول قیمت رقیب گرفت' + (state.unmatched ? ' و ' + state.unmatched + ' مورد به لیست تطبیق‌نیافته رفت.' : '.');
				} else {
					msg += '\nقیمت خودمان از دید ترب به‌روز شد (برای قیمت رقبا، حالت کامل را اجرا کنید).';
				}
				finish(msg, true);
			}
		} catch (e) {
			if (e && e.code === 490) {
				saveState();
				finish('⚠ ترب درخواست‌ها را محدود کرد (۴۹۰). این کار را جدی بگیرید: چند دقیقه صبر کنید و بعد با دکمه‌ی «ادامه» از همان صفحه ادامه دهید. تلاش پشت‌سرهم، زمان بلاک را طولانی‌تر می‌کند.');
			} else if (e && e.message === 'blocked') {
				saveState();
				finish('⚠ ترب به‌جای داده، صفحه‌ی «ربات نیستید» برگرداند. چند دقیقه صبر کنید و از همان صفحه ادامه دهید.');
			} else {
				saveState();
				finish('خطا: ' + (e && e.message ? e.message : e) + '\nاز همان صفحه قابل ادامه است.');
			}
		}
	}

	async function runCatalog(){
		var myShop = Number(state.shopId);

		while (!state.stopped && state.page < MAX_SHOP_PAGES) {
			say('خواندن صفحه ' + (state.page + 1) + ' از کاتالوگ فروشگاه…');

			var pageData = await apiGet('/v4/internet-shop/base-product/list/', {
				shop_id: myShop,
				page: state.page,
				size: PAGE,
				sort: '-date'
			});

			var cards = (pageData.results || []).filter(function(c){ return c && c.random_key; });
			if (!cards.length) { break; }

			state.discovered += cards.length;

			var batch = [];
			var total = Number(pageData.count) || state.discovered;
			progress(Math.min(97, Math.round(((state.page * PAGE) / Math.max(total, 1)) * 100)));

			// ادامه از وسط صفحه (اگر دفعه‌ی قبل وسط کار متوقف شده باشد).
			var i = Number(state.inPage) || 0;

			for (; i < cards.length && !state.stopped; i++) {
				var card = cards[i];
				var sellers = null;   // null = «گرفتن فروشنده‌ها ناموفق بود»

				if (state.mode === 'full') {
					say('صفحه ' + (state.page + 1) + ' — محصول ' + (i + 1) + ' از ' + cards.length +
						' (کل: ' + state.discovered + ')');

					try {
						var sdata = await apiGet('/v4/base-product/sellers/', {
							prk: card.random_key,
							list_type: 'products_info'
						});
						sellers = slimSellers(sdata.results);
					} catch (err) {
						if (err && err.code === 490) { throw err; }
						// خطای یک محصول نباید کل کار را متوقف کند، ولی نباید
						// «فروشنده‌ای نبود» هم تلقی شود: sellers = null می‌ماند
						// تا سرور داده‌ی قبلی را دست‌نخورده نگه دارد.
						sellers = null;
					}

					await delay(jitter(900));
				}

				batch.push(cardToItem(card, sellers));

				if (batch.length >= BATCH || i === cards.length - 1) {
					var res = await sendBatch(batch, {
						discovered: state.discovered,
						pages: state.page + 1,
						version: VERSION,
						mode: state.mode,
						run: state.run
					});

					if (res && res.success) {
						state.matched   += res.data.matched || 0;
						state.checked   += res.data.checked || 0;
						state.unmatched += res.data.unmatched || 0;
					} else {
						throw new Error((res && res.data && res.data.message) || 'پاسخ نامعتبر از سایت');
					}

					batch = [];
					state.inPage = i + 1;
					saveState();
				}
			}

			if (state.stopped) {
				// وسط صفحه متوقف شدیم: صفحه را جلو نمی‌بریم و دسته‌ی نیمه‌کاره را
				// نمی‌فرستیم. دفعه‌ی بعد از همان محصول ادامه می‌دهد؛ قبلاً صفحه
				// جلو می‌رفت و محصولات باقی‌مانده‌ی آن صفحه بی‌خبر جا می‌افتادند.
				saveState();
				finish('متوقف شد. دفعه‌ی بعد از همین‌جا ادامه می‌دهد.\nتا اینجا: ' + state.discovered + ' محصول بررسی شد.');
				return;
			}

			state.page++;
			state.inPage = 0;
			saveState();

			var done = Math.min(97, Math.round(((state.page * PAGE) / Math.max(Number(pageData.count) || state.discovered, 1)) * 100));
			progress(done);

			await delay(jitter(1200));
		}

	}

	/**
	 * حالت «فقط محصولات بدون قیمت رقیب».
	 *
	 * چرا این حالت مهم است: اجرای دومِ «کامل» روی فروشگاهی با ۲۰۰۰ محصول یعنی
	 * ۲۰۰۰ درخواست بی‌مورد، در حالی که شاید فقط ۵۰ محصول ناقص باشند. این‌جا
	 * فهرست ناقص‌ها از سایت گرفته می‌شود و فقط همان‌ها واکشی می‌شوند.
	 *
	 * «ادامه» لازم نیست: هر محصولی که موفق گرفته شود دیگر ناقص نیست، پس اجرای
	 * بعدی خودبه‌خود بقیه را برمی‌دارد (حتی بعد از بلاک یا بستن تب).
	 */
	async function startMissing(){
		if (ui && ui.box && ui.box.parentNode) { ui.box.parentNode.removeChild(ui.box); }
		ui = null;
		buildProgressUi();

		state.mode = 'full';
		state.run  = 'miss' + Date.now() + Math.floor(Math.random() * 1000);
		state.stopped = false;
		state.discovered = 0;
		state.checked = 0;
		state.unmatched = 0;

		progress(3);

		try {
			say('گرفتن فهرست محصولات بدون قیمت رقیب از سایت…');

			var res = await serverCall('torob_missing_list', { limit: 500 });
			if (!res || !res.success) {
				throw new Error((res && res.data && res.data.message) || 'پاسخ نامعتبر از سایت');
			}

			var list  = (res.data && res.data.items) || [];
			var total = list.length;

			if (!total) {
				progress(100);
				finish('✓ همه‌ی محصولات قیمت رقیب تازه دارند؛ کاری برای انجام نبود.', true);
				return;
			}

			state.discovered = total;

			var batch = [];

			for (var i = 0; i < total && !state.stopped; i++) {
				say('محصول ' + (i + 1) + ' از ' + total + ' — ' + (list[i].name || list[i].prk));
				progress(3 + Math.round(((i + 1) / total) * 94));

				var sellers = null;
				try {
					var sdata = await apiGet('/v4/base-product/sellers/', {
						prk: list[i].prk,
						list_type: 'products_info'
					});
					sellers = slimSellers(sdata.results);
				} catch (err) {
					if (err && err.code === 490) { throw err; }
					// خطای یک محصول کل کار را نمی‌خواباند و «فروشنده‌ای نبود» هم
					// تلقی نمی‌شود (sellers = null ⇒ سرور داده‌ی قبلی را نگه می‌دارد).
					sellers = null;
				}

				var item = { prk: list[i].prk, name: list[i].name || '' };
				if (sellers) { item.sellers = sellers; }
				batch.push(item);

				await delay(jitter(900));

				if (batch.length >= BATCH || i === total - 1) {
					var out = await sendBatch(batch, {
						discovered: total,
						pages: 0,
						version: VERSION,
						mode: 'full',
						run: state.run
					});

					if (out && out.success) {
						state.matched   += out.data.matched || 0;
						state.checked   += out.data.checked || 0;
						state.unmatched += out.data.unmatched || 0;
					} else {
						throw new Error((out && out.data && out.data.message) || 'پاسخ نامعتبر از سایت');
					}

					batch = [];
				}
			}

			if (state.stopped) {
				finish('متوقف شد. تا اینجا ' + state.checked + ' محصول قیمت رقیب گرفت.\nدوباره همین دکمه را بزنید؛ فقط ناقص‌ها می‌مانند.');
				return;
			}

			progress(100);
			finish('✓ تمام شد — ' + state.checked + ' محصول قیمت رقیب گرفت' +
				(state.unmatched ? ' و ' + state.unmatched + ' مورد به لیست تطبیق‌نیافته رفت.' : '.'), true);
		} catch (e) {
			if (e && e.code === 490) {
				finish('⚠ ترب درخواست‌ها را محدود کرد (۴۹۰). چند دقیقه صبر کنید و همین دکمه را دوباره بزنید — محصولاتی که گرفته شدند دیگر در فهرست نمی‌آیند.');
			} else {
				finish('خطا: ' + (e && e.message ? e.message : e));
			}
		}
	}

	/* ---------- شروع ---------- */

	var shopId = String(SHOP_ID || '').replace(/[^0-9]/g, '');
	if (!shopId) {
		shopId = String(window.prompt('شناسه فروشگاه ترب را وارد کنید (عدد در آدرس torob.com/shop/عدد/...):', '') || '').replace(/[^0-9]/g, '');
	}
	if (!shopId) {
		window.alert('بدون شناسه‌ی فروشگاه نمی‌شود: افزونه باید بداند کدام فروشنده خودتان هستید تا قیمت خودتان را با رقیب اشتباه نگیرد.');
		return;
	}

	state.shopId = shopId;
	cacheKey = 'wapm_bm3_' + shopId + '_full';
	buildStartUi();
})();
JS;

		$js = str_replace(
			[ '%%ENDPOINT%%', '%%TOKEN%%', '%%SHOP_ID%%' ],
			[ esc_js( $endpoint ), esc_js( $token ), esc_js( WAPM_Torob::get_shop_id() ) ],
			$js
		);

		return 'javascript:' . rawurlencode( $js );
	}
}
