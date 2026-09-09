<?php
/**
 * The participant's journey, on a bare page.
 *
 * The plugin needed a WordPress page template to escape the active theme, which
 * would otherwise wrap the assessment in a sidebar and a site header: the
 * assessment's stylesheet sets `html` and `body` directly and assumes it owns
 * the page, which was true when it was a standalone app and is true again.
 *
 * The config block is the contract. The journey reads #serve-assessment-config
 * and, absent it, behaves as it did before this dashboard existed: no share
 * step, no draft saving. An earlier version of this view emitted hardcoded
 * <link> and <script> tags and no config, so the journey rendered and quietly
 * offered neither -- which looks like a working page.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
use Serve\Platform\Assets;
use Serve_Dashboard\Assessment;

nocache_headers();

/*
 * render() calls enqueue(), so the assets have to be collected before the head
 * is written. Rendered into a variable first for that reason, and echoed below.
 */
$serve_body   = Assessment::render();
$serve_config = Assessment::config();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<title><?php esc_html_e( 'Discover how God has shaped you' ); ?></title>
	<link rel="icon" href="<?php echo esc_url( (string) ( $serve_config['logoUrl'] ?? '' ) ); ?>">

	<?php
	// The stylesheets Assessment::enqueue() asked for.
	echo Assets::render_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>

	<script type="application/json" id="serve-assessment-config"><?php echo wp_json_encode( $serve_config ); ?></script>
</head>
<body class="serve-canvas">

<a class="skip-to-content" href="#shape-app"><?php esc_html_e( 'Skip to the questions' ); ?></a>

<?php
/*
 * Echoed unescaped: markup this application generated, with escaping applied
 * inside render() at each interpolation point.
 */
echo $serve_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

// And the module tags, at the end of the body where they belong.
echo Assets::render_scripts(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

</body>
</html>
