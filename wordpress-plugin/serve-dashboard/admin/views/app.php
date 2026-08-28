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

				<a class="serve-exit" href="<?php echo esc_url( admin_url() ); ?>">
					<?php esc_html_e( 'Exit to WordPress', 'serve-dashboard' ); ?>
				</a>
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

					<div class="serve-grid">
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
							<div data-serve="priority"><?php self::skeleton_rows( 5 ); ?></div>
						</div>

						<div class="serve-col">
							<div class="serve-card">
								<div class="serve-card__head">
									<h2><?php esc_html_e( 'Serving team gaps', 'serve-dashboard' ); ?></h2>
								</div>
								<p class="serve-card__hint">
									<?php esc_html_e( 'Context for a conversation. A gap is not a reason to place someone.', 'serve-dashboard' ); ?>
								</p>
								<div data-serve="gaps"><?php self::skeleton_rows( 4 ); ?></div>
							</div>

							<div class="serve-card">
								<div class="serve-card__head">
									<h2><?php esc_html_e( 'Quick follow-ups', 'serve-dashboard' ); ?></h2>
								</div>
								<div data-serve="followups"><?php self::skeleton_rows( 3 ); ?></div>
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

							<?php
							/*
							 * The pilot is supposed to surface friction, and the
							 * figures cannot: they count what happened, not
							 * whether it made sense. Kept in reach rather than
							 * buried behind a menu, because the moment somebody
							 * hits the problem is the only moment they will say
							 * anything about it.
							 */
							?>
							<div class="serve-card">
								<div class="serve-card__head">
									<h2><?php esc_html_e( 'Something not working?', 'serve-dashboard' ); ?></h2>
								</div>
								<p class="serve-card__hint">
									<?php esc_html_e( 'Say so while it is fresh. This goes to whoever is running the pilot, is about the tool rather than about a person, and nobody is judged by it.', 'serve-dashboard' ); ?>
								</p>

								<form class="serve-stageform" data-friction-form>
									<label for="serve-friction-area"><?php esc_html_e( 'What happened', 'serve-dashboard' ); ?></label>
									<select id="serve-friction-area" data-friction-area>
										<?php foreach ( Friction::areas() as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>

									<label for="serve-friction-body"><?php esc_html_e( 'Tell us a little more', 'serve-dashboard' ); ?></label>
									<textarea id="serve-friction-body" rows="3" maxlength="1000" data-friction-body
										placeholder="<?php esc_attr_e( 'e.g. it suggested Worship for someone whose gifts are all pastoral', 'serve-dashboard' ); ?>"></textarea>

									<button type="submit" class="serve-btn serve-btn--secondary"><?php esc_html_e( 'Send it', 'serve-dashboard' ); ?></button>
									<p class="serve-note serve-note--warn" data-friction-error hidden></p>
								</form>
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
						<div data-serve="gifts"><?php self::skeleton_rows( 6 ); ?></div>
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
						<div class="serve-filters" data-serve="filters"></div>
						<div data-serve="people"><?php self::skeleton_rows( 8 ); ?></div>
						<div class="serve-pager" data-serve="pager"></div>
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
