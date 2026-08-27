/**
 * Consent-and-send bridge.
 *
 * The assessment journey stores its finished profile in localStorage. This
 * reads it, attaches the person's contact details and consent, and posts the
 * result to the REST endpoint. Nothing leaves the browser until the box is
 * ticked and the button pressed.
 */

(function () {
	'use strict';

	const config = window.serveShapeConfig;
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

		[['display_name', 'name'], ['email', 'email'], ['phone', 'phone']].forEach(function (pair) {
			const field = form.elements[pair[0]];
			const value = contact[pair[1]];
			if (field && !field.value && value) {
				field.value = value;
			}
		});
	}

	prefillContact();

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

				form.classList.add('is-sent');
				say(result.body.message, false);
			})
			.catch(function () {
				submit.disabled = false;
				say(config.strings.failed, true);
			});
	});
}());
