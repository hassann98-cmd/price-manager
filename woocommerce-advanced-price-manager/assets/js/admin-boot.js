/* global jQuery, WAPMUI */
/**
 * WAPM Admin — راه‌اندازی اولیه
 *
 * آخرین فایل؛ همه‌ی ماژول‌ها لود شده‌اند، پس می‌توان جدول‌ها را یک‌بار ساخت.
 */
(function ($, UI) {
	'use strict';

	$(function () {
		if (typeof UI.renderRules === 'function') { UI.renderRules(); }
		if (typeof UI.renderSchedules === 'function') { UI.renderSchedules(); }
	});

})(jQuery, window.WAPMUI);
