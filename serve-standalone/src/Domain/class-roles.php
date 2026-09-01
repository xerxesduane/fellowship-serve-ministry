<?php
/**
 * Roles and capabilities.
 *
 * A worship leader has no business browsing the whole church's spiritual gifts,
 * phone numbers, and painful experiences. Access is therefore scoped two ways:
 * by capability (what kind of thing you may do) and by team ownership (whose
 * records you may do it to).
 *
 * The capability names and the team-scoping logic below are unchanged from the
 * WordPress version, because they are the security model and it is tested.
 * What changed is where roles live: WordPress had add_role() and a user meta
 * table, and this application has a role column and Platform\Auth.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve_Dashboard;

use Serve\Platform\Auth;

final class Roles {

	public const ROLE_PASTOR = Auth::ROLE_PASTOR;
	public const ROLE_LEADER = Auth::ROLE_LEADER;
	public const ROLE_ADMIN  = Auth::ROLE_ADMIN;

	/* Capabilities. */
	public const CAP_VIEW_DASHBOARD    = Auth::CAP_VIEW_DASHBOARD;
	public const CAP_VIEW_ALL          = Auth::CAP_VIEW_ALL;
	public const CAP_VIEW_TEAM         = Auth::CAP_VIEW_TEAM;
	public const CAP_VIEW_SENSITIVE    = Auth::CAP_VIEW_SENSITIVE;
	public const CAP_MANAGE_PLACE      = Auth::CAP_MANAGE_PLACE;
	public const CAP_MANAGE_TEAMS      = Auth::CAP_MANAGE_TEAMS;
	public const CAP_MANAGE_SETTINGS   = Auth::CAP_MANAGE_SETTINGS;
	public const CAP_EXPORT            = Auth::CAP_EXPORT;
	public const CAP_VERIFY_SAFEGUARD  = Auth::CAP_VERIFY_SAFEGUARD;
	public const CAP_MANAGE_USERS      = Auth::CAP_MANAGE_USERS;

	/**
	 * Nothing to install.
	 *
	 * WordPress needed roles registered into its own tables on activation. Here
	 * a role is a string in a column and the capability sets are declared in
	 * Platform\Auth::role_caps(), so there is no state to create. Kept as a
	 * method because the installer calls it, and returning quietly is more
	 * honest than deleting the call and leaving a reader wondering where roles
	 * come from.
	 */
	public static function install(): void {}

	/** @return array<int,string> */
	public static function all_roles(): array {
		return Auth::roles();
	}

	public static function label( string $role ): string {
		return Auth::role_label( $role );
	}

	/**
	 * Team ids the given user may see submissions for.
	 *
	 * A pastor sees everything, signalled by null rather than by an array of
	 * every id, so callers can skip the IN() clause entirely.
	 *
	 * @return int[]|null Null means unrestricted.
	 */
	public static function visible_team_ids( ?int $user_id = null ): ?array {
		$user_id = $user_id ?? get_current_user_id();

		/*
		 * A logged-out caller is user 0, and an unassigned team's leader_user_id
		 * is also 0 — so on a fresh install, where no team has a leader yet, the
		 * query below matched every team and handed an anonymous caller the
		 * whole congregation. Nothing reachable over HTTP relied on this alone,
		 * but it is the wrong answer to give any caller, and "no leader" and
		 * "not logged in" must never be the same value.
		 */
		if ( $user_id <= 0 ) {
			return array();
		}

		if ( user_can( $user_id, self::CAP_VIEW_ALL ) ) {
			return null;
		}

		global $wpdb;
		$teams = Schema::table( 'teams' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id FROM {$teams}
				 WHERE leader_user_id = %d
				   AND leader_user_id > 0
				   AND is_active = 1",
				$user_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether the current user may act on one specific team.
	 *
	 * Being able to see a person and being able to move them onto a team are
	 * different questions, and set_status() only ever asked the first. A leader
	 * who could open a profile through their own team could name any team id at
	 * all in the same request — another ministry's, a retired one, or one that
	 * has never existed — and the transition went through.
	 *
	 * Answered here rather than in the caller so every path that writes a
	 * placement asks the same question. Returns false for a team that does not
	 * exist and for one that has been deactivated: a closed team must not
	 * acquire new people, whoever is asking.
	 */
	public static function can_manage_team( int $team_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( $team_id <= 0 || ! user_can( $user_id, self::CAP_MANAGE_PLACE ) ) {
			return false;
		}

		$team = Teams::get( $team_id );
		if ( ! $team || empty( $team->is_active ) ) {
			return false;
		}

		$team_ids = self::visible_team_ids( $user_id );

		// Null is the all-teams capability; an empty array is a leader with no
		// teams, which is not the same thing and must not read as one.
		return null === $team_ids || in_array( $team_id, $team_ids, true );
	}

	/** Whether the current user may open one specific submission. */
	public static function can_view_submission( int $submission_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		if ( ! user_can( $user_id, self::CAP_VIEW_DASHBOARD ) ) {
			return false;
		}

		// Guessing an id must not reveal a profile whose email is unconfirmed.
		$submission = Submissions::get( $submission_id );
		if ( ! $submission || null === $submission->verified_at ) {
			return false;
		}

		$team_ids = self::visible_team_ids( $user_id );
		if ( null === $team_ids ) {
			return true;
		}

		if ( empty( $team_ids ) ) {
			return false;
		}

		global $wpdb;
		$placements = Schema::table( 'placements' );
		$in         = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from a counted int array.
				"SELECT 1 FROM {$placements} WHERE submission_id = %d AND team_id IN ({$in}) LIMIT 1",
				array_merge( array( $submission_id ), $team_ids )
			)
		);

		return (bool) $found;
	}
}
