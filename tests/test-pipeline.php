<?php
/**
 * Moving somebody along the pipeline, and who is allowed to.
 *
 * These are route-level tests because the routes are the boundary that matters:
 * the drawer is one caller of them, and the reason the safeguarding gate lives
 * in the transition code rather than the form is that the form is never the
 * only way in.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Roles;
use Serve_Dashboard\Safeguarding;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Submissions;
use Serve_Dashboard\Teams;

/**
 * Call a dashboard route and return [status, code].
 *
 * @return array{0:int,1:string}
 */
function call_route( string $method, string $route, array $body = array() ): array {
	$request = new \WP_REST_Request( $method, $route );

	if ( $body ) {
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
	}

	$response = rest_do_request( $request );
	$data     = $response->get_data();

	return array( $response->get_status(), is_array( $data ) ? (string) ( $data['code'] ?? 'ok' ) : 'ok' );
}

/** A verified person suggested to one team, ready to be moved along. */
function candidate( Fixtures $f, string $slug = 'welcome' ): int {
	return $f->verified_submission( array( 'suggested_teams' => array( $slug ) ) );
}

/*
 * The regression this file was written for. `team_id` was optional for every
 * status, and the gate only ran when it was supplied — so omitting one
 * parameter moved an uncleared person straight onto a team working with
 * children. Nothing in the UI did that; the point is that the UI is not the
 * only caller.
 */
test(
	'a gated status cannot be set without naming a team',
	function ( Assert $a, Fixtures $f ) {
		$id = candidate( $f, 'fellowship-kids' );
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		foreach ( array( Schema::STATUS_TRIAL_SERVE, Schema::STATUS_PLACED ) as $status ) {
			list( , $code ) = call_route( 'POST', "/serve/v1/people/$id/status", array( 'status' => $status ) );
			$a->same( 'serve_team_required', $code, "$status without a team is refused" );
		}

		$a->same( Schema::STATUS_SUBMITTED, Submissions::get( $id )->status, 'and nothing moved' );
	}
);

test(
	'the ungated stages still work without a team',
	function ( Assert $a, Fixtures $f ) {
		$id = candidate( $f );
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		// Contacting somebody is not a decision about a team, so requiring one
		// here would make the commonest action the most awkward.
		list( $status ) = call_route( 'POST', "/serve/v1/people/$id/status", array( 'status' => Schema::STATUS_CONTACTED ) );

		$a->same( 200, $status, 'contacted is accepted' );
		$a->same( Schema::STATUS_CONTACTED, Submissions::get( $id )->status, 'and recorded' );
	}
);

test(
	'pausing still needs a return date',
	function ( Assert $a, Fixtures $f ) {
		$id = candidate( $f );
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		list( , $code ) = call_route( 'POST', "/serve/v1/people/$id/status", array( 'status' => Schema::STATUS_PAUSED ) );
		$a->same( 'serve_snooze_required', $code, 'refused without one' );

		list( $status ) = call_route(
			'POST',
			"/serve/v1/people/$id/status",
			array( 'status' => Schema::STATUS_PAUSED, 'snooze_until' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) )
		);
		$a->same( 200, $status, 'accepted with one' );
	}
);

/*
 * Separation of duties. A leader who can move somebody to Placed must not also
 * be able to clear the check that permits it, or the gate is decoration.
 */
test(
	'only a pastor can record a background check',
	function ( Assert $a, Fixtures $f ) {
		$id     = candidate( $f, 'fellowship-kids' );
		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'fellowship-kids' );

		wp_set_current_user( $leader );
		$a->ok( Roles::can_view_submission( $id ), 'the leader can see this person at all' );

		list( $status ) = call_route( 'POST', "/serve/v1/people/$id/safeguarding", array( 'status' => Safeguarding::STATUS_CLEARED ) );
		$a->same( 403, $status, 'but cannot clear their check' );
		$a->same( Safeguarding::STATUS_REQUIRED, Submissions::get( $id )->safeguarding_status, 'and it did not change' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		list( $status ) = call_route( 'POST', "/serve/v1/people/$id/safeguarding", array( 'status' => Safeguarding::STATUS_CLEARED ) );
		$a->same( 200, $status, 'a pastor can' );
		$a->same( Safeguarding::STATUS_CLEARED, Submissions::get( $id )->safeguarding_status, 'and it took' );
	}
);

test(
	'an unknown background-check status is refused',
	function ( Assert $a, Fixtures $f ) {
		$id = candidate( $f, 'fellowship-kids' );
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		list( , $code ) = call_route( 'POST', "/serve/v1/people/$id/safeguarding", array( 'status' => 'obviously-fine' ) );
		$a->same( 'serve_bad_safeguarding', $code, 'refused' );
	}
);

test(
	'clearing the check is what opens the gate',
	function ( Assert $a, Fixtures $f ) {
		$id   = candidate( $f, 'fellowship-kids' );
		$team = (int) Teams::get_by_slug( 'fellowship-kids' )->id;
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		list( , $code ) = call_route( 'POST', "/serve/v1/people/$id/status", array( 'status' => Schema::STATUS_PLACED, 'team_id' => $team ) );
		$a->same( 'serve_safeguarding_blocked', $code, 'blocked beforehand' );

		call_route( 'POST', "/serve/v1/people/$id/safeguarding", array( 'status' => Safeguarding::STATUS_CLEARED ) );

		list( $status ) = call_route( 'POST', "/serve/v1/people/$id/status", array( 'status' => Schema::STATUS_PLACED, 'team_id' => $team ) );
		$a->same( 200, $status, 'allowed afterwards' );
		$a->same( Schema::STATUS_PLACED, Submissions::get( $id )->status, 'and they are Placed' );
	}
);

/*
 * Erasure. The plugin takes consent, states a retention period and records
 * religious belief, so "please remove my details" has to be answerable without
 * a database client.
 */
test(
	'a pastor can erase a profile and a leader cannot',
	function ( Assert $a, Fixtures $f ) {
		$id     = candidate( $f );
		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'welcome' );

		wp_set_current_user( $leader );
		list( $status ) = call_route( 'DELETE', "/serve/v1/people/$id" );
		$a->same( 403, $status, 'a leader cannot delete somebody' );
		$a->ok( null !== Submissions::get( $id ), 'the profile survives' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		list( $status ) = call_route( 'DELETE', "/serve/v1/people/$id" );
		$a->same( 200, $status, 'a pastor can' );
		$a->same( null, Submissions::get( $id ), 'and the profile is gone' );
	}
);

test(
	'an anonymous caller can move nobody and delete nobody',
	function ( Assert $a, Fixtures $f ) {
		$id   = candidate( $f );
		$team = (int) Teams::get_by_slug( 'welcome' )->id;

		wp_set_current_user( 0 );

		foreach (
			array(
				array( 'POST', "/serve/v1/people/$id/status", array( 'status' => Schema::STATUS_PLACED, 'team_id' => $team ) ),
				array( 'POST', "/serve/v1/people/$id/safeguarding", array( 'status' => Safeguarding::STATUS_CLEARED ) ),
				array( 'DELETE', "/serve/v1/people/$id", array() ),
			) as $attempt
		) {
			list( $status ) = call_route( $attempt[0], $attempt[1], $attempt[2] );
			$a->same( 401, $status, "{$attempt[0]} {$attempt[1]} is refused" );
		}

		$a->same( Schema::STATUS_SUBMITTED, Submissions::get( $id )->status, 'nothing moved' );
	}
);

/*
 * "Invite, schedule, and support the person" was the deck's Serve stage. The
 * pipeline stopped dead at Placed, so the third of those had no surface at
 * all — somebody put on a team and never spoken to again is how a willing
 * volunteer quietly stops coming.
 */
test(
	'placing somebody schedules a look back at them',
	function ( Assert $a, Fixtures $f ) {
		$id   = candidate( $f );
		$team = (int) Teams::get_by_slug( 'welcome' )->id;
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		// A new submission already carries a first-conversation date, three days
		// out. Placement replaces it with the settling-in one rather than
		// leaving somebody scheduled for a conversation they have already had.
		$before = Submissions::get( $id )->next_action_at;
		$a->same( gmdate( 'Y-m-d', strtotime( '+3 days' ) ), $before, 'the first-contact date is what is there beforehand' );

		Submissions::set_status( $id, Schema::STATUS_PLACED, $team );

		$due = Submissions::get( $id )->next_action_at;
		$a->not( $due === $before, 'placement replaces it' );
		$a->same(
			gmdate( 'Y-m-d', strtotime( '+' . Submissions::settling_weeks() . ' weeks' ) ),
			$due,
			'the configured number of weeks out'
		);
	}
);

test(
	'a settling-in check appears when it comes round, and closes without changing the status',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$id   = candidate( $f );
		$team = (int) Teams::get_by_slug( 'welcome' )->id;
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team );

		$listed = fn() => in_array( $id, wp_list_pluck( Submissions::settling_in( 50 ), 'id' ), true );
		$a->not( $listed(), 'not due yet' );

		$table = Schema::table( 'submissions' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_action_at = DATE_SUB( UTC_DATE(), INTERVAL 2 DAY ) WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$a->ok( $listed(), 'due once the date passes' );

		list( $status ) = call_route( 'POST', "/serve/v1/people/$id/settled" );
		$a->same( 200, $status, 'a leader can close it' );
		$a->not( $listed(), 'and it drops off the list' );

		// They are still on the team; somebody has just been to see them.
		$a->same( Schema::STATUS_PLACED, Submissions::get( $id )->status, 'still Placed' );
	}
);

test(
	'a settling-in check does not clutter the first-conversation queue',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$id   = candidate( $f );
		$team = (int) Teams::get_by_slug( 'welcome' )->id;
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team );

		$table = Schema::table( 'submissions' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_action_at = DATE_SUB( UTC_DATE(), INTERVAL 2 DAY ) WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Burying "see how they are getting on" among people still waiting for
		// a first conversation makes both lists harder to work.
		$queue = wp_list_pluck( \Serve_Dashboard\Metrics::upcoming_followups( 50 ), 'id' );
		$a->not( in_array( $id, $queue, true ), 'placed people stay out of the follow-up queue' );
	}
);

test(
	'placing somebody always leaves a placement row behind',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * set_status() used to UPDATE the placements table and nothing else.
		 * With no row to update it changed nothing and still reported success,
		 * so a person could be marked Placed on a team that had no record of
		 * them: counted in the pipeline, invisible to that team's leader, and
		 * absent from its headcount. Nothing said so.
		 *
		 * Rare while everybody had suggestions. Reachable by design now that a
		 * profile can legitimately match no team.
		 */
		$id = $f->submission( array( 'suggested_teams' => array() ) );

		global $wpdb;
		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'verified_at' => current_time( 'mysql', true ), 'verify_token' => null ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$wpdb->delete( Schema::table( 'placements' ), array( 'submission_id' => $id ), array( '%d' ) );

		$a->same(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					'SELECT COUNT(*) FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d',
					$id
				)
			),
			'starting with nothing to update'
		);

		wp_set_current_user( $f->user( \Serve_Dashboard\Roles::ROLE_PASTOR ) );

		$team = \Serve_Dashboard\Teams::get_by_slug( 'prayer' );
		$a->ok( null !== $team, 'the team exists to place them on' );

		$result = \Serve_Dashboard\Submissions::set_status( $id, Schema::STATUS_PLACED, (int) $team->id );
		$a->not( is_wp_error( $result ), 'the move is accepted' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT status FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d AND team_id = %d',
				$id,
				(int) $team->id
			)
		);

		$a->ok( null !== $row, 'and the team now has a record of them' );
		$a->same( Schema::STATUS_PLACED, $row->status, 'saying they are placed' );

		// Which is what makes them visible to the leader of that team.
		$leader = $f->user( \Serve_Dashboard\Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'prayer' );
		wp_set_current_user( $leader );

		$a->ok(
			\Serve_Dashboard\Roles::can_view_submission( $id ),
			'so the leader they were placed with can open them'
		);
	}
);

test(
	'placing somebody twice does not duplicate the row',
	function ( Assert $a, Fixtures $f ) {
		// ensure() runs on every move, so it has to be safe to run repeatedly.
		$id = $f->submission();

		global $wpdb;
		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'verified_at' => current_time( 'mysql', true ), 'verify_token' => null ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_set_current_user( $f->user( \Serve_Dashboard\Roles::ROLE_PASTOR ) );
		$team = \Serve_Dashboard\Teams::get_by_slug( 'welcome' );

		\Serve_Dashboard\Submissions::set_status( $id, Schema::STATUS_TRIAL_SERVE, (int) $team->id );
		\Serve_Dashboard\Submissions::set_status( $id, Schema::STATUS_PLACED, (int) $team->id );

		$a->same(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					'SELECT COUNT(*) FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d AND team_id = %d',
					$id,
					(int) $team->id
				)
			),
			'one row, updated rather than added to'
		);
	}
);

test(
	'a retired team never becomes the catch-all',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The catch-all owns the first conversation with everybody the ranking
		 * matched to nothing. A team the church has closed cannot do that, and
		 * pointing every unmatched person at it would hand their profiles to a
		 * leader who is no longer running anything.
		 *
		 * Falls back to nobody, which is the safe direction: pastors keep them.
		 */
		$before = get_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, false );
		$team   = \Serve_Dashboard\Teams::get_by_slug( 'welcome' );
		$a->ok( null !== $team, 'the team exists to begin with' );

		global $wpdb;

		try {
			update_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, 'welcome' );
			$a->ok( null !== \Serve_Dashboard\Placements::catchall_team(), 'and is the catch-all while it is active' );

			$wpdb->update( Schema::table( 'teams' ), array( 'is_active' => 0 ), array( 'id' => (int) $team->id ), array( '%d' ), array( '%d' ) );

			$a->same( null, \Serve_Dashboard\Placements::catchall_team(), 'retiring it takes the job away' );

			// And an unmatched profile then gets no placement row at all.
			$id = $f->submission( array( 'suggested_teams' => array() ) );
			$wpdb->delete( Schema::table( 'placements' ), array( 'submission_id' => $id ), array( '%d' ) );

			$a->same(
				0,
				\Serve_Dashboard\Placements::assign_catchall( $id, array() ),
				'so nobody is assigned rather than a closed team'
			);
		} finally {
			$wpdb->update( Schema::table( 'teams' ), array( 'is_active' => 1 ), array( 'id' => (int) $team->id ), array( '%d' ), array( '%d' ) );

			if ( false === $before ) {
				delete_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM );
			} else {
				update_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, $before );
			}
		}
	}
);

test(
	'ensuring a placement twice reports success both times',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The table has a unique key on (submission_id, team_id), so a second
		 * insert fails at the database rather than duplicating. That protects
		 * the data but not the answer: without the early return, the second call
		 * reports failure for a row that exists, and assign_catchall() would
		 * then log that nobody was assigned when somebody was.
		 */
		$id   = $f->submission();
		$team = \Serve_Dashboard\Teams::get_by_slug( 'prayer' );

		global $wpdb;
		$wpdb->delete( Schema::table( 'placements' ), array( 'submission_id' => $id ), array( '%d' ) );

		$a->ok( \Serve_Dashboard\Placements::ensure( $id, (int) $team->id ), 'created the first time' );
		$a->ok( \Serve_Dashboard\Placements::ensure( $id, (int) $team->id ), 'and still true the second time' );

		$a->same(
			1,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					'SELECT COUNT(*) FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d AND team_id = %d',
					$id,
					(int) $team->id
				)
			),
			'with one row to show for it'
		);
	}
);

test(
	'a fresh install gets the catch-all, a cleared one does not',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The seeding migration only runs on upgrade, so an install that has
		 * never had the option set is not the same as one where somebody chose
		 * "Nobody". Collapsing the two gave a brand new church no catch-all at
		 * all -- the opposite of the default this ships with.
		 */
		$before = get_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, false );

		try {
			// Never set: a fresh install.
			delete_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM );
			$team = \Serve_Dashboard\Placements::catchall_team();

			$a->ok( null !== $team, 'an install that has never been configured still has one' );
			$a->same(
				\Serve_Dashboard\Placements::DEFAULT_CATCHALL_TEAM,
				$team->slug,
				'and it is the shipped default'
			);

			// Deliberately cleared: nobody.
			update_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, '' );
			$a->same( null, \Serve_Dashboard\Placements::catchall_team(), 'choosing nobody is respected' );
		} finally {
			if ( false === $before ) {
				delete_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM );
			} else {
				update_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, $before );
			}
		}
	}
);
