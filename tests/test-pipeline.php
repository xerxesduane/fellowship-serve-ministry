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
