<?php
/**
 * Submission storage and the leader-facing queries.
 *
 * This is the piece the assessment never had. Until now a completed SHAPE
 * profile lived only in the visitor's own localStorage, so no leader could
 * see it unless the person copied or emailed it themselves.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Submissions {

	/**
	 * Store one completed profile and its consent record.
	 *
	 * @param array<string,mixed> $payload Already-sanitised payload from Rest.
	 * @return int|\WP_Error Submission id, or an error.
	 */
	public static function create( array $payload ) {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$profile  = $payload['profile'];
		$suggested = $payload['suggested_teams'];

		$inserted = $wpdb->insert(
			Schema::table( 'submissions' ),
			array(
				'uuid'                => wp_generate_uuid4(),
				'display_name'        => $payload['display_name'],
				'email'               => $payload['email'],
				'phone'               => $payload['phone'],
				'status'              => Schema::STATUS_SUBMITTED,
				'tenure_months'       => $payload['tenure_months'],
				'gifts_likely'        => wp_json_encode( $payload['gifts_likely'] ) ?: '[]',
				'languages'           => wp_json_encode( $payload['languages'] ) ?: '[]',
				'suggested_teams'     => wp_json_encode( $suggested ) ?: '[]',
				'profile_json'        => wp_json_encode( $profile ) ?: '{}',
				'safeguarding_status' => Safeguarding::initial_status( $suggested ),
				'next_action_at'      => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
				'submitted_at'        => $now,
				'updated_at'          => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error(
				'serve_insert_failed',
				__( 'We could not save your profile. Please try again.', 'serve-dashboard' ),
				array( 'status' => 500 )
			);
		}

		$submission_id = (int) $wpdb->insert_id;

		/*
		 * Consent is the legal basis for holding any of this, so a submission
		 * that could not record one is not a submission. The insert result was
		 * discarded and the participant was told it worked either way, leaving
		 * a stored profile with nothing recording why the church may keep it.
		 */
		if ( ! Consent::record( $submission_id ) ) {
			$wpdb->delete( Schema::table( 'submissions' ), array( 'id' => $submission_id ), array( '%d' ) );

			return new \WP_Error(
				'serve_consent_failed',
				__( 'We could not record your consent, so nothing has been saved. Please try again.', 'serve-dashboard' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * No placement rows are created from the ranking. This is the change.
		 *
		 * They used to be, one per suggested team, and Roles::can_view_submission()
		 * grants access on the existence of a placement row — so being ranked
		 * against a team was the same event as that team's leader gaining a
		 * pastoral profile to read. A heuristic over eighteen self-assessed
		 * answers is not consent to disclose somebody's painful history to a
		 * particular ministry, and it is not a human deciding anything.
		 *
		 * `suggested_teams` is still recorded. It is the record of what the
		 * person was shown at the end of their journey, which a leader has to
		 * be able to see; it is no longer an authorisation.
		 *
		 * Every new submission goes to central intake instead, and a coordinator
		 * assigns a team after reading it. That assignment is audited, names a
		 * person, and is what may create scoped leader access.
		 */
		$intake = Placements::assign_intake_owner( $submission_id );

		Audit::log(
			Audit::ACTION_SUBMITTED,
			'submission',
			$submission_id,
			array(
				'suggested_teams' => $suggested,
				'intake_team'     => $intake ?: null,
				// Explicitly recorded so the history shows the ranking did not
				// hand anybody access on the day this arrived.
				'match_placements_created' => 0,
			)
		);

		/*
		 * The row exists but stays invisible to leaders until the address is
		 * proven. See Verification for why an anonymous endpoint needs this.
		 */
		$verified_sent = Verification::issue( $submission_id );

		/*
		 * The draft is cleared only once the durable state is actually valid.
		 *
		 * It used to go unconditionally, immediately after writes whose results
		 * nobody read — so a failed verification dispatch destroyed the only
		 * other copy of the journey while the participant was told to check an
		 * email that was never sent, leaving them nothing to return to.
		 */
		Draft::clear_for_email( (string) $payload['email'] );

		/**
		 * Fires after a SHAPE profile has been stored.
		 *
		 * @param int   $submission_id Row id.
		 * @param array $payload       Sanitised payload.
		 */
		do_action( 'serve_dashboard_submission_created', $submission_id, $payload );

		/*
		 * An array rather than the bare id, so the caller can tell the person
		 * the truth about their confirmation email. It used to return the id
		 * and the endpoint said "check your email" regardless of whether the
		 * mailer had answered.
		 */
		return array(
			'submission_id'     => $submission_id,
			'verification_sent' => $verified_sent,
		);
	}

	/**
	 * How long after placement to look in on somebody.
	 *
	 * Six weeks: long enough to have actually served a few times and formed a
	 * view, short enough that "this is not for me" has not already become
	 * quietly not turning up.
	 */
	public static function settling_weeks(): int {
		/**
		 * Filters the settling-in interval, in weeks.
		 *
		 * @param int $weeks Default 6.
		 */
		return max( 1, (int) apply_filters( 'serve_dashboard_settling_weeks', 6 ) );
	}

	/**
	 * People recently placed whose settling-in check has come round.
	 *
	 * Deliberately not folded into the follow-up queue: that queue is people
	 * waiting for a first conversation, and burying "see how Aiza is getting on"
	 * among them makes both harder to work.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function settling_in( int $limit = 10 ): array {
		$rows = self::query(
			array(
				'status'          => Schema::STATUS_PLACED,
				'include_snoozed' => true,
				'orderby'         => 'next_action_at',
				'limit'           => $limit,
			)
		);

		$today = gmdate( 'Y-m-d' );
		$out   = array();

		foreach ( $rows as $row ) {
			if ( ! $row->next_action_at || $row->next_action_at > $today ) {
				continue;
			}

			$out[] = array(
				'id'       => (int) $row->id,
				'name'     => $row->display_name,
				'since'    => mysql2date( get_option( 'date_format' ), $row->updated_at ),
				'overdue'  => $row->next_action_at < $today,
			);
		}

		return $out;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);

		return $row ?: null;
	}

	/**
	 * The leader list.
	 *
	 * Scoped to the caller's teams unless they hold CAP_VIEW_ALL. Snoozed
	 * people drop out of the default view: without that, a queue full of
	 * "travelling until September" turns into noise and leaders stop opening
	 * it at all.
	 *
	 * @param array<string,mixed> $args
	 * @return array<int,object>
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		/*
		 * The same gate can_view_submission() opens with. This method was
		 * relying entirely on visible_team_ids() to scope it, which answers
		 * "which teams" and was never meant to answer "may you be here at all".
		 */
		if ( ! current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) {
			return array();
		}

		$defaults = array(
			'status'          => '',
			'team_id'         => 0,
			'language'        => '',
			'search'          => '',
			'include_snoozed' => false,
			'include_unverified' => false,
			'due_only'        => false,
			'orderby'         => 'next_action_at',
			'limit'           => 50,
			'offset'          => 0,
		);
		$args = array_merge( $defaults, $args );

		$submissions = Schema::table( 'submissions' );
		$placements  = Schema::table( 'placements' );

		$where  = array( '1=1' );
		$params = array();

		$visible = Roles::visible_team_ids();
		if ( null !== $visible ) {
			if ( empty( $visible ) ) {
				return array();
			}

			$in     = implode( ',', array_fill( 0, count( $visible ), '%d' ) );
			$where[] = "s.id IN ( SELECT submission_id FROM {$placements} WHERE team_id IN ({$in}) )";
			$params  = array_merge( $params, $visible );
		}

		if ( $args['team_id'] ) {
			$where[]  = "s.id IN ( SELECT submission_id FROM {$placements} WHERE team_id = %d )";
			$params[] = (int) $args['team_id'];
		}

		if ( $args['status'] ) {
			$where[]  = 's.status = %s';
			$params[] = $args['status'];
		}

		if ( ! $args['include_snoozed'] ) {
			$where[] = '( s.snooze_until IS NULL OR s.snooze_until <= UTC_DATE() )';
		}

		// An unconfirmed email is not a person a leader should be ringing.
		if ( ! $args['include_unverified'] ) {
			$where[] = 's.verified_at IS NOT NULL';
		}

		if ( $args['due_only'] ) {
			$where[] = '( s.next_action_at IS NOT NULL AND s.next_action_at <= UTC_DATE() )';
		}

		if ( $args['language'] ) {
			$where[]  = 's.languages LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['language'] ) . '%';
		}

		if ( $args['search'] ) {
			// The placeholder promises "people, teams, gifts", so search those
			// rather than only the name. Gifts, languages and suggested teams
			// are JSON, but a LIKE across them is more than fast enough at the
			// scale one church produces.
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( s.display_name LIKE %s OR s.email LIKE %s'
				. ' OR s.gifts_likely LIKE %s OR s.languages LIKE %s'
				. ' OR s.suggested_teams LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_order = array(
			'next_action_at' => 's.next_action_at IS NULL, s.next_action_at ASC',
			'submitted_at'   => 's.submitted_at DESC',
			'name'           => 's.display_name ASC',

			/*
			 * Nobody has spoken to these people yet.
			 *
			 * Sorting the main list purely by follow-up date buried every new
			 * arrival: a fresh profile is given a date three days out, so it
			 * lands behind everyone already in the queue — the newest person was
			 * always last on a card called "People ready for a next step", and
			 * on a five-row card they simply were not on it.
			 *
			 * Somebody nobody has contacted is the most ready for a next step
			 * there is, and being overlooked is the exact thing this product
			 * exists to prevent. Overdue follow-ups are not lost by this: the
			 * "Quick follow-ups" card next to it is about nothing else.
			 */
			'waiting'        => "( s.status = '" . Schema::STATUS_SUBMITTED . "' ) DESC, s.next_action_at IS NULL, s.next_action_at ASC",
		);
		$order = $allowed_order[ $args['orderby'] ] ?? $allowed_order['next_action_at'];

		$params[] = max( 1, min( 200, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		$sql = "SELECT s.* FROM {$submissions} s WHERE " . implode( ' AND ', $where )
			. " ORDER BY {$order} LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders and params are assembled in step above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		foreach ( $rows as $row ) {
			$row->is_stale = self::is_stale( $row );
		}

		return $rows;
	}

	/** A profile old enough that its answers may no longer hold. */
	public static function is_stale( object $submission ): bool {
		$submitted = strtotime( (string) $submission->submitted_at . ' UTC' );
		if ( ! $submitted ) {
			return false;
		}

		$cutoff = strtotime( '-' . Privacy::staleness_months() . ' months' );

		return $submitted < $cutoff;
	}

	/**
	 * Move a submission along the pipeline.
	 *
	 * @param int      $id         Submission id.
	 * @param string   $status     Target status.
	 * @param int      $team_id    Team the move applies to, for the gate.
	 * @param array    $extra      Optional snooze_until / decline_reason / next_action_at.
	 * @return true|\WP_Error
	 */
	public static function set_status( int $id, string $status, int $team_id = 0, array $extra = array() ) {
		if ( ! in_array( $status, Schema::statuses(), true ) ) {
			return new \WP_Error( 'serve_bad_status', __( 'Unknown status.', 'serve-dashboard' ) );
		}

		if ( ! current_user_can( Roles::CAP_MANAGE_PLACE ) || ! Roles::can_view_submission( $id ) ) {
			return new \WP_Error( 'serve_forbidden', __( 'You cannot change this record.', 'serve-dashboard' ), array( 'status' => 403 ) );
		}

		/*
		 * A gated status has to name its team, because the gate is a question
		 * about a specific team and cannot be asked without one.
		 *
		 * `team_id` used to be optional here for every status, so the check
		 * below simply did not run when it was left out — and an uncleared
		 * person suggested to Fellowship Kids could be moved straight to Placed
		 * by omitting one parameter. Nothing in the UI did that, but the whole
		 * reason this lives in the transition code rather than the form is that
		 * the form is not the only caller.
		 *
		 * It is the right rule regardless of safeguarding: someone Placed on no
		 * team is counted in "Serving now" while serving nowhere.
		 */
		if ( Safeguarding::is_gated_status( $status ) && ! $team_id ) {
			return new \WP_Error(
				'serve_team_required',
				__( 'Trial serve and Placed apply to a particular team, so one has to be chosen before the move can be recorded.', 'serve-dashboard' ),
				array( 'status' => 422 )
			);
		}

		/*
		 * Team ownership, before any state moves.
		 *
		 * This checked only that the caller could *see* the person. A ministry
		 * leader who could open a profile through their own team could name any
		 * team id in the same request and have it honoured — another ministry's
		 * team, a deactivated one, or an id belonging to no row at all. The
		 * last of those was the worst: block_reason() returns "" for a team it
		 * cannot load, so the safeguarding gate waved it through, the submission
		 * was updated, and Placements::ensure() wrote a placement row pointing
		 * at a team that does not exist. placements_for() INNER JOINs teams, so
		 * the row was then invisible everywhere while the person read as Placed.
		 *
		 * Asked before the first write, so a rejected transition leaves nothing
		 * behind. can_manage_team() covers existence, activity and ownership in
		 * one place, because three callers checking two of the three is how the
		 * gap appeared.
		 */
		if ( $team_id && ! Roles::can_manage_team( $team_id ) ) {
			return new \WP_Error(
				'serve_team_forbidden',
				__( 'That team is not one you lead, or it is no longer running.', 'serve-dashboard' ),
				array( 'status' => 403 )
			);
		}

		if ( $team_id ) {
			/*
			 * Raised before the gate is asked, not read from what intake
			 * happened to record. safeguarding_status was initialised from the
			 * teams the assessment suggested, so a person routed by hand to
			 * Fellowship Kids months later still carried "not required" — and
			 * block_reason() compares against exactly that column, so the gate
			 * it is supposed to close was open. Raising first makes the manual
			 * route reach the same state the suggested route would have.
			 */
			$raised = Safeguarding::raise_for_team( $id, $team_id );
			if ( is_wp_error( $raised ) ) {
				return $raised;
			}

			$blocked = Safeguarding::block_reason( $id, $team_id, $status );
			if ( '' !== $blocked ) {
				return new \WP_Error( 'serve_safeguarding_blocked', $blocked, array( 'status' => 409 ) );
			}
		}

		global $wpdb;
		$previous = self::get( $id );

		$fields = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s' );

		// A pause without a return date is how a follow-up queue rots.
		if ( Schema::STATUS_PAUSED === $status ) {
			$snooze = isset( $extra['snooze_until'] ) ? sanitize_text_field( (string) $extra['snooze_until'] ) : '';
			if ( '' === $snooze ) {
				return new \WP_Error(
					'serve_snooze_required',
					__( 'Pausing someone needs a date to bring them back. Otherwise they quietly disappear from the queue.', 'serve-dashboard' )
				);
			}
			$fields['snooze_until']   = $snooze;
			$fields['next_action_at'] = $snooze;
			$format[]                 = '%s';
			$format[]                 = '%s';
		} elseif ( isset( $extra['next_action_at'] ) ) {
			$fields['next_action_at'] = sanitize_text_field( (string) $extra['next_action_at'] );
			$format[]                 = '%s';
		} elseif ( Schema::STATUS_PLACED === $status ) {
			/*
			 * Placed is where the pipeline stopped, and the deck's Serve stage is
			 * "invite, schedule, and support the person" — the third of which had
			 * no representation at all. Somebody put on a team and never spoken to
			 * again is how a willing volunteer quietly stops coming.
			 *
			 * So placement schedules one look back. Not a status, not a queue
			 * entry among people still waiting for a first conversation: a date,
			 * surfaced separately, that a leader can clear in a click.
			 */
			$fields['next_action_at'] = gmdate( 'Y-m-d', strtotime( '+' . self::settling_weeks() . ' weeks' ) );
			$format[]                 = '%s';
		}

		$updated = $wpdb->update( Schema::table( 'submissions' ), $fields, array( 'id' => $id ), $format, array( '%d' ) );

		if ( false === $updated ) {
			return new \WP_Error( 'serve_update_failed', __( 'Could not save that change.', 'serve-dashboard' ) );
		}

		if ( $team_id ) {
			/*
			 * The row has to exist before it can be updated. Without this the
			 * update matched nothing, changed nothing and reported success: the
			 * person read as Placed while the team had no record of them, its
			 * leader still could not see them, and its headcount never moved.
			 * Only reachable for somebody with no suggestions, which used to be
			 * rare and is now a designed outcome.
			 *
			 * A human moving somebody onto a team is the audited operational
			 * decision the whole model turns on, so the row records that rather
			 * than inheriting the default source. `match` is a heuristic and
			 * must never be the recorded reason a leader gained access.
			 */
			$source = self::assignment_source( $extra );

			if ( ! Placements::ensure( $id, $team_id, $source ) ) {
				return self::rollback_status( $id, $previous, 'serve_placement_failed' );
			}

			/*
			 * A trial or a placement on a real team completes whatever the
			 * catch-all was assigned to do, so it stops holding the profile
			 * open to a team the person is not joining.
			 */
			if ( Safeguarding::is_gated_status( $status ) ) {
				Placements::retire_catchall( $id, $team_id );
			}

			$placed = $wpdb->update(
				Schema::table( 'placements' ),
				array(
					'status'         => $status,
					'decline_reason' => isset( $extra['decline_reason'] ) ? sanitize_text_field( (string) $extra['decline_reason'] ) : '',
					'leader_user_id' => get_current_user_id(),
					'updated_at'     => current_time( 'mysql', true ),
				),
				array(
					'submission_id' => $id,
					'team_id'       => $team_id,
				),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d', '%d' )
			);

			/*
			 * The two halves have to agree. This return value was discarded, so
			 * a placement write that failed left the person reading as Placed
			 * globally while their team's row still said Submitted — and the
			 * leader was told it worked. Put the status back and say so instead.
			 */
			if ( false === $placed ) {
				return self::rollback_status( $id, $previous, 'serve_placement_failed' );
			}
		}

		Audit::log(
			Audit::ACTION_STATUS_CHANGED,
			'submission',
			$id,
			array(
				'from'    => $previous->status ?? null,
				'to'      => $status,
				'team_id' => $team_id,
			)
		);

		return true;
	}

	/**
	 * Why a placement row is being created, for the audit trail.
	 *
	 * Never `match`. A ranking is a heuristic; the record of why a ministry
	 * leader can read somebody's profile has to name a decision a person took.
	 *
	 * @param array<string,mixed> $extra
	 */
	private static function assignment_source( array $extra ): string {
		$source = isset( $extra['source'] ) ? (string) $extra['source'] : '';

		return in_array( $source, Placements::assignment_sources(), true )
			? $source
			: Placements::SOURCE_INTAKE_TRIAGE;
	}

	/**
	 * Put a status back after a dependent write failed.
	 *
	 * The alternative is what happened before: report the failure while leaving
	 * the submission holding the new status anyway, so the next person to look
	 * sees a placement that never happened. MySQL's default engine gives us
	 * transactions, but wpdb offers no portable handle on them across the
	 * multisite and shared-hosting configurations this plugin has to survive,
	 * and a compensating write is legible in the audit log in a way a silent
	 * rollback is not.
	 *
	 * @param object|null $previous Row as it was before the update.
	 * @return \WP_Error
	 */
	private static function rollback_status( int $id, ?object $previous, string $code ): \WP_Error {
		global $wpdb;

		if ( $previous ) {
			$wpdb->update(
				Schema::table( 'submissions' ),
				array(
					'status'         => $previous->status,
					'next_action_at' => $previous->next_action_at,
					'snooze_until'   => $previous->snooze_until,
					'updated_at'     => $previous->updated_at,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		Audit::log(
			Audit::ACTION_STATUS_CHANGED,
			'submission',
			$id,
			array(
				'rolled_back' => true,
				'reason'      => $code,
				'to'          => $previous->status ?? null,
			)
		);

		return new \WP_Error(
			$code,
			__( 'That move could not be recorded against the team, so nothing was changed. Please try again.', 'serve-dashboard' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Decode the stored profile.
	 *
	 * Sensitive sections are stripped for anyone without CAP_VIEW_SENSITIVE.
	 * The Experiences block includes painful experiences, which no ordinary
	 * team leader needs in order to decide whether someone might enjoy the
	 * welcome team.
	 *
	 * @return array<string,mixed>
	 */
	public static function profile( object $submission, bool $log_view = true ): array {
		$profile = json_decode( (string) $submission->profile_json, true );
		$profile = is_array( $profile ) ? $profile : array();

		if ( ! current_user_can( Roles::CAP_VIEW_SENSITIVE ) ) {
			unset( $profile['experiences'] );
			$profile['_redacted'] = array( 'experiences' );
		} elseif ( $log_view && isset( $profile['experiences'] ) ) {
			Audit::log( Audit::ACTION_SENSITIVE_VIEWED, 'submission', (int) $submission->id );
		}

		return $profile;
	}

	/**
	 * @return string[]
	 */
	public static function decode_list( ?string $json ): array {
		$decoded = json_decode( (string) $json, true );

		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}

	/** Counts per pipeline status, for the dashboard header. */
	public static function status_counts(): array {
		$counts = array();

		foreach ( Schema::statuses() as $status ) {
			$counts[ $status ] = count(
				self::query(
					array(
						'status'          => $status,
						'limit'           => 200,
						'include_snoozed' => true,
					)
				)
			);
		}

		return $counts;
	}
}
