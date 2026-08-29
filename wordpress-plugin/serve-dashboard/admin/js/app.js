/**
 * SERVE Dashboard application.
 *
 * No framework and no build step: the plugin has neither, and adding one to
 * render half a dozen lists would be a poor trade. Plain ES module, fetch, and
 * template strings — with escaping applied at every interpolation point.
 *
 * The server decides what this user may see. This file only decides how to
 * draw it.
 */

const configEl = document.getElementById('serve-app-config');
const CONFIG = configEl ? JSON.parse(configEl.textContent) : null;

/* ── Utilities ─────────────────────────────────────────────────────────── */

/** Escape for text and attribute interpolation. */
function esc(value) {
	return String(value ?? '').replace(/[&<>"']/g, (char) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
	}[char]));
}

const $ = (name, scope = document) => scope.querySelector(`[data-serve="${name}"]`);

/**
 * Build a REST URL that survives both permalink styles.
 *
 * With pretty permalinks the root is /wp-json/serve/v1; with plain permalinks
 * it is index.php?rest_route=/serve/v1. Naively appending "?page=1" produces a
 * second question mark and a 404 on the latter, so query parameters go through
 * URLSearchParams instead of string concatenation.
 */
function restUrl(path, params = {}) {
	const url = new URL(CONFIG.root + path, window.location.href);

	Object.entries(params).forEach(([key, value]) => {
		if (value !== '' && value !== null && value !== undefined && value !== false) {
			url.searchParams.set(key, String(value));
		}
	});

	return url.toString();
}

function api(path, options = {}) {
	const { params, ...init } = options;

	return fetch(restUrl(path, params), {
		...init,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': CONFIG.nonce,
			...(init.headers || {})
		},
		credentials: 'same-origin'
	}).then(async (response) => {
		const body = await response.json().catch(() => ({}));

		if (!response.ok) {
			throw Object.assign(new Error(body.message || CONFIG.i18n.errorBody), { body, status: response.status });
		}

		return body;
	});
}

function debounce(fn, wait) {
	let timer;
	return (...args) => {
		window.clearTimeout(timer);
		timer = window.setTimeout(() => fn(...args), wait);
	};
}

/** Announce an async change without stealing focus. */
function announce(message) {
	const live = $('live');
	if (live) {
		live.textContent = message;
	}
}

function icon(name) {
	const paths = {
		users: '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16 5.5a3.2 3.2 0 0 1 0 6.2"/><path d="M17.5 14.5a5.5 5.5 0 0 1 3 5.5"/>',
		heart: '<path d="M12 19.5s-7-4.3-7-9A3.8 3.8 0 0 1 12 8.2 3.8 3.8 0 0 1 19 10.5c0 4.7-7 9-7 9z"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
		phone: '<path d="M7 3.5h3l1.5 4-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2 4 1.5v3a1.5 1.5 0 0 1-1.7 1.5A17 17 0 0 1 4.5 5.2 1.5 1.5 0 0 1 6 3.5z"/>',
		clock: '<circle cx="12" cy="12" r="8.2"/><path d="M12 7.5V12l3 2"/>',
		gift: '<path d="M4.5 10.5h15V20H4.5z"/><path d="M3.5 7h17v3.5h-17zM12 7v13"/>',
		tool: '<path d="M14.5 6.5a3.5 3.5 0 0 0 4.6 4.6l-8 8a2.4 2.4 0 0 1-3.4-3.4z"/>',
		person: '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
		briefcase: '<rect x="3.5" y="7.5" width="17" height="12" rx="1.6"/><path d="M9 7.5V6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 6v1.5"/>',
		target: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.4"/>',
		send: '<path d="m20 4-8.5 16-2-6.5L3 11.5z"/>',
		external: '<path d="M14 4h6v6"/><path d="m20 4-8.5 8.5"/><path d="M18 14v4.5A1.5 1.5 0 0 1 16.5 20h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
		close: '<path d="m6 6 12 12M18 6 6 18"/>',
		chevron: '<path d="m9 6 6 6-6 6"/>',
		check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		alert: '<path d="M12 4.5 20.5 19h-17z"/><path d="M12 10v4M12 16.6v.1"/>',
		shield: '<path d="M12 4l7 2.5v5c0 4.2-3 7.4-7 8.5-4-1.1-7-4.3-7-8.5v-5z"/>'
	};

	return `<span class="serve-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" focusable="false">${paths[name] || ''}</svg></span>`;
}

/* ── Shared partials ───────────────────────────────────────────────────── */

/**
 * Status badge.
 *
 * Carries a glyph and its label, never colour alone — a red pill and a green
 * pill are the same pill to a colourblind reader.
 */
/**
 * Where somebody is on the serving journey.
 *
 * This was seven pills told apart by hue and a glyph. A pipeline is ordered —
 * Submitted, Contacted, Conversation booked, Trial serve, Placed — and a
 * coloured label carries none of that: you had to already know the vocabulary
 * to read it, which is why the stage legend had to be added above the list.
 *
 * The number does the work now. "2 of 5" survives colour-blindness, a greyscale
 * print, and a leader on their first week, and the filled track is a second,
 * redundant encoding rather than the only one.
 *
 * Paused and Declined get no track. They are real outcomes but they are not
 * positions, and rendering Paused as "2 of 5" would state something false.
 */
function stageIndicator(status, label) {
	const path = CONFIG.stagePath || [];
	const index = path.indexOf(status);

	if (index === -1) {
		const glyph = status === 'declined' ? '—' : '⏸';

		return `<span class="serve-stage serve-stage--off">
			<span class="serve-stage__off"><span aria-hidden="true">${glyph}</span>${esc(label)}</span>
			<span class="screen-reader-text">Stage: ${esc(label)}, not on the serving path</span>
		</span>`;
	}

	const step = index + 1;
	const steps = path
		.map((_, i) => `<span class="serve-stage__step${i < step ? ' is-done' : ''}"></span>`)
		.join('');

	return `<span class="serve-stage">
		<span class="serve-stage__label">${esc(label)} <span class="serve-stage__count">${step} of ${path.length}</span></span>
		<span class="serve-stage__track" aria-hidden="true">${steps}</span>
		<span class="screen-reader-text">Stage: ${esc(label)}, step ${step} of ${path.length}</span>
	</span>`;
}

/** Conversation history, newest first. */
function notesHtml(notes) {
	if (!notes || !notes.length) {
		return '<p class="serve-card__hint">No notes yet. The first one is usually the most useful.</p>';
	}

	return `<ul class="serve-notes">${notes.map((note) => `
		<li>
			<p>${esc(note.body)}</p>
			<span class="serve-notes__meta">${esc(note.author)} &middot; ${esc(note.when)}</span>
		</li>`).join('')}</ul>`;
}

function emptyState({ title, body, action, iconName = 'check' }) {
	return `<div class="serve-empty">
		<span class="serve-empty__icon">${icon(iconName)}</span>
		<h3>${esc(title)}</h3>
		<p>${esc(body)}</p>
		${action || ''}
	</div>`;
}

function errorState(message, retryAttr) {
	return `<div class="serve-empty">
		<span class="serve-empty__icon" style="background:var(--serve-coral-soft);color:var(--serve-coral-dark)">${icon('alert')}</span>
		<h3>${esc(CONFIG.i18n.errorTitle)}</h3>
		<p>${esc(message || CONFIG.i18n.errorBody)}</p>
		<button type="button" class="serve-btn serve-btn--secondary" ${retryAttr}>${esc(CONFIG.i18n.retry)}</button>
	</div>`;
}

/** One person row. Columns appear progressively as the viewport allows. */
function personRow(person) {
	const flags = [
		person.needsCheck ? `<span class="serve-flag serve-flag--check">${esc('check required')}</span>` : '',
		person.isStale ? `<span class="serve-flag serve-flag--stale">${esc('stale')}</span>` : '',
		/*
		 * Said on the row, because otherwise the only sign is an empty Teams
		 * column -- which reads as missing data rather than as the answer, and
		 * meant opening every profile to find out which was which.
		 */
		person.unmatched ? `<span class="serve-flag serve-flag--nomatch">${esc('no team matched')}</span>` : ''
	].join('');

	const dueTone = person.isOverdue ? 'is-attention' : (person.isDueToday ? 'is-soon' : '');
	const dueText = person.isOverdue ? 'Overdue' : (person.isDueToday ? 'Today' : person.nextActionLabel);

	const due = person.nextActionLabel
		? `<span class="serve-fu__when ${dueTone}">${esc(dueText)}</span>`
		: '';

	/*
	 * The same thing again, for narrow screens.
	 *
	 * Below 768px the row has three tracks and the date column is hidden, so
	 * "Overdue" — the most actionable word on the row — disappeared, leaving
	 * only the coral left-border to carry it. A leader working from a phone
	 * between meetings is exactly who needs it. Rendered into the meta line
	 * instead, where there is room.
	 *
	 * Two copies, never both visible: display:none removes an element from the
	 * accessibility tree as well as the page, so whichever one is hidden is
	 * also the one screen readers skip, and the row is never read out twice.
	 */
	const dueInline = person.nextActionLabel
		? `<span class="serve-row__due-inline serve-fu__when ${dueTone}">${esc(dueText)}</span>`
		: '';

	return `<button type="button" class="serve-row ${person.isOverdue ? 'is-overdue' : ''}" data-person="${esc(person.id)}">
		<span class="serve-avatar serve-avatar--sm" aria-hidden="true">${esc(person.initials)}</span>
		<span class="serve-row__body">
			<span class="serve-row__name">${esc(person.name)}</span>
			<span class="serve-row__meta">${esc(person.gifts.join(', ') || '—')}${flags}${dueInline}</span>
		</span>
		<span class="serve-row__col serve-row__col--teams">${person.suggestedTeams.length ? esc(person.suggestedTeams.join(', ')) : (person.unmatched ? '<span class="serve-muted-cell">none matched</span>' : '—')}</span>
		<span class="serve-row__col serve-row__col--due">${due}</span>
		<span class="serve-row__aside">
			${stageIndicator(person.status, person.statusLabel)}
		</span>
	</button>`;
}

/**
 * Column titles for a list of people.
 *
 * Without these the four columns were unlabelled and you had to already know
 * the product to read them: a date with no title could be when somebody
 * applied, and the badge on the right had no name at all. Plain words, in the
 * same vocabulary the leader guide uses — "stage" is what that guide calls the
 * thing you move somebody along.
 *
 * The empty cell over the avatar keeps the titles on the same tracks as the
 * cells they describe. Hidden cells stay in the markup and are hidden by the
 * same rules as the row cells, so the two can never fall out of step.
 */
function rowsHead() {
	return `<div class="serve-rows__head" aria-hidden="true">
		<span></span>
		<span>Name and gifts</span>
		<span class="serve-row__col serve-row__col--teams">Suggested teams</span>
		<span class="serve-row__col serve-row__col--due">Next step due</span>
		<span class="serve-rows__head-aside">Stage</span>
	</div>`;
}

function rowsOrEmpty(people, empty) {
	// No titles over an empty state: there are no columns to title, and a bare
	// header strip above "nothing to do" reads as something failing to load.
	if (!people.length) {
		return emptyState(empty);
	}

	return `<div class="serve-rows">${rowsHead()}${people.map(personRow).join('')}</div>`;
}

/* ── Dashboard regions ─────────────────────────────────────────────────── */

function renderMetrics(metrics) {
	const icons = { completed: 'users', ready: 'heart', due: 'clock', serving: 'person' };

	$('metrics').innerHTML = metrics.map((metric) => {
		const tone = metric.tone === 'attention' ? 'attention' : (metric.tone === 'positive' ? 'positive' : 'neutral');
		const tag = metric.view ? 'button' : 'div';
		const attrs = metric.view ? ` type="button" data-metric-view="${esc(metric.view)}"` : '';

		return `<${tag} class="serve-card serve-metric serve-metric--${esc(tone)}"${attrs}>
			<span class="serve-metric__icon">${icon(icons[metric.key] || 'users')}</span>
			<span class="serve-metric__label">${esc(metric.label)}</span>
			<span class="serve-metric__value">${esc(metric.value)}</span>
			<span class="serve-metric__note">${esc(metric.note)}</span>
		</${tag}>`;
	}).join('');
}

function renderGaps(gaps) {
	if (!gaps.length) {
		$('gaps').innerHTML = emptyState({
			title: 'No gaps recorded',
			body: 'Set a target headcount for each team so shortfalls can be shown here.',
			iconName: 'target',
			action: CONFIG.caps.teams
				? `<a class="serve-btn serve-btn--secondary" href="${esc(CONFIG.teamsUrl)}">Set team targets</a>`
				: ''
		});
		return;
	}

	$('gaps').innerHTML = gaps.map((gap) => {
		const filled = gap.target > 0 ? Math.min(100, Math.round((gap.current / gap.target) * 100)) : 0;

		/*
		 * The shortfall is worked out from a number somebody types in, which
		 * counts everyone serving on the team — most of whom never did a SHAPE
		 * assessment. Placements made since it was last confirmed are therefore
		 * not in it, and each one makes the figure above a little more wrong.
		 *
		 * Said out loud rather than quietly subtracted: adjusting the number on
		 * a leader's behalf would be a guess presented as a fact, and would
		 * double-count the moment they went and corrected it themselves.
		 */
		const since = Number(gap.placedSince) || 0;
		const stale = since > 0
			? `<span class="serve-bar__note">${since} placed here since this was last checked — the real shortfall may be smaller</span>`
			: '';

		return `<div class="serve-bar ${gap.below_minimum ? 'serve-bar--critical' : ''}">
			<span class="serve-bar__label">${esc(gap.name)}</span>
			<span class="serve-bar__value">${esc(gap.gap)} needed${gap.below_minimum ? ' · below minimum' : ''}</span>
			<span class="serve-bar__track">
				<span class="serve-bar__fill" style="width:${filled}%"></span>
			</span>
			${stale}
			<span class="screen-reader-text">${esc(gap.current)} of ${esc(gap.target)} places filled${
				since > 0 ? `, and ${since} placed since this figure was last checked` : ''}</span>
		</div>`;
	}).join('');
}

/**
 * Teams whose typed-in headcount has been overtaken by placements.
 *
 * The note on the gap bars states the drift. This is the only thing in the
 * dashboard that asks somebody to go and correct it, which is the whole
 * difference between an error that is disclosed and one that gets fixed.
 *
 * Its own card rather than a line on the gaps panel: folding it in would make
 * it one more piece of grey text on the thing it is a warning about, and the
 * warning has been ignorable precisely because it looked like part of the
 * furniture. Hidden when nothing has drifted, and never populated for leaders
 * who cannot edit capacity — the server does not send them the list.
 */
function renderHeadcountChecks(items) {
	const card = $('headcount-card');
	if (!card) {
		return;
	}

	if (!items || !items.length) {
		card.hidden = true;
		return;
	}

	card.hidden = false;
	$('headcount-checks').innerHTML = items.map((item) => {
		// null is "never confirmed", which is emphatically not "0 days ago".
		const when = item.daysSince === null
			? 'never confirmed'
			: `confirmed ${item.daysSince} day${item.daysSince === 1 ? '' : 's'} ago`;

		return `<div class="serve-fu serve-fu--settling">
			<span class="serve-fu__open">
				<span class="serve-fu__name">${esc(item.name)}</span>
				<span class="serve-fu__when is-attention">${esc(item.placedSince)} placed since · ${esc(when)}</span>
			</span>
			<a class="serve-btn serve-btn--secondary serve-btn--sm" href="${esc(CONFIG.teamsUrl)}">Confirm</a>
		</div>`;
	}).join('');
}

function renderFollowups(items) {
	if (!items.length) {
		$('followups').innerHTML = emptyState({
			title: CONFIG.i18n.allClear,
			body: CONFIG.i18n.allClearBody
		});
		return;
	}

	$('followups').innerHTML = items.map((item) => `
		<button type="button" class="serve-fu" data-person="${esc(item.id)}">
			<span class="serve-fu__name">${esc(item.name)}</span>
			<span class="serve-fu__when is-${esc(item.tone)}">${esc(item.when)}</span>
		</button>`).join('');
}

/*
 * People placed a while ago that nobody has looked in on.
 *
 * Its own card, hidden when there is nothing due. Folding these into the
 * follow-up queue would bury "see how Aiza is getting on" among people still
 * waiting for a first conversation, and make both lists harder to work.
 */
function renderSettling(items) {
	const card = $('settling-card');
	if (!card) {
		return;
	}

	if (!items || !items.length) {
		card.hidden = true;
		return;
	}

	card.hidden = false;
	$('settling').innerHTML = items.map((item) => `
		<div class="serve-fu serve-fu--settling">
			<button type="button" class="serve-fu__open" data-person="${esc(item.id)}">
				<span class="serve-fu__name">${esc(item.name)}</span>
				<span class="serve-fu__when ${item.overdue ? 'is-attention' : 'is-soon'}">placed ${esc(item.since)}</span>
			</button>
			${CONFIG.caps.manage
				? `<button type="button" class="serve-btn serve-btn--secondary serve-btn--sm" data-settled="${esc(item.id)}">Spoke to them</button>`
				: ''}
		</div>`).join('');
}

function renderGifts(gifts) {
	if (!gifts.length) {
		$('gifts').innerHTML = emptyState({
			title: 'Nothing to summarise yet',
			body: 'Once profiles are shared, the spread of gifts across the church appears here.',
			iconName: 'gift'
		});
		return;
	}

	const max = Math.max(...gifts.map((gift) => gift.count));

	$('gifts').innerHTML = gifts.map((gift) => `
		<div class="serve-bar">
			<span class="serve-bar__label">${esc(gift.label)}</span>
			<span class="serve-bar__value">${esc(gift.count)}</span>
			<span class="serve-bar__track">
				<span class="serve-bar__fill" style="width:${Math.round((gift.count / max) * 100)}%"></span>
			</span>
		</div>`).join('');
}

/* ── Drawer ────────────────────────────────────────────────────────────── */

const drawer = {
	el: null,
	body: null,
	lastFocus: null,

	init() {
		this.el = $('drawer');
		this.body = $('drawer-body');

		document.addEventListener('keydown', (event) => {
			if (this.el.hidden) {
				return;
			}

			if (event.key === 'Escape') {
				this.close();
			}

			if (event.key === 'Tab') {
				this.trapFocus(event);
			}
		});
	},

	/** Keep Tab inside the dialog while it is modal. */
	trapFocus(event) {
		const focusable = this.el.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);

		if (!focusable.length) {
			return;
		}

		const first = focusable[0];
		const last = focusable[focusable.length - 1];

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	},

	open(personId) {
		this.lastFocus = document.activeElement;
		this.el.hidden = false;
		document.getElementById('serve-app').classList.add('is-drawer-open');

		this.body.innerHTML = `<div class="serve-sk-rows" aria-hidden="true">${
			'<span class="serve-sk serve-sk--row"></span>'.repeat(7)}</div>`;
		announce(CONFIG.i18n.loading);

		api(`/people/${personId}`)
			.then((person) => {
				this.render(person);
				announce(person.name);

				const close = this.el.querySelector('.serve-drawer__close');
				if (close) {
					close.focus();
				}
			})
			.catch((error) => {
				this.body.innerHTML = errorState(error.message, `data-retry-person="${esc(personId)}"`);
			});
	},

	close() {
		this.el.hidden = true;
		document.getElementById('serve-app').classList.remove('is-drawer-open');

		// Return focus to whatever opened the drawer. If that element has gone
		// (a re-render) or was never focusable, fall back to the search box so
		// keyboard focus is never left stranded on <body>.
		const opener = this.lastFocus;
		const returnable = opener
			&& document.contains(opener)
			&& typeof opener.focus === 'function'
			&& opener !== document.body;

		if (returnable) {
			opener.focus();
		} else {
			$('search')?.focus();
		}
	},

	render(person) {
		const dimIcons = {
			gifts: 'gift', heart: 'heart', abilities: 'tool',
			personality: 'person', experience: 'briefcase'
		};

		const dimensions = person.shape.map((dim) => {
			let values;

			if (dim.redacted) {
				values = '<em>Hidden — visible to pastoral staff only</em>';
			} else if (dim.values.length) {
				values = esc(dim.values.slice(0, 6).join(', '));
			} else {
				values = '<em>Not recorded</em>';
			}

			return `<div class="serve-dim">
				<span class="serve-dim__icon">${icon(dimIcons[dim.key])}</span>
				<span>
					<span class="serve-dim__label">${esc(dim.label)}</span>
					<span class="serve-dim__values">${values}</span>
					<span class="serve-dim__rule" aria-hidden="true"></span>
				</span>
			</div>`;
		}).join('');

		/*
		 * Suggestions now come from ranking every team, not from the three the
		 * assessment picked on spiritual gifts alone — so some of these are
		 * teams the person has never seen. Their own downloaded profile lists
		 * the assessment's picks, and a leader opening a conversation needs to
		 * know which of these the person is already expecting. Flagged on the
		 * ones they have not seen rather than on the ones they have: the new
		 * suggestions are the smaller set and the ones that need the caveat.
		 */
		/*
		 * No strength badge on each row.
		 *
		 * Only strong matches are suggested, so it read "Strong match" on every
		 * one -- the same word repeated down the panel, carrying no information
		 * and taking the eye away from the reasons, which are the part a leader
		 * has to actually read. The claim is made once, in the heading above.
		 */
		const matches = person.matches.length
			? person.matches.map((match) => `
				<div class="serve-match">
					<div class="serve-match__head">
						<span class="serve-match__name">${esc(match.team_name)}${
							match.from_assessment
								? ''
								: '<span class="serve-flag serve-flag--stale">not on their profile</span>'}</span>
					</div>
					${match.reasons.length ? `<div class="serve-match__why">
						<span>Why this team?</span>
						<div class="serve-reasons">${match.reasons.slice(0, 5).map((reason) => `
							<span class="serve-reason">
								<span class="serve-reason__dot" aria-hidden="true"></span>
								<span>${esc(reason.label)}</span>
							</span>`).join('')}</div>
					</div>` : `<p class="serve-match__context">No specific overlap was found. This suggestion needs a conversation before anything else.</p>`}
					${match.opening_note ? `<p class="serve-match__context">${esc(match.opening_note)}</p>` : ''}
				</div>`).join('')
			: `<p class="serve-note serve-note--muted">No team reached a strong match for this profile. That is a real answer, not a gap: the evidence was there but it did not concentrate on any one team. Their answers are on this page, and the conversation starts open.</p>`;

		/*
		 * How this person is likely to serve, whichever team it turns out to be.
		 *
		 * The four personality couplets have been collected and displayed since
		 * the beginning and nothing ever interpreted them, while the deck lists
		 * personality among the five things matching considers.
		 *
		 * Shown once, under the suggestions rather than inside each one, and
		 * headed so it cannot be read as evidence for a particular team: the
		 * same four tendencies apply to all of them. Repeating the block per
		 * team would imply it told you something about the choice between them.
		 */
		const personality = (person.personalityNotes && person.personalityNotes.length)
			? `<div class="serve-personality">
					<span class="serve-personality__head">However the conversation goes, how they are likely to serve</span>
					${person.personalityNotes.map((item) => `
						<p class="serve-personality__item">
							<strong>${esc(item.tendency)}.</strong> ${esc(item.note)}
						</p>`).join('')}
					<p class="serve-card__hint">Temperament shapes how a role is best arranged, not whether somebody is suited to it. There is no wrong answer here.</p>
				</div>`
			: '';

		const safeguarding = person.safeguarding.relevant
			? `<p class="serve-note ${person.safeguarding.cleared ? 'serve-note--info' : 'serve-note--warn'}">
					${person.safeguarding.cleared ? icon('check') : icon('shield')}
					Background check: ${esc(person.safeguarding.label)}.
					${person.safeguarding.cleared ? '' : 'Required before a placement on a team working with children or youth.'}
				</p>`
			: '';

		/*
		 * The whole dashboard exists to start a conversation, and until now the
		 * drawer showed no way to have one — the email and phone were in the
		 * payload and rendered nowhere, so a leader had to leave for the
		 * WordPress admin screen to find them.
		 *
		 * tel: wants the number without the spacing people type; the visible
		 * text keeps whatever they wrote, which is how they will recognise it.
		 */
		const dial = String(person.phone || '').replace(/[^\d+]/g, '');
		const contact = `
			<div class="serve-section">
				<h3>Contact</h3>
				<ul class="serve-contact">
					<li>${icon('mail')}<a href="mailto:${esc(person.email)}">${esc(person.email)}</a></li>
					${dial
						? `<li>${icon('phone')}<a href="tel:${esc(dial)}">${esc(person.phone)}</a></li>`
						: '<li class="serve-card__hint">No phone number — this profile predates the question being required.</li>'}
				</ul>
			</div>`;

		/*
		 * Moving somebody along the pipeline.
		 *
		 * Until now the drawer could set exactly one status — "contacted", via
		 * the invite button — and the screen that could set the rest had been
		 * orphaned, required by nothing. So a leader could start a conversation
		 * and then had nowhere to record how it went.
		 *
		 * The team selector is not decoration: Trial serve and Placed are
		 * decisions about a particular team, and the safeguarding gate is a
		 * question that cannot be asked without one.
		 */
		/*
		 * Ordinarily the choice is between the teams this person was suggested
		 * to. Somebody the ranking matched to nothing has no such list, and
		 * without a fallback the conversation could happen and then have nowhere
		 * to be recorded: the dropdown was empty and Trial serve and Placed were
		 * unreachable for them, permanently.
		 */
		const choosableTeams = person.placements.length
			? person.placements
			: (person.allTeams || []);

		const teamOptions = choosableTeams
			.map((p) => `<option value="${esc(p.teamId)}">${esc(p.teamName)}${p.safeguarded ? ' — background check required' : ''}</option>`)
			.join('');

		const stageForm = CONFIG.caps.manage ? `
			<div class="serve-section">
				<h3>Record what happened</h3>
				<form class="serve-stageform" data-stage-form="${esc(person.id)}">
					<label for="serve-stage-${esc(person.id)}">Move to</label>
					<select id="serve-stage-${esc(person.id)}" data-stage-status>
						${Object.entries(CONFIG.statuses)
							.map(([value, label]) => `<option value="${esc(value)}" ${value === person.status ? 'selected' : ''}>${esc(label)}</option>`)
							.join('')}
					</select>

					<div data-stage-team ${CONFIG.gatedStatuses.includes(person.status) ? '' : 'hidden'}>
						<label for="serve-stage-team-${esc(person.id)}">On which team</label>
						${teamOptions
							? `<select id="serve-stage-team-${esc(person.id)}" data-stage-team-select>${teamOptions}</select>
								${!person.placements.length ? '<p class="serve-card__hint">No team matched this profile, so every active team is listed. Choose the one the conversation settled on.</p>' : ''}`
							: '<p class="serve-note serve-note--warn">There are no active teams to place anyone on. Add one on the Teams screen first.</p>'}
					</div>

					<div data-stage-until ${person.status === 'paused' ? '' : 'hidden'}>
						<label for="serve-stage-until-${esc(person.id)}">Bring them back on</label>
						<input type="date" id="serve-stage-until-${esc(person.id)}" data-stage-snooze>
						<p class="serve-card__hint">A pause without a return date is how somebody quietly disappears from the queue.</p>
					</div>

					<div data-stage-reason ${person.status === 'declined' ? '' : 'hidden'}>
						<label for="serve-stage-reason-${esc(person.id)}">Anything worth recording (optional)</label>
						<input type="text" id="serve-stage-reason-${esc(person.id)}" maxlength="190" data-stage-decline
							placeholder="e.g. not this season, asked us to check back after Ramadan">
					</div>

					<button type="submit" class="serve-btn serve-btn--primary">Save this change</button>
					<p class="serve-note serve-note--warn" data-stage-error hidden></p>
				</form>
			</div>` : '';

		/*
		 * Recording a background check. Pastors only, and on its own route — a
		 * leader who can move someone to Placed must not also be able to clear
		 * the check that permits it.
		 */
		const safeguardForm = (CONFIG.caps.safeguard && person.safeguarding.relevant) ? `
			<div class="serve-section">
				<h3>Background check</h3>
				<form class="serve-stageform" data-safeguard-form="${esc(person.id)}">
					<label for="serve-sg-${esc(person.id)}">Check status</label>
					<select id="serve-sg-${esc(person.id)}" data-safeguard-status>
						${Object.entries(CONFIG.safeguardStatuses)
							.map(([value, label]) => `<option value="${esc(value)}" ${value === person.safeguarding.status ? 'selected' : ''}>${esc(label)}</option>`)
							.join('')}
					</select>
					<button type="submit" class="serve-btn serve-btn--secondary">Record it</button>
					<p class="serve-note serve-note--warn" data-safeguard-error hidden></p>
				</form>
			</div>` : '';

		/*
		 * Inviting somebody, and what they said back.
		 *
		 * The button used to move a stage and send nothing, which was honest
		 * given no messaging workflow had been agreed but left the deck's Serve
		 * stage with no invitation in it. A leader now chooses per person:
		 * ringing somebody you know and emailing a newcomer are different acts.
		 *
		 * The wording is theirs. A pastoral invitation signed with a leader's
		 * name but written by software reads as a mass mailing at exactly the
		 * moment personal contact matters, so this is a draft, not a template.
		 */
		const answered = person.inviteResponse;
		const answerBlock = answered
			? `<div class="serve-section">
					<h3>What they said</h3>
					<p class="serve-note serve-note--${esc(answered.tone === 'ok' ? 'info' : answered.tone)}">
						<strong>${esc(answered.label)}</strong>${answered.when ? ` &middot; ${esc(answered.when)}` : ''}
					</p>
					${answered.note ? `<p class="serve-card__hint">“${esc(answered.note)}”</p>` : ''}
				</div>`
			: '';

		const inviteBlock = CONFIG.caps.manage ? `
			<div class="serve-section" data-invite-panel="${esc(person.id)}" hidden>
				<h3>Invite to a conversation</h3>
				<form class="serve-stageform" data-invite-form="${esc(person.id)}">
					<label for="serve-invite-how-${esc(person.id)}">How</label>
					<select id="serve-invite-how-${esc(person.id)}" data-invite-method>
						<option value="email">Send them an email now</option>
						<option value="personal">I will contact them myself</option>
					</select>

					<div data-invite-fields>
						<label for="serve-invite-subject-${esc(person.id)}">Subject</label>
						<input type="text" id="serve-invite-subject-${esc(person.id)}" data-invite-subject
							value="${esc(person.inviteDraft.subject)}" maxlength="190">

						<label for="serve-invite-body-${esc(person.id)}">Message</label>
						<textarea id="serve-invite-body-${esc(person.id)}" rows="8" data-invite-body>${esc(person.inviteDraft.body)}</textarea>
						<p class="serve-card__hint">A draft, not a template — say it the way you would say it. Your name and a link where they can answer are added underneath.</p>
					</div>

					<div data-invite-personal hidden>
						<p class="serve-card__hint">Nothing is sent. This records that you have taken it on, so nobody else rings them tomorrow.</p>
					</div>

					<button type="submit" class="serve-btn serve-btn--primary">Do it</button>
					<p class="serve-note serve-note--warn" data-invite-error hidden></p>
				</form>
			</div>` : '';

		const actions = [];

		if (CONFIG.caps.manage) {
			actions.push(`<button type="button" class="serve-btn serve-btn--primary serve-btn--block" data-invite-open="${esc(person.id)}">
				${icon('send')}${person.invitedAt ? 'Invite again' : 'Invite to a conversation'}</button>`);
		}

		if (person.planningCenterUrl) {
			actions.push(`<a class="serve-btn serve-btn--secondary serve-btn--block" href="${esc(person.planningCenterUrl)}" target="_blank" rel="noopener noreferrer">
				${icon('external')}Open in Planning Center</a>`);
		} else {
			actions.push(`<p class="serve-note serve-note--muted">Planning Center links are not configured${
				CONFIG.caps.settings ? ', so a record cannot be opened from here yet.' : '.'}</p>`);
		}

		// Honouring "please remove my details". Pastors only, and it asks twice,
		// because there is no undo and the profile includes pastoral history.
		if (CONFIG.caps.settings) {
			actions.push(`<button type="button" class="serve-btn serve-btn--danger serve-btn--block"
				data-erase="${esc(person.id)}">${icon('alert')}Delete this profile</button>`);
		}

		this.body.innerHTML = `
			<div class="serve-drawer__head">
				<span class="serve-avatar" aria-hidden="true">${esc(person.initials)}</span>
				<span>
					<h2 id="serve-drawer-name">${esc(person.name)}</h2>
					<span class="serve-drawer__meta">
						Profile completed ${esc(person.submittedAt)}<br>
						Last updated ${esc(person.updatedAt)}
					</span>
				</span>
				<button type="button" class="serve-drawer__close" data-close-drawer>
					${icon('close')}<span class="screen-reader-text">Close profile</span>
				</button>
			</div>

			${contact}

			<div class="serve-section">
				<h3>Journey status</h3>
				<div class="serve-journey">
					${(CONFIG.stagePhases || {})[person.status]
						? `<span class="serve-phase__tag">${esc(CONFIG.stagePhases[person.status])}</span>`
						: ''}
					${stageIndicator(person.status, person.statusLabel)}
					${person.isStale ? '<span class="serve-flag serve-flag--stale">profile is stale</span>' : ''}
				</div>
				${person.tenureMonths !== null ? `<p class="serve-card__hint">Expects to be in the UAE about ${esc(person.tenureMonths)} more months.</p>` : ''}
				${safeguarding}
			</div>

			${answerBlock}
			${inviteBlock}
			${stageForm}
			${safeguardForm}

			<div class="serve-section">
				<h3>S.H.A.P.E. profile</h3>
				${dimensions}
				${person.redacted ? '<p class="serve-note serve-note--muted">Some sections are hidden because they can include pastoral history.</p>' : ''}
			</div>

			<div class="serve-section">
				<h3>Suggested teams</h3>
				<p class="serve-card__hint">Every one of these is a strong match, so the list is short by design and sometimes empty. A suggestion is still a starting point: the leader confirms, and the person chooses. Anything marked <em>not on their profile</em> came from their wider answers, so they have not seen it yet.</p>
				${person.matches.find((m) => m.caveat)
					? `<p class="serve-note serve-note--warn">${esc(person.matches.find((m) => m.caveat).caveat)}</p>`
					: ''}
				${matches}
				${personality}
			</div>

			<div class="serve-section">
				<h3>Who is following this up</h3>
				${person.owner.name
					? `<p class="serve-owner">${esc(person.owner.name)}${person.owner.isMine ? ' (you)' : ''}</p>`
					: '<p class="serve-card__hint">Nobody has picked this up yet.</p>'}
				${CONFIG.caps.manage ? (person.owner.isMine || !person.owner.name
					? `<button type="button" class="serve-btn serve-btn--secondary" data-claim="${esc(person.id)}" data-release="${person.owner.isMine ? '1' : ''}">
							${person.owner.isMine ? 'Release it' : 'I will follow this up'}</button>`
					: `<p class="serve-card__hint">Ask them to release it before you call, so you are not both ringing.</p>`) : ''}
			</div>

			<div class="serve-section">
				<h3>Conversation history</h3>
				${CONFIG.caps.manage ? `
					<form class="serve-noteform" data-note-form="${esc(person.id)}">
						<label class="screen-reader-text" for="serve-note-${esc(person.id)}">Add a note</label>
						<textarea id="serve-note-${esc(person.id)}" rows="2" maxlength="2000"
							placeholder="What was said? e.g. Spoke Tuesday, travelling until September."></textarea>
						<button type="submit" class="serve-btn serve-btn--secondary">Add note</button>
					</form>` : ''}
				<div data-notes-list>${notesHtml(person.notes)}</div>
			</div>

			<div class="serve-actions">${actions.join('')}</div>
		`;
	}
};

/* ── People view ───────────────────────────────────────────────────────── */

const people = {
	state: { search: '', status: '', team_id: 0, due_only: false, page: 1 },
	teams: [],
	statuses: {},

	renderFilters() {
		const options = Object.entries(this.statuses)
			.map(([value, label]) => `<option value="${esc(value)}" ${this.state.status === value ? 'selected' : ''}>${esc(label)}</option>`)
			.join('');

		const teamOptions = this.teams
			.map((team) => `<option value="${esc(team.id)}" ${Number(this.state.team_id) === team.id ? 'selected' : ''}>${esc(team.name)}</option>`)
			.join('');

		$('filters').innerHTML = `
			<label class="screen-reader-text" for="serve-f-status">Status</label>
			<select id="serve-f-status" data-filter="status"><option value="">Any status</option>${options}</select>

			<label class="screen-reader-text" for="serve-f-team">Team</label>
			<select id="serve-f-team" data-filter="team_id"><option value="0">Any team</option>${teamOptions}</select>

			<button type="button" class="serve-chip" data-filter-toggle="due_only" aria-pressed="${this.state.due_only}">
				${icon('clock')}Due or overdue
			</button>`;
	},

	load() {
		const region = document.querySelector('[data-serve-region="people"]');
		region.setAttribute('aria-busy', 'true');

		api('/people', {
			params: {
				page: this.state.page,
				per_page: 25,
				search: this.state.search,
				status: this.state.status,
				team_id: Number(this.state.team_id) || '',
				due_only: this.state.due_only ? '1' : ''
			}
		})
			.then((data) => {
				const isFiltered = this.state.search || this.state.status || Number(this.state.team_id) || this.state.due_only;

				$('people').innerHTML = rowsOrEmpty(data.people, isFiltered
					? { title: CONFIG.i18n.noResults, body: CONFIG.i18n.noResultsBody, iconName: 'alert' }
					: { title: CONFIG.i18n.noProfiles, body: CONFIG.i18n.noProfilesBody, iconName: 'users' });

				$('pager').innerHTML = (data.page > 1 || data.hasMore)
					? `<span>Page ${esc(data.page)}</span>
						<span style="display:flex;gap:var(--serve-space-2)">
							<button type="button" class="serve-btn serve-btn--secondary" data-page="${data.page - 1}" ${data.page <= 1 ? 'disabled' : ''}>Previous</button>
							<button type="button" class="serve-btn serve-btn--secondary" data-page="${data.page + 1}" ${data.hasMore ? '' : 'disabled'}>Next</button>
						</span>`
					: '';

				region.setAttribute('aria-busy', 'false');
				announce(data.people.length === 1
					? '1 person listed'
					: `${data.people.length} people listed`);
			})
			.catch((error) => {
				$('people').innerHTML = errorState(error.message, 'data-retry-people');
				region.setAttribute('aria-busy', 'false');
			});
	}
};


/* ── Team matching ─────────────────────────────────────────────────────── */

const matching = {
	teams: [],
	teamId: 0,

	renderPicker() {
		const select = $('match-team');
		if (!select) {
			return;
		}

		select.innerHTML = this.teams
			.map((team) => `<option value="${esc(team.id)}" ${Number(this.teamId) === team.id ? 'selected' : ''}>${esc(team.name)}</option>`)
			.join('');

		if (!this.teamId && this.teams.length) {
			this.teamId = this.teams[0].id;
			select.value = String(this.teamId);
		}
	},

	load() {
		if (!this.teamId) {
			return;
		}

		const container = $('candidates');
		container.innerHTML = `<div class="serve-sk-rows" aria-hidden="true">${
			'<span class="serve-sk serve-sk--row"></span>'.repeat(5)}</div>`;

		api(`/teams/${this.teamId}/candidates`)
			.then((data) => {
				const gapLine = data.team.target > 0
					? `${data.team.current} of ${data.team.target} places filled${data.team.gap > 0 ? ` — ${data.team.gap} still needed` : ''}`
					: 'No target headcount set for this team yet.';

				if (!data.candidates.length) {
					container.innerHTML = `<p class="serve-card__hint">${esc(gapLine)}</p>` + emptyState({
						title: 'Nobody to suggest yet',
						body: 'No profile currently overlaps this team. That is not a gap in the tool — it may simply mean the right person has not completed the journey yet.',
						iconName: 'target'
					});
					announce('No candidates');
					return;
				}

				container.innerHTML = `
					<p class="serve-card__hint">${esc(gapLine)}</p>
					<div class="serve-rows serve-rows--compact">
					<div class="serve-rows__head" aria-hidden="true">
						<span></span>
						<span>Name and why they might fit</span>
						<span class="serve-row__col serve-row__col--due">Stage</span>
						<span class="serve-rows__head-aside">Strength of evidence</span>
					</div>
					${data.candidates.map((c) => `
						<button type="button" class="serve-row" data-person="${esc(c.id)}">
							<span class="serve-avatar serve-avatar--sm" aria-hidden="true">${esc(c.initials)}</span>
							<span class="serve-row__body">
								<span class="serve-row__name">${esc(c.name)}</span>
								<span class="serve-row__meta">
									${esc(c.match.reasons.length ? c.match.reasons[0].label : 'No specific overlap found')}
									${c.needsCheck ? '<span class="serve-flag serve-flag--check">check required</span>' : ''}
									${c.alreadySuggested ? '' : '<span class="serve-flag serve-flag--stale">not auto-suggested</span>'}
								</span>
							</span>
							<span class="serve-row__col serve-row__col--due">${esc(c.statusLabel)}</span>
							<span class="serve-row__aside">
								<span class="serve-badge serve-badge--${c.match.strength === 'strong' ? 'ready' : 'progress'}">
									<span class="serve-badge__glyph" aria-hidden="true">${c.match.strength === 'strong' ? '●' : '◐'}</span>${esc(c.match.strength_label)}
								</span>
							</span>
						</button>`).join('')}</div>`;

				announce(`${data.candidates.length} possible people for ${data.team.name}`);
			})
			.catch((error) => {
				container.innerHTML = errorState(error.message, 'data-retry-matching');
			});
	}
};

/* ── Views ─────────────────────────────────────────────────────────────── */

function showView(name) {
	const view = name === 'followup' ? 'people' : name;

	if (view === 'matching') {
		document.querySelectorAll('[data-serve-region]').forEach((region) => {
			region.hidden = region.dataset.serveRegion !== 'matching';
		});
		document.querySelectorAll('.serve-nav__item[data-serve-view]').forEach((item) => {
			if (item.dataset.serveView === name) {
				item.setAttribute('aria-current', 'page');
			} else {
				item.removeAttribute('aria-current');
			}
		});
		// Wait for the team list rather than drawing an empty control.
		const candidates = $('candidates');
		if (candidates && !matching.teams.length) {
			candidates.innerHTML = `<div class="serve-sk-rows" aria-hidden="true">${
				'<span class="serve-sk serve-sk--row"></span>'.repeat(4)}</div>`;
		}

		Promise.resolve(bootPromise).then(() => {
			matching.renderPicker();
			matching.load();
		});

		closeSidebar();
		return;
	}

	document.querySelectorAll('[data-serve-region]').forEach((region) => {
		region.hidden = region.dataset.serveRegion !== view;
	});

	document.querySelectorAll('.serve-nav__item[data-serve-view]').forEach((item) => {
		if (item.dataset.serveView === name) {
			item.setAttribute('aria-current', 'page');
		} else {
			item.removeAttribute('aria-current');
		}
	});

	if (view === 'people') {
		if (name === 'followup') {
			people.state.due_only = true;
			people.state.page = 1;
			people.renderFilters();
		}

		$('people-title').textContent = name === 'followup' ? 'Follow-up' : 'People';
		people.load();
	}

	closeSidebar();
}

/* ── Sidebar (mobile) ──────────────────────────────────────────────────── */

function openSidebar() {
	document.getElementById('serve-sidebar').classList.add('is-open');
	document.querySelector('.serve-navtoggle').setAttribute('aria-expanded', 'true');
	$('scrim').hidden = false;
}

function closeSidebar() {
	document.getElementById('serve-sidebar').classList.remove('is-open');
	const toggle = document.querySelector('.serve-navtoggle');
	if (toggle) {
		toggle.setAttribute('aria-expanded', 'false');
	}
	$('scrim').hidden = true;
}

/* ── Boot ──────────────────────────────────────────────────────────────── */

/**
 * The initial dashboard fetch.
 *
 * Held as a promise because Team matching needs the team list that comes with
 * it. Clicking that nav item during the first load used to render an empty
 * picker and no candidates, with nothing to explain why.
 */
let bootPromise = null;

function loadDashboard() {
	const region = document.querySelector('[data-serve-region="dashboard"]');
	region.setAttribute('aria-busy', 'true');

	return api('/dashboard')
		.then((data) => {
			const first = data.greeting.name;
			const hour = new Date().getHours();
			const part = hour < 12 ? 'Good morning' : (hour < 18 ? 'Good afternoon' : 'Good evening');

			$('greeting').textContent = first ? `${part}, ${first}` : part;

			document.querySelectorAll('[data-serve="user-initials"]').forEach((el) => {
				el.textContent = (first || '?').trim().charAt(0).toUpperCase();
			});

			/*
			 * "Needs you now" holds two lists. When both are empty the band
			 * would be two empty states stacked under the loudest heading on
			 * the page, so it collapses to a single quiet line instead.
			 */
			const nothingToday = !data.priority.length && !data.followups.length;
			const todayGrid = $('today-grid');
			const todayClear = $('today-clear');
			if (todayGrid && todayClear) {
				todayGrid.hidden = nothingToday;
				todayClear.hidden = !nothingToday;
			}

			renderMetrics(data.metrics);
			renderGaps(data.gaps);
			renderHeadcountChecks(data.headcountChecks);
			renderFollowups(data.followups);
			renderSettling(data.settling);
			renderGifts(data.gifts);

			/*
			 * How many people the ranking matched to nothing.
			 *
			 * Shown as a sentence above the queue rather than as another metric
			 * tile, because it is not a number to drive down: it says how many
			 * conversations start without a suggestion to open them with.
			 */
			const unmatched = $('unmatched');
			if (unmatched) {
				const n = Number(data.unmatchedCount || 0);
				unmatched.hidden = n === 0;
				unmatched.textContent = n === 0
					? ''
					: `${n} ${n === 1 ? 'person' : 'people'} matched no team. Their answers are still here and the conversation starts open.`;
			}

			$('priority').innerHTML = rowsOrEmpty(data.priority, {
				title: data.metrics.some((m) => m.value > 0) ? CONFIG.i18n.allClear : CONFIG.i18n.noProfiles,
				body: data.metrics.some((m) => m.value > 0) ? CONFIG.i18n.allClearBody : CONFIG.i18n.noProfilesBody,
				iconName: 'users'
			});

			const dueMetric = data.metrics.find((metric) => metric.key === 'due');
			const badgeEl = $('due-badge');
			if (badgeEl && dueMetric && dueMetric.value > 0) {
				badgeEl.textContent = dueMetric.value;
				badgeEl.hidden = false;
			}

			people.teams = data.teams;
			people.statuses = data.statuses;
			people.renderFilters();

			matching.teams = data.teams;

			// Export lives beside the list it exports, not in a settings screen.
			if (CONFIG.exportUrl) {
				const head = document.querySelector('[data-serve-region="dashboard"] .serve-card--primary .serve-card__head');
				if (head && !head.querySelector('[data-export]')) {
					const link = document.createElement('a');
					link.className = 'serve-link';
					link.href = CONFIG.exportUrl;
					link.dataset.export = '1';
					link.textContent = 'Export CSV';
					head.appendChild(link);
				}
			}

			region.setAttribute('aria-busy', 'false');
			announce('Dashboard loaded');
		})
		.catch((error) => {
			$('priority').innerHTML = errorState(error.message, 'data-retry-dashboard');
			region.setAttribute('aria-busy', 'false');
		});
}

function boot() {
	drawer.init();
	bootPromise = loadDashboard();

	// One delegated listener rather than rebinding after every re-render.
	document.addEventListener('click', (event) => {
		const personBtn = event.target.closest('[data-person]');
		if (personBtn) {
			drawer.open(personBtn.dataset.person);
			return;
		}

		const nav = event.target.closest('[data-serve-view]');
		if (nav) {
			showView(nav.dataset.serveView);
			return;
		}

		const metric = event.target.closest('[data-metric-view]');
		if (metric) {
			const params = new URLSearchParams(metric.dataset.metricView);
			people.state.status = params.get('status') || '';
			people.state.due_only = params.get('due_only') === '1';
			people.state.page = 1;
			people.renderFilters();
			showView('people');
			return;
		}

		if (event.target.closest('[data-close-drawer]')) {
			drawer.close();
			return;
		}

		const toggle = event.target.closest('.serve-navtoggle');
		if (toggle) {
			const open = toggle.getAttribute('aria-expanded') === 'true';
			if (open) { closeSidebar(); } else { openSidebar(); }
			return;
		}

		if (event.target.closest('[data-serve="scrim"]')) {
			closeSidebar();
			return;
		}

		const pageBtn = event.target.closest('[data-page]');
		if (pageBtn && !pageBtn.disabled) {
			people.state.page = Math.max(1, Number(pageBtn.dataset.page));
			people.load();
			return;
		}

		const chip = event.target.closest('[data-filter-toggle]');
		if (chip) {
			const key = chip.dataset.filterToggle;
			people.state[key] = !people.state[key];
			people.state.page = 1;
			chip.setAttribute('aria-pressed', String(people.state[key]));
			people.load();
			return;
		}

		if (event.target.closest('[data-retry-dashboard]')) {
			loadDashboard();
			return;
		}

		if (event.target.closest('[data-retry-people]')) {
			people.load();
			return;
		}

		if (event.target.closest('[data-retry-matching]')) {
			matching.load();
			return;
		}

		const retryPerson = event.target.closest('[data-retry-person]');
		if (retryPerson) {
			drawer.open(retryPerson.dataset.retryPerson);
			return;
		}

		const inviteOpen = event.target.closest('[data-invite-open]');
		if (inviteOpen) {
			const panel = document.querySelector(`[data-invite-panel="${inviteOpen.dataset.inviteOpen}"]`);
			if (panel) {
				panel.hidden = false;
				panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
				panel.querySelector('[data-invite-method]').focus();
			}
			return;
		}

		const settled = event.target.closest('[data-settled]');
		if (settled) {
			settled.disabled = true;
			settled.textContent = 'Saving…';

			api(`/people/${settled.dataset.settled}/settled`, { method: 'POST' })
				.then(() => {
					announce('Settling-in check closed');
					bootPromise = loadDashboard();
				})
				.catch((error) => {
					settled.disabled = false;
					settled.textContent = 'Spoke to them';
					announce(error.message);
				});
			return;
		}

		const erase = event.target.closest('[data-erase]');
		if (erase) {
			const id = erase.dataset.erase;

			/*
			 * Two presses, not a confirm() dialog. This deletes a profile, its
			 * consent record, its placements and its conversation notes, with
			 * no undo — so the button says plainly what the next press does
			 * rather than putting that in a box people click through.
			 */
			if (erase.dataset.armed !== '1') {
				erase.dataset.armed = '1';
				erase.textContent = 'Press again to delete permanently';
				announce('Press again to delete this profile permanently');
				return;
			}

			erase.disabled = true;
			erase.textContent = 'Deleting…';

			api(`/people/${id}`, { method: 'DELETE' })
				.then(() => {
					announce('Profile deleted');
					drawer.close();
					bootPromise = loadDashboard();
				})
				.catch((error) => {
					erase.disabled = false;
					delete erase.dataset.armed;
					erase.textContent = 'Delete this profile';
					announce(error.message);
				});
			return;
		}

		const claim = event.target.closest('[data-claim]');
		if (claim) {
			const id = claim.dataset.claim;
			const releasing = claim.dataset.release === '1';
			claim.disabled = true;

			api(`/people/${id}/claim`, {
				method: 'POST',
				body: JSON.stringify({ release: releasing })
			})
				.then(() => {
					announce(releasing ? 'Follow-up released' : 'You are following this up');
					drawer.open(id);
				})
				.catch((error) => {
					claim.disabled = false;
					announce(error.message);
					window.alert(error.message);
				});
		}
	});

	// Adding a note re-renders only the history, so the drawer does not jump.
	/* Show only the fields the chosen stage actually needs. */
	function syncStageFields(form) {
		const status = form.querySelector('[data-stage-status]').value;

		form.querySelector('[data-stage-team]').hidden = !CONFIG.gatedStatuses.includes(status);
		form.querySelector('[data-stage-until]').hidden = status !== 'paused';
		form.querySelector('[data-stage-reason]').hidden = status !== 'declined';
	}

	document.addEventListener('submit', (event) => {
		const friction = event.target.closest('[data-friction-form]');
		if (friction) {
			event.preventDefault();

			const body = friction.querySelector('[data-friction-body]');
			const area = friction.querySelector('[data-friction-area]');
			const error = friction.querySelector('[data-friction-error]');
			const button = friction.querySelector('button[type="submit"]');

			error.hidden = true;

			if (!body.value.trim()) {
				body.focus();
				return;
			}

			button.disabled = true;
			button.textContent = 'Sending…';

			api('/friction', {
				method: 'POST',
				body: JSON.stringify({ area: area.value, body: body.value.trim() })
			})
				.then(() => {
					// Replaced rather than merely cleared: a form that empties
					// itself looks identical to one that failed silently.
					friction.innerHTML =
						'<p class="serve-note serve-note--info">Thank you — that is recorded. '
						+ 'It appears under “How the pilot is going” in Settings.</p>';
					announce('Feedback recorded');
				})
				.catch((err) => {
					button.disabled = false;
					button.textContent = 'Send it';
					error.textContent = err.message;
					error.hidden = false;
				});
			return;
		}

		const invite = event.target.closest('[data-invite-form]');
		if (invite) {
			event.preventDefault();
			submitInvite(invite);
			return;
		}

		const stage = event.target.closest('[data-stage-form]');
		if (stage) {
			event.preventDefault();
			submitStage(stage);
			return;
		}

		const safeguard = event.target.closest('[data-safeguard-form]');
		if (safeguard) {
			event.preventDefault();
			submitSafeguard(safeguard);
			return;
		}

		const form = event.target.closest('[data-note-form]');
		if (!form) {
			return;
		}

		event.preventDefault();

		const id = form.dataset.noteForm;
		const field = form.querySelector('textarea');
		const body = field.value.trim();

		if (!body) {
			field.focus();
			return;
		}

		const button = form.querySelector('button');
		button.disabled = true;

		api(`/people/${id}/notes`, {
			method: 'POST',
			body: JSON.stringify({ body })
		})
			.then((data) => {
				field.value = '';
				button.disabled = false;
				const list = form.parentElement.querySelector('[data-notes-list]');
				if (list) {
					list.innerHTML = notesHtml(data.notes);
				}
				announce('Note added');
			})
			.catch((error) => {
				button.disabled = false;
				announce(error.message);
			});
	});

	document.addEventListener('change', (event) => {
		const how = event.target.closest('[data-invite-method]');
		if (how) {
			const form = how.closest('[data-invite-form]');
			const byEmail = 'email' === how.value;
			form.querySelector('[data-invite-fields]').hidden = !byEmail;
			form.querySelector('[data-invite-personal]').hidden = byEmail;
		}

		const stage = event.target.closest('[data-stage-status]');
		if (stage) {
			syncStageFields(stage.closest('[data-stage-form]'));
		}

		const picker = event.target.closest('[data-serve="match-team"]');
		if (picker) {
			matching.teamId = Number(picker.value);
			matching.load();
			return;
		}

		const filter = event.target.closest('[data-filter]');
		if (filter) {
			people.state[filter.dataset.filter] = filter.value;
			people.state.page = 1;
			people.load();
		}
	});

	// Search drives the people view, debounced so typing does not spam the API.
	const runSearch = debounce((value) => {
		people.state.search = value;
		people.state.page = 1;
		$('search-spinner').hidden = false;

		const done = () => { $('search-spinner').hidden = true; };
		people.load();
		window.setTimeout(done, 300);

		if (document.querySelector('[data-serve-region="people"]').hidden) {
			showView('people');
		}
	}, 280);

	$('search').addEventListener('input', (event) => runSearch(event.target.value.trim()));

	$('search-form').addEventListener('submit', (event) => event.preventDefault());
}

/**
 * Move someone to "Contacted".
 *
 * Named for what it actually does. It does not send anything: no messaging
 * workflow has been defined, and a button that claims to have emailed someone
 * when it has not is worse than no button.
 */
/**
 * Record a pipeline move.
 *
 * The server decides whether the move is allowed — the safeguarding gate lives
 * in the transition code precisely so that it does not depend on this form
 * being correct — so a refusal is shown as it comes back rather than being
 * second-guessed here. The one thing worth catching early is a missing return
 * date, because the person is looking straight at the empty field.
 */
function submitStage(form) {
	const id = form.dataset.stageForm;
	const status = form.querySelector('[data-stage-status]').value;
	const teamSelect = form.querySelector('[data-stage-team-select]');
	const snooze = form.querySelector('[data-stage-snooze]');
	const reason = form.querySelector('[data-stage-decline]');
	const error = form.querySelector('[data-stage-error]');
	const button = form.querySelector('button[type="submit"]');

	const fail = (message) => {
		error.textContent = message;
		error.hidden = false;
		button.disabled = false;
		button.textContent = 'Save this change';
	};

	error.hidden = true;

	if (status === 'paused' && !snooze.value) {
		fail('Pausing someone needs a date to bring them back.');
		snooze.focus();
		return;
	}

	if (CONFIG.gatedStatuses.includes(status) && !teamSelect) {
		fail('No team is suggested for this person yet, so there is nothing to place them on.');
		return;
	}

	const payload = { status };

	if (teamSelect && CONFIG.gatedStatuses.includes(status)) {
		payload.team_id = Number(teamSelect.value);
	}
	if (status === 'paused') {
		payload.snooze_until = snooze.value;
	}
	if (status === 'declined' && reason.value.trim()) {
		payload.decline_reason = reason.value.trim();
	}

	button.disabled = true;
	button.textContent = 'Saving…';

	api(`/people/${id}/status`, { method: 'POST', body: JSON.stringify(payload) })
		.then(() => {
			announce(`Moved to ${CONFIG.statuses[status]}`);
			drawer.open(id);
			bootPromise = loadDashboard();
		})
		.catch((err) => fail(err.message));
}

/** Record a background check. Pastors only; the route enforces that too. */
function submitSafeguard(form) {
	const id = form.dataset.safeguardForm;
	const status = form.querySelector('[data-safeguard-status]').value;
	const error = form.querySelector('[data-safeguard-error]');
	const button = form.querySelector('button[type="submit"]');

	error.hidden = true;
	button.disabled = true;
	button.textContent = 'Saving…';

	api(`/people/${id}/safeguarding`, { method: 'POST', body: JSON.stringify({ status }) })
		.then(() => {
			announce(`Background check recorded: ${CONFIG.safeguardStatuses[status]}`);
			drawer.open(id);
		})
		.catch((err) => {
			error.textContent = err.message;
			error.hidden = false;
			button.disabled = false;
			button.textContent = 'Record it';
		});
}

/**
 * Send the invitation, or record that the leader will do it themselves.
 *
 * A refusal comes straight back — if the mailer will not take it, nothing is
 * recorded as invited, because a leader believing they have written to somebody
 * who never heard is worse than an obvious failure.
 */
function submitInvite(form) {
	const id = form.dataset.inviteForm;
	const method = form.querySelector('[data-invite-method]').value;
	const error = form.querySelector('[data-invite-error]');
	const button = form.querySelector('button[type="submit"]');

	error.hidden = true;
	button.disabled = true;
	button.textContent = 'email' === method ? 'Sending…' : 'Saving…';

	api(`/people/${id}/invite`, {
		method: 'POST',
		body: JSON.stringify({
			method,
			subject: form.querySelector('[data-invite-subject]').value,
			body: form.querySelector('[data-invite-body]').value
		})
	})
		.then(() => {
			announce('email' === method ? 'Invitation sent' : 'Recorded — you are contacting them');
			drawer.open(id);
			bootPromise = loadDashboard();
		})
		.catch((err) => {
			button.disabled = false;
			button.textContent = 'Do it';
			error.textContent = err.message;
			error.hidden = false;
		});
}

/*
 * Start last: `drawer` and `people` are const bindings, so calling boot()
 * before this point would hit the temporal dead zone.
 */
if (CONFIG) {
	boot();
}
