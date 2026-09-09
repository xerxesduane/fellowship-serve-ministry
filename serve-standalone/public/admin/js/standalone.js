/*
 * The little that the server-rendered screens need.
 *
 * admin/js/app.js is the dashboard: it fetches, renders and routes five views,
 * and it is shared byte-for-byte with the plugin. Teams and gaps needs none of
 * that -- the page arrives complete and the form posts -- but it does wear the
 * same sidebar, and on a phone that sidebar has to be openable.
 *
 * So this is the sidebar behaviour and nothing else. It is not a module and it
 * does not touch the network. The screen works without it: the sidebar is only
 * hidden below 1024px, where the toggle is the only way to reach it, and every
 * other control on the page is a plain form control.
 */
(function () {
	'use strict';

	var sidebar = document.getElementById('serve-sidebar');
	var toggle = document.querySelector('.serve-navtoggle');
	var scrim = document.querySelector('[data-serve="scrim"]');

	if (!sidebar || !toggle) {
		return;
	}

	function setOpen(open) {
		sidebar.classList.toggle('is-open', open);
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

		if (scrim) {
			scrim.hidden = !open;
		}
	}

	toggle.addEventListener('click', function () {
		setOpen(toggle.getAttribute('aria-expanded') !== 'true');
	});

	if (scrim) {
		scrim.addEventListener('click', function () {
			setOpen(false);
		});
	}

	// Escape closes it, the same as it closes the dashboard's drawer.
	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape') {
			setOpen(false);
		}
	});
})();
