<?php
/**
 * Sign in.
 *
 * Replaces wp-login.php, which is the one screen the plugin had no design for.
 *
 * It says nothing about whether an address is known: the message is identical
 * for an unknown account and a wrong password, and Auth::login() does the same
 * amount of bcrypt work either way so the response time does not answer the
 * question the message refuses to. That is why the errors below are deliberately
 * vague about *which* field was wrong when the credentials are rejected, while
 * being specific about a field left empty -- which gives away nothing.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;

$serve_error  = '';
$serve_fields = array();
$serve_email  = '';

// Only our own paths, so ?next= cannot be used to bounce somebody off-site.
$serve_next = (string) ( $_GET['next'] ?? '' );
$serve_next = preg_match( '#^[a-z0-9/_-]{0,64}$#i', $serve_next ) ? $serve_next : '';

if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
	check_admin_referer( 'serve_login' );

	$serve_email    = trim( (string) ( $_POST['email'] ?? '' ) );
	$serve_password = (string) ( $_POST['password'] ?? '' );

	/*
	 * An empty field is named; a rejected pair is not.
	 *
	 * Checked here rather than left to the browser's `required`, because that is
	 * bypassable and because the error then appears in the same place and shape
	 * as every other error on this form.
	 */
	if ( '' === $serve_email ) {
		$serve_fields['email'] = __( 'Enter the email address your account uses.' );
	}

	if ( '' === $serve_password ) {
		$serve_fields['password'] = __( 'Enter your password.' );
	}

	if ( array() === $serve_fields ) {
		$serve_result = App::auth()->login( $serve_email, $serve_password );

		if ( is_wp_error( $serve_result ) ) {
			$serve_error = $serve_result->get_error_message();
		} else {
			App::auth()->start_session( (int) $serve_result );

			wp_safe_redirect( App::url( '' === $serve_next ? 'dashboard' : $serve_next ) );
			exit;
		}
	} else {
		$serve_error = __( 'Check the fields marked below.' );
	}
} elseif ( App::auth()->current_id() > 0 ) {
	wp_safe_redirect( App::url( 'dashboard' ) );
	exit;
}

nocache_headers();

/** A small warning triangle, used beside each field error. */
function serve_error_icon(): string {
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
		. ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
		. '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>'
		. '<path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php esc_html_e( 'Sign in — SERVE' ); ?></title>

	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/app.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/signin.css' ) ); ?>">
</head>
<body class="serve-signin-page">

<?php
/*
 * .serve-app is not decoration here: the design tokens are declared on that
 * class rather than on :root, so everything inside this wrapper resolves its
 * colours and spacing from the dashboard's own system.
 */
?>
<div class="serve-app">
	<main class="serve-signin">

		<?php // The sidebar's brand block, in the navy context it was designed for. ?>
		<div class="serve-signin__brand">
			<div class="serve-brand">
				<span class="serve-brand__mark"><?php esc_html_e( 'SHAPE' ); ?></span>
				<span class="serve-brand__sub"><?php esc_html_e( 'DISCOVERY' ); ?></span>
				<span class="serve-brand__rule" aria-hidden="true"></span>
				<span class="serve-brand__ministry">
					<?php esc_html_e( 'SERVE MINISTRY' ); ?><br>
					<?php esc_html_e( 'FELLOWSHIP DUBAI' ); ?>
				</span>
			</div>

			<p class="serve-signin__purpose">
				<?php esc_html_e( 'For ministry leaders and pastors. Volunteers do not need an account — they simply complete the journey.' ); ?>
			</p>
		</div>

		<form class="serve-signin__form" method="post" novalidate>
			<?php wp_nonce_field( 'serve_login' ); ?>

			<h1 class="serve-signin__title"><?php esc_html_e( 'Sign in' ); ?></h1>
			<p class="serve-signin__lede">
				<?php esc_html_e( 'To see who is ready for a serving conversation.' ); ?>
			</p>

			<?php
			/*
			 * A summary, and it takes focus.
			 *
			 * Announced by role="alert" and focused on load so somebody using a
			 * screen reader is told the submission failed rather than being
			 * silently returned to the top of an unchanged-looking page. Each
			 * field carries its own message as well; a summary alone leaves you
			 * hunting for which input is wrong.
			 */
			?>
			<?php if ( '' !== $serve_error ) : ?>
				<p class="serve-note serve-note--warn" role="alert" tabindex="-1" id="serve-signin-error" autofocus>
					<?php echo esc_html( $serve_error ); ?>
				</p>
			<?php endif; ?>

			<div class="serve-field">
				<label class="serve-field__label" for="serve-email"><?php esc_html_e( 'Email address' ); ?></label>

				<input class="serve-field__input"
					id="serve-email"
					name="email"
					type="email"
					value="<?php echo esc_attr( $serve_email ); ?>"
					autocomplete="username"
					inputmode="email"
					spellcheck="false"
					<?php echo isset( $serve_fields['email'] ) ? 'aria-invalid="true" aria-describedby="serve-email-error"' : ''; ?>
					<?php echo '' === $serve_error ? 'autofocus' : ''; ?>>

				<?php if ( isset( $serve_fields['email'] ) ) : ?>
					<p class="serve-field__error" id="serve-email-error">
						<?php
						echo serve_error_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup from this file.
						echo esc_html( $serve_fields['email'] );
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="serve-field">
				<label class="serve-field__label" for="serve-password"><?php esc_html_e( 'Password' ); ?></label>

				<div class="serve-signin__password">
					<?php
					/*
					 * Paste is allowed and autocomplete is on, deliberately.
					 *
					 * Blocking either is the classic way a login form becomes
					 * inaccessible: it forces anybody using a password manager to
					 * transcribe a long random string by hand.
					 */
					?>
					<input class="serve-field__input"
						id="serve-password"
						name="password"
						type="password"
						autocomplete="current-password"
						<?php echo isset( $serve_fields['password'] ) ? 'aria-invalid="true" aria-describedby="serve-password-error"' : ''; ?>>

					<button type="button" class="serve-signin__reveal"
						data-serve-reveal="serve-password"
						aria-controls="serve-password"
						aria-pressed="false"
						aria-label="<?php esc_attr_e( 'Show password' ); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
							stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>
							<circle cx="12" cy="12" r="3"/>
						</svg>
					</button>
				</div>

				<?php if ( isset( $serve_fields['password'] ) ) : ?>
					<p class="serve-field__error" id="serve-password-error">
						<?php
						echo serve_error_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup from this file.
						echo esc_html( $serve_fields['password'] );
						?>
					</p>
				<?php endif; ?>
			</div>

			<button type="submit" class="serve-btn serve-btn--primary" data-serve-submit>
				<span data-serve-submit-label><?php esc_html_e( 'Sign in' ); ?></span>
			</button>

			<p class="serve-signin__foot">
				<a href="<?php echo esc_url( App::url( 'assessment' ) ); ?>"><?php esc_html_e( 'Take the S.H.A.P.E. journey' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( App::url( 'privacy' ) ); ?>"><?php esc_html_e( 'How we look after your answers' ); ?></a>
				<br>
				<a href="<?php echo esc_url( App::url( 'reset' ) ); ?>"><?php esc_html_e( 'Forgotten your password?' ); ?></a>
			</p>
		</form>
	</main>
</div>

<script type="module" src="<?php echo esc_url( App::asset( 'admin/js/signin.js' ) ); ?>"></script>

</body>
</html>
