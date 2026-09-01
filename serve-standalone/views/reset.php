<?php
/**
 * Ask for a password link, and set a new password with one.
 *
 * Two states on one page, because they are two halves of one errand and
 * splitting them would mean a second screen that exists only to say "check your
 * email". Which half renders depends on whether the URL carries a token.
 *
 * Neither half says whether an address has an account. A form that answers that
 * is an account-enumeration oracle, and on an application holding a
 * congregation's spiritual gifts, the list of who has an account is itself worth
 * not leaking. Auth::request_reset() therefore does comparable work either way
 * and always reports success.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;

nocache_headers();

/** @var string $serve_stage 'request', 'sent', 'set' or 'done'. */
/** @var string $serve_error Empty unless something was refused. */
$serve_stage = $serve_stage ?? 'request';
$serve_error = $serve_error ?? '';
$serve_token = $serve_token ?? '';

$serve_titles = array(
	'request' => __( 'Set a new password' ),
	'sent'    => __( 'Check your email' ),
	'set'     => __( 'Choose a new password' ),
	'done'    => __( 'Your password is set' ),
);
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php echo esc_html( $serve_titles[ $serve_stage ] ?? $serve_titles['request'] ); ?> — SERVE</title>

	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/app.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/signin.css' ) ); ?>">
</head>
<body class="serve-signin-page">

<?php // .serve-app is where the design tokens live, not :root. ?>
<div class="serve-app">
	<main class="serve-signin">

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
				<?php esc_html_e( 'Only accounts belonging to ministry leaders and pastors can sign in here.' ); ?>
			</p>
		</div>

		<?php if ( 'sent' === $serve_stage ) : ?>

			<div class="serve-signin__form">
				<h1 class="serve-signin__title"><?php esc_html_e( 'Check your email' ); ?></h1>

				<p class="serve-signin__lede">
					<?php esc_html_e( 'If that address has an account, a link to set a new password is on its way. It works once and expires in an hour.' ); ?>
				</p>

				<p class="serve-signin__lede">
					<?php esc_html_e( 'Nothing has changed yet — your current password still works until you use the link.' ); ?>
				</p>

				<p class="serve-signin__foot">
					<a href="<?php echo esc_url( App::url( 'login' ) ); ?>"><?php esc_html_e( 'Back to sign in' ); ?></a>
				</p>
			</div>

		<?php elseif ( 'done' === $serve_stage ) : ?>

			<div class="serve-signin__form">
				<h1 class="serve-signin__title"><?php esc_html_e( 'Your password is set' ); ?></h1>

				<p class="serve-signin__lede">
					<?php esc_html_e( 'You can sign in with it now. Any other device that was signed in to this account has been signed out.' ); ?>
				</p>

				<p>
					<a class="serve-btn serve-btn--primary" href="<?php echo esc_url( App::url( 'login' ) ); ?>">
						<?php esc_html_e( 'Sign in' ); ?>
					</a>
				</p>
			</div>

		<?php elseif ( 'set' === $serve_stage ) : ?>

			<form class="serve-signin__form" method="post" novalidate>
				<?php wp_nonce_field( 'serve_reset_password' ); ?>
				<input type="hidden" name="token" value="<?php echo esc_attr( $serve_token ); ?>">

				<h1 class="serve-signin__title"><?php esc_html_e( 'Choose a new password' ); ?></h1>
				<p class="serve-signin__lede">
					<?php esc_html_e( 'Twelve characters or more. A short sentence you will remember beats a scramble you will not.' ); ?>
				</p>

				<?php if ( '' !== $serve_error ) : ?>
					<p class="serve-note serve-note--warn" role="alert" tabindex="-1" autofocus>
						<?php echo esc_html( $serve_error ); ?>
					</p>
				<?php endif; ?>

				<div class="serve-field">
					<label class="serve-field__label" for="serve-new-password"><?php esc_html_e( 'New password' ); ?></label>

					<div class="serve-signin__password">
						<?php
						/*
						 * autocomplete="new-password" rather than
						 * "current-password", so a password manager offers to
						 * generate and store one instead of filling in the old.
						 */
						?>
						<input class="serve-field__input"
							id="serve-new-password"
							name="password"
							type="password"
							autocomplete="new-password"
							minlength="12"
							<?php echo '' === $serve_error ? 'autofocus' : ''; ?>
							aria-describedby="serve-new-password-help">

						<button type="button" class="serve-signin__reveal"
							data-serve-reveal="serve-new-password"
							aria-controls="serve-new-password"
							aria-pressed="false"
							aria-label="<?php esc_attr_e( 'Show password' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
								stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>
								<circle cx="12" cy="12" r="3"/>
							</svg>
						</button>
					</div>

					<p class="serve-field__help" id="serve-new-password-help">
						<?php esc_html_e( 'Paste from a password manager if you use one — nothing here blocks it.' ); ?>
					</p>
				</div>

				<button type="submit" class="serve-btn serve-btn--primary" data-serve-submit>
					<span data-serve-submit-label><?php esc_html_e( 'Set my password' ); ?></span>
				</button>
			</form>

		<?php else : ?>

			<form class="serve-signin__form" method="post" novalidate>
				<?php wp_nonce_field( 'serve_request_reset' ); ?>

				<h1 class="serve-signin__title"><?php esc_html_e( 'Set a new password' ); ?></h1>
				<p class="serve-signin__lede">
					<?php esc_html_e( 'Enter the address your account uses and we will email a link.' ); ?>
				</p>

				<?php if ( '' !== $serve_error ) : ?>
					<p class="serve-note serve-note--warn" role="alert" tabindex="-1" autofocus>
						<?php echo esc_html( $serve_error ); ?>
					</p>
				<?php endif; ?>

				<div class="serve-field">
					<label class="serve-field__label" for="serve-reset-email"><?php esc_html_e( 'Email address' ); ?></label>
					<input class="serve-field__input"
						id="serve-reset-email"
						name="email"
						type="email"
						autocomplete="username"
						inputmode="email"
						spellcheck="false"
						<?php echo '' === $serve_error ? 'autofocus' : ''; ?>>
				</div>

				<button type="submit" class="serve-btn serve-btn--primary" data-serve-submit>
					<span data-serve-submit-label><?php esc_html_e( 'Email me a link' ); ?></span>
				</button>

				<p class="serve-signin__foot">
					<a href="<?php echo esc_url( App::url( 'login' ) ); ?>"><?php esc_html_e( 'Back to sign in' ); ?></a>
				</p>
			</form>

		<?php endif; ?>
	</main>
</div>

<script type="module" src="<?php echo esc_url( App::asset( 'admin/js/signin.js' ) ); ?>"></script>

</body>
</html>
