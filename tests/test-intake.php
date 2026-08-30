<?php
/**
 * What the public submission endpoint accepts.
 *
 * Name, email and phone are all required now. The assessment enforces that in
 * the browser, which is a convenience for the person filling it in and no
 * obstacle at all to anything posting straight at the endpoint — so the rules
 * that matter are these, and these are the ones worth testing.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Matching;
use Serve_Dashboard\Privacy;
use Serve_Dashboard\Schema;

/** A complete, valid payload; override one field at a time to test a rule. */
function intake_payload( array $overrides = array() ): array {
	return array_merge(
		array(
			'consent'       => true,
			'display_name'  => 'Intake Test',
			'email'         => 'intake-' . wp_generate_password( 8, false ) . '@serve.test',
			'phone'         => '+971 50 123 4567',
			'tenure_months' => 12,
			'profile'       => array(
				'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
				'contact'        => array( 'name' => 'Intake Test', 'email' => 'leaked@serve.test', 'phone' => '000' ),
			),
		),
		$overrides
	);
}

/**
 * POST a payload the way a browser would.
 *
 * Each call gets its own client IP so the five-per-hour throttle, which is a
 * real rule and not one these tests are trying to exercise, never fires.
 *
 * Any row that results is handed to the fixtures, including from a call that
 * was supposed to be rejected. Normally there is nothing to collect — that is
 * the point of the assertion — but when a rule is broken the endpoint starts
 * accepting what it should refuse, and a test that only inspects the error code
 * would leave those rows sitting in the leaders' queue. A run that catches a
 * regression should not also litter.
 */
function post_submission( array $payload, ?Fixtures $fixtures = null ): \WP_REST_Response {
	static $n = 0;
	++$n;

	$ip = '198.51.100.' . ( $n % 250 + 1 );
	$filter = static fn() => $ip;
	add_filter( 'serve_dashboard_client_ip', $filter );

	$request = new \WP_REST_Request( 'POST', '/serve/v1/submissions' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $payload ) );

	try {
		$response = rest_do_request( $request );

		if ( $fixtures && isset( $payload['email'] ) ) {
			global $wpdb;
			$table = Schema::table( 'submissions' );
			$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $payload['email'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $id ) {
				$fixtures->adopt( $id );
			}
		}

		return $response;
	} finally {
		/*
		 * Clear the throttle bucket while the filter is still in place. Removing
		 * it first computes the hash of the real (empty) address instead, so the
		 * per-IP counters survive, and the suite starts returning 429 after
		 * enough runs — passing today and failing on Thursday for no visible
		 * reason. A fresh CI database would never have shown it.
		 */
		$hash = Privacy::hash_ip() ?: 'unknown';
		foreach ( array( 'submit', 'preview' ) as $bucket ) {
			delete_transient( 'serve_rl_' . $bucket . '_' . $hash );
		}
		remove_filter( 'serve_dashboard_client_ip', $filter );
	}
}

function error_code( \WP_REST_Response $response ): string {
	$data = $response->get_data();

	return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
}

test(
	'a complete submission is accepted and keeps all three contact details',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$payload  = intake_payload();
		$response = post_submission( $payload, $f );

		$a->same( 201, $response->get_status(), 'accepted' );

		$table = Schema::table( 'submissions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $payload['email'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$a->ok( null !== $row, 'the row exists' );
		$f->adopt( (int) $row->id );

		$a->same( 'Intake Test', $row->display_name, 'name stored' );
		$a->same( $payload['email'], $row->email, 'email stored' );
		$a->same( '+971 50 123 4567', $row->phone, 'phone stored, formatting and all' );
	}
);

test(
	'a phone number is required',
	function ( Assert $a, Fixtures $f ) {
		$a->same( 'serve_phone_required', error_code( post_submission( intake_payload( array( 'phone' => '' ) ), $f ) ), 'empty is refused' );
		$a->same( 'serve_phone_required', error_code( post_submission( intake_payload( array( 'phone' => '   ' ) ), $f ) ), 'whitespace is not a phone number' );

		$missing = intake_payload();
		unset( $missing['phone'] );
		$a->same( 'rest_missing_callback_param', error_code( post_submission( $missing, $f ) ), 'omitting it entirely is refused' );
	}
);

test(
	'an unusable phone number is refused',
	function ( Assert $a, Fixtures $f ) {
		foreach ( array( '12345', '+1', 'call me', 'x' ) as $bad ) {
			$a->same( 'serve_bad_phone', error_code( post_submission( intake_payload( array( 'phone' => $bad ) ), $f ) ), "\"$bad\" is refused" );
		}
	}
);

test(
	'the numbers this congregation actually types are accepted',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		// Dubai, UK, India, Philippines, and a local landline. No single format
		// covers a congregation whose first languages span six countries.
		foreach ( array( '+971 50 123 4567', '0501234567', '+44 7700 900123', '+91-98765-43210', '(04) 123 4567' ) as $good ) {
			$payload  = intake_payload( array( 'phone' => $good ) );
			$response = post_submission( $payload, $f );

			$a->same( 201, $response->get_status(), "\"$good\" is accepted" );

			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $payload['email'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $id ) {
				$f->adopt( $id );
			}
		}
	}
);

test(
	'name and email are still required, and email must be plausible',
	function ( Assert $a, Fixtures $f ) {
		$a->same( 'serve_name_required', error_code( post_submission( intake_payload( array( 'display_name' => '  ' ) ), $f ) ), 'blank name refused' );
		$a->same( 'serve_bad_email', error_code( post_submission( intake_payload( array( 'email' => 'amina@' ) ), $f ) ), 'half an address refused' );
	}
);

/*
 * The journey carries the contact details inside the profile so the consent
 * page can prefill them. They must not survive into profile_json as well: two
 * copies of the same personal data drift apart and the second one is easy to
 * miss when erasing somebody.
 */
test(
	'contact details are not duplicated inside the stored profile',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$payload  = intake_payload();
		$response = post_submission( $payload, $f );
		$a->same( 201, $response->get_status(), 'accepted' );

		$table = Schema::table( 'submissions' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, profile_json FROM {$table} WHERE email = %s", $payload['email'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$f->adopt( (int) $row->id );

		$stored = json_decode( (string) $row->profile_json, true );

		$a->not( isset( $stored['contact'] ), 'no contact block in profile_json' );
		// The payload deliberately carried a different address in that block.
		$a->lacks( 'leaked@serve.test', (string) $row->profile_json, 'and nothing from it leaked through' );
	}
);

/*
 * The public suggestion preview.
 *
 * The assessment showed a person its own top three, ranked on spiritual-gift
 * name overlap in the browser, while their leader saw every team ranked across
 * all five dimensions. Two answers to the same question, and the person's was
 * the one printed on the profile they downloaded. This endpoint lets the
 * assessment ask the same code the dashboard uses.
 *
 * It is unauthenticated, so what it must not do matters as much as what it does.
 */
function serve_preview( array $profile ) {
	$request = new \WP_REST_Request( 'POST', '/serve/v1/suggestions' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( (string) wp_json_encode( array( 'profile' => $profile ) ) );

	return rest_get_server()->dispatch( $request );
}

test(
	'a person can be shown the same ranking their leader will see',
	function ( Assert $a, Fixtures $f ) {
		// Logged out: this is the public assessment, not the dashboard.
		wp_set_current_user( 0 );

		/*
		 * Three mapped likely gifts, so this clears the gift-only strong route.
		 * The abilities and personality are here to prove they travel nowhere
		 * and change nothing, not because the match needs them.
		 */
		$profile = Fixtures::gift_profile(
			array( 'administration', 'leadership', 'wisdom' ),
			array(),
			array(
				'abilities'   => array( 'Counting ability', 'Classifying ability', 'Editing ability' ),
				'personality' => array( 'Be Introverted', 'Prefer Routine' ),
				'experiences' => array( 'painful' => array( 'A bereavement' ) ),
				'contact'     => array( 'email' => 'someone@example.test' ),
			)
		);

		$response = serve_preview( $profile );
		$a->same( 200, $response->get_status(), 'the endpoint answers a logged-out caller' );

		$data = $response->get_data();
		$a->ok( ! empty( $data['suggestions'] ), 'and returns suggestions' );
		$a->same( 'ready', $data['state'], 'with an explicit state rather than a bare array' );

		$teams = array_column( $data['suggestions'], 'team' );
		$a->ok( in_array( 'Administration', $teams, true ), 'the team the gifts point to is among them' );

		// The same answer the dashboard gives for the same profile.
		$ranked = array_column( Matching::suggestions_for_profile( $profile ), 'team_name' );
		$a->same( $ranked, $teams, 'identical to what a leader is shown' );

		// And nothing weaker travels to either of them.
		foreach ( $data['suggestions'] as $suggestion ) {
			$a->ok(
				in_array( $suggestion['tier'], array( 'strong', 'suggested' ), true ),
				'every suggestion cleared the bar'
			);
		}

		/*
		 * The reasons name gifts, not free text.
		 *
		 * They used to quote abilities back, which meant an editable keyword
		 * field could put words on this page — and, worse, carry a team to a
		 * strength that granted its leader the profile. Gifts are the only
		 * scored evidence now, so they are the only thing a reason can cite.
		 */
		$reasons = implode( ' | ', $data['suggestions'][0]['reasons'] );
		$a->contains( 'Administration', $reasons, 'with readable reasons naming the actual gifts' );
		$a->lacks( 'Counting ability', $reasons, 'and nothing drawn from free-text abilities' );

		// Personality is no longer returned at all: it never influenced a team,
		// and the results page already has the person's own answers.
		$a->not( isset( $data['personality'] ), 'personality does not travel back' );
	}
);

test(
	'the preview stores nothing and is given no way to identify anybody',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;
		wp_set_current_user( 0 );

		$before_submissions = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'submissions' ) );
		$before_audit       = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit' ) );

		$secret = 'Particular-' . wp_generate_password( 10, false );

		$response = serve_preview(
			array(
				'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
				// Things the browser might send that this must simply drop.
				'displayName'    => $secret,
				'email'          => $secret . '@example.com',
				'availability'   => array( 'priority' => $secret ),
			)
		);

		$a->same( 200, $response->get_status(), 'it still answers' );

		$a->same(
			$before_submissions,
			(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'submissions' ) ),
			'no submission row is created'
		);
		$a->same(
			$before_audit,
			(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit' ) ),
			'and nothing is written to the audit trail'
		);

		// Nothing it was handed comes back out, and nothing it ignored is quoted.
		$a->lacks( $secret, (string) wp_json_encode( $response->get_data() ), 'and it echoes none of it back' );
	}
);

test(
	'the preview ignores every sensitive section, whatever a client sends',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( 0 );

		/*
		 * The browser used to send heart, abilities, experiences and
		 * personality here — before the consent step, where the person decides
		 * whether the church may hold any of it. The Experiences section holds
		 * painful history and every "Other" free-text answer they typed, and it
		 * was travelling so a team name could be previewed.
		 *
		 * The browser no longer sends it. This asserts the endpoint refuses it
		 * too, because "our client does not send that" is not a property of the
		 * endpoint and anything at all can post here.
		 */
		$secret = 'Particular-' . wp_generate_password( 10, false );

		$bare = serve_preview(
			Fixtures::gift_profile( array( 'faith', 'discernment', 'mercy' ) )
		)->get_data();

		$loaded = serve_preview(
			Fixtures::gift_profile(
				array( 'faith', 'discernment', 'mercy' ),
				array(),
				array(
					'experiences' => array( 'What painful experiences have shaped you?' => array( $secret ) ),
					'heart'       => array( 'roles' => array( $secret ), 'people' => array(), 'causes' => array() ),
					'abilities'   => array( $secret ),
					'personality' => array( 'Be Introverted' ),
					'contact'     => array( 'name' => $secret, 'email' => $secret . '@example.test' ),
				)
			)
		)->get_data();

		$a->same(
			(string) wp_json_encode( $bare ),
			(string) wp_json_encode( $loaded ),
			'the answer is byte-identical with and without every sensitive section'
		);
		$a->lacks( $secret, (string) wp_json_encode( $loaded ), 'and none of it is quoted back' );
	}
);

test(
	'reading your own results cannot use up your one chance to submit',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( 0 );

		/*
		 * Previews and submissions shared one counter in the first version of
		 * this endpoint. Six views of a results page — trivially reached by
		 * reloading — spent the five-submission allowance, and the person was
		 * then refused when they tried to share the profile. The cheap
		 * repeatable request must not be able to lock somebody out of the
		 * important one.
		 *
		 * Asserted behaviourally, by actually submitting afterwards. The first
		 * version of this test checked the value of a transient it named
		 * itself, and passed against a build where the buckets were merged
		 * again under a third name — it was reading a key nothing used. What
		 * matters is not where the counters live but that a person who read
		 * their results can still share their profile.
		 */
		$ip     = '198.51.100.251';
		$filter = static fn() => $ip;
		add_filter( 'serve_dashboard_client_ip', $filter );

		try {
			$hash = \Serve_Dashboard\Privacy::hash_ip() ?: 'unknown';
			foreach ( array( 'submit', 'preview', 'shared' ) as $bucket ) {
				delete_transient( 'serve_rl_' . $bucket . '_' . $hash );
			}

			$profile = array( 'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ) );

			// More previews than the submission allowance, from one address.
			for ( $i = 0; $i < 8; $i++ ) {
				$a->same( 200, serve_preview( $profile )->get_status(), "preview {$i} is allowed" );
			}

			$email   = 'lockout-' . wp_generate_password( 8, false ) . '@serve.test';
			$request = new \WP_REST_Request( 'POST', '/serve/v1/submissions' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( intake_payload( array( 'email' => $email ) ) ) );
			$response = rest_do_request( $request );

			// Adopted by address, as post_submission() does: the endpoint does
			// not return an id, and a row left behind sits in a leader's queue.
			global $wpdb;
			$table = Schema::table( 'submissions' );
			$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $id ) {
				$f->adopt( $id );
			}

			$a->not( 429 === $response->get_status(), 'the submission is not throttled' );
			$a->lacks( 'rate_limited', error_code( $response ), 'and not refused for reading their results' );
		} finally {
			$hash = \Serve_Dashboard\Privacy::hash_ip() ?: 'unknown';
			foreach ( array( 'submit', 'preview', 'shared' ) as $bucket ) {
				delete_transient( 'serve_rl_' . $bucket . '_' . $hash );
			}
			remove_filter( 'serve_dashboard_client_ip', $filter );
		}
	}
);

test(
	'an oversized profile is refused rather than walked',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( 0 );

		$hash = \Serve_Dashboard\Privacy::hash_ip() ?: 'unknown';
		delete_transient( 'serve_rl_preview_' . $hash );

		// Comfortably past the 64KB ceiling.
		$response = serve_preview(
			array(
				'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
				'abilities'      => array_fill( 0, 4000, str_repeat( 'a', 40 ) ),
			)
		);

		$a->same( 413, $response->get_status(), 'it is refused on size' );
		$a->same( 'serve_profile_too_large', error_code( $response ), 'and says why' );

		delete_transient( 'serve_rl_preview_' . $hash );
	}
);

test(
	'the browser cannot choose which teams may see a profile',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * This replaces a test of the opposite guarantee, and the reversal is
		 * the point.
		 *
		 * `suggested_teams` used to be built from `recommendedMinistries`, which
		 * the browser computed and posted. So a hand-crafted submission could
		 * name whichever teams it liked and get placement rows on them —
		 * choosing which ministry leaders could open the profile. Bounded, since
		 * you could only ever expose your own answers, but it was the client
		 * deciding an access-control question.
		 *
		 * The server ranks the answers now and the field is not even in the
		 * profile whitelist, so what the browser claims is discarded before
		 * anything reads it. Asserted by posting a profile that names teams its
		 * answers do not support and watching them fail to appear.
		 */
		$email = 'ranked-' . wp_generate_password( 8, false ) . '@serve.test';

		$response = post_submission(
			intake_payload(
				array(
					'email'   => $email,
					'profile' => Fixtures::gift_profile(
						// Answers that genuinely point at Administration.
						array( 'administration', 'leadership', 'wisdom' ),
						array(),
						array(
							'abilities' => array( 'Counting ability', 'Classifying ability', 'Editing ability' ),

							// And a claim to three teams they say nothing about,
							// in every shape a client has ever used to make one.
							'recommendedMinistries' => array(
								array( 'ministry' => 'Youth Ministry', 'matchedGifts' => array() ),
								array( 'ministry' => 'Production', 'matchedGifts' => array() ),
								array( 'ministry' => 'Livestream Team', 'matchedGifts' => array() ),
							),
							'rankedTeams'   => array( array( 'team' => 'Youth Ministry' ) ),
							'matchSnapshot' => array(
								'teams' => array( array( 'team_slug' => 'youth-ministry', 'tier' => 'strong' ) ),
							),
						)
					),
				)
			),
			$f
		);

		$a->same( 201, $response->get_status(), 'the profile is accepted' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT id, suggested_teams, profile_json, match_snapshot FROM ' . Schema::table( 'submissions' ) . ' WHERE email = %s',
				$email
			)
		);
		$a->ok( null !== $row, 'and stored' );

		$stored = \Serve_Dashboard\Submissions::decode_list( $row->suggested_teams );

		$a->not( in_array( 'youth-ministry', $stored, true ), 'the team it asked for is not there' );
		$a->not( in_array( 'production', $stored, true ), 'nor the second' );
		$a->not( in_array( 'livestream-team', $stored, true ), 'nor the third' );
		$a->ok( in_array( 'administration', $stored, true ), 'the team its answers point at is' );

		/*
		 * What is shown, stored and placed agree.
		 *
		 * This used to assert the count equalled the suggestion limit, which was
		 * only ever a proxy for agreement and stopped being true the moment
		 * suggestions were restricted to strong matches: a profile now gets as
		 * many as it earns, up to that limit. Compared against the suggestions
		 * themselves instead, which is the guarantee the proxy stood in for.
		 */
		$posted  = json_decode( (string) $row->profile_json, true ) ?: array();
		$shown   = \Serve_Dashboard\Matching::suggestions_for_profile( $posted );
		$expected = array_column( $shown, 'team_slug' );
		sort( $expected );
		$sorted_stored = $stored;
		sort( $sorted_stored );

		$a->same( $expected, $sorted_stored, 'stored is exactly what the person was shown' );
		$a->ok(
			count( $stored ) <= \Serve_Dashboard\Matching::suggestion_limit(),
			'and never more than the limit allows'
		);

		// The claim is not kept either — it is not in the whitelist at all.
		$profile = json_decode( (string) $row->profile_json, true );
		$a->not( isset( $profile['recommendedMinistries'] ), 'the posted list is not stored' );
		$a->not( isset( $profile['rankedTeams'] ), 'nor any other ranking the browser invents' );
		$a->not( isset( $profile['matchSnapshot'] ), 'nor a snapshot it tried to supply' );

		/*
		 * And the stored snapshot is the server's own answer, not theirs.
		 *
		 * This is the stronger version of the same guarantee: the snapshot is
		 * what the dashboard and the list both read, so a client able to write
		 * it would be choosing what every leader is told about this person.
		 */
		$snapshot = json_decode( (string) $row->match_snapshot, true ) ?: array();
		$snap_slugs = array_column( (array) ( $snapshot['teams'] ?? array() ), 'team_slug' );
		$a->not( in_array( 'youth-ministry', $snap_slugs, true ), 'the forged snapshot team is absent' );
		$a->ok( in_array( 'administration', $snap_slugs, true ), 'and the earned one is present' );

		/*
		 * No placement row follows from any of it.
		 *
		 * These used to be created one per suggested team, which is what made
		 * the browser's claim an access-control question in the first place.
		 * Nothing is created from a ranking now — only the central intake
		 * owner, so a coordinator can read the profile and decide.
		 */
		$sources = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT source FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d',
				(int) $row->id
			)
		);
		$a->not(
			in_array( \Serve_Dashboard\Placements::SOURCE_MATCH, (array) $sources, true ),
			'no placement row is sourced to the matcher'
		);
		$a->same(
			array( \Serve_Dashboard\Placements::SOURCE_CATCHALL ),
			array_values( array_unique( (array) $sources ) ),
			'only the central intake owner exists'
		);

		// And a leader of a team it tried to claim still cannot open them.
		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'verified_at' => current_time( 'mysql', true ), 'verify_token' => null ),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$leader = $f->user( \Serve_Dashboard\Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'youth-ministry' );
		wp_set_current_user( $leader );

		$a->not(
			\Serve_Dashboard\Roles::can_view_submission( (int) $row->id ),
			'the leader of the claimed team is refused'
		);
	}
);

test(
	'a hostile profile shape is refused quietly, not logged',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( 0 );

		$hash = \Serve_Dashboard\Privacy::hash_ip() ?: 'unknown';
		delete_transient( 'serve_rl_preview_' . $hash );

		/*
		 * Found by probing the endpoint with the payloads nobody designs for.
		 *
		 * An array where a string belongs used to reach a string cast, so PHP
		 * emitted "Array to string conversion" once per value. On any site with
		 * WP_DEBUG_LOG enabled — which is most of them at some point — a
		 * 200-byte unauthenticated request could grow the log without limit.
		 * Cheap, remote, and invisible until the disk filled.
		 *
		 * Both layers were fixed: the endpoint pins the shapes it accepts, and
		 * the matcher skips non-scalars whatever its caller hands it.
		 */
		$diagnostics = array();
		set_error_handler(
			static function ( $number, $message ) use ( &$diagnostics ) {
				$diagnostics[] = $message;
				return true;
			}
		);

		try {
			$shapes = array(
				'arrays where strings belong' => array(
					'spiritualGifts' => array( 'likely' => array( array( 'Mercy' ) ) ),
					'heart'          => array( 'roles' => array( array( 'a' => array( 'b' ) ) ) ),
					'abilities'      => array( array( 'Counting ability' ) ),
					'experiences'    => array( 'x' => array( array( 'nested' ) ) ),
					'personality'    => array( array( 'Be Introverted' ) ),
				),
				'scalars of the wrong type'   => array(
					'spiritualGifts' => 'not-an-array',
					'heart'          => 42,
					'abilities'      => true,
					'experiences'    => null,
					'personality'    => 3.14,
				),
			);

			foreach ( $shapes as $label => $profile ) {
				delete_transient( 'serve_rl_preview_' . $hash );
				$diagnostics = array();

				$response = serve_preview( $profile );

				$a->same( 200, $response->get_status(), "{$label}: answered rather than crashing" );
				$a->same( 0, count( $diagnostics ), "{$label}: and provoked no PHP diagnostic" );
			}

			// A real profile still works once the guards are in place.
			delete_transient( 'serve_rl_preview_' . $hash );
			$diagnostics = array();
			$good        = serve_preview(
				array(
					'spiritualGifts' => array( 'likely' => array( 'Administration', 'Leadership', 'Wisdom' ) ),
					'abilities'      => array( 'Counting ability', 'Classifying ability' ),
				)
			);

			$a->ok( ! empty( $good->get_data()['suggestions'] ), 'and a valid profile is still ranked' );
			$a->same( 0, count( $diagnostics ), 'quietly' );
		} finally {
			restore_error_handler();
			delete_transient( 'serve_rl_preview_' . $hash );
		}
	}
);

test(
	'a profile with no gift overlap hands no team access to it',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * recommendMinistries() used to pad its list up to three with Serve,
		 * Welcome and Administration when fewer than three ministries scored
		 * above zero. What it returns becomes suggested_teams, and the placement
		 * rows built from that column are what decide which ministry leaders may
		 * open a profile — so three names invented to reach a round number were
		 * handing three teams access to somebody's pastoral profile on no
		 * evidence at all.
		 *
		 * An empty list is now what arrives, and exactly one team is given
		 * access on purpose: the configured catch-all, so somebody owns the
		 * first conversation rather than leaving it to pastors alone. That row
		 * is marked as a catch-all and is not a suggestion -- the profile still
		 * records that nothing matched.
		 *
		 * Asserted with the setting on and then off, because the difference
		 * between "one team, deliberately" and "any team, accidentally" is the
		 * whole point, and only the second run proves the first was a choice.
		 */
		$email = 'nogifts-' . wp_generate_password( 8, false ) . '@serve.test';

		$response = post_submission(
			intake_payload(
				array(
					'email'   => $email,
					// Every one of the eighteen gifts answered, all of them
					// "unlikely" — the real shape of somebody who matched
					// nothing, rather than a profile that simply omits them.
					'profile' => Fixtures::gift_profile( array() ),
				)
			),
			$f
		);

		$a->same( 201, $response->get_status(), 'the profile is still accepted' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT id, suggested_teams, safeguarding_status FROM ' . Schema::table( 'submissions' ) . ' WHERE email = %s',
				$email
			)
		);
		$a->ok( null !== $row, 'and stored' );

		$a->same(
			array(),
			\Serve_Dashboard\Submissions::decode_list( $row->suggested_teams ),
			'with no suggested teams rather than three invented ones'
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input.
				'SELECT p.source, t.slug FROM ' . Schema::table( 'placements' ) . ' p'
				. ' INNER JOIN ' . Schema::table( 'teams' ) . ' t ON t.id = p.team_id'
				. ' WHERE p.submission_id = %d',
				(int) $row->id
			)
		);

		$a->same( 1, count( $rows ), 'exactly one team gains access, not none and not several' );
		$a->same( 'welcome', $rows[0]->slug, 'and it is the configured catch-all' );
		$a->same(
			\Serve_Dashboard\Placements::SOURCE_CATCHALL,
			$rows[0]->source,
			'recorded as a catch-all, so nothing can read it as a match'
		);

		// Nothing was named, so nothing required a background check.
		$a->same(
			\Serve_Dashboard\Safeguarding::STATUS_NOT_REQUIRED,
			$row->safeguarding_status,
			'and no background check is claimed to be required'
		);

		/*
		 * Confirm the address before asking who can see them. A fresh
		 * submission is deliberately invisible to everybody, pastors included,
		 * until the email is proven — so testing visibility on an unconfirmed
		 * row measures the verification gate rather than the placement rows,
		 * which is what the first version of this test did.
		 */
		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'verified_at' => current_time( 'mysql', true ), 'verify_token' => null ),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$welcome_leader = $f->user( \Serve_Dashboard\Roles::ROLE_LEADER );
		$f->lead_team( $welcome_leader, 'welcome' );
		wp_set_current_user( $welcome_leader );

		$a->ok(
			\Serve_Dashboard\Roles::can_view_submission( (int) $row->id ),
			'the catch-all team can open them, which is the point of it'
		);

		// And nobody else. One team owns the conversation, not every team.
		$other = $f->user( \Serve_Dashboard\Roles::ROLE_LEADER );
		$f->lead_team( $other, 'youth-ministry' );
		wp_set_current_user( $other );

		$a->not(
			\Serve_Dashboard\Roles::can_view_submission( (int) $row->id ),
			'a leader of any other team still cannot' 
		);

		/*
		 * The part that keeps this safe. A pastor sees everyone, and the
		 * dashboard sorts people nobody has spoken to to the top of its first
		 * band, so an unmatched profile is waiting in front of somebody rather
		 * than lost.
		 */
		wp_set_current_user( $f->user( \Serve_Dashboard\Roles::ROLE_PASTOR ) );

		$a->ok(
			\Serve_Dashboard\Roles::can_view_submission( (int) $row->id ),
			'but a pastor can'
		);
		$a->ok(
			in_array( (int) $row->id, array_map( 'intval', wp_list_pluck( \Serve_Dashboard\Submissions::query( array( 'limit' => 200, 'orderby' => 'waiting' ) ), 'id' ) ), true ),
			'and they appear in the queue a pastor works from'
		);

		/*
		 * With no catch-all configured, the old behaviour returns exactly: no
		 * team at all, and the conversation belongs to pastors. This is what
		 * makes the row above a decision rather than a leak.
		 */
		$before = get_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, false );

		try {
			update_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, '' );

			$second = 'nocatch-' . wp_generate_password( 8, false ) . '@serve.test';
			$a->same(
				201,
				post_submission(
					intake_payload(
						array(
							'email'   => $second,
							'profile' => array( 'spiritualGifts' => array( 'likely' => array() ) ),
						)
					),
					$f
				)->get_status(),
				'a second unmatched profile is accepted'
			);

			$second_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					'SELECT id FROM ' . Schema::table( 'submissions' ) . ' WHERE email = %s',
					$second
				)
			);

			$a->same(
				0,
				(int) $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
						'SELECT COUNT(*) FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d',
						$second_id
					)
				),
				'and with no catch-all set, no team gains access at all'
			);
		} finally {
			if ( false === $before ) {
				delete_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM );
			} else {
				update_option( \Serve_Dashboard\Placements::OPTION_CATCHALL_TEAM, $before );
			}
		}
	}
);
