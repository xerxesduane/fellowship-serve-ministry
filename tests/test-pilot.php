<?php
/**
 * Pilot measurement.
 *
 * The report covers every profile on the install, so these assert on the
 * difference a fixture makes rather than on absolute totals — otherwise they
 * would pass or fail according to whatever else happens to be in the database.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Pilot;
use Serve_Dashboard\Privacy;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Submissions;
use Serve_Dashboard\Teams;

/** The count for one stage in the progression table. */
function stage_count( array $report, string $status ): int {
	foreach ( $report['progression'] as $stage ) {
		if ( $status === $stage['status'] ) {
			return (int) $stage['count'];
		}
	}

	return -1;
}

test(
	'an unconfirmed profile counts as shared but not as confirmed',
	function ( Assert $a, Fixtures $f ) {
		$before = Pilot::report();

		$f->submission();          // never confirms their address
		$f->verified_submission();

		$after = Pilot::report();

		$a->same( $before['reach']['shared'] + 2, $after['reach']['shared'], 'both count as shared' );
		$a->same( $before['reach']['confirmed'] + 1, $after['reach']['confirmed'], 'only one as confirmed' );
		$a->same( $before['reach']['waiting'] + 1, $after['reach']['waiting'], 'the other is still waiting' );
	}
);

test(
	'everybody counts as having reached the first stage',
	function ( Assert $a, Fixtures $f ) {
		$before = Pilot::report();
		$f->verified_submission();
		$after = Pilot::report();

		// Submitted is set when the row is created, not through a status
		// change, so it leaves no audit trail. Counting only the audit would
		// give a funnel whose first bar was shorter than its second.
		$a->same(
			stage_count( $before, Schema::STATUS_SUBMITTED ) + 1,
			stage_count( $after, Schema::STATUS_SUBMITTED ),
			'a new confirmed profile has reached Submitted'
		);
		$a->same(
			$after['reach']['confirmed'],
			stage_count( $after, Schema::STATUS_SUBMITTED ),
			'and the first stage always equals the number confirmed'
		);
	}
);

test(
	'a stage passed through still counts once the person has moved on',
	function ( Assert $a, Fixtures $f ) {
		$before = Pilot::report();

		$id   = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$team = (int) Teams::get_by_slug( 'welcome' )->id;
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		Submissions::set_status( $id, Schema::STATUS_CONTACTED );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team );

		$after = Pilot::report();

		$a->same( Schema::STATUS_PLACED, Submissions::get( $id )->status, 'they end up Placed' );
		$a->same( stage_count( $before, Schema::STATUS_CONTACTED ) + 1, stage_count( $after, Schema::STATUS_CONTACTED ), 'Contacted still counts them' );
		$a->same( stage_count( $before, Schema::STATUS_PLACED ) + 1, stage_count( $after, Schema::STATUS_PLACED ), 'so does Placed' );
	}
);

/*
 * The defect this file exists to prevent recurring.
 *
 * The audit trail outlives the people in it, deliberately — it records the
 * deletion itself. The first version of the progression count read every audit
 * row regardless of whether the submission still existed, while the denominator
 * counted only live profiles, and reported sixty-five people reaching Trial
 * serve out of seven.
 */
test(
	'an erased profile leaves the figures, rather than haunting them',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$before = Pilot::report();

		$id   = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$team = (int) Teams::get_by_slug( 'welcome' )->id;
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team );

		$a->same( stage_count( $before, Schema::STATUS_PLACED ) + 1, stage_count( Pilot::report(), Schema::STATUS_PLACED ), 'counted while they exist' );

		Privacy::erase_submission( $id );

		// The audit rows are still there; the report must not read them.
		$audit = Schema::table( 'audit' );
		$a->ok(
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE object_id = %d", $id ) ) > 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'their audit trail survives, as designed'
		);

		$after = Pilot::report();
		$a->same( stage_count( $before, Schema::STATUS_PLACED ), stage_count( $after, Schema::STATUS_PLACED ), 'but the count is back where it started' );
		$a->same( $before['reach']['confirmed'], $after['reach']['confirmed'], 'and so is the denominator' );
	}
);

test(
	'somebody confirmed and untouched is counted as waiting',
	function ( Assert $a, Fixtures $f ) {
		$before = Pilot::report();

		$id = $f->verified_submission();
		$a->same( $before['overlooked']['count'] + 1, Pilot::report()['overlooked']['count'], 'waiting for a first response' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		Submissions::set_status( $id, Schema::STATUS_CONTACTED );

		$a->same( $before['overlooked']['count'], Pilot::report()['overlooked']['count'], 'and no longer, once acted on' );
	}
);

test(
	'a median from too few people says so',
	function ( Assert $a, Fixtures $f ) {
		$report = Pilot::report();

		$a->ok( is_int( $report['speed']['sample'] ), 'the sample size is always reported' );

		/*
		 * Checked at the boundary rather than against this install's data. The
		 * first version of this test compared the report's own `enough` flag to
		 * its own sample size, which passes on any install where the sample is
		 * already large — hardcoding the flag to true left it green.
		 */
		$a->not( Pilot::is_enough( 0 ), 'nothing is not enough' );
		$a->not( Pilot::is_enough( 3 ), 'three is not enough for a median' );
		$a->not( Pilot::is_enough( 4 ), 'nor four' );
		$a->ok( Pilot::is_enough( 5 ), 'five is the threshold' );
		$a->ok( Pilot::is_enough( 50 ), 'and anything above it' );

		$a->same(
			Pilot::is_enough( (int) $report['speed']['sample'] ),
			(bool) $report['speed']['enough'],
			'and the report uses that rule rather than deciding separately'
		);

		if ( 0 === $report['speed']['sample'] ) {
			$a->same( null, $report['speed']['median'], 'no median from nothing' );
		} else {
			$a->ok( null !== $report['speed']['median'], 'a median once there is anything to measure' );
		}
	}
);

/*
 * Four of the deck's five success questions are about how something felt.
 * Printing them beside the figures is the difference between a report and a
 * scoreboard, and it is the sort of thing that quietly disappears in a tidy-up.
 */
test(
	'the questions the figures cannot answer are still listed',
	function ( Assert $a, Fixtures $f ) {
		$questions = Pilot::unanswerable();

		$a->ok( count( $questions ) >= 4, 'all four are there' );

		foreach ( $questions as $question ) {
			$a->ok( str_ends_with( trim( $question ), '?' ), 'each one is a question to ask somebody' );
		}
	}
);

/*
 * Friction.
 *
 * The deck asks the pilot to capture what is not working, and the figures
 * above cannot: they count what happened, not whether it made sense.
 */
test(
	'any leader can report friction, and nobody else can',
	function ( Assert $a, Fixtures $f ) {
		$request = function ( string $body, string $area = 'suggestion' ) {
			$r = new \WP_REST_Request( 'POST', '/serve/v1/friction' );
			$r->set_header( 'content-type', 'application/json' );
			$r->set_body( (string) wp_json_encode( array( 'area' => $area, 'body' => $body ) ) );

			return rest_do_request( $r );
		};

		wp_set_current_user( 0 );
		$a->same( 401, $request( 'anonymous should not manage this' )->get_status(), 'anonymous is refused' );

		// Deliberately the widest capability in the plugin: the people who hit
		// the problems are the ones who must be able to say so.
		wp_set_current_user( $f->user( Roles::ROLE_LEADER ) );
		$a->same( 200, $request( 'Suggested Worship for someone whose gifts are pastoral.' )->get_status(), 'a ministry leader can' );
	}
);

test(
	'an empty report is refused and an unknown area is filed rather than lost',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$a->ok( is_wp_error( \Serve_Dashboard\Friction::record( 'suggestion', '   ' ) ), 'whitespace is not a report' );

		$before = \Serve_Dashboard\Friction::total();
		$a->same( true, \Serve_Dashboard\Friction::record( 'not-a-real-area', 'Filed somewhere sensible.' ), 'an unknown area still records' );
		$a->same( $before + 1, \Serve_Dashboard\Friction::total(), 'rather than being dropped' );

		$a->same( 'general', \Serve_Dashboard\Friction::recent( 1 )[0]['area'], 'under general' );
	}
);

/*
 * Same rule as conversation notes: the audit trail accounts for access, it is
 * not a second copy of everything anybody wrote.
 */
test(
	'what somebody wrote does not end up in the audit trail',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$secret = 'Particular wording ' . wp_generate_password( 8, false );
		\Serve_Dashboard\Friction::record( 'finding', $secret );

		$audit = Schema::table( 'audit' );
		$rows  = (string) wp_json_encode(
			$wpdb->get_results( "SELECT action, meta_json FROM {$audit} ORDER BY id DESC LIMIT 5" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$a->contains( 'friction.recorded', $rows, 'that a report was left is recorded' );
		$a->lacks( $secret, $rows, 'what it said is not' );
	}
);
