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

	/** Rows one export may carry, and the number the trail calls truncation. */
	private const MAX_ROWS = 200;

	/**
	 * The rows an export will carry, recorded on the way past.
	 *
	 * Separate from send() so a test can reach it. send() writes headers and
	 * streams to php://output, so it cannot be called from a test at all — and
	 * with the logging inside it, a mutation that stopped logging entirely
	 * passed a suite that only ever tested audit_meta() in isolation. The seam
	 * was tested and the wiring was not.
	 *
	 * "Who left the building, not just how many." This recorded a row count. A
	 * CSV of contact details and gift profiles is the easiest way for this data
	 * to end up somewhere nobody intended, and "23 rows were exported on the
	 * 4th" cannot answer the only question that matters afterwards: was this
	 * person's information in it.
	 *
	 * @return array<int,object>
	 */
	public static function gather(): array {
		$rows = Submissions::query(
			array(
				'include_snoozed' => true,
				'limit'           => self::MAX_ROWS,
			)
		);

		Audit::log( Audit::ACTION_EXPORTED, 'submission', null, self::audit_meta( $rows ) );

		return $rows;
	}

	/**
	 * What the audit trail records about one export.
	 *
	 * Its own method so a test can assert it without send() writing headers and
	 * streaming a file to a browser that is not there. A test that rebuilds this
	 * array itself proves only that the test can build an array -- which is
	 * exactly what the first version of that test did, and a mutation removing
	 * the ids from the real log passed it.
	 *
	 * @param array<int,object> $rows
	 * @return array<string,mixed>
	 */
	public static function audit_meta( array $rows ): array {
		return array(
			'rows'      => count( $rows ),
			'truncated' => count( $rows ) >= self::MAX_ROWS,
			/*
			 * Ids rather than names or addresses. The audit table is readable by
			 * anyone who can see the audit screen, and a log reproducing the
			 * contents of an export is a second copy of the thing being logged.
			 * Ids resolve to people for as long as those people exist, and stop
			 * resolving once they are erased -- the right behaviour for a record
			 * of a disclosure.
			 */
			'ids'       => array_values( array_map( static fn( $row ) => (int) $row->id, $rows ) ),
		);
	}

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
			/*
			 * 403, not the 500 wp_die() defaults to.
			 *
			 * "You may not do this" is an answer, not a server fault. Left at
			 * 500 it is indistinguishable in a log from the application breaking,
			 * so a refused export looks like an outage and a real outage looks
			 * routine.
			 */
			wp_die(
				esc_html__( 'You do not have permission to export.', 'serve-dashboard' ),
				esc_html__( 'Not allowed', 'serve-dashboard' ),
				array( 'response' => 403 )
			);
		}

		/*
		 * The one line here no test can reach.
		 *
		 * gather() is covered, including that it writes exactly one audit row.
		 * This call to it is not: send() sets headers and streams to
		 * php://output, so invoking it from a test is not possible, and a
		 * mutation replacing this line with a bare query passes the suite. The
		 * remaining risk is one line of glue, recorded here rather than papered
		 * over with a test that greps this file for its own source.
		 *
		 * If this ever moves, the audit row moves with it.
		 */
		$rows = self::gather();

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
