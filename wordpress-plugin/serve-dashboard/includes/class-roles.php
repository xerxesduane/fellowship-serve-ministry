<?php
/**
 * Roles and capabilities.
 *
 * A worship leader has no business browsing the whole church's spiritual
 * gifts, phone numbers, and painful experiences. Access is therefore scoped
 * two ways: by capability (what kind of thing you may do) and by team
 * ownership (whose records you may do it to).
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Roles {

	public const ROLE_PASTOR = 'serve_pastor';
	public const ROLE_LEADER = 'serve_ministry_leader';

	/* Capabilities. */
	public const CAP_VIEW_DASHBOARD  = 'serve_view_dashboard';
	public const CAP_VIEW_ALL        = 'serve_view_all_submissions';
	public const CAP_VIEW_TEAM       = 'serve_view_team_submissions';
	public const CAP_VIEW_SENSITIVE  = 'serve_view_sensitive';
	public const CAP_MANAGE_PLACE    = 'serve_manage_placements';
	public const CAP_MANAGE_TEAMS    = 'serve_manage_teams';
	public const CAP_MANAGE_SETTINGS = 'serve_manage_settings';
	public const CAP_EXPORT          = 'serve_export_data';
	public const CAP_VERIFY_SAFEGUARD = 'serve_verify_safeguarding';

	/**
	 * Capability sets per role.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function role_caps(): array {
		$leader = array(
			self::CAP_VIEW_DASHBOARD => true,
			self::CAP_VIEW_TEAM      => true,
			self::CAP_MANAGE_PLACE   => true,
			'read'                   => true,
		);

		$pastor = array_merge(
			$leader,
			array(
				self::CAP_VIEW_ALL         => true,
				self::CAP_VIEW_SENSITIVE   => true,
				self::CAP_MANAGE_TEAMS     => true,
				self::CAP_MANAGE_SETTINGS  => true,
				self::CAP_EXPORT           => true,
				self::CAP_VERIFY_SAFEGUARD => true,
			)
		);

		return array(
			self::ROLE_LEADER => $leader,
			self::ROLE_PASTOR => $pastor,
		);
	}

	/** Called on activation. */
	public static function install(): void {
		$caps = self::role_caps();

		add_role( self::ROLE_LEADER, __( 'SERVE Ministry Leader', 'serve-dashboard' ), $caps[ self::ROLE_LEADER ] );
		add_role( self::ROLE_PASTOR, __( 'SERVE Pastor', 'serve-dashboard' ), $caps[ self::ROLE_PASTOR ] );

		// Administrators get everything, so the site owner is never locked out
		// of the plugin they installed.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( $caps[ self::ROLE_PASTOR ] ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/** Called on deactivation: drop the roles, leave the data alone. */
	public static function uninstall(): void {
		remove_role( self::ROLE_LEADER );
		remove_role( self::ROLE_PASTOR );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::role_caps()[ self::ROLE_PASTOR ] ) as $cap ) {
				if ( 'read' !== $cap ) {
					$admin->remove_cap( $cap );
				}
			}
		}
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

		if ( user_can( $user_id, self::CAP_VIEW_ALL ) ) {
			return null;
		}

		global $wpdb;
		$teams = Schema::table( 'teams' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id FROM {$teams} WHERE leader_user_id = %d AND is_active = 1",
				$user_id
			)
		);

		return array_map( 'intval', (array) $ids );
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
