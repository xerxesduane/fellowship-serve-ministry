<?php
/**
 * Page template for the share-your-profile step.
 *
 * The journey runs for nineteen full-bleed branded steps and then, on the
 * default theme template, handed people to Twenty Twenty-Five's stock header
 * and footer: a nav of six dead `href="#"` links including Blog, Events and
 * Shop, and a footer offering Themes, Patterns and "Designed with WordPress".
 *
 * On the page where somebody is asked to hand over their spiritual gifts and
 * their pastoral history, "Shop" is not a neutral piece of furniture. It says
 * this is a website rather than a church, at the exact moment that matters.
 *
 * So this page gets its own document too. Unlike the assessment's canvas it
 * keeps a real header and footer — the shortcode renders them — because a form
 * asking for personal data should say who is asking and what happens next, and
 * there is nowhere else on this subdomain to go.
 *
 * wp_head() and wp_footer() still run, so enqueued assets, the admin bar and
 * other plugins behave normally.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#12263f">
	<link rel="icon" href="<?php echo esc_url( SERVE_DASHBOARD_URL . 'public/assessment/fellowship-logo.jpeg' ); ?>">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'serve-canvas serve-canvas--consent' ); ?>>
<a class="skip-to-content" href="#serve-consent-form"><?php esc_html_e( 'Skip to the form', 'serve-dashboard' ); ?></a>
<?php
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;

wp_footer();
?>
</body>
</html>
