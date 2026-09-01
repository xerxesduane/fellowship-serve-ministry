<?php
/**
 * What leaders say is not working.
 *
 * The deck asks the pilot to "capture questions and friction rather than
 * hiding them; the purpose of the pilot is to learn". The measurement panel
 * cannot do that. It counts what happened — how long until somebody acted, how
 * far people got — and none of those numbers can say that a suggested team made
 * no sense, or that a leader could not find the person they were looking for.
 *
 * Four of the five questions the deck wants answered are of that kind. This is
 * where the answers go.
 *
 * Deliberately not a notes field:
 *
 * - **It is about the tool, not the person.** Notes are pastoral, belong to a
 *   profile, and are deleted with it. Friction has to outlive the profile that
 *   provoked it, or the pilot loses its findings every time somebody is erased.
 * - **No submission is attached.** A leader wanting to record something about a
 *   person has notes for that. Keeping the two apart stops this becoming a
 *   second, quieter place where pastoral detail accumulates without the
 *   redaction and deletion rules that protect the first.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Friction {

	/** Long enough to explain something, short enough to stay a note about the tool. */
	private const MAX_LENGTH = 1000;

	/**
	 * The parts of the job somebody might be stuck on.
	 *
	 * Taken from the deck's own success questions rather than invented, so what
	 * comes back can be read against what it asked.
	 *
	 * @return array<string,string>
	 */
	public static function areas(): array {
		return array(
			'suggestion' => __( 'A suggested team did not make sense', 'serve-dashboard' ),
			'profile'    => __( 'The profile did not match the person', 'serve-dashboard' ),
			'finding'    => __( 'I could not find someone or something', 'serve-dashboard' ),
			'planning'   => __( 'Moving into Planning Center was unclear', 'serve-dashboard' ),
			'general'    => __( 'Something else', 'serve-dashboard' ),
		);
	}

	/**
	 * Record one observation.
	 *
	 * @return true|\WP_Error
	 */
	public static function record( string $area, string $body ) {
		if ( ! current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) {
			return new \WP_Error( 'serve_forbidden', __( 'You cannot leave feedback here.', 'serve-dashboard' ), array( 'status' => 403 ) );
		}

		$body = trim( $body );

		if ( '' === $body ) {
			return new \WP_Error( 'serve_friction_empty', __( 'Tell us what happened first.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		if ( ! array_key_exists( $area, self::areas() ) ) {
			$area = 'general';
		}

		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'feedback' ),
			array(
				'user_id'    => get_current_user_id(),
				'area'       => $area,
				'body'       => mb_substr( $body, 0, self::MAX_LENGTH ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'serve_friction_failed', __( 'That did not save. Please try again.', 'serve-dashboard' ), array( 'status' => 500 ) );
		}

		/*
		 * The observation itself is not copied into the audit trail, only that
		 * one was left — same rule as conversation notes. An audit trail is for
		 * accounting for access, not a second copy of everything written.
		 */
		Audit::log( 'friction.recorded', 'feedback', null, array( 'area' => $area ) );

		return true;
	}

	/**
	 * Everything recorded, newest first. Read on the settings screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'feedback' );
		$limit = max( 1, min( 200, $limit ) );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d",
				$limit
			)
		);

		$areas = self::areas();
		$out   = array();

		foreach ( $rows as $row ) {
			$user = get_userdata( (int) $row->user_id );

			$out[] = array(
				'id'      => (int) $row->id,
				'area'    => (string) $row->area,
				'label'   => $areas[ $row->area ] ?? $areas['general'],
				'body'    => (string) $row->body,
				'who'     => $user ? $user->display_name : __( 'a leader who no longer has an account', 'serve-dashboard' ),
				'when'    => mysql2date( get_option( 'date_format' ), $row->created_at ),
			);
		}

		return $out;
	}

	/** How many observations, by area. Reported beside the pilot figures. */
	public static function tally(): array {
		global $wpdb;
		$table = Schema::table( 'feedback' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		$rows = (array) $wpdb->get_results( "SELECT area, COUNT(*) AS total FROM {$table} GROUP BY area" );

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row->area ] = (int) $row->total;
		}

		return $out;
	}

	public static function total(): int {
		global $wpdb;
		$table = Schema::table( 'feedback' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
