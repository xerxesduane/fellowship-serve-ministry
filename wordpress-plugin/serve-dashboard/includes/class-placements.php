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

	/** A row created because the ranking suggested this team. */
	public const SOURCE_MATCH = 'match';

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
				'source'        => self::SOURCE_CATCHALL === $source ? self::SOURCE_CATCHALL : self::SOURCE_MATCH,
				'notes'         => '',
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Submissions no team matched, among those the caller may see.
	 *
	 * Counted from `suggested_teams` rather than from placement rows, because a
	 * catch-all row exists precisely for these people and would hide them.
	 */
	public static function unmatched_count(): int {
		global $wpdb;

		$submissions = Schema::table( 'submissions' );
		$team_ids    = Roles::visible_team_ids();

		// A leader scoped to teams sees people through placements, and an
		// unmatched person has no matched placement anywhere. The count is
		// meaningful only for somebody who sees everyone.
		if ( null !== $team_ids ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input in this statement.
			"SELECT COUNT(*) FROM {$submissions}
			 WHERE verified_at IS NOT NULL
			   AND ( suggested_teams = '[]' OR suggested_teams = '' OR suggested_teams IS NULL )"
		);
	}
}
