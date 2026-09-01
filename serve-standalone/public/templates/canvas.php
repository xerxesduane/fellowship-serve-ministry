<?php
/**
 * Full-canvas page template.
 *
 * A bare document with no theme markup. The assessment's stylesheet sets `html`
 * and `body` directly and assumes it owns the page, which was true when it was
 * a standalone app; rendering it inside an arbitrary theme's wrappers means
 * fighting whatever that theme does to margins, fonts and backgrounds. So the
 * page gets its own document instead.
 *
 * wp_head() and wp_footer() are still called, so enqueued assets, the admin bar
 * and other plugins all behave normally.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$serve_config = Assessment::config();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<link rel="icon" href="<?php echo esc_url( $serve_config['logoUrl'] ); ?>">
	<?php wp_head(); ?>
	<script type="application/json" id="serve-assessment-config">
		<?php echo wp_json_encode( $serve_config ); ?>
	</script>
</head>
<body <?php body_class( 'serve-canvas' ); ?>>
<a class="skip-to-content" href="#shape-app"><?php esc_html_e( 'Skip to the questions', 'serve-dashboard' ); ?></a>
<?php
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;

wp_footer();
?>
</body>
</html>
