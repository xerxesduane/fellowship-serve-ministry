<?php
/**
 * Email verification: the thing that makes an anonymous write endpoint safe.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Schema;
use Serve_Dashboard\Submissions;
use Serve_Dashboard\Verification;

/** Force wp_mail() to fail, run something, restore. */
function with_broken_mail( callable $fn ) {
	$saved = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
	unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
	add_filter( 'pre_wp_mail', '__return_false', 99 );

	try {
		return $fn();
	} finally {
		unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
		if ( null !== $saved ) {
			$GLOBALS['wp_filter']['pre_wp_mail'] = $saved;
		}
	}
}

/** Capture the raw token out of the email body. */
function with_captured_mail( callable $fn ): array {
	$captured = array();

	$saved = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
	unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
	add_filter(
		'pre_wp_mail',
		static function ( $null, $atts ) use ( &$captured ) {
			$captured[] = $atts;

			return true;
		},
		10,
		2
	);

	try {
		$fn();
	} finally {
		unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
		if ( null !== $saved ) {
			$GLOBALS['wp_filter']['pre_wp_mail'] = $saved;
		}
	}

	return $captured;
}

function token_from( string $body ): string {
	preg_match( '/serve_verify=([a-f0-9]{64})/', $body, $m );

	return $m[1] ?? '';
}

test(
	'a new submission is unverified and invisible until confirmed',
	function ( Assert $a, Fixtures $f ) {
		$id  = $f->submission();
		$row = Submissions::get( $id );

		$a->same( null, $row->verified_at, 'verified_at starts null' );
		$a->not( in_array( $id, wp_list_pluck( Submissions::query(), 'id' ), false ), 'absent from the leader list' );
	}
);

test(
	'confirming works once and only once',
	function ( Assert $a, Fixtures $f ) {
		$mail = with_captured_mail(
			static function () use ( $f ) {
				$f->submission();
			}
		);

		$a->same( 1, count( $mail ), 'one confirmation email' );
		$token = token_from( (string) $mail[0]['message'] );
		$a->same( 64, strlen( $token ), 'the email carries a 64-character token' );

		$a->same( Verification::RESULT_OK, Verification::consume( $token ), 'first use confirms' );
		$a->same( Verification::RESULT_INVALID, Verification::consume( $token ), 'replay is refused' );
	}
);

test(
	'only the hash of a token is stored',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$mail = with_captured_mail(
			static function () use ( $f ) {
				$f->submission();
			}
		);

		$token = token_from( (string) $mail[0]['message'] );
		$table = Schema::table( 'submissions' );

		// A database dump must not let anyone confirm somebody else's address.
		$a->same(
			'0',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE verify_token = %s", $token ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'the raw token appears nowhere in the table'
		);
		$a->same(
			'1',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE verify_token = %s", hash( 'sha256', $token ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'its hash does'
		);
	}
);

test(
	'a garbled token is rejected without a database round trip',
	function ( Assert $a, Fixtures $f ) {
		$a->same( Verification::RESULT_INVALID, Verification::consume( 'not-hex' ), 'non-hex refused' );
		$a->same( Verification::RESULT_INVALID, Verification::consume( str_repeat( 'a', 63 ) ), 'wrong length refused' );
		$a->same( Verification::RESULT_INVALID, Verification::consume( '' ), 'empty refused' );
	}
);

/*
 * The regression this suite exists for. A host with broken SMTP used to accept
 * a submission, tell the person to check their email, send nothing, record it
 * as sent, and let the purge delete their work a week later — with no trace
 * anywhere that any of it had happened.
 */
test(
	'a failed confirmation email is recorded, not assumed sent',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$id = with_broken_mail(
			static function () use ( $f ) {
				return $f->submission();
			}
		);

		$row = Submissions::get( $id );
		$a->same( null, $row->verify_sent_at, 'verify_sent_at is not set when the mail failed' );
		$a->ok( ! empty( $row->verify_token ), 'the token survives so it can be sent again' );

		$audit = Schema::table( 'audit' );
		$a->same(
			'1',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit} WHERE object_id = %d AND action = 'verification.mail_failed'", $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'the failure is in the audit trail'
		);

		$a->ok( Verification::unsent_count() >= 1, 'it is counted as unsent' );
	}
);

test(
	'an unsent confirmation is held back from the purge',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		$id = with_broken_mail(
			static function () use ( $f ) {
				return $f->submission();
			}
		);

		// Old enough to be swept, had it ever been emailed.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET submitted_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY ) WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Verification::purge_unverified();

		$a->ok( null !== Submissions::get( $id ), 'nobody loses their work because our mailer was down' );
	}
);

test(
	'the purge window runs from when the email went, not when the journey ended',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		$id = with_broken_mail(
			static function () use ( $f ) {
				return $f->submission();
			}
		);

		// A fortnight of broken SMTP, then somebody fixes it and resends.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET submitted_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 14 DAY ) WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = with_captured_mail(
			static function () {
				Verification::resend_unsent();
			}
		);

		$a->ok( count( $result ) >= 1, 'the resend emails them' );
		$a->same( 0, Verification::unsent_count(), 'nothing left unsent' );

		Verification::purge_unverified();
		$a->ok(
			null !== Submissions::get( $id ),
			'their link is not deleted the morning after it finally arrived'
		);
	}
);

test(
	'a confirmation that was genuinely sent and ignored is still purged',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		$id = $f->submission();

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET verify_sent_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY ) WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Verification::purge_unverified();

		$a->same( null, Submissions::get( $id ), 'the retention promise still holds for everyone else' );
	}
);
