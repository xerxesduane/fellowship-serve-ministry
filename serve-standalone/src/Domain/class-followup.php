<?php
/**
 * Follow-up ownership and conversation history.
 *
 * Two holes this closes. First, nothing recorded who had picked up a
 * follow-up, so on a team with two leaders both could ring the same person and
 * neither would know. Second, there was nowhere to write down what was said —
 * the deck promises to "replace scattered notes with a consistent view of each
 * serving journey", and a status label alone cannot carry "spoke to her
 * Tuesday, travelling until September".
 *
 * Notes are append-only. A later leader adds to the history rather than
 * overwriting what an earlier one recorded.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Followup {

	private const MAX_NOTE_LENGTH = 2000;

	/**
	 * Claim a follow-up.
	 *
	 * Refuses when somebody else already holds it, so two leaders cannot both
	 * believe they own the conversation. Releasing and re-claiming is the way
	 * to hand it over deliberately.
	 *
	 * @return true|\WP_Error
	 */
	public static function claim( int $submission_id ) {
		$guard = self::guard( $submission_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$submission = Submissions::get( $submission_id );
		$me         = get_current_user_id();
		$holder     = (int) ( $submission->assigned_user_id ?? 0 );

		if ( $holder > 0 && $holder !== $me ) {
			$user = get_userdata( $holder );

			return new \WP_Error(
				'serve_already_claimed',
				sprintf(
					/* translators: %s: name of the leader who holds the follow-up. */
					__( '%s is already following this up. Ask them to release it first, so you are not both calling.', 'serve-dashboard' ),
					$user ? $user->display_name : __( 'Another leader', 'serve-dashboard' )
				),
				array( 'status' => 409 )
			);
		}

		return self::set_owner( $submission_id, $me );
	}

	/**
	 * Release a follow-up.
	 *
	 * Only the holder, or a pastor, may release — otherwise ownership means
	 * nothing.
	 *
	 * @return true|\WP_Error
	 */
	public static function release( int $submission_id ) {
		$guard = self::guard( $submission_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$submission = Submissions::get( $submission_id );
		$holder     = (int) ( $submission->assigned_user_id ?? 0 );

		if ( $holder > 0
			&& $holder !== get_current_user_id()
			&& ! current_user_can( Roles::CAP_VIEW_ALL ) ) {
			return new \WP_Error(
				'serve_not_yours',
				__( 'Only the leader following this up, or a pastor, can release it.', 'serve-dashboard' ),
				array( 'status' => 403 )
			);
		}

		return self::set_owner( $submission_id, null );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function set_owner( int $submission_id, ?int $user_id ) {
		global $wpdb;

		$updated = $wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'assigned_user_id' => $user_id,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => $submission_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error( 'serve_claim_failed', __( 'Could not save that.', 'serve-dashboard' ) );
		}

		Audit::log(
			null === $user_id ? 'followup.released' : 'followup.claimed',
			'submission',
			$submission_id,
			array( 'user_id' => $user_id )
		);

		return true;
	}

	/**
	 * Add one note to the history.
	 *
	 * @return int|\WP_Error New note id.
	 */
	public static function add_note( int $submission_id, string $body ) {
		$guard = self::guard( $submission_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$body = trim( wp_strip_all_tags( $body ) );

		if ( '' === $body ) {
			return new \WP_Error( 'serve_note_empty', __( 'A note needs some text.', 'serve-dashboard' ) );
		}

		if ( mb_strlen( $body ) > self::MAX_NOTE_LENGTH ) {
			$body = mb_substr( $body, 0, self::MAX_NOTE_LENGTH );
		}

		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'notes' ),
			array(
				'submission_id' => $submission_id,
				'user_id'       => get_current_user_id() ?: null,
				'body'          => $body,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'serve_note_failed', __( 'Could not save that note.', 'serve-dashboard' ) );
		}

		// The note body itself is not copied into the audit trail: it is
		// pastoral content, and duplicating it would put the same sensitive text
		// in a second place with different retention.
		Audit::log( 'followup.note_added', 'submission', $submission_id, array( 'length' => mb_strlen( $body ) ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * History for one submission, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function notes( int $submission_id, int $limit = 25 ): array {
		if ( ! Roles::can_view_submission( $submission_id ) ) {
			return array();
		}

		global $wpdb;
		$table = Schema::table( 'notes' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE submission_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
				$submission_id,
				max( 1, min( 100, $limit ) )
			)
		);

		return array_map(
			static function ( $row ) {
				$user = $row->user_id ? get_userdata( (int) $row->user_id ) : null;

				return array(
					'id'     => (int) $row->id,
					'body'   => $row->body,
					'author' => $user ? $user->display_name : __( 'Unknown', 'serve-dashboard' ),
					'when'   => mysql2date( 'j M Y, H:i', $row->created_at ),
				);
			},
			$rows
		);
	}

	/**
	 * Who holds this follow-up, for display.
	 *
	 * @return array<string,mixed>
	 */
	public static function owner( object $submission ): array {
		$holder = (int) ( $submission->assigned_user_id ?? 0 );
		$user   = $holder > 0 ? get_userdata( $holder ) : null;

		return array(
			'userId' => $holder ?: null,
			'name'   => $user ? $user->display_name : '',
			'isMine' => $holder > 0 && $holder === get_current_user_id(),
		);
	}

	/**
	 * Shared permission check for every write here.
	 *
	 * @return true|\WP_Error
	 */
	private static function guard( int $submission_id ) {
		if ( ! current_user_can( Roles::CAP_MANAGE_PLACE ) || ! Roles::can_view_submission( $submission_id ) ) {
			return new \WP_Error(
				'serve_forbidden',
				__( 'You cannot change this record.', 'serve-dashboard' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/** Notes go when the profile goes. Called from Privacy::erase_submission(). */
	public static function delete_for_submission( int $submission_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'notes' ), array( 'submission_id' => $submission_id ), array( '%d' ) );
	}
}
