<?php
/**
 * The page frame.
 *
 * One layout for every signed-in screen. $serve_body names a file in views/,
 * $serve_title the browser title.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
use Serve_Dashboard\Roles;

$serve_user = wp_get_current_user();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $serve_title ?? 'SERVE' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/app.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( App::asset( 'admin/css/admin.css' ) ); ?>">
</head>
<body class="serve-standalone">

<?php if ( (int) ( $serve_user->ID ?? 0 ) > 0 ) : ?>
	<header class="serve-topbar">
		<a class="serve-topbar__brand" href="<?php echo esc_url( App::url( 'dashboard' ) ); ?>">SERVE</a>

		<nav class="serve-topbar__nav">
			<a href="<?php echo esc_url( App::url( 'dashboard' ) ); ?>"><?php esc_html_e( 'Dashboard' ); ?></a>
			<?php if ( current_user_can( Roles::CAP_MANAGE_TEAMS ) ) : ?>
				<a href="<?php echo esc_url( App::url( 'teams' ) ); ?>"><?php esc_html_e( 'Teams' ); ?></a>
			<?php endif; ?>
		</nav>

		<form class="serve-topbar__out" method="post" action="<?php echo esc_url( App::url( 'logout' ) ); ?>">
			<?php wp_nonce_field( 'serve_logout' ); ?>
			<span class="serve-topbar__who"><?php echo esc_html( (string) ( $serve_user->display_name ?? '' ) ); ?></span>
			<button type="submit" class="serve-btn serve-btn--secondary serve-btn--sm"><?php esc_html_e( 'Sign out' ); ?></button>
		</form>
	</header>
<?php endif; ?>

<main class="serve-main">
	<?php require SERVE_ROOT . '/views/' . basename( (string) $serve_body ) . '.php'; ?>
</main>

</body>
</html>
