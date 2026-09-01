<?php
/**
 * A plain outcome page: not allowed, or no such page.
 *
 * Its own document, replacing views/layout.php. That layout drew a top bar from
 * classes -- .serve-topbar__brand, __nav, __who -- that were never styled, so
 * every 403 and 404 arrived as unstyled browser default: Times New Roman on
 * white, purple links. The error page is exactly where somebody is already
 * confused, and looking broken on top of that suggests the application has
 * fallen over rather than that they took a wrong turn.
 *
 * "Not allowed" and "does not exist" are told apart deliberately. Telling
 * somebody already signed in that a screen exists and they may not use it is
 * useful; the things worth hiding are the records, and those are scoped by the
 * queries that read them rather than by the shape of a URL.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
use Serve_Dashboard\Roles;

nocache_headers();

$serve_signed_in = App::auth()->current_id() > 0;
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php echo esc_html( (string) $heading ); ?></title>

	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/app.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/document.css' ) ); ?>">
</head>
<body class="serve-document">

<?php // .serve-app is where the design tokens are declared, not :root. ?>
<div class="serve-app">

	<header class="serve-doc__head">
		<div class="serve-brand">
			<span class="serve-brand__mark"><?php esc_html_e( 'SHAPE' ); ?></span>
			<span class="serve-brand__sub"><?php esc_html_e( 'DISCOVERY' ); ?></span>
			<span class="serve-brand__rule" aria-hidden="true"></span>
			<span class="serve-brand__ministry">
				<?php esc_html_e( 'SERVE MINISTRY' ); ?><br>
				<?php esc_html_e( 'FELLOWSHIP DUBAI' ); ?>
			</span>
		</div>
	</header>

	<main class="serve-prose-wrap serve-message">
		<h1 class="serve-message__title"><?php echo esc_html( (string) $heading ); ?></h1>

		<?php if ( '' !== (string) $detail ) : ?>
			<p class="serve-message__detail"><?php echo esc_html( (string) $detail ); ?></p>
		<?php endif; ?>

		<p class="serve-message__links">
			<?php
			/*
			 * Somewhere to go, chosen by who is asking.
			 *
			 * A signed-in leader wants the dashboard. Somebody with no account
			 * who mistyped a URL has no use for a link to a screen that will
			 * bounce them to a sign-in form, so they are offered the journey and
			 * the notice instead.
			 */
			if ( $serve_signed_in && current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) :
				?>
				<a href="<?php echo esc_url( App::url( 'dashboard' ) ); ?>"><?php esc_html_e( 'Back to the dashboard' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( App::url( 'assessment' ) ); ?>"><?php esc_html_e( 'Take the S.H.A.P.E. journey' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( App::url( 'privacy' ) ); ?>"><?php esc_html_e( 'How we look after your answers' ); ?></a>
			<?php endif; ?>
		</p>
	</main>

</div>

</body>
</html>
