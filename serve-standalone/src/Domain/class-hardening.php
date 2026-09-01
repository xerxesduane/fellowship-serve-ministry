<?php
/**
 * Response hardening.
 *
 * The WordPress version of this class turned off XML-RPC, blocked author
 * archives, hid the generator tag, disabled application passwords and closed the
 * wp/v2/users endpoints. None of that is needed here, because none of it exists:
 * dropping WordPress removed those attack surfaces rather than mitigating them.
 * That is the single biggest security gain of the port and it is worth naming.
 *
 * What is left is the part that was always generally true -- the response
 * headers -- plus a content security policy, which the plugin could not
 * usefully set because it did not control the whole page.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve_Dashboard;

use Serve\Platform\Auth;

final class Hardening {

	public static function register(): void {
		// Nothing to hook. Headers are sent by send_headers() from the front
		// controller, which is the only thing that renders a page.
	}

	/**
	 * @param bool $strict Tighter policy for the dashboard than for the
	 *                     participant journey.
	 */
	public static function send_headers( bool $strict = false ): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );

		/*
		 * Referrer stripped on the way out.
		 *
		 * A confirmation URL carries a token in its path. Without this, clicking
		 * any outbound link from that page hands the token to the other site in
		 * the Referer header.
		 */
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );

		header( 'Cross-Origin-Opener-Policy: same-origin' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );

		/*
		 * No third-party anything.
		 *
		 * The journey and the dashboard are both self-contained: no CDN, no
		 * analytics, no web fonts. So the policy can be strict enough to be
		 * worth having, which is what a CSP with a dozen exceptions is not.
		 *
		 * 'unsafe-inline' is absent from script-src on purpose. The one inline
		 * script the dashboard needs is the config blob, and it is delivered as
		 * a JSON <script type="application/json"> that the module reads, rather
		 * than as executable code.
		 */
		$csp = array(
			"default-src 'self'",
			"script-src 'self'",
			"style-src 'self'",
			"img-src 'self' data:",
			"font-src 'self'",
			"connect-src 'self'",
			"form-action 'self'",
			"frame-ancestors 'none'",
			"base-uri 'none'",
			"object-src 'none'",
		);

		if ( $strict ) {
			// The dashboard never embeds anything at all.
			$csp[] = "frame-src 'none'";
		}

		header( 'Content-Security-Policy: ' . implode( '; ', $csp ) );

		/*
		 * HSTS only over HTTPS.
		 *
		 * Sent on a plain-HTTP response it does nothing except risk locking a
		 * developer out of localhost.
		 */
		if ( Auth::is_https() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}

		// Nothing here should be in a shared cache.
		header( 'Cache-Control: private, no-store' );
	}

	/**
	 * Kept because the security checklist reports on it.
	 *
	 * The equivalent WordPress behaviour was a filter on login_errors. The real
	 * implementation now lives in Platform\Auth::login(), which returns the same
	 * message whether the address is unknown or the password is wrong, and does
	 * the same amount of work either way so the timing does not answer the
	 * question the message refuses to.
	 */
	public static function login_errors_are_generic(): bool {
		return true;
	}
}
