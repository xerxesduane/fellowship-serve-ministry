<?php
/**
 * Managing accounts, and the escalation rules around it.
 *
 * Pastors were given CAP_MANAGE_USERS because an installation whose only
 * account is a pastor -- the normal case -- otherwise had nobody who could add
 * a ministry leader, so onboarding required shell access. Widening that
 * capability is exactly the kind of change that quietly becomes "anybody who
 * can manage accounts can become an administrator", so the rules that stop it
 * are asserted here rather than trusted.
 *
 * Every case attacks the boundary from the low-privilege side, because a test
 * that only checks the happy path proves that the feature works, not that it is
 * safe.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve\Platform\App;
use Serve\Platform\Auth;

test(
	'a pastor can add a ministry leader, which is the whole point',
	function ( Assert $a, Fixtures $f ): void {
		$pastor = $f->user( Auth::ROLE_PASTOR );
		$auth   = App::auth();

		$roles = $auth->manageable_roles( $pastor );

		$a->ok( in_array( Auth::ROLE_LEADER, $roles, true ), 'a leader is offered' );
		$a->ok( in_array( Auth::ROLE_PASTOR, $roles, true ), 'and another pastor' );

		/*
		 * The operational point, stated as an assertion: before this a pastor
		 * held no such capability and a church could not onboard anybody without
		 * a developer.
		 */
		$a->ok( $auth->user_can( $pastor, Auth::CAP_MANAGE_USERS ), 'a pastor may manage accounts' );
	}
);

test(
	'nobody can grant a capability they do not hold',
	function ( Assert $a, Fixtures $f ): void {
		$auth   = App::auth();
		$pastor = $f->user( Auth::ROLE_PASTOR );
		$leader = $f->user( Auth::ROLE_LEADER );

		/*
		 * serve_admin is capability-identical to serve_pastor now, so the
		 * subset test alone would let a pastor mint one. It is excluded
		 * explicitly, and this is what says so.
		 */
		$a->not(
			in_array( Auth::ROLE_ADMIN, $auth->manageable_roles( $pastor ), true ),
			'a pastor is not offered the administrator role'
		);

		$refused = $auth->update_user( $pastor, $leader, array( 'role' => Auth::ROLE_ADMIN ) );

		$a->ok( is_wp_error( $refused ), 'and cannot assign it anyway' );
		$a->same( 'serve_bad_role', $refused->get_error_code(), 'as a role matter' );

		/*
		 * And not through the back door either. 'administrator' is the
		 * WordPress-compatible alias for the same capability set, so blocking
		 * one name and not the other would be no protection at all.
		 */
		$aliased = $auth->update_user( $pastor, $leader, array( 'role' => Auth::ROLE_ADMIN_WP ) );

		$a->ok( is_wp_error( $aliased ), 'nor via the WordPress alias' );

		$a->same( Auth::ROLE_LEADER, (string) $auth->user( $leader )->role, 'the role is unchanged' );
	}
);

test(
	'a ministry leader cannot manage accounts at all',
	function ( Assert $a, Fixtures $f ): void {
		$auth   = App::auth();
		$leader = $f->user( Auth::ROLE_LEADER );
		$pastor = $f->user( Auth::ROLE_PASTOR );

		$a->same( array(), $auth->manageable_roles( $leader ), 'no roles are offered to them' );
		$a->not( $auth->can_manage_user( $leader, $pastor ), 'and no account is theirs to change' );

		$refused = $auth->update_user( $leader, $pastor, array( 'role' => Auth::ROLE_LEADER ) );

		$a->ok( is_wp_error( $refused ), 'demoting a pastor is refused' );
		$a->same( 'serve_forbidden', $refused->get_error_code(), 'as a permissions matter' );
		$a->same( Auth::ROLE_PASTOR, (string) $auth->user( $pastor )->role, 'and nothing moved' );
	}
);

test(
	'nobody edits their own account',
	function ( Assert $a, Fixtures $f ): void {
		/*
		 * The rule exists to prevent a lockout rather than an escalation:
		 * switching yourself off, or demoting yourself, removes your access to
		 * the only screen that could undo it and leaves the command line as the
		 * way back.
		 */
		$auth   = App::auth();
		$pastor = $f->user( Auth::ROLE_PASTOR );

		$a->not( $auth->can_manage_user( $pastor, $pastor ), 'their own account is not theirs to change' );

		$refused = $auth->update_user( $pastor, $pastor, array( 'is_active' => 0 ) );

		$a->ok( is_wp_error( $refused ), 'switching themselves off is refused' );
		$a->same( 1, (int) $auth->user( $pastor )->is_active, 'and they can still sign in' );
	}
);

test(
	'switching an account off ends its sessions there and then',
	function ( Assert $a, Fixtures $f ): void {
		$auth   = App::auth();
		$pastor = $f->user( Auth::ROLE_PASTOR );
		$leader = $f->user( Auth::ROLE_LEADER );

		// A session for the leader, as signing in would create.
		$auth->start_session( $leader );

		global $wpdb;
		$sessions = $wpdb->table( 'sessions' );

		$before = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE user_id = %d", $leader )
		);

		$a->ok( $before > 0, 'the leader has a live session' );

		$done = $auth->update_user( $pastor, $leader, array( 'is_active' => 0 ) );

		$a->not( is_wp_error( $done ), 'a pastor may switch them off' );

		$after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$sessions} WHERE user_id = %d", $leader )
		);

		/*
		 * Left to expire, a disabled account keeps working for up to twelve
		 * hours on whatever device it is already open on -- which is not what
		 * anybody switching it off believes they are doing.
		 */
		$a->same( 0, $after, 'and the session is gone immediately, not at expiry' );
	}
);

test(
	'a reset link works once, and only for an hour',
	function ( Assert $a, Fixtures $f ): void {
		$auth   = App::auth();
		$leader = $f->user( Auth::ROLE_LEADER );
		$email  = (string) $auth->user( $leader )->email;

		/*
		 * The raw token only exists in the email, so it is read back out of the
		 * message the same way a person would. Nothing else has it -- the table
		 * stores a hash.
		 */
		$captured = '';

		$grab = static function ( $null, $atts ) use ( &$captured ) {
			$captured = (string) ( $atts['message'] ?? '' );

			return true;
		};

		add_filter( 'pre_wp_mail', $grab, 10, 2 );
		$auth->request_reset( $email );
		remove_filter( 'pre_wp_mail', $grab, 10 );

		preg_match( '/token=([a-f0-9]{64})/', $captured, $found );
		$token = $found[1] ?? '';

		$a->same( 64, strlen( $token ), 'the email carries a token' );

		$done = $auth->complete_reset( $token, 'a-long-enough-passphrase' );

		$a->not( is_wp_error( $done ), 'and it sets the password' );

		$reused = $auth->complete_reset( $token, 'another-long-passphrase' );

		$a->ok( is_wp_error( $reused ), 'a second use is refused' );
		$a->same( 'serve_bad_token', $reused->get_error_code(), 'as an invalid token' );

		// The new password is the one that works.
		$a->not( is_wp_error( $auth->login( $email, 'a-long-enough-passphrase' ) ), 'the new password signs in' );
		$a->ok( is_wp_error( $auth->login( $email, 'another-long-passphrase' ) ), 'the refused one does not' );

		wp_set_current_user( 0 );
	}
);

test(
	'a reset refuses a password too short to be worth setting',
	function ( Assert $a, Fixtures $f ): void {
		$auth   = App::auth();
		$leader = $f->user( Auth::ROLE_LEADER );
		$email  = (string) $auth->user( $leader )->email;

		$captured = '';

		$grab = static function ( $null, $atts ) use ( &$captured ) {
			$captured = (string) ( $atts['message'] ?? '' );

			return true;
		};

		add_filter( 'pre_wp_mail', $grab, 10, 2 );
		$auth->request_reset( $email );
		remove_filter( 'pre_wp_mail', $grab, 10 );

		preg_match( '/token=([a-f0-9]{64})/', $captured, $found );
		$token = $found[1] ?? '';

		$weak = $auth->complete_reset( $token, 'short' );

		$a->ok( is_wp_error( $weak ), 'a short password is refused' );
		$a->same( 'serve_weak_password', $weak->get_error_code(), 'and says why' );

		/*
		 * And the token survives, which matters: spending it on a rejected
		 * attempt would mean somebody who mistypes has to request a new link.
		 */
		$a->not(
			is_wp_error( $auth->complete_reset( $token, 'a-long-enough-passphrase' ) ),
			'the link still works afterwards'
		);
	}
);

test(
	'asking for a reset says the same thing whether or not the account exists',
	function ( Assert $a, Fixtures $f ): void {
		/*
		 * A form that distinguishes the two is a way to discover who has an
		 * account. On an application holding a congregation's spiritual gifts,
		 * that list is worth not leaking on its own.
		 */
		$auth = App::auth();

		$sent = array();

		$count = static function ( $null, $atts ) use ( &$sent ) {
			$sent[] = $atts;

			return true;
		};

		add_filter( 'pre_wp_mail', $count, 10, 2 );

		$a->same( true, $auth->request_reset( 'nobody-here-' . wp_generate_password( 6, false ) . '@serve.test' ), 'an unknown address reports success' );

		$a->same( 0, count( $sent ), 'and sends nothing' );

		remove_filter( 'pre_wp_mail', $count, 10 );
	}
);
