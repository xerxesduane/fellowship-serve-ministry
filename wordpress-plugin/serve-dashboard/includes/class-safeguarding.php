<?php
/**
 * Safeguarding gate.
 *
 * The ministry-gift table happily suggests Fellowship Kids and Youth Ministry
 * to whoever matches on gifts, including someone the church has never met.
 * Those two teams must not be reachable without a cleared background check,
 * so the gate lives in the transition path rather than in the UI, where it
 * cannot be skipped by posting the form directly.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Safeguarding {

	public const STATUS_NOT_REQUIRED = 'not_required';
	public const STATUS_REQUIRED     = 'required';
	public const STATUS_PENDING      = 'pending';
	public const STATUS_CLEARED      = 'cleared';
	public const STATUS_REJECTED     = 'rejected';

	/**
	 * The first pipeline stage that puts a person in contact with the people a
	 * team serves. Everything at or beyond this point needs clearance.
	 */
	private const GATED_FROM = Schema::STATUS_TRIAL_SERVE;

	/**
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			self::STATUS_NOT_REQUIRED => __( 'Not required', 'serve-dashboard' ),
			self::STATUS_REQUIRED     => __( 'Required, not started', 'serve-dashboard' ),
			self::STATUS_PENDING      => __( 'In progress', 'serve-dashboard' ),
			self::STATUS_CLEARED      => __( 'Cleared', 'serve-dashboard' ),
			self::STATUS_REJECTED     => __( 'Not cleared', 'serve-dashboard' ),
		);
	}

	public static function statuses(): array {
		return array_keys( self::status_labels() );
	}

	/** Whether a given pipeline status sits at or beyond the gate. */
	public static function is_gated_status( string $status ): bool {
		$pipeline = Schema::pipeline();
		$gate     = array_search( self::GATED_FROM, $pipeline, true );
		$position = array_search( $status, $pipeline, true );

		if ( false === $gate || false === $position ) {
			return false;
		}

		return $position >= $gate;
	}

	/**
	 * Decide whether a submission may move to a status on a given team.
	 *
	 * Returns an empty string when the move is allowed, or a human-readable
	 * reason when it is blocked.
	 */
	public static function block_reason( int $submission_id, int $team_id, string $new_status ): string {
		if ( ! self::is_gated_status( $new_status ) ) {
			return '';
		}

		$team = Teams::get( $team_id );
		if ( ! $team || ! (int) $team->requires_safeguarding ) {
			return '';
		}

		$submission = Submissions::get( $submission_id );
		if ( ! $submission ) {
			return __( 'Submission not found.', 'serve-dashboard' );
		}

		if ( self::STATUS_CLEARED === $submission->safeguarding_status ) {
			return '';
		}

		Audit::log(
			Audit::ACTION_SAFEGUARD_BLOCK,
			'submission',
			$submission_id,
			array(
				'team_id'    => $team_id,
				'new_status' => $new_status,
				'had_status' => $submission->safeguarding_status,
			)
		);

		return sprintf(
			/* translators: 1: team name, 2: pipeline status label. */
			__( '%1$s works with children or youth, so a cleared background check is required before moving anyone to "%2$s". Current check status: %3$s.', 'serve-dashboard' ),
			$team->name,
			Schema::status_labels()[ $new_status ] ?? $new_status,
			self::status_labels()[ $submission->safeguarding_status ] ?? $submission->safeguarding_status
		);
	}

	/**
	 * Record a background-check outcome. Only a pastor may do this.
	 */
	public static function set_status( int $submission_id, string $status ): bool {
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return false;
		}

		if ( ! current_user_can( Roles::CAP_VERIFY_SAFEGUARD ) ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'safeguarding_status'      => $status,
				'safeguarding_verified_at' => self::STATUS_CLEARED === $status ? current_time( 'mysql', true ) : null,
				'updated_at'               => current_time( 'mysql', true ),
			),
			array( 'id' => $submission_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			Audit::log( Audit::ACTION_SAFEGUARD_SET, 'submission', $submission_id, array( 'status' => $status ) );
		}

		return false !== $updated;
	}

	/**
	 * Mark a submission as needing a check, because one of its suggested teams
	 * is a safeguarded one. Called at submission time.
	 */
	public static function initial_status( array $suggested_team_slugs ): string {
		foreach ( $suggested_team_slugs as $slug ) {
			$team = Teams::get_by_slug( (string) $slug );
			if ( $team && (int) $team->requires_safeguarding ) {
				return self::STATUS_REQUIRED;
			}
		}

		return self::STATUS_NOT_REQUIRED;
	}
}
