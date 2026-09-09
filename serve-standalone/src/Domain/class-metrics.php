<?php
/**
 * Dashboard aggregates.
 *
 * Every figure here is computed from real rows. Nothing is seeded with the
 * illustrative numbers from the concept mockups (184 / 37 / 12 / 96): those are
 * example values from a presentation and must never appear as though they were
 * Fellowship Dubai's own data.
 *
 * Counts respect the caller's team scope, so a ministry leader's "ready to
 * serve" figure describes their teams rather than the whole church.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Metrics {

	private const CACHE_TTL = 300;

	/**
	 * The four headline figures.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function headline(): array {
		global $wpdb;

		$table = Schema::table( 'submissions' );
		$scope = self::scope_clause();

		$completed = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope clause is built from integer ids.
			"SELECT COUNT(*) FROM {$table} s WHERE 1=1 {$scope}"
		);

		$completed_this_month = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope clause is built from integer ids.
			"SELECT COUNT(*) FROM {$table} s
			 WHERE s.submitted_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY ) {$scope}"
		);

		$ready = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope clause is built from integer ids.
				"SELECT COUNT(*) FROM {$table} s
				 WHERE s.status = %s
				   AND ( s.snooze_until IS NULL OR s.snooze_until <= UTC_DATE() ) {$scope}",
				Schema::STATUS_SUBMITTED
			)
		);

		$due = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope clause is built from integer ids.
			"SELECT COUNT(*) FROM {$table} s
			 WHERE s.next_action_at IS NOT NULL
			   AND s.next_action_at <= UTC_DATE()
			   AND s.status NOT IN ( 'placed', 'declined' )
			   AND ( s.snooze_until IS NULL OR s.snooze_until <= UTC_DATE() ) {$scope}"
		);

		$overdue = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope clause is built from integer ids.
			"SELECT COUNT(*) FROM {$table} s
			 WHERE s.next_action_at IS NOT NULL
			   AND s.next_action_at < UTC_DATE()
			   AND s.status NOT IN ( 'placed', 'declined' )
			   AND ( s.snooze_until IS NULL OR s.snooze_until <= UTC_DATE() ) {$scope}"
		);

		$serving = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- scope clause is built from integer ids.
				"SELECT COUNT(*) FROM {$table} s WHERE s.status = %s {$scope}",
				Schema::STATUS_PLACED
			)
		);

		return array(
			'completed' => array(
				'label' => __( 'Profiles completed', 'serve-dashboard' ),
				'value' => $completed,
				'delta' => $completed_this_month,
				'note'  => __( 'in the last 30 days', 'serve-dashboard' ),
				'tone'  => 'neutral',
				'view'  => '',
			),
			'ready'     => array(
				'label' => __( 'Ready for a conversation', 'serve-dashboard' ),
				'value' => $ready,
				'delta' => null,
				'note'  => __( 'no follow-up started yet', 'serve-dashboard' ),
				'tone'  => 'neutral',
				'view'  => 'status=' . Schema::STATUS_SUBMITTED,
			),
			'due'       => array(
				'label' => __( 'Follow-ups due', 'serve-dashboard' ),
				'value' => $due,
				'delta' => $overdue,
				'note'  => $overdue > 0
					/* translators: %d: number of overdue follow-ups. */
					? sprintf( __( '%d overdue', 'serve-dashboard' ), $overdue )
					: __( 'none overdue', 'serve-dashboard' ),
				'tone'  => $overdue > 0 ? 'attention' : 'neutral',
				'view'  => 'due_only=1',
			),
			'serving'   => array(
				'label' => __( 'Serving now', 'serve-dashboard' ),
				'value' => $serving,
				'delta' => null,
				'note'  => __( 'placed on a team', 'serve-dashboard' ),
				'tone'  => 'positive',
				'view'  => 'status=' . Schema::STATUS_PLACED,
			),
		);
	}

	/**
	 * Distribution of likely gifts across the visible submissions.
	 *
	 * Cached: it reads every row's JSON and is contextual information, not part
	 * of the follow-up workflow, so it does not need to be exact to the second.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function gift_distribution( int $limit = 8 ): array {
		$key    = 'serve_gifts_' . get_current_user_id();
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit );
		}

		global $wpdb;
		$table = Schema::table( 'submissions' );
		$scope = self::scope_clause();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- scope clause is built from integer ids.
		$rows = (array) $wpdb->get_col( "SELECT s.gifts_likely FROM {$table} s WHERE 1=1 {$scope}" );

		$counts = array();
		foreach ( $rows as $json ) {
			foreach ( Submissions::decode_list( $json ) as $gift ) {
				$counts[ $gift ] = ( $counts[ $gift ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );

		$out = array();
		foreach ( $counts as $gift => $count ) {
			$out[] = array(
				'label' => $gift,
				'count' => $count,
			);
		}

		set_transient( $key, $out, self::CACHE_TTL );

		return array_slice( $out, 0, $limit );
	}

	/**
	 * The next few follow-ups, for the compact side module.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function upcoming_followups( int $limit = 5 ): array {
		$rows = Submissions::query(
			array(
				'due_only' => false,
				'orderby'  => 'next_action_at',
				'limit'    => $limit,
			)
		);

		$today    = gmdate( 'Y-m-d' );
		$tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
		$out      = array();

		foreach ( $rows as $row ) {
			if ( ! $row->next_action_at ) {
				continue;
			}

			if ( in_array( $row->status, array( Schema::STATUS_PLACED, Schema::STATUS_DECLINED ), true ) ) {
				continue;
			}

			if ( $row->next_action_at < $today ) {
				$when = __( 'Overdue', 'serve-dashboard' );
				$tone = 'attention';
			} elseif ( $row->next_action_at === $today ) {
				$when = __( 'Today', 'serve-dashboard' );
				$tone = 'attention';
			} elseif ( $row->next_action_at === $tomorrow ) {
				$when = __( 'Tomorrow', 'serve-dashboard' );
				$tone = 'soon';
			} else {
				$when = mysql2date( get_option( 'date_format' ), $row->next_action_at );
				$tone = 'neutral';
			}

			$out[] = array(
				'id'    => (int) $row->id,
				'name'  => $row->display_name,
				'when'  => $when,
				'tone'  => $tone,
				'date'  => $row->next_action_at,
			);
		}

		return $out;
	}

	/**
	 * Team-scope SQL fragment, or an empty string for unrestricted callers.
	 *
	 * Returns a clause that matches nothing when the user leads no teams, so a
	 * leader with no assignment sees zeroes rather than the whole church.
	 */
	private static function scope_clause(): string {
		// Unverified submissions are excluded from every headline figure, so a
		// leader is never shown a queue depth that includes unproven addresses.
		$verified = ' AND s.verified_at IS NOT NULL';

		$visible = Roles::visible_team_ids();

		if ( null === $visible ) {
			return $verified;
		}

		if ( empty( $visible ) ) {
			return ' AND 1=0';
		}

		$placements = Schema::table( 'placements' );
		$ids        = implode( ',', array_map( 'intval', $visible ) );

		return $verified . " AND s.id IN ( SELECT submission_id FROM {$placements} WHERE team_id IN ({$ids}) )";
	}

	/** Called whenever a submission changes, so cached aggregates do not go stale. */
	public static function flush(): void {
		global $wpdb;

		// Per-user transients; clear them all rather than guessing which users
		// have a warm cache.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_serve_gifts_%'
			 OR option_name LIKE '_transient_timeout_serve_gifts_%'"
		);
	}
}
