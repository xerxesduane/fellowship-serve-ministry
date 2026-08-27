<?php
/**
 * CSV export.
 *
 * The honest pilot version of the Planning Center handoff: a leader exports
 * what they can see, imports it into Planning Center, and carries on there. No
 * API, no sync, no field-ownership questions to settle first.
 *
 * Deliberately narrow. The export carries contact details and suggested teams —
 * enough to create a person record — and stops there. Painful experiences and
 * the conversation history are not exported at any capability level, because a
 * spreadsheet is the easiest thing in the world to forward to the wrong person.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Export {

	public static function register(): void {
		add_action( 'admin_post_serve_export_csv', array( __CLASS__, 'download' ) );
	}

	/**
	 * The download URL.
	 *
	 * Built with add_query_arg rather than wp_nonce_url: the latter escapes the
	 * ampersand for use in an HTML attribute, which is right for markup but
	 * wrong here. This URL travels through JSON and is assigned to href from
	 * JavaScript, neither of which decodes entities — so the nonce would arrive
	 * as a parameter called "amp;_wpnonce" and the request would 403.
	 */
	public static function url(): string {
		return add_query_arg(
			array(
				'action'   => 'serve_export_csv',
				'_wpnonce' => wp_create_nonce( 'serve_export_csv' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The exported columns, in order.
	 *
	 * Split out from `download()` so the exclusions can be asserted in a test
	 * rather than only promised in a README. Experiences and conversation notes
	 * are absent at every capability level, deliberately: a spreadsheet is the
	 * easiest thing in the world to forward to the wrong person.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array(
			__( 'Name', 'serve-dashboard' ),
			__( 'Email', 'serve-dashboard' ),
			__( 'Phone', 'serve-dashboard' ),
			__( 'Status', 'serve-dashboard' ),
			__( 'Likely gifts', 'serve-dashboard' ),
			__( 'Languages', 'serve-dashboard' ),
			__( 'Suggested teams', 'serve-dashboard' ),
			__( 'Months in UAE', 'serve-dashboard' ),
			__( 'Background check', 'serve-dashboard' ),
			__( 'Next action', 'serve-dashboard' ),
			__( 'Submitted', 'serve-dashboard' ),
		);
	}

	/**
	 * One submission as a row of cells, matching `columns()`.
	 *
	 * @return array<int,string|int>
	 */
	public static function row( object $submission ): array {
		$labels    = Schema::status_labels();
		$safeguard = Safeguarding::status_labels();

		return array(
			$submission->display_name,
			$submission->email,
			$submission->phone,
			$labels[ $submission->status ] ?? $submission->status,
			implode( '; ', Submissions::decode_list( $submission->gifts_likely ) ),
			implode( '; ', Submissions::decode_list( $submission->languages ) ),
			implode( '; ', Submissions::decode_list( $submission->suggested_teams ) ),
			null === $submission->tenure_months ? '' : (int) $submission->tenure_months,
			$safeguard[ $submission->safeguarding_status ] ?? $submission->safeguarding_status,
			(string) $submission->next_action_at,
			(string) $submission->submitted_at,
		);
	}

	/**
	 * Stream the export.
	 *
	 * Scoped by the same query the dashboard uses, so a ministry leader
	 * exports their own teams and nothing else.
	 */
	public static function download(): void {
		check_admin_referer( 'serve_export_csv' );

		if ( ! current_user_can( Roles::CAP_EXPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'serve-dashboard' ) );
		}

		$rows = Submissions::query(
			array(
				'include_snoozed' => true,
				'limit'           => 200,
			)
		);

		Audit::log( Audit::ACTION_EXPORTED, 'submission', null, array( 'rows' => count( $rows ) ) );

		$filename = 'serve-profiles-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// Excel opens UTF-8 CSV as the local codepage without this, which
		// mangles every non-Latin name in the congregation.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, self::columns() );

		foreach ( $rows as $row ) {
			fputcsv( $out, self::row( $row ) );
		}

		fclose( $out );
		exit;
	}
}
