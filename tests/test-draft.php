<?php
/**
 * Saved drafts.
 *
 * The one surface where somebody's unfinished S.H.A.P.E. answers leave their
 * device and can be fetched back, over a public route with no login, by anyone
 * holding a 64-character token. Every promise the feature makes — single use, a
 * 30-day window, a rate limit, deletion once the profile is submitted — was
 * enforced by code no test had ever run.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Draft;
use Serve_Dashboard\Privacy;
use Serve_Dashboard\Schema;

/**
 * Save a draft without letting a real email leave the building, and hand back
 * the token from the link the mailer was given.
 */
function serve_draft_save( string $email, array $answers = array(), int $step = 5 ): array {
	$captured = '';

	$intercept = static function ( $null, $atts ) use ( &$captured ) {
		$captured = (string) ( $atts['message'] ?? '' );

		return true;
	};

	add_filter( 'pre_wp_mail', $intercept, 10, 2 );
	$result = Draft::save( $email, $answers, $step );
	remove_filter( 'pre_wp_mail', $intercept, 10 );

	preg_match( '/serve_resume=([a-f0-9]{64})/', $captured, $m );

	return array(
		'result' => $result,
		'token'  => $m[1] ?? '',
	);
}

/** Clear both rate-limit buckets so a test is not throttled by its neighbours. */
function serve_draft_unthrottle( string $email ): void {
	delete_transient( 'serve_draft_rl_' . ( Privacy::hash_ip() ?: 'unknown' ) );
	delete_transient( 'serve_draft_rl_to_' . hash( 'sha256', strtolower( $email ) ) );
}

test(
	'a saved draft comes back exactly as it went in, once',
	function ( Assert $a, Fixtures $f ) {
		$email = 'draft-' . wp_generate_password( 8, false ) . '@serve.test';
		serve_draft_unthrottle( $email );

		$answers = array(
			'gifts'      => array( 'mercy' => 'likely', 'teaching' => 'unlikely' ),
			'selections' => array( 'heart-roles' => array( 'organize' ) ),
		);

		$saved = serve_draft_save( $email, $answers, 12 );

		$a->not( is_wp_error( $saved['result'] ), 'the draft saves' );
		$a->same( 64, strlen( $saved['token'] ), 'and the emailed link carries a token' );

		$resumed = Draft::resume( $saved['token'] );

		$a->not( is_wp_error( $resumed ), 'which resumes' );
		$a->same( $answers, $resumed['answers'], 'with the answers unchanged' );
		$a->same( 12, $resumed['step'], 'and the step they were on' );

		/*
		 * Single use. The answers are back on the person's device, and a
		 * resume link forwarded in a family WhatsApp thread should not keep
		 * handing them out.
		 */
		$a->ok( is_wp_error( Draft::resume( $saved['token'] ) ), 'and not a second time' );

		serve_draft_unthrottle( $email );
	}
);

test(
	'a stranger cannot destroy somebody else\'s saved draft',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The route is public and cannot prove who owns the address that was
		 * typed. It used to delete every existing draft for that address before
		 * inserting, so one POST naming somebody else destroyed their saved
		 * journey — and their emailed link then hashed to no row and told them
		 * it had "already been used, or has expired". Nineteen steps gone, with
		 * a message blaming them for it.
		 */
		$victim = 'victim-' . wp_generate_password( 8, false ) . '@serve.test';
		serve_draft_unthrottle( $victim );

		$theirs = serve_draft_save( $victim, array( 'gifts' => array( 'mercy' => 'likely' ) ), 17 );
		$a->not( is_wp_error( $theirs['result'] ), 'the victim saves their place' );

		// Somebody else posts the same address with nothing in it.
		$attack = serve_draft_save( $victim, array(), 0 );
		$a->not( is_wp_error( $attack['result'] ), 'a second save for that address is accepted' );

		// And the first link still works, with the first answers.
		$resumed = Draft::resume( $theirs['token'] );

		$a->not( is_wp_error( $resumed ), 'the original link still resumes' );
		$a->same( 17, $resumed['step'], 'at the step they had reached' );
		$a->same( array( 'gifts' => array( 'mercy' => 'likely' ) ), $resumed['answers'], 'with their own answers' );

		Draft::clear_for_email( $victim );
		serve_draft_unthrottle( $victim );
	}
);

test(
	'one address cannot be used to keep the mailer pointed at an inbox',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * Saving emails a link, so this endpoint can be made to send mail to
		 * any address a caller types. The per-IP ceiling does not protect the
		 * person at the other end — an attacker moving between addresses stays
		 * under it — so the ceiling that matters is keyed on the address.
		 */
		$target = 'inbox-' . wp_generate_password( 8, false ) . '@serve.test';
		serve_draft_unthrottle( $target );

		$sent    = 0;
		$refused = 0;

		for ( $i = 0; $i < 6; $i++ ) {
			$attempt = serve_draft_save( $target, array( 'i' => $i ), $i );

			if ( is_wp_error( $attempt['result'] ) ) {
				++$refused;
				$a->same( 'serve_rate_limited', $attempt['result']->get_error_code(), 'refused for the right reason' );
				continue;
			}

			++$sent;
		}

		$a->ok( $sent > 0, 'a person can save their place' );
		$a->ok( $refused > 0, 'but not without limit to one address' );
		$a->ok( $sent <= 3, 'and the ceiling is low enough to matter' );

		Draft::clear_for_email( $target );
		serve_draft_unthrottle( $target );
	}
);

test(
	'saving repeatedly does not grow the table without limit',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * Saves no longer replace each other, which is what keeps a stranger
		 * from destroying a draft. Something else therefore has to stop one
		 * address accumulating rows for ever.
		 */
		$email = 'many-' . wp_generate_password( 8, false ) . '@serve.test';
		serve_draft_unthrottle( $email );

		for ( $i = 0; $i < 8; $i++ ) {
			serve_draft_save( $email, array( 'i' => $i ), $i );
			serve_draft_unthrottle( $email );
		}

		$held = Draft::count_for_email( $email );

		$a->ok( $held > 1, 'more than one link can be live at a time' );
		$a->ok( $held <= 5, 'but not more than the cap' );

		Draft::clear_for_email( $email );
		serve_draft_unthrottle( $email );
	}
);

test(
	'an expired draft is refused and removed rather than served',
	function ( Assert $a, Fixtures $f ) {
		$email = 'stale-' . wp_generate_password( 8, false ) . '@serve.test';
		serve_draft_unthrottle( $email );

		$saved = serve_draft_save( $email, array( 'gifts' => array( 'mercy' => 'likely' ) ), 4 );
		$a->same( 64, strlen( $saved['token'] ), 'saved' );

		global $wpdb;
		$table = Schema::table( 'drafts' );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"UPDATE {$table} SET expires_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 DAY ) WHERE token_hash = %s",
				hash( 'sha256', $saved['token'] )
			)
		);

		$resumed = Draft::resume( $saved['token'] );

		$a->ok( is_wp_error( $resumed ), 'an expired link is refused' );
		$a->same( 'serve_draft_expired', $resumed->get_error_code(), 'and says which of the two it is' );

		// And the row goes, rather than sitting there holding somebody's
		// answers past the window they were promised.
		$a->same( 0, Draft::count_for_email( $email ), 'the row is gone with it' );

		serve_draft_unthrottle( $email );
	}
);

test(
	'a malformed token is refused without touching the database',
	function ( Assert $a, Fixtures $f ) {
		foreach ( array( '', 'x', 'not-hex-at-all', str_repeat( 'z', 64 ), str_repeat( 'a', 63 ) ) as $bad ) {
			$result = Draft::resume( $bad );

			$a->ok( is_wp_error( $result ), 'refused: ' . ( '' === $bad ? '(empty)' : substr( $bad, 0, 12 ) ) );
			$a->same( 'serve_draft_invalid', $result->get_error_code(), 'as unreadable rather than as missing' );
		}
	}
);

test(
	'submitting the finished profile clears the draft behind it',
	function ( Assert $a, Fixtures $f ) {
		$email = 'finished-' . wp_generate_password( 8, false ) . '@serve.test';
		serve_draft_unthrottle( $email );

		serve_draft_save( $email, array( 'gifts' => array( 'mercy' => 'likely' ) ), 18 );
		$a->ok( Draft::count_for_email( $email ) > 0, 'a draft exists while the journey is unfinished' );

		Draft::clear_for_email( $email );

		$a->same( 0, Draft::count_for_email( $email ), 'and none once the profile is submitted' );

		serve_draft_unthrottle( $email );
	}
);
