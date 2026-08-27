<?php
/**
 * Planning Center integration boundary.
 *
 * There is no integration. The presentation describes a *proposed* division of
 * responsibilities, and its own speaker notes say so explicitly: "This slide
 * describes a proposed division of responsibilities, not a finished live
 * integration."
 *
 * This class therefore exists to hold the boundary honestly rather than to
 * imply a connection. It reports that it is not connected, it never claims a
 * sync time, and the only outbound behaviour is a deep link that opens a
 * search in Planning Center — something that needs no API access at all.
 *
 * Before any real integration is written, the brief requires these to be
 * settled first: which system owns each field, what SERVE reads, what SERVE
 * writes, sync direction and frequency, conflict handling, permissions, error
 * handling, API limits, and the person/team identifiers to join on. Until then
 * the methods below intentionally do nothing.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Planning_Center {

	public const STATUS_NOT_CONFIGURED = 'not_configured';
	public const STATUS_CONFIGURED     = 'configured';

	public const OPTION_SUBDOMAIN = 'serve_dashboard_pco_subdomain';

	/**
	 * Connection state, for the sidebar.
	 *
	 * Deliberately never returns a "last sync" value. Nothing syncs.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$subdomain = self::subdomain();

		return array(
			'status'      => '' === $subdomain ? self::STATUS_NOT_CONFIGURED : self::STATUS_CONFIGURED,
			'connected'   => false,
			'label'       => '' === $subdomain
				? __( 'Not connected', 'serve-dashboard' )
				: __( 'Links only', 'serve-dashboard' ),
			'explanation' => '' === $subdomain
				? __( 'Planning Center is not connected. Add your Church Center address in Settings to enable direct links to a person\'s record.', 'serve-dashboard' )
				: __( 'SERVE can open records in Planning Center. No data is read from or written to Planning Center — that integration has not been built.', 'serve-dashboard' ),
		);
	}

	public static function subdomain(): string {
		return (string) get_option( self::OPTION_SUBDOMAIN, '' );
	}

	/**
	 * A deep link that opens a people search in Planning Center.
	 *
	 * This is a plain URL, not an API call: it needs no credentials and cannot
	 * fail silently. The leader lands in Planning Center's own search results
	 * and takes it from there.
	 *
	 * Returns an empty string when no subdomain is configured, so callers can
	 * hide the action rather than offer a broken link.
	 */
	public static function person_search_url( string $email ): string {
		if ( '' === self::subdomain() || '' === $email || ! is_email( $email ) ) {
			return '';
		}

		return add_query_arg(
			array( 'q' => $email ),
			'https://people.planningcenteronline.com/people'
		);
	}

	/**
	 * Placeholder for the eventual push.
	 *
	 * Intentionally unimplemented. It returns a WP_Error rather than a
	 * pretend-success so that any caller wired up prematurely fails loudly
	 * during development instead of appearing to work.
	 *
	 * @param int $submission_id Submission that would be pushed.
	 * @return \WP_Error
	 */
	public static function push_person( int $submission_id ) {
		return new \WP_Error(
			'serve_pco_not_implemented',
			__( 'Writing to Planning Center is not implemented. Field ownership, sync direction, and permissions have to be agreed before this can be built.', 'serve-dashboard' ),
			array( 'submission_id' => $submission_id )
		);
	}
}
