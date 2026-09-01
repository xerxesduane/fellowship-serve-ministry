<?php
/**
 * Application shell markup.
 *
 * Semantic landmarks and the brand block are server-rendered; the data regions
 * are filled by admin/js/app.js. Each region ships with its own loading
 * skeleton so the page never flashes empty, and the whole thing degrades to a
 * readable message without JavaScript.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nav = array(
	array(
		'key'   => 'dashboard',
		'label' => __( 'Dashboard', 'serve-dashboard' ),
		'icon'  => 'home',
	),
	array(
		'key'   => 'people',
		'label' => __( 'People', 'serve-dashboard' ),
		'icon'  => 'users',
	),
	array(
		'key'   => 'matching',
		'label' => __( 'Team matching', 'serve-dashboard' ),
		'icon'  => 'target',
	),
	array(
		'key'   => 'followup',
		'label' => __( 'Follow-up', 'serve-dashboard' ),
		'icon'  => 'clock',
		'badge' => true,
	),
	/*
	 * Reporting friction used to be a card wedged under the dashboard's right
	 * column, which put a form nobody was looking for in the way of the lists
	 * everybody was. It is its own place now, reachable from the sidebar on
	 * every view — which keeps it as close to hand as it was before, without
	 * spending dashboard space on it.
	 */
	array(
		'key'   => 'support',
		'label' => __( 'Contact support', 'serve-dashboard' ),
		'icon'  => 'tool',
	),
);
?>
<div class="serve-app" id="serve-app" data-view="dashboard">

	<script type="application/json" id="serve-app-config">
		<?php echo wp_json_encode( App::config() ); ?>
	</script>

	<noscript>
		<div class="serve-noscript">
			<h1><?php esc_html_e( 'The SERVE dashboard needs JavaScript', 'serve-dashboard' ); ?></h1>
			<p><?php esc_html_e( 'Please enable JavaScript to see who is ready for a serving conversation. Teams and Settings still work without it.', 'serve-dashboard' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_TEAMS ) ); ?>">
					<?php esc_html_e( 'Go to Teams and gaps', 'serve-dashboard' ); ?>
				</a>
			</p>
		</div>
	</noscript>

	<?php // Mobile top bar. Hidden at desktop widths, where the sidebar is permanent. ?>
	<header class="serve-mobilebar">
		<button type="button" class="serve-navtoggle" aria-expanded="false" aria-controls="serve-sidebar">
			<span class="serve-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
					<path d="M4 7h16M4 12h16M4 17h16" />
				</svg>
			</span>
			<span class="screen-reader-text"><?php esc_html_e( 'Open navigation', 'serve-dashboard' ); ?></span>
		</button>

		<span class="serve-mobilebar__title"><?php esc_html_e( 'SERVE', 'serve-dashboard' ); ?></span>

		<span class="serve-avatar serve-avatar--sm" data-serve="user-initials" aria-hidden="true"></span>
	</header>

	<div class="serve-shell">

		<!-- Sidebar ------------------------------------------------------- -->
		<nav class="serve-sidebar" id="serve-sidebar" aria-label="<?php esc_attr_e( 'SERVE navigation', 'serve-dashboard' ); ?>">
			<div class="serve-brand">
				<span class="serve-brand__mark"><?php esc_html_e( 'SHAPE', 'serve-dashboard' ); ?></span>
				<span class="serve-brand__sub"><?php esc_html_e( 'DISCOVERY', 'serve-dashboard' ); ?></span>
				<span class="serve-brand__rule" aria-hidden="true"></span>
				<span class="serve-brand__ministry">
					<?php esc_html_e( 'SERVE MINISTRY', 'serve-dashboard' ); ?><br>
					<?php esc_html_e( 'FELLOWSHIP DUBAI', 'serve-dashboard' ); ?>
				</span>
			</div>

			<ul class="serve-nav" role="list">
				<?php foreach ( $nav as $item ) : ?>
					<li>
						<button type="button" class="serve-nav__item" data-serve-view="<?php echo esc_attr( $item['key'] ); ?>"
							<?php echo 'dashboard' === $item['key'] ? 'aria-current="page"' : ''; ?>>
							<span class="serve-icon" aria-hidden="true"><?php self::icon( $item['icon'] ); ?></span>
							<span><?php echo esc_html( $item['label'] ); ?></span>
							<?php if ( ! empty( $item['badge'] ) ) : ?>
								<span class="serve-nav__badge" data-serve="due-badge" hidden></span>
							<?php endif; ?>
						</button>
					</li>
				<?php endforeach; ?>

				<?php if ( current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) : ?>
					<li>
						<a class="serve-nav__item" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_TEAMS ) ); ?>">
							<span class="serve-icon" aria-hidden="true"><?php self::icon( 'grid' ); ?></span>
							<span><?php esc_html_e( 'Teams and gaps', 'serve-dashboard' ); ?></span>
						</a>
					</li>
				<?php endif; ?>

				<?php if ( current_user_can( Roles::CAP_MANAGE_SETTINGS ) ) : ?>
					<li>
						<a class="serve-nav__item" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ) ); ?>">
							<span class="serve-icon" aria-hidden="true"><?php self::icon( 'settings' ); ?></span>
							<span><?php esc_html_e( 'Settings and audit', 'serve-dashboard' ); ?></span>
						</a>
					</li>
				<?php endif; ?>
			</ul>

			<div class="serve-sidebar__foot">
				<?php $pc = Planning_Center::status(); ?>
				<div class="serve-pc serve-pc--<?php echo esc_attr( $pc['status'] ); ?>">
					<span class="serve-pc__label"><?php esc_html_e( 'Planning Center', 'serve-dashboard' ); ?></span>
					<span class="serve-pc__state">
						<span class="serve-pc__dot" aria-hidden="true"></span>
						<?php echo esc_html( $pc['label'] ); ?>
					</span>
					<p class="serve-pc__note"><?php echo esc_html( $pc['explanation'] ); ?></p>
				</div>

				<?php
				/*
				 * The way out, which is not the same door in both builds.
				 *
				 * This screen is shipped twice: as a WordPress admin page, where
				 * leaving means going back to the WordPress admin, and as a
				 * standalone application, where there is no WordPress to go back
				 * to and the honest control is a sign-out. The markup around it
				 * is identical either way, so the two builds still render the
				 * same shell; only this one control differs, and each build says
				 * which it is rather than the shell guessing.
				 */
				self::exit_control();
				?>
			</div>
		</nav>

		<?php // Closes the sidebar on mobile when tapped. ?>
		<div class="serve-scrim" data-serve="scrim" hidden></div>

		<!-- Main ---------------------------------------------------------- -->
		<main class="serve-main" id="serve-main">
			<div class="serve-topbar">
				<div class="serve-greeting">
					<h1 data-serve="greeting"><?php esc_html_e( 'Dashboard', 'serve-dashboard' ); ?></h1>
					<p><?php esc_html_e( 'Here\'s where people are in their serving journey.', 'serve-dashboard' ); ?></p>
				</div>

				<div class="serve-topbar__tools">
					<form class="serve-search" role="search" data-serve="search-form">
						<label class="screen-reader-text" for="serve-q">
							<?php esc_html_e( 'Search people, teams, gifts', 'serve-dashboard' ); ?>
						</label>
						<span class="serve-icon serve-search__icon" aria-hidden="true"><?php self::icon( 'search' ); ?></span>
						<input type="search" id="serve-q" data-serve="search"
							placeholder="<?php esc_attr_e( 'Search people, teams, gifts…', 'serve-dashboard' ); ?>"
							autocomplete="off" spellcheck="false">
						<span class="serve-search__spinner" data-serve="search-spinner" hidden aria-hidden="true"></span>
					</form>

					<span class="serve-avatar" data-serve="user-initials" aria-hidden="true"></span>
				</div>
			</div>

			<?php // Announces async region changes to assistive technology. ?>
			<p class="screen-reader-text" role="status" aria-live="polite" data-serve="live"></p>

			<div class="serve-views">
				<section class="serve-view" data-serve-region="dashboard" aria-busy="true">
					<div class="serve-metrics" data-serve="metrics">
						<?php for ( $i = 0; $i < 4; $i++ ) : ?>
							<div class="serve-card serve-metric is-skeleton" aria-hidden="true">
								<span class="serve-sk serve-sk--icon"></span>
								<span class="serve-sk serve-sk--label"></span>
								<span class="serve-sk serve-sk--value"></span>
							</div>
						<?php endfor; ?>
					</div>

					<?php
					/*
					 * Two bands, because six cards of equal weight are not a
					 * priority order.
					 *
					 * A leader used to land on a metrics row and six cards with
					 * nothing saying which to read first, on a product whose
					 * deck promises they "see the next best action in one
					 * place". The hierarchy is made of headings and order
					 * rather than colour or size: it costs nothing, it reads
					 * correctly to a screen reader as document structure, and
					 * it cannot fail the way a colour-coded scheme can.
					 */
					?>
					<div class="serve-band">
						<h2 class="serve-band__title"><?php esc_html_e( 'Needs you now', 'serve-dashboard' ); ?></h2>
						<p class="serve-band__hint"><?php esc_html_e( 'In the order a leader would work them.', 'serve-dashboard' ); ?></p>
						<span class="serve-band__rule" aria-hidden="true"></span>
					</div>

					<?php
					/*
					 * Shown only when both cards below are empty. Two empty
					 * cards under "Needs you now" would make the most
					 * prominent thing on the page a pair of holes.
					 */
					?>
					<div class="serve-card serve-today-clear" data-serve="today-clear" hidden>
						<span class="serve-today-clear__icon" aria-hidden="true"><?php self::icon( 'check' ); ?></span>
						<div>
							<h3><?php esc_html_e( 'Nothing is waiting on you today', 'serve-dashboard' ); ?></h3>
							<p><?php esc_html_e( 'Every follow-up is current and everyone who has finished the journey has been spoken to. The context below is there when you want it.', 'serve-dashboard' ); ?></p>
						</div>
					</div>

					<div class="serve-grid" data-serve="today-grid">
						<div class="serve-card serve-card--primary">
							<div class="serve-card__head">
								<h2><?php esc_html_e( 'People ready for a next step', 'serve-dashboard' ); ?></h2>
								<button type="button" class="serve-link" data-serve-view="people">
									<?php esc_html_e( 'View all', 'serve-dashboard' ); ?>
								</button>
							</div>
							<p class="serve-card__hint">
								<?php esc_html_e( 'Anyone nobody has spoken to yet comes first. A suggested team is a conversation starter, not a decision.', 'serve-dashboard' ); ?>
							</p>
							<?php self::stage_legend(); ?>
							<p class="serve-note serve-note--info" data-serve="unmatched" hidden></p>
							<div data-serve="priority"><?php self::skeleton_rows( 5 ); ?></div>
						</div>

						<div class="serve-col">
							<?php
							/*
							 * Overdue conversations lead the band. They were
							 * third in the right-hand rail, under two cards
							 * about capacity, on a dashboard whose first
							 * promised benefit is "bring overdue and upcoming
							 * conversations into view".
							 */
							?>
							<div class="serve-card">
								<div class="serve-card__head">
									<h2><?php esc_html_e( 'Overdue and due today', 'serve-dashboard' ); ?></h2>
								</div>
								<div data-serve="followups"><?php self::skeleton_rows( 3 ); ?></div>
							</div>
						</div>
					</div>

					<div class="serve-band">
						<h2 class="serve-band__title"><?php esc_html_e( 'Context', 'serve-dashboard' ); ?></h2>
						<p class="serve-band__hint"><?php esc_html_e( 'Background. Nothing here is waiting on you today.', 'serve-dashboard' ); ?></p>
						<span class="serve-band__rule" aria-hidden="true"></span>
					</div>

					<div class="serve-grid">
						<div class="serve-card serve-card--primary">
							<div class="serve-card__head">
								<h2><?php esc_html_e( 'Serving team gaps', 'serve-dashboard' ); ?></h2>
							</div>
							<p class="serve-card__hint">
								<?php esc_html_e( 'Context for a conversation. A gap is not a reason to place someone.', 'serve-dashboard' ); ?>
							</p>
							<div class="serve-bars" data-serve="gaps"><?php self::skeleton_rows( 4 ); ?></div>
						</div>

						<div class="serve-col">

							<?php
							/*
							 * The gaps above are worked out from a number
							 * somebody types in by hand. Now that people can
							 * actually be placed, that number goes stale — and
							 * the note on the bars discloses the drift without
							 * ever asking anybody to correct it, which over a
							 * pilot means a quietly worsening figure nobody
							 * owns. This card is the ask.
							 *
							 * Only rendered for leaders who can edit team
							 * capacity; the server sends the list to nobody
							 * else.
							 */
							?>
							<div class="serve-card" data-serve="headcount-card" hidden>
								<div class="serve-card__head">
									<h2><?php esc_html_e( 'Confirm these headcounts', 'serve-dashboard' ); ?></h2>
								</div>
								<p class="serve-card__hint">
									<?php esc_html_e( 'People have been placed since these were last confirmed, so the gaps above are overstated until somebody checks them.', 'serve-dashboard' ); ?>
								</p>
								<div data-serve="headcount-checks"></div>
							</div>

							<?php
							/*
							 * "Invite, schedule, and support the person" was the
							 * Serve stage in the deck. The pipeline stopped at
							 * Placed, so the third of those had no surface at
							 * all — somebody put on a team and never spoken to
							 * again is how a willing volunteer quietly stops
							 * coming.
							 */
							?>
							<div class="serve-card" data-serve="settling-card" hidden>
								<div class="serve-card__head">
									<h2><?php esc_html_e( 'Settling in', 'serve-dashboard' ); ?></h2>
								</div>
								<p class="serve-card__hint">
									<?php esc_html_e( 'Placed a while ago. Worth asking how it is actually going before it becomes obvious.', 'serve-dashboard' ); ?>
								</p>
								<div data-serve="settling"></div>
							</div>

						</div>
					</div>

					<div class="serve-card serve-card--wide">
						<div class="serve-card__head">
							<h2><?php esc_html_e( 'Gifts across our church', 'serve-dashboard' ); ?></h2>
						</div>
						<p class="serve-card__hint">
							<?php esc_html_e( 'Background context only, drawn from the profiles you can see.', 'serve-dashboard' ); ?>
						</p>
						<div class="serve-bars serve-bars--split" data-serve="gifts"><?php self::skeleton_rows( 6 ); ?></div>
					</div>
				</section>

				<section class="serve-view" data-serve-region="matching" hidden>
					<div class="serve-card serve-card--primary">
						<div class="serve-card__head">
							<h2><?php esc_html_e( 'Who might fit a team', 'serve-dashboard' ); ?></h2>
						</div>
						<p class="serve-card__hint">
							<?php esc_html_e( 'Start from a team that needs people. Everyone listed still needs a conversation, and a team being short is never a reason on its own.', 'serve-dashboard' ); ?>
						</p>
						<div class="serve-filters">
							<label class="screen-reader-text" for="serve-match-team"><?php esc_html_e( 'Team', 'serve-dashboard' ); ?></label>
							<select id="serve-match-team" data-serve="match-team"></select>
						</div>
						<div data-serve="candidates"></div>
					</div>
				</section>

				<section class="serve-view" data-serve-region="people" hidden>
					<div class="serve-card serve-card--primary">
						<div class="serve-card__head">
							<h2 data-serve="people-title"><?php esc_html_e( 'People', 'serve-dashboard' ); ?></h2>
						</div>
						<?php self::stage_legend(); ?>
						<div class="serve-filters" data-serve="filters"></div>
						<div data-serve="people"><?php self::skeleton_rows( 8 ); ?></div>
						<div class="serve-pager" data-serve="pager"></div>
					</div>
				</section>

				<section class="serve-view" data-serve-region="support" hidden>
					<div class="serve-card serve-card--primary">
						<div class="serve-card__head">
							<h2><?php esc_html_e( 'Something not working? Tell us', 'serve-dashboard' ); ?></h2>
						</div>
						<p class="serve-card__hint">
							<?php esc_html_e( 'You do not need to know what went wrong or how to describe it. If something was confusing, looked incorrect, or simply did not do what you expected, that is worth reporting exactly as you experienced it.', 'serve-dashboard' ); ?>
						</p>

						<form class="serve-stageform" data-friction-form>
							<label for="serve-friction-area"><?php esc_html_e( 'What happened', 'serve-dashboard' ); ?></label>
							<select id="serve-friction-area" data-friction-area>
								<?php foreach ( Friction::areas() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>

							<label for="serve-friction-body"><?php esc_html_e( 'Tell us a little more', 'serve-dashboard' ); ?></label>
							<textarea id="serve-friction-body" rows="4" maxlength="1000" data-friction-body
								placeholder="<?php esc_attr_e( 'e.g. it suggested Worship for someone whose gifts are all pastoral', 'serve-dashboard' ); ?>"></textarea>

							<button type="submit" class="serve-btn serve-btn--secondary"><?php esc_html_e( 'Send it', 'serve-dashboard' ); ?></button>
							<p class="serve-note serve-note--warn" data-friction-error hidden></p>
						</form>
					</div>

					<div class="serve-card">
						<div class="serve-card__head">
							<h2><?php esc_html_e( 'What happens to what you send', 'serve-dashboard' ); ?></h2>
						</div>
						<ul class="serve-plainlist">
							<li><?php esc_html_e( 'It goes to whoever is running the pilot, not to the person whose profile you were looking at.', 'serve-dashboard' ); ?></li>
							<li><?php esc_html_e( 'It is about the tool, never about a person, and nobody is judged by it.', 'serve-dashboard' ); ?></li>
							<?php
							/*
							 * Stated exactly. The audit trail records that a
							 * report was left and by whom; the wording is
							 * deliberately kept out of it. Promising that
							 * nothing at all is recorded would be a false
							 * reassurance, and the one a leader would feel
							 * misled by if they ever looked.
							 */
							?>
							<li><?php esc_html_e( 'The audit trail records that you left a report, but not a word of what it said.', 'serve-dashboard' ); ?></li>
							<li><?php esc_html_e( 'It is read under “How the pilot is going” in Settings, and it is the main way the dashboard gets better.', 'serve-dashboard' ); ?></li>
						</ul>
						<p class="serve-card__hint">
							<?php esc_html_e( 'Anything that needs pastoral care, or is about a person rather than the software, belongs in a conversation instead.', 'serve-dashboard' ); ?>
						</p>
					</div>
				</section>
			</div>
		</main>

		<!-- Person drawer -------------------------------------------------- -->
		<aside class="serve-drawer" data-serve="drawer" hidden
			role="dialog" aria-modal="true" aria-labelledby="serve-drawer-name">
			<div class="serve-drawer__inner" data-serve="drawer-body"></div>
		</aside>
	</div>
</div>
