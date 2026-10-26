/* global module */
/**
 * پیکربندی Tailwind برای بیلد محلی CSS.
 *
 * تا پیش از این صفحه‌ی افزونه Tailwind را از Play CDN لود می‌کرد: ~۴۰۰ کیلوبایت
 * جاوااسکریپت، کامپایل در مرورگر (پرش ظاهری/FOUC)، وابستگی به دامنه‌ی ثالث و
 * ناسازگاری با CSP. حالا CSS یک‌بار و در CI ساخته می‌شود.
 *
 * `preflight: false` عمدی است: preflight استایل‌های پایه‌ی wp-admin (دکمه‌ها،
 * فهرست‌ها، جدول‌ها) را صفر می‌کند و کل پیشخوان را به‌هم می‌ریزد.
 */
const path = require('path');

const root = path.resolve(__dirname, '../../');

module.exports = {
	content: [
		path.join(root, 'woocommerce-advanced-price-manager/**/*.php'),
		path.join(root, 'woocommerce-advanced-price-manager/assets/js/*.js'),
	],
	corePlugins: { preflight: false },
	darkMode: 'class',
	theme: {
		extend: {
			colors: {
				primary: {
					50: '#f5f1fa',
					100: '#ece4f5',
					200: '#d5c5ea',
					300: '#b99cd9',
					400: '#a179ca',
					500: '#8b5cb8',
					600: '#7f54b3',
					700: '#6a4494',
					800: '#563778',
					900: '#452c61',
				},
			},
		},
	},
	plugins: [],
};
