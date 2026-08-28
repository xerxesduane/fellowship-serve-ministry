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
<a class="skip-to-content" href="#serve-main"><?php esc_html_e( 'Skip to the content', 'serve-dashboard' ); ?></a>
<?php
/*
 * The chrome belongs to the template rather than to a shortcode, so every page
 * in this journey gets the same header and footer — the consent step and the
 * privacy notice it links to. A person who follows that link should not land
 * back in the theme's navigation, which is the thing this template exists to
 * avoid in the first place.
 */
$serve_is_privacy = get_queried_object_id() === (int) get_option( 'wp_page_for_privacy_policy' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, decides a heading.
$serve_is_invite = isset( $_GET[ Invitation::QUERY_VAR ] );

if ( $serve_is_privacy ) {
	$serve_eyebrow = __( 'Privacy', 'serve-dashboard' );
} elseif ( $serve_is_invite ) {
	// Not "Final step" — they finished the journey some time ago.
	$serve_eyebrow = __( 'An invitation', 'serve-dashboard' );
} else {
	$serve_eyebrow = __( 'Final step', 'serve-dashboard' );
}

Shortcode::brand_header( $serve_eyebrow );
?>
<main id="serve-main">
<?php
while ( have_posts() ) :
	the_post();

	/*
	 * The consent page's form supplies its own h1. A content page does not, and
	 * with the theme stripped there is nothing else to provide one — so the
	 * privacy notice was rendering as a stack of h2s under no heading at all.
	 */
	if ( $serve_is_privacy ) {
		echo '<h1 class="serve-prose__title">' . esc_html( get_the_title() ) . '</h1>';
	}

	the_content();
endwhile;
?>
</main>
<?php
// No self-link in the footer of the page it points at.
Shortcode::page_footer( ! $serve_is_privacy );

wp_footer();
?>
</body>
</html>
