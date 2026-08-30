<?php
/**
 * Routing, authorisation and intake integrity.
 *
 * The matcher sits on top of this. A recommendation that is merely wrong costs
 * somebody a conversation; a recommendation that grants access, or a transition
 * that half-succeeds, costs somebody their privacy or leaves a record asserting
 * something that never happened. These are the tests for the second kind.
 *
 * Needs a real WordPress and a real database: every guarantee here is a SQL
 * predicate or a capability check, and none of them would survive being mocked.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Admin;
use Serve_Dashboard\Matching;
use Serve_Dashboard\Placements;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Safeguarding;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Submissions;
use Serve_Dashboard\Teams;

test(
	'a recommendation creates no placement row and grants no leader access',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		// A profile that genuinely reaches a strong gift match for Prayer.
		$profile = Fixtures::gift_profile( array( 'faith', 'discernment', 'mercy' ) );

		$suggested = Matching::slugs_for_profile( $profile );
		$a->ok( in_array( 'prayer', $suggested, true ), 'the ranking does suggest Prayer' );

		$id = $f->verified_submission(
			array(
				'suggested_teams' => $suggested,
				'profile'         => $profile,
			)
		);

		global $wpdb;
		$table = Schema::table( 'placements' );

		$match_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT COUNT(*) FROM {$table} WHERE submission_id = %d AND source = %s",
				$id,
				Placements::SOURCE_MATCH
			)
		);

		$a->same( 0, $match_rows, 'no placement row is created from the ranking' );

		// And the leader of the suggested team cannot open the profile.
		$prayer = Teams::get_by_slug( 'prayer' );
		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'prayer' );

		wp_set_current_user( $leader );
		$a->not(
			Roles::can_view_submission( $id ),
			'and the suggested team\'s leader still cannot read the profile'
		);
	}
);

test(
	'every submission reaches central intake, whatever its tier',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$profiles = array(
			'strong'    => Fixtures::gift_profile( array( 'faith', 'discernment', 'mercy' ) ),
			'suggested' => Fixtures::gift_profile( array( 'faith', 'discernment' ) ),
			'explore'   => Fixtures::gift_profile( array( 'mercy' ) ),
			'none'      => Fixtures::gift_profile( array() ),
		);

		global $wpdb;
		$table = Schema::table( 'placements' );

		foreach ( $profiles as $label => $profile ) {
			$id = $f->verified_submission(
				array(
					'suggested_teams' => Matching::slugs_for_profile( $profile ),
					'profile'         => $profile,
				)
			);

			$owner = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					"SELECT COUNT(*) FROM {$table} WHERE submission_id = %d AND source = %s",
					$id,
					Placements::SOURCE_CATCHALL
				)
			);

			$a->same( 1, $owner, "a $label profile gets exactly one central intake owner" );

			// A pastor sees them regardless, which is what "central" means.
			$a->ok( Roles::can_view_submission( $id ), "and a pastor can read the $label profile" );
		}
	}
);

test(
	'an ordinary leader cannot move somebody onto a team they do not lead',
	function ( Assert $a, Fixtures $f ) {
		$welcome = Teams::get_by_slug( 'welcome' );
		$serve   = Teams::get_by_slug( 'serve' );

		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'welcome' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id = $f->verified_submission();

		// Give them a real assignment on Welcome, so the leader can see them.
		Placements::ensure( $id, (int) $welcome->id, Placements::SOURCE_INTAKE_TRIAGE );

		wp_set_current_user( $leader );
		$a->ok( Roles::can_view_submission( $id ), 'the leader can see this person through their own team' );

		// The other team is not theirs, and seeing the person is not permission
		// to move them onto it.
		$result = Submissions::set_status( $id, Schema::STATUS_PLACED, (int) $serve->id );

		$a->ok( is_wp_error( $result ), 'placing them on another ministry\'s team is refused' );
		$a->same( 'serve_team_forbidden', $result->get_error_code(), 'and refused for the right reason' );

		$after = Submissions::get( $id );
		$a->not( Schema::STATUS_PLACED === $after->status, 'and the submission status did not move' );
	}
);

test(
	'a nonexistent or inactive team fails before anything changes',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id     = $f->verified_submission();
		$before = Submissions::get( $id );

		// An id belonging to no row at all. This used to pass the safeguarding
		// gate — block_reason() returns "" for a team it cannot load — update
		// the submission, and write a placement pointing at nothing.
		$result = Submissions::set_status( $id, Schema::STATUS_PLACED, 999999 );
		$a->ok( is_wp_error( $result ), 'a fabricated team id is refused' );

		$after = Submissions::get( $id );
		$a->same( $before->status, $after->status, 'and the status is untouched' );

		$orphans = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT COUNT(*) FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d AND team_id = %d',
				$id,
				999999
			)
		);
		$a->same( 0, $orphans, 'and no placement row points at a team that does not exist' );

		// A deactivated team must not acquire new people either.
		$events = Teams::get_by_slug( 'events' );
		$wpdb->update( Schema::table( 'teams' ), array( 'is_active' => 0 ), array( 'id' => (int) $events->id ), array( '%d' ), array( '%d' ) );

		$closed = Submissions::set_status( $id, Schema::STATUS_PLACED, (int) $events->id );
		$a->ok( is_wp_error( $closed ), 'a closed team cannot be assigned to' );
		$a->not( Placements::ensure( $id, (int) $events->id ), 'and no row can be created for it' );

		$wpdb->update( Schema::table( 'teams' ), array( 'is_active' => 1 ), array( 'id' => (int) $events->id ), array( '%d' ), array( '%d' ) );
	}
);

test(
	'the drawer exposes only placements the caller may act on',
	function ( Assert $a, Fixtures $f ) {
		$welcome = Teams::get_by_slug( 'welcome' );
		$serve   = Teams::get_by_slug( 'serve' );

		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'welcome' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id = $f->verified_submission();
		Placements::ensure( $id, (int) $welcome->id, Placements::SOURCE_INTAKE_TRIAGE );
		Placements::ensure( $id, (int) $serve->id, Placements::SOURCE_PASTOR_ASSIGNED );

		$a->same( 2, count( Admin::placements_for( $id ) ), 'a pastor sees the whole picture' );

		wp_set_current_user( $leader );
		$scoped = Admin::placements_for( $id );
		$names  = array_column( $scoped, 'team_name' );

		$a->same( 1, count( $scoped ), 'a ministry leader sees only their own' );
		$a->ok( in_array( $welcome->name, $names, true ), 'their team is there' );
		$a->not( in_array( $serve->name, $names, true ), 'and the other ministry is not' );
	}
);

test(
	'routing to a safeguarded team raises the requirement and cannot stay not_required',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		// Nothing safeguarded was suggested, so intake set not_required.
		$id = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$a->same(
			Safeguarding::STATUS_NOT_REQUIRED,
			Submissions::get( $id )->safeguarding_status,
			'it starts as not required'
		);

		$kids = Teams::get_by_slug( 'fellowship-kids' );

		// The manual route that used to slip through: the gate reads
		// safeguarding_status, which described a different question.
		$result = Submissions::set_status( $id, Schema::STATUS_PLACED, (int) $kids->id );

		$a->ok( is_wp_error( $result ), 'the move is blocked' );
		$a->same( 'serve_safeguarding_blocked', $result->get_error_code(), 'by the safeguarding gate' );

		$after = Submissions::get( $id );
		$a->same( Safeguarding::STATUS_REQUIRED, $after->safeguarding_status, 'and the requirement is now recorded' );
		$a->not(
			Safeguarding::STATUS_NOT_REQUIRED === $after->safeguarding_status,
			'so the controls to resolve it are no longer hidden'
		);
	}
);

test(
	'a raised safeguarding requirement is never downgraded automatically',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id = $f->verified_submission();

		foreach ( array( Safeguarding::STATUS_PENDING, Safeguarding::STATUS_CLEARED, Safeguarding::STATUS_REJECTED ) as $status ) {
			$wpdb->update(
				Schema::table( 'submissions' ),
				array( 'safeguarding_status' => $status ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);

			// Routing to an unsafeguarded team must not clear a real answer.
			Submissions::set_status( $id, Schema::STATUS_CONTACTED, (int) Teams::get_by_slug( 'welcome' )->id );

			$a->same(
				$status,
				Submissions::get( $id )->safeguarding_status,
				"a $status check survives being routed to an unsafeguarded team"
			);
		}
	}
);

test(
	'a failed placement write does not leave the submission claiming success',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id     = $f->verified_submission();
		$before = Submissions::get( $id );

		$welcome = Teams::get_by_slug( 'welcome' );

		/*
		 * Force the placement write to fail by renaming the table for the
		 * duration. Crude, and the only way to exercise a database failure
		 * without mocking $wpdb — which would stop this being a test of the
		 * real thing.
		 */
		$placements = Schema::table( 'placements' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input.
		$wpdb->query( "RENAME TABLE {$placements} TO {$placements}_hidden" );
		$wpdb->suppress_errors( true );

		$result = Submissions::set_status( $id, Schema::STATUS_PLACED, (int) $welcome->id );

		$wpdb->suppress_errors( false );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input.
		$wpdb->query( "RENAME TABLE {$placements}_hidden TO {$placements}" );

		$a->ok( is_wp_error( $result ), 'the caller is told it failed' );

		$after = Submissions::get( $id );
		$a->same( $before->status, $after->status, 'and the status was rolled back rather than left claiming Placed' );
	}
);

test(
	'candidate discovery is a SERVE-wide view, and says so to everybody else',
	function ( Assert $a, Fixtures $f ) {
		$kids = Teams::get_by_slug( 'fellowship-kids' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$profile = Fixtures::gift_profile( array( 'teaching', 'mercy', 'hospitality', 'encouragement' ) );
		$id      = $f->verified_submission(
			array(
				'display_name' => 'Ifeoma Balogun',
				'profile'      => $profile,
			)
		);

		$a->ok( Teams::can_discover_candidates(), 'a pastor may discover candidates' );
		$found = array_column( Teams::candidates( (int) $kids->id, 200 ), 'id' );
		$a->ok( in_array( $id, $found, true ), 'and finds the person whose gifts fit the team' );

		/*
		 * An ordinary leader gets nothing, and that is the documented
		 * behaviour rather than an accident. The query behind this view is
		 * scoped to people who already have a placement row on the caller's
		 * teams, so for a leader it could only ever return people already
		 * assigned to them — a circular answer that looked like a working
		 * feature. Answering it properly means reading profiles the leader has
		 * not been given, which is the disclosure the routing model exists to
		 * require a human decision for.
		 */
		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'fellowship-kids' );
		wp_set_current_user( $leader );

		$a->not( Teams::can_discover_candidates(), 'a ministry leader may not' );
		$a->same( array(), Teams::candidates( (int) $kids->id, 200 ), 'and gets an empty list they are told the reason for' );
		$a->not( Roles::can_view_submission( $id ), 'discovery never granted them the profile either' );
	}
);

test(
	'the list and the drawer cannot disagree, and neither leaks a redacted inference',
	function ( Assert $a, Fixtures $f ) {
		$welcome = Teams::get_by_slug( 'welcome' );
		$leader  = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'welcome' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		/*
		 * A profile whose painful Experience names a team's vocabulary. Under
		 * the old code the list decoded profile_json raw and ranked all of it,
		 * so this text could produce a team name in a column read by a leader
		 * forbidden to see the text itself.
		 */
		$profile = Fixtures::gift_profile(
			array( 'mercy' ),
			array(),
			array(
				'experiences' => array(
					'What painful experiences have shaped you?' => array( 'Homelessness', 'Other: a private matter' ),
				),
			)
		);

		$id = $f->verified_submission(
			array(
				'suggested_teams' => Matching::slugs_for_profile( $profile ),
				'profile'         => $profile,
			)
		);
		Placements::ensure( $id, (int) $welcome->id, Placements::SOURCE_INTAKE_TRIAGE );

		wp_set_current_user( $leader );

		/*
		 * An explicit page size. Leaving it out relied on the endpoint's
		 * default, and this test passed for a while only because the table
		 * happened to hold one person — the moment demo data existed, the row
		 * being looked for fell off a one-row page.
		 */
		$request = new \WP_REST_Request( 'GET', '/serve/v1/people' );
		$request->set_param( 'per_page', 100 );

		$response = \Serve_Dashboard\Rest_Dashboard::people( $request );
		$rows     = $response->get_data()['people'];

		$row = null;
		foreach ( $rows as $candidate ) {
			if ( (int) $candidate['id'] === $id ) {
				$row = $candidate;
			}
		}

		$a->ok( null !== $row, 'the leader can see the row' );

		// The redacted profile the same leader would get in the drawer.
		$redacted = Submissions::profile( Submissions::get( $id ), false );
		$a->not( isset( $redacted['experiences'] ), 'and cannot read the Experiences section' );

		// The teams named in the list come from the stored snapshot, which
		// holds no experience-derived anything.
		$encoded = wp_json_encode( $row );
		foreach ( array( 'Homelessness', 'a private matter' ) as $secret ) {
			$a->lacks( $secret, (string) $encoded, "the list row carries no trace of \"$secret\"" );
		}

		/*
		 * And what the list shows is what the snapshot recommends — the
		 * Strong and Suggested teams, not everything in it.
		 *
		 * A snapshot for somebody with no clear match still records the teams
		 * they were offered to explore with an advisor. Printing those in the
		 * same column as everyone else's strong matches would put the weakest
		 * results under the strongest heading, and would make `unmatched` false
		 * for exactly the people that flag exists to surface. Caught by this
		 * test: this profile has one likely gift, and the list was listing its
		 * two explore options as though they were recommendations.
		 */
		$snapshot    = json_decode( (string) Submissions::get( $id )->match_snapshot, true );
		$recommended = array_filter(
			(array) ( $snapshot['teams'] ?? array() ),
			static fn( $team ) => in_array( $team['tier'] ?? '', array( 'strong', 'suggested' ), true )
		);

		$a->same(
			array_values( array_column( $recommended, 'team_name' ) ),
			$row['suggestedTeams'],
			'the list names exactly the snapshot\'s recommended teams'
		);
		$a->ok( $row['unmatched'], 'and a profile with only explore options reads as unmatched' );
		$a->ok(
			count( (array) ( $snapshot['teams'] ?? array() ) ) > 0,
			'while the explore options themselves survive in the snapshot for the drawer'
		);
	}
);

test(
	'a new row records a human decision as its source, never a match',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id      = $f->verified_submission();
		$welcome = Teams::get_by_slug( 'welcome' );

		Submissions::set_status( $id, Schema::STATUS_CONTACTED, (int) $welcome->id );

		$source = (string) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT source FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d AND team_id = %d',
				$id,
				(int) $welcome->id
			)
		);

		$a->not( Placements::SOURCE_MATCH === $source, 'the row is not sourced to the matcher' );
		$a->ok( in_array( $source, Placements::assignment_sources(), true ), 'and names a decision somebody took' );

		// Even asked for explicitly, `match` is not an accepted source.
		$serve = Teams::get_by_slug( 'serve' );
		Placements::ensure( $id, (int) $serve->id, Placements::SOURCE_MATCH );

		$forced = (string) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT source FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d AND team_id = %d',
				$id,
				(int) $serve->id
			)
		);

		$a->not( Placements::SOURCE_MATCH === $forced, 'a caller cannot reintroduce it' );
	}
);

test(
	'the participant snapshot is stored server-side and ignores anything the client sends',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$profile = Fixtures::gift_profile(
			array( 'faith', 'discernment', 'mercy' ),
			array(),
			array(
				// A forged snapshot, exactly as a hostile client would send it.
				'matchSnapshot' => array(
					'teams' => array( array( 'team_slug' => 'fellowship-kids', 'tier' => 'strong' ) ),
				),
				'recommendedMinistries' => array( array( 'ministry' => 'Fellowship Kids' ) ),
			)
		);

		$id       = $f->verified_submission( array( 'profile' => $profile ) );
		$stored   = json_decode( (string) Submissions::get( $id )->match_snapshot, true );
		$slugs    = array_column( (array) ( $stored['teams'] ?? array() ), 'team_slug' );

		$a->ok( in_array( 'prayer', $slugs, true ), 'the snapshot is the server\'s own answer' );
		$a->not( in_array( 'fellowship-kids', $slugs, true ), 'and the forged team is absent' );
		$a->same( 'gift-v2', $stored['versions']['contract'], 'with the contract version it was produced under' );

		// And the forgery grants nothing.
		$kids   = Teams::get_by_slug( 'fellowship-kids' );
		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'fellowship-kids' );
		wp_set_current_user( $leader );

		$a->not( Roles::can_view_submission( $id ), 'a forged snapshot creates no access' );
	}
);
