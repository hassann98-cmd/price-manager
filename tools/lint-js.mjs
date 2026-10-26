#!/usr/bin/env node
/**
 * lint-js — بررسی ساده‌ی اسکریپت‌های پیشخوان (بدون وابستگی).
 *
 * چرا این ابزار: در `admin.js` دو تابع هم‌نام (`updateBulkBar`) در یک scope
 * تعریف شده بودند. جاوااسکریپت اعلان دوم را حاکم می‌کند (hoisting)، پس انتخاب
 * ردیف‌های لیست محصولات، نسخه‌ی «صف تأیید ترب» را صدا می‌زد و روی `undefined`
 * خطا می‌داد — بدون هیچ خطای سینتکسی، و هیچ‌کس متوجه نمی‌شد تا در مرورگر
 * امتحان شود. `node --check` چنین چیزی را نمی‌گیرد؛ این اسکریپت می‌گیرد.
 *
 * بررسی‌ها:
 *   ۱) اعلان تکراری `function` با همان نام در یک فایل (سایه‌انداختن).
 *   ۲) `var/let/const` تکراری با مقدار — نشانه‌ی کپی‌شدن بلوک.
 *   ۳) توازن آکولادها (تشخیص بریدگی در ویرایش دستی).
 *
 * استفاده: node tools/lint-js.mjs
 */

import fs from 'fs';
import path from 'path';
import vm from 'vm';
import { fileURLToPath } from 'url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const dir = path.join(root, 'woocommerce-advanced-price-manager', 'assets', 'js');

let problems = 0;
const files = fs.readdirSync(dir).filter((f) => f.endsWith('.js')).sort();

for (const name of files) {
	const file = path.join(dir, name);
	const source = fs.readFileSync(file, 'utf8');
	const lines = source.split('\n');

	/** @type {Map<string, number[]>} */
	const functions = new Map();
	/** @type {Map<string, number[]>} */
	const vars = new Map();

	lines.forEach((line, index) => {
		const lineNo = index + 1;

		const fn = /^\tfunction\s+([A-Za-z0-9_$]+)\s*\(/.exec(line);
		if (fn) {
			const key = fn[1];
			functions.set(key, [...(functions.get(key) ?? []), lineNo]);
		}

		const va = /^\t(?:var|let|const)\s+([A-Za-z0-9_$]+)\s*=/.exec(line);
		if (va) {
			const key = va[1];
			vars.set(key, [...(vars.get(key) ?? []), lineNo]);
		}
	});

	for (const [key, hits] of functions) {
		if (hits.length > 1) {
			problems++;
			console.error(`❌ ${name}: تابع «${key}» ${hits.length} بار تعریف شده (خط ${hits.join('، ')}) — اعلان دوم اولی را سایه می‌اندازد.`);
		}
	}

	for (const [key, hits] of vars) {
		if (hits.length > 1) {
			problems++;
			console.error(`❌ ${name}: متغیر «${key}» ${hits.length} بار مقداردهی شده (خط ${hits.join('، ')}) — بلوک تکراری؟`);
		}
	}

	// توازن آکولادها — فقط هشدار: آکولاد داخل رشته/کامنت هم شمرده می‌شود، پس
	// ممکن است مثبت کاذب باشد و نباید ساخت را متوقف کند (node --check قطعی است).
	const open = (source.match(/\{/g) ?? []).length;
	const close = (source.match(/\}/g) ?? []).length;
	if (open !== close) {
		console.warn(`⚠ ${name}: آکولادها متوازن نیستند (${open} باز، ${close} بسته) — با node --check بررسی کنید.`);
	}
}

/* -------------------------------------------------------------------------
 * بوکمارکلت: ~۴۰۰ خط جاوااسکریپت داخل یک nowdoc در class-bookmarklet.php است و
 * ابزار بالا آن را نمی‌بیند. یک غلط تایپی در آن، فقط وقتی در مرورگر کاربر روی
 * torob.com اجرا شود معلوم می‌شود — پس همان‌جا کامپایل می‌شود.
 * ---------------------------------------------------------------------- */
const bookmarkletFile = path.join(root, 'woocommerce-advanced-price-manager', 'includes', 'class-bookmarklet.php');
if (fs.existsSync(bookmarkletFile)) {
	const php = fs.readFileSync(bookmarkletFile, 'utf8');
	const match = php.match(/\$js = <<<'JS'\n([\s\S]*?)\nJS;/);

	if (!match) {
		problems++;
		console.error('❌ class-bookmarklet.php: بلوک جاوااسکریپت (nowdoc JS) پیدا نشد.');
	} else {
		try {
			// فقط کامپایل، بدون اجرا (نه fetch، نه DOM لازم است).
			new vm.Script(match[1], { filename: 'bookmarklet.js' });
		} catch (error) {
			problems++;
			console.error('❌ class-bookmarklet.php: جاوااسکریپت بوکمارکلت کامپایل نمی‌شود — ' + error.message);
		}
	}
}

if (problems > 0) {
	console.error(`\n${problems} مشکل در اسکریپت‌های پیشخوان.`);
	process.exit(1);
}

console.log(`✅ ${files.length} فایل JS بدون اعلان تکراری و با آکولاد متوازن.`);
