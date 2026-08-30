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
	/**
	 * Raise the requirement when somebody is routed to a safeguarded team.
	 *
	 * safeguarding_status was set once, at intake, from the teams the ranking
	 * had suggested — and never revisited. So a person whose suggestions
	 * contained no safeguarded team carried `not_required` permanently, and a
	 * leader manually routing them to Fellowship Kids months later hit a record
	 * that said no check was needed. block_reason() reads exactly that column,
	 * so the gate meant to stop the move consulted a value describing a
	 * different question, and the dashboard hid the clearance controls because
	 * the requirement was not "relevant".
	 *
	 * Only ever raises. A person already cleared stays cleared; one already
	 * pending, rejected or required keeps that state. Downgrading somebody to
	 * `not_required` because today's target team happens not to need a check
	 * would silently discard a real safeguarding fact, and no automatic path
	 * should be able to do that.
	 *
	 * @return true|\WP_Error
	 */
	public static function raise_for_team( int $submission_id, int $team_id ) {
		$team = Teams::get( $team_id );

		if ( ! $team || ! (int) $team->requires_safeguarding ) {
			return true;
		}

		$submission = Submissions::get( $submission_id );
		if ( ! $submission ) {
			return new \WP_Error( 'serve_not_found', __( 'Submission not found.', 'serve-dashboard' ) );
		}

		// Anything other than "no check needed here" is already a real answer
		// about this person and is left exactly as it stands.
		if ( self::STATUS_NOT_REQUIRED !== $submission->safeguarding_status ) {
			return true;
		}

		global $wpdb;

		$updated = $wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'safeguarding_status' => self::STATUS_REQUIRED,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $submission_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'serve_safeguarding_write_failed',
				__( 'The background-check requirement for that team could not be recorded, so nothing was changed.', 'serve-dashboard' ),
				array( 'status' => 500 )
			);
		}

		Audit::log(
			Audit::ACTION_SAFEGUARD_BLOCK,
			'submission',
			$submission_id,
			array(
				'raised_for_team' => $team_id,
				'from'            => self::STATUS_NOT_REQUIRED,
				'to'              => self::STATUS_REQUIRED,
			)
		);

		return true;
	}

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
