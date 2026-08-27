<?php
/**
 * The safeguarding gate.
 *
 * Of everything here this is the one where a silent regression has a
 * consequence that is not about data. It lives in the transition code rather
 * than the UI precisely so that posting the form directly cannot get round it,
 * which is exactly what these tests exercise.
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

test(
	'Fellowship Kids and Youth Ministry require a background check',
	function ( Assert $a, Fixtures $f ) {
		foreach ( array( 'fellowship-kids', 'youth-ministry' ) as $slug ) {
			$team = Teams::get_by_slug( $slug );
			$a->ok( (bool) $team->requires_safeguarding, "$slug is safeguarded" );
		}

		$a->not( (bool) Teams::get_by_slug( 'production' )->requires_safeguarding, 'Production is not' );
	}
);

test(
	'trial serve and placement are the gated statuses',
	function ( Assert $a, Fixtures $f ) {
		$a->ok( Safeguarding::is_gated_status( Schema::STATUS_TRIAL_SERVE ), 'trial serve is gated' );
		$a->ok( Safeguarding::is_gated_status( Schema::STATUS_PLACED ), 'placed is gated' );

		// Earlier stages are conversations, not access to children.
		$a->not( Safeguarding::is_gated_status( Schema::STATUS_SUBMITTED ), 'submitted is not' );
		$a->not( Safeguarding::is_gated_status( Schema::STATUS_CONTACTED ), 'contacted is not' );
		$a->not( Safeguarding::is_gated_status( Schema::STATUS_CONVERSATION_BOOKED ), 'conversation booked is not' );
	}
);

test(
	'an uncleared person cannot reach trial serve on a safeguarded team',
	function ( Assert $a, Fixtures $f ) {
		$id   = $f->verified_submission( array( 'suggested_teams' => array( 'fellowship-kids' ) ) );
		$team = (int) Teams::get_by_slug( 'fellowship-kids' )->id;

		$reason = Safeguarding::block_reason( $id, $team, Schema::STATUS_TRIAL_SERVE );
		$a->ok( '' !== $reason, 'the move is blocked' );
		$a->contains( 'Fellowship Kids', $reason, 'and says which team' );

		// Not just advisory: the transition itself must refuse.
		$pastor = $f->user( Roles::ROLE_PASTOR );
		wp_set_current_user( $pastor );

		$result = Submissions::set_status( $id, Schema::STATUS_TRIAL_SERVE, $team );
		$a->ok( is_wp_error( $result ), 'set_status refuses, so posting the form directly does not get round it' );
		$a->same( Schema::STATUS_SUBMITTED, Submissions::get( $id )->status, 'the status did not move' );
	}
);

test(
	'the same move is allowed once the check is cleared',
	function ( Assert $a, Fixtures $f ) {
		$id   = $f->verified_submission( array( 'suggested_teams' => array( 'fellowship-kids' ) ) );
		$team = (int) Teams::get_by_slug( 'fellowship-kids' )->id;

		$pastor = $f->user( Roles::ROLE_PASTOR );
		wp_set_current_user( $pastor );

		Safeguarding::set_status( $id, Safeguarding::STATUS_CLEARED );

		$a->same( '', Safeguarding::block_reason( $id, $team, Schema::STATUS_TRIAL_SERVE ), 'no longer blocked' );

		$result = Submissions::set_status( $id, Schema::STATUS_TRIAL_SERVE, $team );
		$a->not( is_wp_error( $result ), 'and the transition goes through' );
	}
);

test(
	'a pending or rejected check is not a cleared one',
	function ( Assert $a, Fixtures $f ) {
		$team   = (int) Teams::get_by_slug( 'youth-ministry' )->id;
		$pastor = $f->user( Roles::ROLE_PASTOR );
		wp_set_current_user( $pastor );

		foreach ( array( Safeguarding::STATUS_REQUIRED, Safeguarding::STATUS_PENDING, Safeguarding::STATUS_REJECTED ) as $status ) {
			$id = $f->verified_submission( array( 'suggested_teams' => array( 'youth-ministry' ) ) );
			Safeguarding::set_status( $id, $status );

			$a->ok(
				'' !== Safeguarding::block_reason( $id, $team, Schema::STATUS_PLACED ),
				"\"$status\" does not open the gate"
			);
		}
	}
);

test(
	'an unsafeguarded team is unaffected',
	function ( Assert $a, Fixtures $f ) {
		$id   = $f->verified_submission( array( 'suggested_teams' => array( 'production' ) ) );
		$team = (int) Teams::get_by_slug( 'production' )->id;

		$a->same( '', Safeguarding::block_reason( $id, $team, Schema::STATUS_PLACED ), 'no gate where none is needed' );
	}
);

test(
	'a blocked attempt is written to the audit trail',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$id   = $f->verified_submission( array( 'suggested_teams' => array( 'fellowship-kids' ) ) );
		$team = (int) Teams::get_by_slug( 'fellowship-kids' )->id;

		Safeguarding::block_reason( $id, $team, Schema::STATUS_PLACED );

		$audit = Schema::table( 'audit' );
		$a->ok(
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE object_id = %d AND action = 'safeguarding.blocked'", $id ) ) >= 1, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'somebody trying is itself worth recording'
		);
	}
);

test(
	'a submission suggested to a safeguarded team starts as check required',
	function ( Assert $a, Fixtures $f ) {
		$gated   = $f->submission( array( 'suggested_teams' => array( 'fellowship-kids' ) ) );
		$ungated = $f->submission( array( 'suggested_teams' => array( 'production' ) ) );

		$a->same( Safeguarding::STATUS_REQUIRED, Submissions::get( $gated )->safeguarding_status, 'required' );
		$a->same( Safeguarding::STATUS_NOT_REQUIRED, Submissions::get( $ungated )->safeguarding_status, 'not required' );
	}
);
