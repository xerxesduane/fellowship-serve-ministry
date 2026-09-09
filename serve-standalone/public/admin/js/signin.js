/**
 * Sign in: the two things the form cannot do in HTML alone.
 *
 * Deliberately small, and deliberately additive. The form submits and reports
 * its errors entirely server-side; if this file fails to load, sign-in still
 * works and the only losses are the reveal button and the submitting state.
 * That is the right split for the one screen standing between a leader and
 * their work.
 *
 * No framework and no build step, matching the rest of the project.
 */

/* ── Show the password ───────────────────────────────────────────────────── */

/*
 * Typing a long passphrase blind is where people give up, and a manager-
 * generated password is worse. The toggle is a real 44px target with an
 * accessible name that changes with its state, and aria-pressed so a screen
 * reader announces which state it is in rather than just "button".
 */
for (const button of document.querySelectorAll('[data-serve-reveal]')) {
	button.addEventListener('click', () => {
		const input = document.getElementById(button.dataset.serveReveal);

		if (!input) {
			return;
		}

		const nowVisible = input.type === 'password';

		input.type = nowVisible ? 'text' : 'password';

		button.setAttribute('aria-pressed', nowVisible ? 'true' : 'false');
		button.setAttribute('aria-label', nowVisible ? 'Hide password' : 'Show password');

		/*
		 * Focus goes back to the field, at the end of what was typed.
		 *
		 * Without this the caret is lost and the next keystroke goes nowhere,
		 * which is worse than not offering the toggle.
		 */
		const end = input.value.length;

		input.focus();
		input.setSelectionRange(end, end);
	});
}

/* ── Say that something is happening ─────────────────────────────────────── */

const form = document.querySelector('.serve-signin__form');
const submit = form?.querySelector('[data-serve-submit]');
const label = submit?.querySelector('[data-serve-submit-label]');

if (form && submit && label) {
	form.addEventListener('submit', () => {
		/*
		 * aria-disabled, not disabled.
		 *
		 * A truly disabled button leaves the focus ring on an element that is
		 * no longer in the tab order, and some browsers drop the button's value
		 * from the submitted form. This keeps it focusable and announced while
		 * refusing the second click below.
		 */
		submit.setAttribute('aria-disabled', 'true');

		label.textContent = 'Signing in…';
		label.insertAdjacentHTML('beforebegin', '<span class="serve-signin__spinner" aria-hidden="true"></span>');
	});

	// bcrypt is deliberately slow, so a double submit is a real possibility.
	submit.addEventListener('click', (event) => {
		if (submit.getAttribute('aria-disabled') === 'true') {
			event.preventDefault();
		}
	});
}
