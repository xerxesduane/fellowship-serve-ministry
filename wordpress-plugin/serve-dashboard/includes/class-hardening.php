<?php
/**
 * Leader-side hardening.
 *
 * The dashboard holds religious belief, contact details and pastoral history
 * for named people, behind WordPress accounts. WordPress ships with a few
 * defaults that are fine for a blog and wrong for this: it will happily tell an
 * anonymous caller the usernames of every leader, which is the first half of a
 * brute-force attempt.
 *
 * Only measures that genuinely belong in plugin code live here. Things that
 * must be set on the server or in wp-config — HTTPS, a least-privilege database
 * user, two-factor authentication — cannot be fixed from here and are reported
 * by Security_Status instead of being silently ignored.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Hardening {

	public static function register(): void {
		// Close anonymous user enumeration through the REST API.
		add_filter( 'rest_endpoints', array( __CLASS__, 'protect_user_endpoints' ) );

		// ...and through author archives, which leak the same names.
		add_action( 'template_redirect', array( __CLASS__, 'block_author_archives' ) );

		// XML-RPC is a password-guessing amplifier and nothing here needs it.
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );

		// Application passwords bypass any two-factor plugin you add later.
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		// Do not advertise the exact WordPress version.
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );

		// Identical message whether or not the username exists.
		add_filter( 'login_errors', array( __CLASS__, 'generic_login_error' ) );

		// Never send the referrer to another origin from an admin screen.
		add_action( 'admin_head', array( __CLASS__, 'referrer_policy' ) );
	}

	/**
	 * Require authentication for the users collection and single-user routes.
	 *
	 * The routes stay registered for logged-in callers, because the block
	 * editor and the Teams screen both need them; they simply stop answering
	 * anonymous requests.
	 *
	 * @param array<string,mixed> $endpoints
	 * @return array<string,mixed>
	 */
	public static function protect_user_endpoints( array $endpoints ): array {
		$guard = static function () {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'Authentication required.', 'serve-dashboard' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			return true;
		};

		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( ! isset( $endpoints[ $route ] ) ) {
				continue;
			}

			foreach ( $endpoints[ $route ] as $index => $handler ) {
				// Only the readable handlers are the enumeration problem; the
				// write handlers already require capabilities.
				if ( isset( $handler['methods'] ) && str_contains( (string) $handler['methods'], 'GET' ) ) {
					$endpoints[ $route ][ $index ]['permission_callback'] = $guard;
				}
			}
		}

		return $endpoints;
	}

	/**
	 * ?author=1 and /author/name/ both confirm a username exists.
	 */
	public static function block_author_archives(): void {
		if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only guard.
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * @param string $error
	 */
	public static function generic_login_error( $error ): string {
		unset( $error );

		return __( 'That username and password combination is not correct.', 'serve-dashboard' );
	}

	public static function referrer_policy(): void {
		echo '<meta name="referrer" content="strict-origin-when-cross-origin">' . "\n";
	}
}
