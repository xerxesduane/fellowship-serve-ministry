<?php
/**
 * Who can see what.
 *
 * Three separate promises, each of which would fail quietly: unverified rows
 * are invisible to everyone, a ministry leader sees only their own teams, and
 * the Experiences section is redacted without `serve_view_sensitive`.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Roles;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Submissions;

test(
	'an unverified submission is invisible even to an administrator',
	function ( Assert $a, Fixtures $f ) {
		$id    = $f->submission(); // unconfirmed
		$admin = $f->user( 'administrator' );

		wp_set_current_user( $admin );

		$a->not( Roles::can_view_submission( $id ), 'can_view_submission says no' );
		$a->not(
			in_array( $id, wp_list_pluck( Submissions::query(), 'id' ), false ),
			'absent from the list'
		);
		$a->not(
			in_array( $id, wp_list_pluck( Submissions::query( array( 'include_snoozed' => true ) ), 'id' ), false ),
			'absent even when snoozed rows are included'
		);
	}
);

test(
	'a verified submission is visible to a pastor',
	function ( Assert $a, Fixtures $f ) {
		$id     = $f->verified_submission();
		$pastor = $f->user( Roles::ROLE_PASTOR );

		wp_set_current_user( $pastor );

		$a->ok( Roles::can_view_submission( $id ), 'a pastor sees everything verified' );
	}
);

test(
	'a ministry leader sees only submissions suggested to their own team',
	function ( Assert $a, Fixtures $f ) {
		$mine     = $f->verified_submission( array( 'suggested_teams' => array( 'worship' ) ) );
		$not_mine = $f->verified_submission( array( 'suggested_teams' => array( 'production' ) ) );

		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'worship' );

		wp_set_current_user( $leader );

		$a->ok( Roles::can_view_submission( $mine ), 'their own team' );
		$a->not( Roles::can_view_submission( $not_mine ), 'somebody else\'s team' );

		$ids = wp_list_pluck( Submissions::query(), 'id' );
		$a->ok( in_array( $mine, $ids, false ), 'their own team is listed' );
		$a->not( in_array( $not_mine, $ids, false ), 'the other team is not' );
	}
);

test(
	'a leader who leads nothing sees nothing',
	function ( Assert $a, Fixtures $f ) {
		$f->verified_submission();
		$leader = $f->user( Roles::ROLE_LEADER );

		wp_set_current_user( $leader );

		$a->same( array(), Submissions::query(), 'an empty scope is empty, not unscoped' );
	}
);

test(
	'Experiences are redacted without serve_view_sensitive',
	function ( Assert $a, Fixtures $f ) {
		$id  = $f->verified_submission();
		$row = Submissions::get( $id );

		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'welcome' );
		wp_set_current_user( $leader );

		$a->not( current_user_can( Roles::CAP_VIEW_SENSITIVE ), 'a leader does not hold the capability' );

		$profile = Submissions::profile( $row, false );
		$a->not( isset( $profile['experiences'] ), 'experiences are gone' );
		$a->same( array( 'experiences' ), $profile['_redacted'] ?? null, 'and the redaction is declared, not silent' );
		$a->lacks( 'A bereavement', (string) wp_json_encode( $profile ), 'nothing of it survives anywhere in the payload' );
	}
);

test(
	'a pastor sees Experiences, and the read is audited',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$id  = $f->verified_submission();
		$row = Submissions::get( $id );

		$pastor = $f->user( Roles::ROLE_PASTOR );
		wp_set_current_user( $pastor );

		$a->ok( current_user_can( Roles::CAP_VIEW_SENSITIVE ), 'a pastor holds the capability' );

		$profile = Submissions::profile( $row, true );
		$a->ok( isset( $profile['experiences'] ), 'experiences are present' );

		$audit = Schema::table( 'audit' );
		$a->same(
			'1',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE object_id = %d AND action = 'submission.sensitive_viewed'", $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'looking at somebody\'s painful experiences leaves a trace'
		);
	}
);

test(
	'a logged-out visitor can view nothing',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();

		wp_set_current_user( 0 );

		$a->not( Roles::can_view_submission( $id ), 'anonymous sees nothing' );
		$a->same( array(), Submissions::query(), 'and lists nothing' );
	}
);

/*
 * A regression found by running the suite on a fresh install, where no team has
 * been given a leader yet. An unassigned team's leader_user_id was 0, and a
 * logged-out caller is user 0, so "the teams you lead" matched all sixteen.
 * This is the state every church is in on their first day.
 */
test(
	'an unassigned team does not make everyone its leader',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;
		$teams = Schema::table( 'teams' );

		$saved = $wpdb->get_results( "SELECT id, leader_user_id FROM {$teams}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "UPDATE {$teams} SET leader_user_id = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		try {
			$f->verified_submission();

			wp_set_current_user( 0 );
			$a->same( array(), Roles::visible_team_ids(), 'anonymous leads no teams' );
			$a->same( array(), Submissions::query(), 'and is shown no profiles' );

			// Nor does a logged-in user who simply has no team.
			$nobody = $f->user( 'subscriber' );
			wp_set_current_user( $nobody );
			$a->same( array(), Roles::visible_team_ids(), 'a subscriber leads no teams' );
			$a->same( array(), Submissions::query(), 'and is shown no profiles' );
		} finally {
			foreach ( $saved as $team ) {
				$wpdb->update(
					$teams,
					array( 'leader_user_id' => $team->leader_user_id ),
					array( 'id' => (int) $team->id ),
					array( null === $team->leader_user_id ? null : '%d' ),
					array( '%d' )
				);
			}
		}
	}
);
