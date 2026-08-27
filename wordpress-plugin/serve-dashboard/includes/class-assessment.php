<?php
/**
 * The public S.H.A.P.E. journey.
 *
 * The assessment is the front door of the site, so it lives here rather than in
 * a separate standalone app. There used to be three implementations of the same
 * questionnaire — a Next.js one, a framework-free PHP one, and whatever Vercel
 * happened to be serving — which is three chances for the workbook content to
 * drift. Now there is one.
 *
 * The assets are the framework-free ES modules from the previous PHP edition,
 * unchanged apart from the share handoff. They style `html` and `body`
 * directly, so the journey renders through a full-canvas page template that
 * bypasses the theme instead of fighting it.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assessment {

	/** Page template name, as stored in _wp_page_template. */
	public const TEMPLATE = 'serve-assessment-canvas';

	/** Where the consent step lives, so the journey can link to it. */
	public const OPTION_CONSENT_PAGE = 'serve_dashboard_consent_page';

	/** The page hosting the journey itself. */
	public const OPTION_ASSESSMENT_PAGE = 'serve_dashboard_assessment_page';

	/**
	 * Create the two public pages if they are missing.
	 *
	 * Run on activation so the plugin works without a manual setup ritual. The
	 * site's front-page setting is deliberately left alone — silently
	 * repointing the home page of an existing site would be rude. Settings
	 * offers that as an explicit choice instead.
	 */
	public static function install_pages(): void {
		$assessment = self::ensure_page(
			self::OPTION_ASSESSMENT_PAGE,
			__( 'Discover your S.H.A.P.E.', 'serve-dashboard' ),
			'shape',
			'[serve_shape_assessment]'
		);

		if ( $assessment ) {
			update_post_meta( $assessment, '_wp_page_template', self::TEMPLATE );
		}

		self::ensure_page(
			self::OPTION_CONSENT_PAGE,
			__( 'Share your profile', 'serve-dashboard' ),
			'share-my-profile',
			'[serve_shape_consent]'
		);
	}

	/**
	 * Find or create one page, remembering its id.
	 *
	 * @return int Page id, or 0 on failure.
	 */
	private static function ensure_page( string $option, string $title, string $slug, string $content ): int {
		$existing = (int) get_option( $option, 0 );

		if ( $existing > 0 && 'page' === get_post_type( $existing ) ) {
			return $existing;
		}

		// A page with the shortcode may already exist from a manual setup.
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 1,
				's'              => trim( $content, '[]' ),
				'fields'         => 'ids',
			)
		);

		$page_id = $found ? (int) $found[0] : (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
			)
		);

		if ( $page_id > 0 ) {
			update_option( $option, $page_id );
		}

		return $page_id;
	}

	/** Point the site's front page at the assessment. */
	public static function set_as_front_page(): bool {
		$page_id = (int) get_option( self::OPTION_ASSESSMENT_PAGE, 0 );

		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return false;
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );

		return true;
	}

	public static function is_front_page(): bool {
		$page_id = (int) get_option( self::OPTION_ASSESSMENT_PAGE, 0 );

		return $page_id > 0
			&& 'page' === get_option( 'show_on_front' )
			&& (int) get_option( 'page_on_front' ) === $page_id;
	}

	public static function assessment_url(): string {
		$page_id = (int) get_option( self::OPTION_ASSESSMENT_PAGE, 0 );

		return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
	}

	public static function register(): void {
		add_shortcode( 'serve_shape_assessment', array( __CLASS__, 'render' ) );

		add_filter( 'theme_page_templates', array( __CLASS__, 'register_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ) );
	}

	/**
	 * Offer the canvas template in the page editor's Template dropdown.
	 *
	 * @param array<string,string> $templates
	 * @return array<string,string>
	 */
	public static function register_template( array $templates ): array {
		$templates[ self::TEMPLATE ] = __( 'SERVE — Full canvas (no theme)', 'serve-dashboard' );

		return $templates;
	}

	/**
	 * Swap in the plugin's bare document when a page selects the canvas
	 * template.
	 */
	public static function use_template( string $template ): string {
		if ( ! is_page() ) {
			return $template;
		}

		$chosen = get_page_template_slug( get_queried_object_id() );

		if ( self::TEMPLATE !== $chosen ) {
			return $template;
		}

		return SERVE_DASHBOARD_DIR . 'public/templates/canvas.php';
	}

	/**
	 * Queue the journey's own assets.
	 *
	 * app.js is an ES module that imports its siblings by relative path, so it
	 * has to be registered as a script module rather than a classic script.
	 */
	public static function enqueue(): void {
		wp_enqueue_style(
			'serve-assessment',
			SERVE_DASHBOARD_URL . 'public/assessment/styles.css',
			array(),
			SERVE_DASHBOARD_VERSION
		);

		wp_enqueue_script_module(
			'serve-assessment',
			SERVE_DASHBOARD_URL . 'public/assessment/app.js',
			array(),
			SERVE_DASHBOARD_VERSION
		);
	}

	/**
	 * Config the journey reads to decide whether to offer the share step.
	 *
	 * Absent a consent page the journey simply does not show the action, which
	 * is how it behaved before this plugin existed.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		return array(
			'logoUrl'      => SERVE_DASHBOARD_URL . 'public/assessment/fellowship-logo.jpeg',
			'shareUrl'     => self::consent_url(),
			'draftUrl'     => esc_url_raw( rest_url( Rest::NAMESPACE . '/draft' ) ),
			'draftConsent' => Draft::purpose_text(),
			// Roughly how long the journey takes. Saying so up front is the
			// cheapest thing that reduces abandonment on a nineteen-step form.
			'estimate'     => __( 'about 20 minutes', 'serve-dashboard' ),
			'stepUrl'      => esc_url_raw( rest_url( Rest::NAMESPACE . '/step' ) ),
		);
	}

	public static function consent_url(): string {
		$page_id = (int) get_option( self::OPTION_CONSENT_PAGE, 0 );

		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		// Fall back to any published page carrying the consent shortcode, so a
		// site that was set up by hand still links correctly.
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => 'serve_shape_consent',
				'fields'         => 'ids',
			)
		);

		return $found ? (string) get_permalink( (int) $found[0] ) : '';
	}

	/**
	 * Render the mount point.
	 *
	 * The journey builds its own DOM inside #shape-app, matching the id the
	 * assets have always used so returning visitors keep their saved progress.
	 */
	public static function render(): string {
		self::enqueue();

		ob_start();
		?>
		<div id="shape-app" aria-live="polite"></div>

		<noscript>
			<div class="noscript-notice">
				<h1><?php esc_html_e( 'This assessment needs JavaScript', 'serve-dashboard' ); ?></h1>
				<p><?php esc_html_e( 'The S.H.A.P.E. journey uses JavaScript to save your answers, work out your profile, and move between steps. Please enable it, or ask the SERVE team for a printed copy.', 'serve-dashboard' ); ?></p>
			</div>
		</noscript>
		<?php

		return (string) ob_get_clean();
	}
}
