/* global tailwind */
/**
 * پیکربندی Tailwind Play CDN — فقط برای صفحه افزونه (تب «لیست محصولات»).
 * Preflight غیرفعال است تا استایل‌های پیش‌فرض wp-admin به‌هم نریزد.
 */
tailwind.config = {
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
					900: '#452c61'
				}
			}
		}
	}
};
