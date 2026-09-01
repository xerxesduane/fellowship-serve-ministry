<?php
/**
 * The application frame, for screens the server renders whole.
 *
 * The dashboard is a single page holding five views, drawn by admin/js/app.js
 * into views/app/shell.php. Teams and gaps is not one of those views: it is a
 * form, it has to work without JavaScript, and it is a page the browser
 * navigates to. Before this it therefore got views/layout.php -- a bare top bar
 * with two links -- so walking from the dashboard to Teams meant the whole
 * application apparently disappearing and being replaced by a plain document.
 *
 * This gives those screens the same sidebar, the same brand block and the same
 * Planning Center panel, so the navigation stays put and only the main region
 * changes. It is deliberately *not* views/app/shell.php: that file is shared
 * byte-for-byte with the plugin and is the dashboard's own markup, JSON config
 * and skeletons included. Rendering it here to then hide all of it would put a
 * second copy of the dashboard on a page that is not the dashboard.
 *
 * The duplication that remains is the sidebar's markup, which is checked by
 * eye against the shell. tools/check-shared-frontend.sh cannot check this one,
 * because there is nothing in the plugin to check it against: in that build
 * Teams is a WordPress admin page and wears WordPress's own chrome.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App as Platform;
use Serve_Dashboard\App;
use Serve_Dashboard\Planning_Center;
use Serve_Dashboard\Roles;

/**
 * Open the frame: everything up to and including the start of <main>.
 *
 * @param string $current Which navigation item is this screen. Either a
 *                        dashboard view name or 'teams'.
 * @param string $title   The <h1> and the browser title.
 * @param string $lede    One sentence under the heading. May be empty.
 */
function serve_chrome_open( string $current, string $title, string $lede = '' ): void {
	/*
	 * The dashboard's own views, linked by fragment.
	 *
	 * app.js reads the fragment on boot, so these land on the view they name
	 * rather than dropping the leader back on the dashboard and making them
	 * find their way again.
	 */
	$views = array(
		array( 'dashboard', __( 'Dashboard' ), 'home' ),
		array( 'people', __( 'People' ), 'users' ),
		array( 'matching', __( 'Team matching' ), 'target' ),
		array( 'followup', __( 'Follow-up' ), 'clock' ),
		array( 'support', __( 'Contact support' ), 'tool' ),
	);

	$pc = Planning_Center::status();
	?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php echo esc_html( $title ); ?></title>

	<link rel="stylesheet" href="<?php echo esc_url( Platform::asset( 'admin/css/app.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( Platform::asset( 'admin/css/standalone.css' ) ); ?>">
</head>
<body class="serve-app-fullscreen">

<div class="serve-app" data-view="<?php echo esc_attr( $current ); ?>">
	<?php // Mobile top bar. Hidden at desktop widths, where the sidebar is permanent. ?>
	<header class="serve-mobilebar">
		<button type="button" class="serve-navtoggle" aria-expanded="false" aria-controls="serve-sidebar">
			<span class="serve-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round">
					<path d="M4 7h16M4 12h16M4 17h16" />
				</svg>
			</span>
			<span class="screen-reader-text"><?php esc_html_e( 'Open navigation' ); ?></span>
		</button>

		<span class="serve-mobilebar__title"><?php esc_html_e( 'SERVE' ); ?></span>
	</header>

	<div class="serve-shell">
		<nav class="serve-sidebar" id="serve-sidebar" aria-label="<?php esc_attr_e( 'SERVE navigation' ); ?>">
			<div class="serve-brand">
				<span class="serve-brand__mark"><?php esc_html_e( 'SHAPE' ); ?></span>
				<span class="serve-brand__sub"><?php esc_html_e( 'DISCOVERY' ); ?></span>
				<span class="serve-brand__rule" aria-hidden="true"></span>
				<span class="serve-brand__ministry">
					<?php esc_html_e( 'SERVE MINISTRY' ); ?><br>
					<?php esc_html_e( 'FELLOWSHIP DUBAI' ); ?>
				</span>
			</div>

			<ul class="serve-nav" role="list">
				<?php foreach ( $views as list( $key, $label, $icon ) ) : ?>
					<li>
						<a class="serve-nav__item"
							href="<?php echo esc_url( Platform::url( 'dashboard' ) . ( 'dashboard' === $key ? '' : '#' . $key ) ); ?>">
							<span class="serve-icon" aria-hidden="true"><?php App::icon( $icon ); ?></span>
							<span><?php echo esc_html( $label ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>

				<?php if ( current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) : ?>
					<li>
						<a class="serve-nav__item" href="<?php echo esc_url( Platform::url( 'teams' ) ); ?>"
							<?php echo 'teams' === $current ? 'aria-current="page"' : ''; ?>>
							<span class="serve-icon" aria-hidden="true"><?php App::icon( 'grid' ); ?></span>
							<span><?php esc_html_e( 'Teams and gaps' ); ?></span>
						</a>
					</li>
				<?php endif; ?>
			</ul>

			<div class="serve-sidebar__foot">
				<div class="serve-pc serve-pc--<?php echo esc_attr( (string) $pc['status'] ); ?>">
					<span class="serve-pc__label"><?php esc_html_e( 'Planning Center' ); ?></span>
					<span class="serve-pc__state">
						<span class="serve-pc__dot" aria-hidden="true"></span>
						<?php echo esc_html( (string) $pc['label'] ); ?>
					</span>
					<p class="serve-pc__note"><?php echo esc_html( (string) $pc['explanation'] ); ?></p>
				</div>

				<?php App::exit_control(); ?>
			</div>
		</nav>

		<div class="serve-scrim" data-serve="scrim" hidden></div>

		<main class="serve-main">
			<div class="serve-topbar">
				<div class="serve-greeting">
					<h1><?php echo esc_html( $title ); ?></h1>
					<?php if ( '' !== $lede ) : ?>
						<p><?php echo esc_html( $lede ); ?></p>
					<?php endif; ?>
				</div>
			</div>
	<?php
}

/** Close the frame opened above. */
function serve_chrome_close(): void {
	?>
		</main>
	</div>
</div>

<script src="<?php echo esc_url( Platform::asset( 'admin/js/standalone.js' ) ); ?>"></script>

</body>
</html>
	<?php
}
