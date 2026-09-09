<?php
/**
 * Reads the dashboard views need.
 *
 * The plugin's Admin class was mostly WordPress: add_menu_page, the settings
 * screens, the form handlers behind admin-post.php. All of that is replaced by
 * this application's own routes and views.
 *
 * What is kept is the part that was never about WordPress -- the scoped
 * placement query. It is here rather than inlined into a view because the scope
 * rule it applies is the security model: null means unrestricted, an empty array
 * means a leader with no teams, and those two must never collapse into one.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve_Dashboard;

final class Admin {

	/*
	 * Screen names, kept because other classes build links from them.
	 *
	 * In WordPress these were add_menu_page() slugs and became
	 * admin.php?page=serve-dashboard-teams. Here they are route names, and
	 * admin_url() in the compatibility layer translates the calls that remain --
	 * so the digest's "confirm the headcount" link still points at the right
	 * screen without every caller being rewritten.
	 */
	public const PAGE_DASHBOARD = 'serve-dashboard';
	public const PAGE_TEAMS     = 'serve-dashboard-teams';
	public const PAGE_SETTINGS  = 'serve-dashboard-settings';

	public static function placements_for( int $submission_id, bool $all_scopes = false ): array {
		global $wpdb;

		$placements = Schema::table( 'placements' );
		$teams      = Schema::table( 'teams' );

		$where  = array( 'p.submission_id = %d' );
		$params = array( $submission_id );

		if ( ! $all_scopes ) {
			$visible = Roles::visible_team_ids();

			// Null means unrestricted, so no clause at all. An empty array is
			// the opposite and must not be allowed to fall through as one.
			if ( null !== $visible ) {
				if ( empty( $visible ) ) {
					return array();
				}

				$in      = implode( ',', array_fill( 0, count( $visible ), '%d' ) );
				$where[] = "p.team_id IN ({$in})";
				$params  = array_merge( $params, $visible );
			}
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input; placeholders built from a counted int array.
				"SELECT p.*, t.name AS team_name, t.requires_safeguarding
				 FROM {$placements} p
				 INNER JOIN {$teams} t ON t.id = p.team_id
				 WHERE " . implode( ' AND ', $where ) . '
				 ORDER BY t.name ASC',
				$params
			)
		);
	}
}
