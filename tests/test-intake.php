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
		delete_transient( 'serve_rl_' . ( Privacy::hash_ip() ?: 'unknown' ) );
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
