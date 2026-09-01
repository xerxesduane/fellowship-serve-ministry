<?php
/**
 * Sign in.
 *
 * Replaces wp-login.php. Deliberately says nothing about whether an address is
 * known: the message is identical for an unknown account and a wrong password,
 * and Auth::login() does the same amount of work either way, so the response
 * time does not answer the question the message refuses to.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;

$serve_error = '';

// Only our own paths, so ?next= cannot be used to bounce somebody off-site.
$serve_next = (string) ( $_GET['next'] ?? '' );
$serve_next = preg_match( '#^[a-z0-9/_-]{0,64}$#i', $serve_next ) ? $serve_next : '';

if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
	check_admin_referer( 'serve_login' );

	$serve_result = App::auth()->login(
		(string) ( $_POST['email'] ?? '' ),
		(string) ( $_POST['password'] ?? '' )
	);

	if ( is_wp_error( $serve_result ) ) {
		$serve_error = $serve_result->get_error_message();
	} else {
		App::auth()->start_session( (int) $serve_result );

		wp_safe_redirect( App::url( '' === $serve_next ? 'dashboard' : $serve_next ) );
		exit;
	}
} elseif ( App::auth()->current_id() > 0 ) {
	wp_safe_redirect( App::url( 'dashboard' ) );
	exit;
}

nocache_headers();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Sign in — SERVE' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( App::url( 'admin/css/app.css' ) ); ?>">
</head>
<body class="serve-standalone serve-standalone--centred">

<main class="serve-signin">
	<h1 class="serve-signin__title"><?php esc_html_e( 'SERVE' ); ?></h1>
	<p class="serve-signin__lede"><?php esc_html_e( 'Sign in to see who is ready for a serving conversation.' ); ?></p>

	<?php if ( '' !== $serve_error ) : ?>
		<p class="serve-note serve-note--warn" role="alert"><?php echo esc_html( $serve_error ); ?></p>
	<?php endif; ?>

	<form method="post" class="serve-signin__form">
		<?php wp_nonce_field( 'serve_login' ); ?>

		<label class="serve-field">
			<span class="serve-field__label"><?php esc_html_e( 'Email address' ); ?></span>
			<input class="serve-field__input" type="email" name="email" autocomplete="username" required autofocus>
		</label>

		<label class="serve-field">
			<span class="serve-field__label"><?php esc_html_e( 'Password' ); ?></span>
			<input class="serve-field__input" type="password" name="password" autocomplete="current-password" required>
		</label>

		<button type="submit" class="serve-btn serve-btn--primary"><?php esc_html_e( 'Sign in' ); ?></button>
	</form>

	<p class="serve-signin__foot">
		<a href="<?php echo esc_url( App::url( 'assessment' ) ); ?>"><?php esc_html_e( 'Take the SHAPE assessment' ); ?></a>
		&middot;
		<a href="<?php echo esc_url( App::url( 'privacy' ) ); ?>"><?php esc_html_e( 'Privacy notice' ); ?></a>
	</p>
</main>

</body>
</html>
