<?php
/**
 * The dashboard, as its own document.
 *
 * This file is only the document: the head, the stylesheets, the module tag.
 * The screen itself comes from App::render(), which requires
 * views/app/shell.php -- a byte-for-byte copy of the plugin's
 * admin/views/app.php.
 *
 * That split is deliberate and it is the whole answer to "make it look exactly
 * the same". Matching a design by eye means two sets of markup that agree today
 * and disagree after the next change to either. Rendering the same markup
 * against the same stylesheet means they cannot diverge unless somebody edits
 * one on purpose.
 *
 * Three things differ from the plugin, none of them a style decision:
 *
 *   1. It is a whole document. The plugin rendered into a WordPress admin page
 *      and used a body class to hide the surrounding chrome -- the admin menu,
 *      the footer, the notice area. There is no chrome here to hide, but the
 *      class is kept because app.css also sets the page background on it.
 *   2. The stylesheets and the module are linked here, because there is no
 *      asset queue to ask.
 *   3. It does not use views/layout.php. That layout has a top bar of its own
 *      and the plugin's design does not; showing both would put two competing
 *      navigations on one screen.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App as Platform;
use Serve_Dashboard\App;

nocache_headers();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php esc_html_e( 'SERVE Dashboard' ); ?></title>

	<link rel="stylesheet" href="<?php echo esc_url( Platform::asset( 'admin/css/app.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( Platform::asset( 'admin/css/admin.css' ) ); ?>">
	<?php
	/*
	 * Last, and only in this build.
	 *
	 * app.css is shared byte-for-byte with the plugin, and it assumes a
	 * WordPress admin bar above it -- an assumption that is wrong here and gave
	 * the sidebar a scrollbar and a sign-out control below the fold. This sheet
	 * corrects what only this build gets wrong; it must load after the sheet it
	 * corrects.
	 */
	?>
	<link rel="stylesheet" href="<?php echo esc_url( Platform::asset( 'admin/css/standalone.css' ) ); ?>">
</head>
<body class="serve-app-fullscreen">

<?php App::render(); ?>

<script type="module" src="<?php echo esc_url( Platform::asset( 'admin/js/app.js' ) ); ?>"></script>

</body>
</html>
