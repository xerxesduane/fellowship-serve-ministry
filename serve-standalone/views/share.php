<?php
/**
 * Share your profile with the SERVE team.
 *
 * The last screen of the journey and the only one that sends anything anywhere.
 * Nineteen steps run entirely on the participant's own device; this is where
 * they are asked to hand the answers over, so the page says what is being
 * shared, who will see it, how long it is kept, and that nothing leaves the
 * device until they press the button.
 *
 * Its own document rather than the signed-in layout, for the reason the
 * plugin's page template gives at length: on the default WordPress theme this
 * step inherited a stock nav of dead links including Blog, Events and Shop. On
 * the page where somebody is asked for their spiritual gifts and their pastoral
 * history, "Shop" is not neutral furniture -- it says this is a website rather
 * than a church, at the exact moment that matters. There is no theme here to
 * inherit from, and the same reasoning says not to introduce one.
 *
 * The header and footer belong to this page rather than to the form, so the
 * privacy notice it links to can wear the same ones.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
use Serve\Platform\Assets;
use Serve_Dashboard\Invitation;
use Serve_Dashboard\Shortcode;

nocache_headers();

/*
 * Rendered before the document, because render() calls enqueue() and the
 * stylesheets have to be collected before <head> is written.
 */
$serve_form = Shortcode::render();

/*
 * The eyebrow, which is not always "Final step".
 *
 * Somebody arriving from an invitation finished the journey weeks ago and is
 * being asked something different, so telling them they are on the final step
 * of a form they have already completed would be simply untrue.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, picks a heading.
$serve_eyebrow = isset( $_GET[ Invitation::QUERY_VAR ] )
	? __( 'An invitation' )
	: __( 'Final step' );
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php esc_html_e( 'Share your profile with the SERVE team' ); ?></title>
	<link rel="icon" href="<?php echo esc_url( App::asset( 'assessment/fellowship-logo.jpeg' ) ); ?>">

	<?php
	// The stylesheets Shortcode::enqueue() asked for.
	echo Assets::render_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</head>
<body class="serve-canvas serve-canvas--consent">

<a class="skip-to-content" href="#serve-main"><?php esc_html_e( 'Skip to the content' ); ?></a>

<?php
/*
 * The form's config, as data rather than as inline script.
 *
 * wp_localize_script() would emit `window.serveShapeConfig = {...}` inline,
 * which this build's content security policy forbids -- and rightly: allowing
 * inline script to pass one config object means allowing it for everything.
 * serve-form.js reads this block by id, exactly as admin/js/app.js reads its
 * own.
 */
?>
<script type="application/json" id="serve-shape-config"><?php
	echo wp_json_encode( Assets::data_for( 'serve-shape-form' ) );
?></script>

<?php Shortcode::brand_header( $serve_eyebrow ); ?>

<main id="serve-main">
	<?php
	/*
	 * Echoed unescaped: markup this application generated, with escaping
	 * applied inside render() at each interpolation point.
	 */
	echo $serve_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</main>

<?php
Shortcode::page_footer();

// And the module tags, at the end of the body.
echo Assets::render_scripts(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

</body>
</html>
