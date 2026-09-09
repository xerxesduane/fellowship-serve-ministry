<?php
/**
 * The privacy notice, as a public document.
 *
 * Its own page rather than views/layout.php, for two reasons. The layout draws
 * a signed-in top bar, and this page is read by volunteers who have no account
 * -- most of them arriving from a link at the end of the journey. And the
 * layout's classes were never styled, so the notice was being served as
 * unstyled browser default: the page carrying this application's legal promises
 * about somebody's personal data, in Times New Roman.
 *
 * The text comes from Privacy_Page::render(), generated on every request from
 * the retention period actually in force. See tests/test-privacy-notice.php.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;

nocache_headers();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php esc_html_e( 'How we look after your answers — SERVE' ); ?></title>

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

		<a class="serve-doc__back" href="<?php echo esc_url( App::url( 'assessment' ) ); ?>">
			<?php esc_html_e( 'Back to the journey' ); ?>
		</a>
	</header>

	<main class="serve-prose-wrap">
		<?php
		/*
		 * Echoed unescaped, and that is correct: this is markup the application
		 * generated, and every value interpolated into it was escaped by
		 * Privacy_Page::content() at the point of interpolation. Escaping again
		 * here would print the tags.
		 */
		echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</main>

</div>

</body>
</html>
