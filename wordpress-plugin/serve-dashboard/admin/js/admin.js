/**
 * Reveals the fields a given pipeline move actually needs.
 *
 * Pausing someone requires a return date, and declining reads better with a
 * reason. Both are hidden until the status that needs them is chosen, so the
 * common moves stay one click.
 */

(function () {
	'use strict';

	document.querySelectorAll('.serve-move').forEach(function (form) {
		const select = form.querySelector('.serve-status-select');
		const snooze = form.querySelector('.serve-snooze-field');
		const decline = form.querySelector('.serve-decline-field');

		if (!select) {
			return;
		}

		function sync() {
			const value = select.value;

			if (snooze) {
				snooze.hidden = value !== 'paused';
				const input = snooze.querySelector('input');
				if (input) {
					input.required = value === 'paused';
				}
			}

			if (decline) {
				decline.hidden = value !== 'declined';
			}
		}

		select.addEventListener('change', sync);
		sync();
	});
}());
