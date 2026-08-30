<?php
/**
 * Placement rows, and what it means when there are none.
 *
 * A placement row is what makes a submission visible to a ministry leader, so
 * everything here decides who can read somebody's answers.
 *
 * Since suggestions became strong-only, a profile can legitimately match no
 * team at all. That is an honest answer rather than a failure, but on its own it
 * left those people visible to pastors and nobody else, and offered no way to
 * record what the conversation decided. This class holds the two things that
 * close that: the catch-all owner, and the rule that placing somebody always
 * leaves a row behind.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Placements {

	/**
	 * A row created because the ranking suggested this team.
	 *
	 * Legacy, and kept only so the rows already in the database keep saying
	 * what they have always said. Nothing creates one any more: a heuristic is
	 * not consent to disclose somebody's profile to a particular ministry, and
	 * this value being an accepted reason for access was precisely how it
	 * became one. New rows name a decision a person took — see
	 * assignment_sources() below.
	 */
	public const SOURCE_MATCH = 'match';

	/** A coordinator reviewed the profile and chose this team. */
	public const SOURCE_INTAKE_TRIAGE = 'intake_triage';

	/** A pastor assigned this team directly. */
	public const SOURCE_PASTOR_ASSIGNED = 'pastor_assigned';

	/**
	 * The participant asked to be contacted about this team.
	 *
	 * Still a request rather than a grant: it records what they asked for so a
	 * coordinator can act on it, and does not by itself open the profile.
	 */
	public const SOURCE_PARTICIPANT_REQUESTED = 'participant_requested';

	/**
	 * Sources a new row may legitimately be created with.
	 *
	 * SOURCE_MATCH is deliberately absent. Anything handed a source outside
	 * this list is recorded as intake triage rather than rejected, because the
	 * caller has already been authorised by then and losing the audit row would
	 * be worse than recording a conservative reason for it.
	 *
	 * @return string[]
	 */
	public static function assignment_sources(): array {
		return array(
			self::SOURCE_INTAKE_TRIAGE,
			self::SOURCE_PASTOR_ASSIGNED,
			self::SOURCE_PARTICIPANT_REQUESTED,
			self::SOURCE_CATCHALL,
		);
	}

	/** Keep an unrecognised source out of the column without losing the row. */
	private static function normalise_source( string $source ): string {
		return in_array( $source, self::assignment_sources(), true )
			? $source
			: self::SOURCE_INTAKE_TRIAGE;
	}

	/**
	 * A row created because nothing matched.
	 *
	 * Not a suggestion, and never to be shown as one. It says only that
	 * somebody owns the first conversation with this person.
	 */
	public const SOURCE_CATCHALL = 'catchall';

	/**
	 * Which team picks up the people no team matched.
	 *
	 * An option rather than a constant. The last two things hardcoded in this
	 * plugin — the ministry list in the browser and the serving form URL — both
	 * had to be dug back out again, and a church that renames or retires
	 * Welcome should not have to edit PHP.
	 */
	public const OPTION_CATCHALL_TEAM = 'serve_dashboard_catchall_team';

	/** What a new install starts with. Blank is a real answer: pastors keep it. */
	public const DEFAULT_CATCHALL_TEAM = 'welcome';

	/**
	 * The configured catch-all team, or null if there is not one.
	 *
	 * Returns null for a team that has been retired as well as for a blank
	 * setting, so a closed team can never quietly become the owner of every
	 * unmatched person.
	 */
	public static function catchall_team(): ?object {
		/*
		 * `false` and `''` mean different things and must not be collapsed.
		 *
		 * `false` is an option nobody has ever set, which is a fresh install --
		 * the seeding migration only runs on upgrade, so a new church would
		 * otherwise get no catch-all at all, the exact opposite of the
		 * documented default. CI caught this; a developer machine that had
		 * upgraded through the migration could not.
		 *
		 * `''` is an administrator who chose "Nobody" in Settings, and that
		 * choice has to survive.
		 */
		$stored = get_option( self::OPTION_CATCHALL_TEAM, false );
		$slug   = false === $stored ? self::DEFAULT_CATCHALL_TEAM : trim( (string) $stored );

		if ( '' === $slug ) {
			return null;
		}

		$team = Teams::get_by_slug( $slug );

		return $team && ! empty( $team->is_active ) ? $team : null;
	}

	/**
	 * Give a submission with no matches an owner.
	 *
	 * Called at intake, after the matched rows are in. Does nothing when the
	 * person matched something, when no catch-all is configured, or when a row
	 * for that team already exists.
	 *
	 * `suggested_teams` is deliberately left alone. The person matched no team
	 * and their profile should keep saying so — this creates an owner for the
	 * conversation, not a claim of fit, and the two must not be confused.
	 *
	 * @return int The team id used, or 0 if nothing was created.
	 */
	/**
	 * Give every new submission its central intake owner.
	 *
	 * This is assign_catchall() generalised, and the generalisation is the
	 * point. The catch-all used to fire only when the ranking matched nothing,
	 * because a matched person was already owned — by the ministry teams the
	 * ranking had just handed placement rows to. Those rows are gone, so
	 * without this a person the matcher liked would have *no* owner at all
	 * while a person it could not read had one: exactly backwards.
	 *
	 * One owner for everybody, whatever their tier, until a coordinator reads
	 * the profile and assigns a team. Strong, Suggested, Explore and no-match
	 * all arrive in the same queue and are all somebody's to answer.
	 *
	 * Net effect on who can see whom is a narrowing: up to three ministry teams
	 * per person before, exactly one configured intake owner now — and pastors,
	 * who hold CAP_VIEW_ALL and have always seen the whole queue.
	 *
	 * @return int The team id used, or 0 if no intake team is configured.
	 */
	public static function assign_intake_owner( int $submission_id ): int {
		if ( $submission_id <= 0 ) {
			return 0;
		}

		$team = self::catchall_team();
		if ( ! $team ) {
			return 0;
		}

		return self::ensure( $submission_id, (int) $team->id, self::SOURCE_CATCHALL )
			? (int) $team->id
			: 0;
	}

	public static function assign_catchall( int $submission_id, array $suggested ): int {
		if ( $suggested || $submission_id <= 0 ) {
			return 0;
		}

		$team = self::catchall_team();
		if ( ! $team ) {
			return 0;
		}

		return self::ensure( $submission_id, (int) $team->id, self::SOURCE_CATCHALL )
			? (int) $team->id
			: 0;
	}

	/**
	 * Make sure a placement row exists for this submission and team.
	 *
	 * `set_status()` used to UPDATE the placements table and nothing else. With
	 * no row to update it changed nothing and reported success, so a person
	 * could be marked Placed on a team that had no record of them: counted in
	 * the pipeline, invisible to that team's leader, and absent from its
	 * headcount. Silent, and only reachable for somebody with no suggestions —
	 * which used to be rare and is now a designed outcome.
	 *
	 * @param string $source Only applied when the row is created.
	 * @return bool Whether a row exists afterwards.
	 */
	public static function ensure( int $submission_id, int $team_id, string $source = self::SOURCE_MATCH ): bool {
		global $wpdb;

		if ( $submission_id <= 0 || $team_id <= 0 ) {
			return false;
		}

		/*
		 * The team has to be real and running before a row can point at it.
		 * Only `$team_id <= 0` was checked, so a fabricated id inserted a
		 * placement referencing no team at all — invisible to every read path
		 * (they INNER JOIN teams) while still counting as a row, and a
		 * deactivated team could quietly acquire new people.
		 */
		$team = Teams::get( $team_id );
		if ( ! $team || empty( $team->is_active ) ) {
			return false;
		}

		$table = Schema::table( 'placements' );

		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id FROM {$table} WHERE submission_id = %d AND team_id = %d",
				$submission_id,
				$team_id
			)
		);

		if ( $existing > 0 ) {
			return true;
		}

		$now = current_time( 'mysql', true );

		return (bool) $wpdb->insert(
			$table,
			array(
				'submission_id' => $submission_id,
				'team_id'       => $team_id,
				'status'        => Schema::STATUS_SUBMITTED,
				'source'        => self::normalise_source( $source ),
				'notes'         => '',
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Withdraw the catch-all once another team has taken the person on.
	 *
	 * The catch-all exists so that one team owns the *first conversation* with
	 * somebody nothing matched. When that conversation ends with a trial or a
	 * placement on a different team, the assignment is finished: continuing to
	 * expose the profile to a team the person is not joining is the same
	 * over-sharing that restricting suggestions to strong matches was about.
	 *
	 * Deleted rather than marked closed. There is no status that means "handed
	 * on" -- `declined` says the person decided against it, which is a claim
	 * about them and would be untrue -- and inventing one would put a new word
	 * into a pipeline vocabulary the metrics, filters and funnel all read.
	 *
	 * Only ever a catch-all row still sitting at `submitted`. If that team has
	 * begun something of their own with the person, it is not a catch-all any
	 * more and is left alone.
	 *
	 * @param int $team_id The team the person was just moved onto.
	 * @return int How many were withdrawn.
	 */
	public static function retire_catchall( int $submission_id, int $team_id ): int {
		global $wpdb;

		$table = Schema::table( 'placements' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id, team_id FROM {$table}
				 WHERE submission_id = %d AND team_id <> %d AND source = %s AND status = %s",
				$submission_id,
				$team_id,
				self::SOURCE_CATCHALL,
				Schema::STATUS_SUBMITTED
			)
		);

		$retired = 0;

		foreach ( $rows as $row ) {
			if ( ! $wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) ) ) {
				continue;
			}

			++$retired;

			Audit::log(
				Audit::ACTION_PLACEMENT_RETIRED,
				'submission',
				$submission_id,
				array(
					'team_id' => (int) $row->team_id,
					'reason'  => 'catch-all completed: placed with another team',
				)
			);
		}

		return $retired;
	}

	/**
	 * Placements that grant access the ranking would no longer give.
	 *
	 * Strong-only suggestions apply from the moment they shipped, so everybody
	 * already in the system keeps rows built under the old rules. Those rows are
	 * what decide who may read a profile, so the drift is an access question
	 * rather than a tidiness one.
	 *
	 * Two things are never listed, and the distinction is the whole safety of
	 * this:
	 *
	 * - **A placement anything has happened on.** Status past `submitted`, or
	 *   an owner, a follow-up date, a decline reason or notes against it. Those
	 *   are relationships. Somebody halfway through a trial serve must not lose
	 *   the leader walking them through it because the vocabulary moved.
	 * - **A catch-all row**, which was never a claim of fit and is not drift.
	 *
	 * Reads only. Nothing here deletes anything.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function stale_rows(): array {
		global $wpdb;

		$submissions = Schema::table( 'submissions' );
		$placements  = Schema::table( 'placements' );
		$teams       = Schema::table( 'teams' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input.
				"SELECT p.id, p.submission_id, p.team_id, t.slug AS team_slug, t.name AS team_name,
				        s.display_name, s.profile_json
				 FROM {$placements} p
				 INNER JOIN {$teams} t ON t.id = p.team_id
				 INNER JOIN {$submissions} s ON s.id = p.submission_id
				 WHERE p.status = %s
				   AND p.source = %s
				   AND ( p.leader_user_id IS NULL OR p.leader_user_id = 0 )
				   AND p.next_action_at IS NULL
				   AND p.decline_reason = ''
				   AND TRIM( p.notes ) = ''
				 ORDER BY s.id, t.slug",
				Schema::STATUS_SUBMITTED,
				self::SOURCE_MATCH
			)
		);

		$stale   = array();
		$ranking = array();

		foreach ( $rows as $row ) {
			$submission_id = (int) $row->submission_id;

			/*
			 * One ranking per person, not one per row.
			 *
			 * This is the report-only review path for legacy `match` rows —
			 * placements created back when a ranking was allowed to grant a
			 * ministry leader access. Nothing creates them any more, and
			 * nothing here deletes one: it lists them for a human to decide
			 * about, which is the only safe way to change who can see whom.
			 *
			 * The raw decode is deliberate and safe in this one place. It runs
			 * from the reconciliation tool under an explicit capability check,
			 * never from a request, and its output is team slugs for comparison
			 * rather than anything shown to a scoped leader.
			 *
			 * Legacy profiles were written before gift ids were carried, so
			 * most now fail partition validation and rank nothing at all. That
			 * is reported honestly — the row is listed for review with its
			 * reason — rather than being read as "the ranking changed its mind".
			 */
			if ( ! isset( $ranking[ $submission_id ] ) ) {
				$profile                   = json_decode( (string) $row->profile_json, true );
				$ranking[ $submission_id ] = array_column(
					Matching::suggestions_for_profile( is_array( $profile ) ? $profile : array() ),
					'team_slug'
				);
			}

			if ( in_array( (string) $row->team_slug, $ranking[ $submission_id ], true ) ) {
				continue;
			}

			$stale[] = array(
				'placement_id'  => (int) $row->id,
				'submission_id' => $submission_id,
				'person'        => (string) $row->display_name,
				'team_id'       => (int) $row->team_id,
				'team_slug'     => (string) $row->team_slug,
				'team_name'     => (string) $row->team_name,
				'would_suggest' => $ranking[ $submission_id ],
			);
		}

		return $stale;
	}

	/**
	 * Withdraw one of those rows.
	 *
	 * Deliberately takes a single id rather than doing the sweep itself, so the
	 * caller has to have looked at the list. Audited, because this removes
	 * somebody's access to a profile and "who could see this, and when did that
	 * change" is a question worth being able to answer later.
	 */
	public static function retire( int $placement_id ): bool {
		global $wpdb;

		$table = Schema::table( 'placements' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT submission_id, team_id, status, source FROM {$table} WHERE id = %d",
				$placement_id
			)
		);

		// Re-checked here rather than trusted from the list, because the list
		// may have been read minutes ago and a conversation may have started
		// since.
		if ( ! $row
			|| Schema::STATUS_SUBMITTED !== $row->status
			|| self::SOURCE_MATCH !== $row->source ) {
			return false;
		}

		$deleted = (bool) $wpdb->delete( $table, array( 'id' => $placement_id ), array( '%d' ) );

		if ( $deleted ) {
			Audit::log(
				Audit::ACTION_PLACEMENT_RETIRED,
				'submission',
				(int) $row->submission_id,
				array(
					'team_id' => (int) $row->team_id,
					'reason'  => 'no longer a suggested team',
				)
			);
		}

		return $deleted;
	}

	/**
	 * Submissions no team matched, among those the caller may see.
	 *
	 * Counted from `suggested_teams` rather than from placement rows, because a
	 * catch-all row exists precisely for these people and would hide them.
	 *
	 * Scoped the same way everything else is. This returned zero for anybody
	 * with a team scope, which was wrong the moment the catch-all existed: the
	 * leader who has been handed these people is exactly the person who needs to
	 * know how many there are, and they were the one person the count refused to
	 * tell.
	 */
	public static function unmatched_count(): int {
		global $wpdb;

		$submissions = Schema::table( 'submissions' );
		$placements  = Schema::table( 'placements' );
		$team_ids    = Roles::visible_team_ids();

		$unmatched = "s.verified_at IS NOT NULL
			AND ( s.suggested_teams = '[]' OR s.suggested_teams = '' OR s.suggested_teams IS NULL )";

		// Sees everyone: every unmatched person counts.
		if ( null === $team_ids ) {
			return (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input in this statement.
				"SELECT COUNT(*) FROM {$submissions} s WHERE {$unmatched}"
			);
		}

		// Sees nothing: an unassigned leader has no scope at all.
		if ( empty( $team_ids ) ) {
			return 0;
		}

		/*
		 * Scoped to teams: the ones this leader has actually been given, which
		 * is a catch-all row on a team they lead. A matched placement cannot
		 * qualify, because an unmatched person has none by definition.
		 */
		$in = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and placeholders are assembled above.
				"SELECT COUNT(DISTINCT s.id) FROM {$submissions} s
				 INNER JOIN {$placements} p ON p.submission_id = s.id
				 WHERE {$unmatched}
				   AND p.source = %s
				   AND p.team_id IN ({$in})",
				array_merge( array( self::SOURCE_CATCHALL ), array_map( 'intval', $team_ids ) )
			)
		);
	}
}
