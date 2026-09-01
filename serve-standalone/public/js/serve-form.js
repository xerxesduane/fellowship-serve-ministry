/**
 * Consent-and-send bridge.
 *
 * The assessment journey stores its finished profile in localStorage. This
 * reads it, attaches the person's contact details and consent, and posts the
 * result to the REST endpoint. Nothing leaves the browser until the box is
 * ticked and the button pressed.
 */

/*
 * Config from a JSON block, falling back to the global.
 *
 * wp_localize_script() delivers this as an inline `window.serveShapeConfig =
 * {...}`, which a content security policy worth having forbids: allowing inline
 * script to pass one config object means allowing it for everything. A
 * <script type="application/json"> block is data rather than code, so a strict
 * script-src needs no exception -- and admin/js/app.js already reads its own
 * config exactly this way.
 *
 * The global is still honoured, so the WordPress build keeps working with no
 * change to how it delivers the value.
 */
function serveShapeConfig() {
	try {
		const el = document.getElementById('serve-shape-config');

		if (el) {
			return JSON.parse(el.textContent);
		}
	} catch (e) {
		// A malformed block should not take the whole form down.
	}

	return window.serveShapeConfig;
}

(function () {
	'use strict';

	const config = serveShapeConfig();
	if (!config) {
		return;
	}

	const form = document.getElementById('serve-consent-form');
	if (!form) {
		return;
	}

	const status = form.querySelector('.serve-consent__status');
	const submit = form.querySelector('.serve-consent__submit');

	/** The profile step writes this alongside the raw answers. */
	const PROFILE_KEY = config.storageKey + '-profile';

	function say(message, isError) {
		status.textContent = message;
		status.classList.toggle('is-error', Boolean(isError));
	}

	function readProfile() {
		try {
			const raw = window.localStorage.getItem(PROFILE_KEY);
			if (!raw) {
				return null;
			}

			const parsed = JSON.parse(raw);
			// A profile with no gift ratings at all means the journey was not
			// actually completed, whatever is in storage.
			const gifts = parsed && parsed.spiritualGifts;
			const answered = gifts && (
				(gifts.likely || []).length
				+ (gifts.possible || []).length
				+ (gifts.unlikely || []).length
			) > 0;

			return answered ? parsed : null;
		} catch (error) {
			// Private browsing can block storage entirely.
			return null;
		}
	}

	/**
	 * Fill the contact fields from the journey the person just finished.
	 *
	 * The assessment now requires all three, so asking for them again is asking
	 * someone to retype what we already hold — and a mistyped second copy is
	 * worse than no copy, because a wrong email looks exactly like a right one
	 * right up until nobody can reach them. Anything already typed here wins,
	 * and a profile saved before this existed simply carries no contact block,
	 * so those fields stay empty and the person fills them in as before.
	 */
	function prefillContact() {
		const profile = readProfile();
		const contact = (profile && profile.contact) || {};

		let filled = 0;

		[['display_name', 'name'], ['email', 'email'], ['phone', 'phone']].forEach(function (pair) {
			const field = form.elements[pair[0]];
			const value = contact[pair[1]];
			if (field && !field.value && value) {
				field.value = value;
				filled += 1;
			}
		});

		// Say where they came from. Three boxes that fill themselves in are
		// unsettling unless something explains it, and they still want checking.
		const note = form.querySelector('[data-serve-prefill]');
		if (note && filled > 0) {
			note.hidden = false;
		}
	}

	prefillContact();

	/**
	 * Show what is about to be sent.
	 *
	 * Consenting to share something you cannot see is not really consenting,
	 * and after nineteen steps "what did all that add up to?" is the question
	 * people actually have in front of them here.
	 *
	 * Deliberately a summary and not the whole profile: the Experiences section
	 * can hold painful history, and reprinting it on a page someone may be
	 * filling in on a phone in a church foyer serves nobody. The note underneath
	 * says plainly that the full answers go too.
	 */
	function renderSummary() {
		const panel = form.querySelector('[data-serve-summary]');
		const body = form.querySelector('[data-serve-summary-body]');
		if (!panel || !body) {
			return;
		}

		const profile = readProfile();
		if (!profile) {
			return;
		}

		const rows = [];
		const gifts = (profile.spiritualGifts && profile.spiritualGifts.likely) || [];
		const availability = profile.availability || {};

		if (gifts.length) {
			rows.push([config.strings.summaryGifts, gifts.join(', ')]);
		}

		/*
		 * No team summary here.
		 *
		 * This read profile.recommendedMinistries, which the browser stopped
		 * producing when ranking moved to the server — so the row had silently
		 * not appeared for some time, under a heading the page still shipped.
		 * Dead rather than wrong, but the fix is not to repopulate it: the
		 * authoritative teams come from the server's own ranking, this page has
		 * not asked for them, and a stale or client-era list is exactly what
		 * must not drive what somebody believes they are consenting to.
		 *
		 * The teams are shown on the results page the person has just come
		 * from, computed server-side. What this page is for is the consent
		 * decision itself.
		 */
		if (availability.hours) {
			const timing = (availability.timing || []).join(', ');
			rows.push([config.strings.summaryTime, timing ? availability.hours + ' · ' + timing : availability.hours]);
		}

		if (!rows.length) {
			return;
		}

		body.innerHTML = '';
		rows.forEach(function (row) {
			const dt = document.createElement('dt');
			dt.textContent = row[0];
			const dd = document.createElement('dd');
			dd.textContent = row[1];
			body.appendChild(dt);
			body.appendChild(dd);
		});

		panel.hidden = false;
	}

	renderSummary();

	form.addEventListener('submit', function (event) {
		event.preventDefault();

		const consent = document.getElementById('serve-consent-check');
		if (!consent.checked) {
			say(config.strings.consent, true);
			consent.focus();
			return;
		}

		const profile = readProfile();
		if (!profile) {
			say(config.strings.noProfile, true);
			return;
		}

		const tenure = form.elements.tenure_months.value;

		const payload = {
			consent: true,
			display_name: form.elements.display_name.value.trim(),
			email: form.elements.email.value.trim(),
			phone: form.elements.phone.value.trim(),
			honeypot: form.elements.honeypot.value,
			profile: profile
		};

		if (tenure !== '') {
			payload.tenure_months = parseInt(tenure, 10);
		}

		submit.disabled = true;
		say(config.strings.sending, false);

		fetch(config.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		})
			.then(function (response) {
				return response.json().then(function (body) {
					return { ok: response.ok, body: body };
				});
			})
			.then(function (result) {
				if (!result.ok) {
					submit.disabled = false;
					say((result.body && result.body.message) || config.strings.failed, true);
					return;
				}

				// Swap the form for the confirmation rather than emptying it out.
				const done = document.querySelector('[data-serve-done]');
				const message = document.querySelector('[data-serve-done-message]');

				if (done) {
					if (message) {
						message.textContent = result.body.message || '';
					}
					form.hidden = true;
					done.hidden = false;
					done.setAttribute('tabindex', '-1');
					done.focus({ preventScroll: true });
					done.scrollIntoView({ block: 'start', behavior: 'smooth' });
					return;
				}

				form.classList.add('is-sent');
				say(result.body.message, false);
			})
			.catch(function () {
				submit.disabled = false;
				say(config.strings.failed, true);
			});
	});
}());

/*
 * Answering an invitation.
 *
 * Its own small block rather than folded into the consent form: they are
 * different pages that happen to share a stylesheet, and the consent script
 * above bails out early when its form is absent.
 */
(function () {
	'use strict';

	const config = serveShapeConfig();
	const form = document.getElementById('serve-invite-form');
	if (!config || !form) {
		return;
	}

	const status = form.querySelector('.serve-consent__status');
	const submit = form.querySelector('.serve-consent__submit');

	form.addEventListener('submit', function (event) {
		event.preventDefault();

		const chosen = form.querySelector('input[name="serve_invite_response"]:checked');
		if (!chosen) {
			return;
		}

		submit.disabled = true;
		status.textContent = config.strings.sending;
		status.classList.remove('is-error');

		fetch(config.inviteEndpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				token: form.dataset.inviteToken,
				response: chosen.value,
				note: (form.querySelector('[data-invite-note]').value || '').trim()
			})
		})
			.then(function (response) {
				return response.json().then(function (body) {
					return { ok: response.ok, body: body };
				});
			})
			.then(function (result) {
				if (!result.ok) {
					submit.disabled = false;
					status.textContent = (result.body && result.body.message) || config.strings.failed;
					status.classList.add('is-error');
					return;
				}

				const done = document.querySelector('[data-invite-done]');
				const message = document.querySelector('[data-invite-done-message]');

				if (done) {
					if (message) {
						message.textContent = result.body.message || '';
					}
					form.hidden = true;
					done.hidden = false;
					done.setAttribute('tabindex', '-1');
					done.focus({ preventScroll: true });
				}
			})
			.catch(function () {
				submit.disabled = false;
				status.textContent = config.strings.failed;
				status.classList.add('is-error');
			});
	});
}());
