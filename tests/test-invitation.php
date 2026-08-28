<?php
/**
 * Inviting somebody, and letting them answer.
 *
 * Two separable pieces sharing a token: the leader choosing how to make
 * contact, and the person answering for themselves. The second is the only
 * place in the whole product where the person has any say.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Invitation;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Submissions;

/** Force wp_mail() to fail, run something, restore. */
function with_dead_mailer( callable $fn ) {
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

/** Capture the invitation and hand back the raw token from its link. */
function invite_and_capture( int $id ): string {
	$token = '';

	$saved = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
	unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
	add_filter(
		'pre_wp_mail',
		static function ( $null, $atts ) use ( &$token ) {
			if ( preg_match( '/serve_invite=([a-f0-9]{64})/', (string) $atts['message'], $m ) ) {
				$token = $m[1];
			}

			return true;
		},
		10,
		2
	);

	try {
		$draft = Invitation::draft( Submissions::get( $id ) );
		Invitation::send( $id, $draft['subject'], $draft['body'] );
	} finally {
		unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
		if ( null !== $saved ) {
			$GLOBALS['wp_filter']['pre_wp_mail'] = $saved;
		}
	}

	return $token;
}

test(
	'the draft is addressed to the person and names a suggested team',
	function ( Assert $a, Fixtures $f ) {
		$id    = $f->verified_submission( array( 'display_name' => 'Amira Haddad', 'suggested_teams' => array( 'welcome' ) ) );
		$draft = Invitation::draft( Submissions::get( $id ) );

		$a->contains( 'Amira', $draft['body'], 'uses their first name' );
		$a->contains( 'Welcome', $draft['body'], 'and mentions the suggested team' );
		$a->ok( '' !== trim( $draft['subject'] ), 'there is a subject' );
	}
);

test(
	'a leader can take it on themselves without anything being sent',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		// If this ever starts sending, the mailer being dead would fail it.
		$done = with_dead_mailer(
			static fn() => Invitation::record_personal( $id )
		);

		$a->same( true, $done, 'recorded' );

		$row = Submissions::get( $id );
		$a->same( Invitation::METHOD_PERSONAL, $row->invite_method, 'as a personal contact' );
		$a->same( null, $row->invite_token, 'with no link to anywhere' );
	}
);

/*
 * Same rule as the confirmation email: a row claiming to have invited somebody
 * it never reached is worse than one that admits nothing went.
 */
test(
	'a failed invitation records nobody as invited',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$result = with_dead_mailer(
			static function () use ( $id ) {
				$draft = Invitation::draft( Submissions::get( $id ) );

				return Invitation::send( $id, $draft['subject'], $draft['body'] );
			}
		);

		$a->ok( is_wp_error( $result ), 'the leader is told' );
		$a->same( null, Submissions::get( $id )->invited_at, 'and nothing claims to have gone' );
	}
);

test(
	'an invitation needs something to say',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$a->ok( is_wp_error( Invitation::send( $id, '', 'body' ) ), 'no subject is refused' );
		$a->ok( is_wp_error( Invitation::send( $id, 'subject', '   ' ) ), 'no message is refused' );
	}
);

/*
 * The half the deck actually asks for: system suggests, leader confirms,
 * person chooses. Until this existed "Declined" was something a leader typed
 * on somebody else's behalf.
 */
test(
	'the person answers for themselves, once',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$token = invite_and_capture( $id );
		$a->same( 64, strlen( $token ), 'the email carries a link' );

		// Nobody is logged in when somebody opens their own email.
		wp_set_current_user( 0 );

		$a->same( true, Invitation::respond( $token, Invitation::RESPONSE_YES ), 'they can answer' );
		$a->same( Invitation::RESPONSE_YES, Submissions::get( $id )->invite_response, 'and it is recorded' );

		$a->ok( is_wp_error( Invitation::respond( $token, Invitation::RESPONSE_YES ) ), 'the link works once' );
	}
);

test(
	'saying yes does not move them along by itself',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$token = invite_and_capture( $id );

		$before = Submissions::get( $id )->status;
		wp_set_current_user( 0 );
		Invitation::respond( $token, Invitation::RESPONSE_YES );

		// Willing to talk is not the same as having talked, and a leader
		// deciding that is the whole point of the middle step.
		$a->same( $before, Submissions::get( $id )->status, 'the stage is unchanged' );
	}
);

test(
	'"not right now" pauses rather than declines, and carries their words',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$token = invite_and_capture( $id );

		wp_set_current_user( 0 );
		Invitation::respond( $token, Invitation::RESPONSE_NOT_NOW, 'Travelling until October.' );

		$row = Submissions::get( $id );

		// Not the same thing as declining, and the difference matters to
		// whoever reads this next.
		$a->same( Schema::STATUS_PAUSED, $row->status, 'paused' );
		$a->ok( (bool) $row->snooze_until, 'with a date to come back' );
		$a->same( 'Travelling until October.', $row->invite_note, 'and what they said' );
	}
);

test(
	'a junk or expired link is refused',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( 0 );

		$a->same( null, Invitation::find( 'not-hex' ), 'non-hex' );
		$a->same( null, Invitation::find( str_repeat( 'a', 64 ) ), 'well-formed but unknown' );
		$a->ok( is_wp_error( Invitation::respond( str_repeat( 'b', 64 ), Invitation::RESPONSE_YES ) ), 'and answering with one fails' );
	}
);

test(
	'an unrecognised answer is refused',
	function ( Assert $a, Fixtures $f ) {
		$id = $f->verified_submission();
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$token = invite_and_capture( $id );

		wp_set_current_user( 0 );
		$a->ok( is_wp_error( Invitation::respond( $token, 'maybe' ) ), 'refused' );
		$a->same( null, Submissions::get( $id )->invite_response, 'and nothing recorded' );
	}
);
