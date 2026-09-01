<?php
/**
 * Users, sessions and capabilities.
 *
 * This is the one part of the port that is genuinely new rather than
 * reimplemented: WordPress supplied users, login, password hashing and the
 * capability check, and none of it can be borrowed.
 *
 * The capability model is copied exactly, because it is load-bearing. Access is
 * scoped two ways -- by capability (what kind of thing you may do) and by team
 * ownership (whose records you may do it to) -- and a worship leader has no
 * business browsing the whole church's spiritual gifts, phone numbers and
 * painful experiences.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Auth {

	public const ROLE_PASTOR = 'serve_pastor';
	public const ROLE_LEADER = 'serve_ministry_leader';
	public const ROLE_ADMIN  = 'serve_admin';

	/** WordPress's names for the same two ideas. See role_caps(). */
	public const ROLE_ADMIN_WP = 'administrator';
	public const ROLE_NONE     = 'subscriber';

	public const CAP_VIEW_DASHBOARD    = 'serve_view_dashboard';
	public const CAP_VIEW_ALL          = 'serve_view_all_submissions';
	public const CAP_VIEW_TEAM         = 'serve_view_team_submissions';
	public const CAP_VIEW_SENSITIVE    = 'serve_view_sensitive';
	public const CAP_MANAGE_PLACE      = 'serve_manage_placements';
	public const CAP_MANAGE_TEAMS      = 'serve_manage_teams';
	public const CAP_MANAGE_SETTINGS   = 'serve_manage_settings';
	public const CAP_EXPORT            = 'serve_export_data';
	public const CAP_VERIFY_SAFEGUARD  = 'serve_verify_safeguarding';
	public const CAP_MANAGE_USERS      = 'serve_manage_users';

	private const COOKIE = 'serve_session';

	/** How long a session lives without being used. */
	private const IDLE_SECONDS = 43200; // 12 hours.

	/** How long a session lives at all, however active. */
	private const MAX_SECONDS = 604800; // 7 days.

	private Db $db;

	private Options $options;

	private int $current = 0;

	/** @var array<int,object|null> */
	private array $users = array();

	public function __construct( Db $db, Options $options ) {
		$this->db      = $db;
		$this->options = $options;
	}

	/* ── The capability model ───────────────────────────────────────────── */

	/**
	 * @return array<string,array<int,string>>
	 */
	public static function role_caps(): array {
		$leader = array(
			self::CAP_VIEW_DASHBOARD,
			self::CAP_VIEW_TEAM,
			self::CAP_MANAGE_PLACE,
		);

		$pastor = array_merge(
			$leader,
			array(
				self::CAP_VIEW_ALL,
				self::CAP_VIEW_SENSITIVE,
				self::CAP_MANAGE_TEAMS,
				self::CAP_MANAGE_SETTINGS,
				self::CAP_EXPORT,
				self::CAP_VERIFY_SAFEGUARD,
				/*
				 * Pastors manage accounts, which admins alone previously did.
				 *
				 * That was not a defensible line, it was an oversight with an
				 * operational cost: an installation whose only account is a
				 * pastor -- which is the normal case, since that is who gets set
				 * up first -- had nobody who could add a ministry leader.
				 * Onboarding required somebody with shell access, so a church
				 * could not run this without a developer on call.
				 *
				 * The escalation risk this raises is handled where it belongs,
				 * in manageable_roles(): a pastor may create and edit leaders
				 * and other pastors, and cannot mint an administrator. Granting
				 * a capability you do not hold is the thing to prevent, not
				 * managing accounts at all.
				 */
				self::CAP_MANAGE_USERS,
			)
		);

		// The person who installed it is never locked out of it.
		$admin = array_merge( $pastor, array( self::CAP_MANAGE_USERS ) );

		return array(
			self::ROLE_LEADER => $leader,
			self::ROLE_PASTOR => $pastor,
			self::ROLE_ADMIN  => $admin,

			/*
			 * WordPress's two names, kept.
			 *
			 * 'administrator' is what the site owner had, and it mapped to every
			 * SERVE capability so nobody could be locked out of the plugin they
			 * installed. 'subscriber' is the important one: an account that can
			 * sign in and has no SERVE capability at all. Several tests assert
			 * that such a person sees nothing, and dropping the role would turn
			 * those into tests of a role that does not exist.
			 */
			self::ROLE_ADMIN_WP => $admin,
			self::ROLE_NONE     => array(),
		);
	}

	/** @return array<int,string> */
	public static function roles(): array {
		return array_keys( self::role_caps() );
	}

	public static function role_label( string $role ): string {
		$labels = array(
			self::ROLE_LEADER => __( 'Ministry Leader' ),
			self::ROLE_PASTOR => __( 'Pastor' ),
			self::ROLE_ADMIN  => __( 'Administrator' ),
			self::ROLE_ADMIN_WP => __( 'Administrator' ),
			self::ROLE_NONE     => __( 'No SERVE access' ),
		);

		return $labels[ $role ] ?? $role;
	}

	/**
	 * Roles this user may assign.
	 *
	 * Nobody may grant a capability they do not themselves hold. Without that
	 * rule "manage accounts" silently means "become an administrator": create an
	 * admin, sign in as it, and every other limit is decoration.
	 *
	 * Compared by capability rather than by a hardcoded ranking, so adding a
	 * role to role_caps() cannot accidentally become assignable by everyone.
	 *
	 * @return array<int,string>
	 */
	public function manageable_roles( int $user_id ): array {
		if ( ! $this->user_can( $user_id, self::CAP_MANAGE_USERS ) ) {
			return array();
		}

		$user = $this->user( $user_id );
		$mine = self::role_caps()[ (string) ( $user->role ?? '' ) ] ?? array();

		$out = array();

		foreach ( self::role_caps() as $role => $caps ) {
			/*
			 * The WordPress-compatible aliases are not offered.
			 *
			 * 'administrator' and 'subscriber' exist so ported code and the test
			 * fixtures keep working; presenting them in a chooser beside
			 * serve_admin and serve_ministry_leader would be two names for one
			 * thing and an invitation to pick the wrong one.
			 */
			if ( in_array( $role, array( self::ROLE_ADMIN_WP, self::ROLE_NONE ), true ) ) {
				continue;
			}

			/*
			 * serve_admin is not assignable from a screen, and that is a
			 * consequence worth spelling out.
			 *
			 * It existed so the person who installed this could never be locked
			 * out of it. Now that pastors manage accounts, there is nothing an
			 * administrator can do that a pastor cannot -- the two role
			 * definitions are capability-identical, so the subset test below
			 * would let a pastor mint one, and the distinction would be a label
			 * rather than a boundary.
			 *
			 * Rather than invent a capability to justify the difference, it stays
			 * as what it actually is: the account bin/serve creates at install
			 * time. Existing admin accounts keep working and can be edited by
			 * nobody through the UI, which is the same protection the
			 * capability-subset rule gave when the roles differed.
			 */
			if ( self::ROLE_ADMIN === $role ) {
				continue;
			}

			// Nothing this role grants may be outside what the actor holds.
			if ( array() === array_diff( $caps, $mine ) ) {
				$out[] = $role;
			}
		}

		return $out;
	}

	/**
	 * Whether this user may edit that account at all.
	 *
	 * Two refusals beyond the role check. Nobody edits their own account here --
	 * changing your own role or switching yourself off is how somebody locks
	 * themselves out of the only screen that could undo it, and the CLI is then
	 * the only way back. And nobody edits an account whose role they could not
	 * create, so a pastor cannot demote, disable or reset the password of an
	 * administrator.
	 */
	public function can_manage_user( int $actor_id, int $target_id ): bool {
		if ( $actor_id <= 0 || $target_id <= 0 || $actor_id === $target_id ) {
			return false;
		}

		$target = $this->user( $target_id );

		if ( null === $target ) {
			return false;
		}

		return in_array( (string) $target->role, $this->manageable_roles( $actor_id ), true );
	}

	/**
	 * Change an account.
	 *
	 * @param array<string,mixed> $fields name, role, is_active.
	 * @return true|\WP_Error
	 */
	public function update_user( int $actor_id, int $target_id, array $fields ) {
		if ( ! $this->can_manage_user( $actor_id, $target_id ) ) {
			return new \WP_Error(
				'serve_forbidden',
				__( 'You cannot change that account.' ),
				array( 'status' => 403 )
			);
		}

		$allowed = $this->manageable_roles( $actor_id );
		$write   = array();

		if ( isset( $fields['display_name'] ) ) {
			$write['display_name'] = sanitize_text_field( (string) $fields['display_name'] );
		}

		if ( isset( $fields['role'] ) ) {
			$role = (string) $fields['role'];

			if ( ! in_array( $role, $allowed, true ) ) {
				return new \WP_Error(
					'serve_bad_role',
					__( 'You cannot give an account that role.' ),
					array( 'status' => 403 )
				);
			}

			$write['role'] = $role;
		}

		if ( isset( $fields['is_active'] ) ) {
			$active = (int) (bool) $fields['is_active'];

			/*
			 * The last account that can manage accounts stays on.
			 *
			 * Switching it off leaves an installation nobody can administer
			 * except from a shell, which is the state this screen exists to
			 * avoid.
			 */
			if ( 0 === $active && ! $this->another_manager_remains( $target_id ) ) {
				return new \WP_Error(
					'serve_last_manager',
					__( 'This is the last account that can manage accounts. Give somebody else that role first.' ),
					array( 'status' => 409 )
				);
			}

			$write['is_active'] = $active;

			// A disabled account's sessions end now, not when they expire.
			if ( 0 === $active ) {
				$this->db->delete( $this->sessions_table(), array( 'user_id' => $target_id ) );
			}
		}

		if ( array() === $write ) {
			return true;
		}

		unset( $this->users[ $target_id ] );

		$done = $this->db->update( $this->table(), $write, array( 'id' => $target_id ) );

		return false === $done
			? new \WP_Error( 'serve_save_failed', __( 'That change could not be saved.' ), array( 'status' => 500 ) )
			: true;
	}

	/** Whether anybody else active can still manage accounts. */
	private function another_manager_remains( int $excluding ): bool {
		foreach ( $this->users_with_cap( self::CAP_MANAGE_USERS ) as $user ) {
			if ( (int) $user->id !== $excluding ) {
				return true;
			}
		}

		return false;
	}

	public function user_can( int $user_id, string $cap ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = $this->user( $user_id );

		if ( null === $user || ! (int) $user->is_active ) {
			return false;
		}

		$caps = self::role_caps()[ (string) $user->role ] ?? array();

		return in_array( $cap, $caps, true );
	}

	public function current_can( string $cap ): bool {
		return $this->user_can( $this->current, $cap );
	}

	/* ── Who is asking ──────────────────────────────────────────────────── */

	public function current_id(): int {
		return $this->current;
	}

	/**
	 * Used by login, by the CLI, and by the tests.
	 *
	 * The tests set the current user directly to exercise each role, which is
	 * exactly what WordPress's wp_set_current_user() allowed.
	 */
	public function set_current( int $user_id ): void {
		$this->current = max( 0, $user_id );
	}

	/** @return object|null */
	public function user( int $user_id ) {
		if ( array_key_exists( $user_id, $this->users ) ) {
			return $this->users[ $user_id ];
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT id, email, display_name, role, is_active, password_hash'
				. ' FROM ' . $this->table() . ' WHERE id = %d',
				$user_id
			)
		);

		$this->users[ $user_id ] = $row;

		return $row;
	}

	/** @return object|null */
	public function user_by_email( string $email ) {
		return $this->db->get_row(
			$this->db->prepare(
				'SELECT id, email, display_name, role, is_active, password_hash'
				. ' FROM ' . $this->table() . ' WHERE email = %s',
				strtolower( trim( $email ) )
			)
		);
	}

	/**
	 * @param array<int,string> $roles Empty for all roles.
	 * @return array<int,object>
	 */
	public function users_with_cap( string $cap ): array {
		$roles = array();

		foreach ( self::role_caps() as $role => $caps ) {
			if ( in_array( $cap, $caps, true ) ) {
				$roles[] = $role;
			}
		}

		if ( array() === $roles ) {
			return array();
		}

		$list = implode(
			', ',
			array_map( fn( string $r ): string => $this->db->prepare( '%s', $r ), $roles )
		);

		return $this->db->get_results(
			'SELECT id, email, display_name, role FROM ' . $this->table()
			. " WHERE is_active = 1 AND role IN ({$list}) ORDER BY display_name"
		);
	}

	/* ── Accounts ───────────────────────────────────────────────────────── */

	/**
	 * @return int|\WP_Error The new user id.
	 */
	public function create_user( string $email, string $name, string $role, string $password ) {
		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return new \WP_Error( 'serve_bad_email', __( 'That email address is not valid.' ), array( 'status' => 422 ) );
		}

		if ( ! in_array( $role, self::roles(), true ) ) {
			return new \WP_Error( 'serve_bad_role', __( 'Unknown role.' ), array( 'status' => 422 ) );
		}

		$weak = self::password_problem( $password );

		if ( null !== $weak ) {
			return new \WP_Error( 'serve_weak_password', $weak, array( 'status' => 422 ) );
		}

		if ( null !== $this->user_by_email( $email ) ) {
			return new \WP_Error( 'serve_email_taken', __( 'An account already uses that email address.' ), array( 'status' => 409 ) );
		}

		$done = $this->db->insert(
			$this->table(),
			array(
				'email'         => $email,
				'display_name'  => sanitize_text_field( $name ),
				'role'          => $role,
				'password_hash' => self::hash( $password ),
				'is_active'     => 1,
				'created_at'    => current_time( 'mysql', true ),
			)
		);

		if ( false === $done ) {
			return new \WP_Error( 'serve_save_failed', __( 'The account could not be created.' ), array( 'status' => 500 ) );
		}

		return $this->db->insert_id;
	}

	/**
	 * Why a password is refused, or null when it is fine.
	 *
	 * Length is the only rule. Composition rules push people towards
	 * Passw0rd! and a long passphrase beats a short scramble, so the floor is
	 * twelve characters and nothing else is demanded.
	 */
	public static function password_problem( string $password ): ?string {
		$length = mb_strlen( $password );

		if ( $length < 12 ) {
			return __( 'Use at least 12 characters. A short sentence works well.' );
		}

		if ( $length > 4096 ) {
			// bcrypt truncates and a megabyte of input is a denial of service.
			return __( 'That password is too long.' );
		}

		return null;
	}

	public static function hash( string $password ): string {
		return password_hash( $password, PASSWORD_DEFAULT );
	}

	public function set_password( int $user_id, string $password ): bool {
		$weak = self::password_problem( $password );

		if ( null !== $weak ) {
			return false;
		}

		unset( $this->users[ $user_id ] );

		return false !== $this->db->update(
			$this->table(),
			array( 'password_hash' => self::hash( $password ) ),
			array( 'id' => $user_id )
		);
	}

	/* ── Login ──────────────────────────────────────────────────────────── */

	/**
	 * @return int|\WP_Error The user id on success.
	 */
	public function login( string $email, string $password, string $ip = '' ) {
		/*
		 * The same answer whether the address is unknown or the password is
		 * wrong, and the same amount of work either way. A login form that
		 * distinguishes the two is an account-enumeration oracle, and one that
		 * returns faster for an unknown address is the same oracle with extra
		 * steps.
		 */
		$user = $this->user_by_email( $email );
		$hash = null !== $user ? (string) $user->password_hash : self::dummy_hash();

		$ok = password_verify( $password, $hash );

		if ( $this->too_many_attempts( $email, $ip ) ) {
			return new \WP_Error(
				'serve_rate_limited',
				__( 'Too many attempts. Wait a few minutes and try again.' ),
				array( 'status' => 429 )
			);
		}

		if ( ! $ok || null === $user || ! (int) $user->is_active ) {
			$this->record_attempt( $email, $ip );

			return new \WP_Error(
				'serve_bad_credentials',
				__( 'That email address and password do not match an account.' ),
				array( 'status' => 401 )
			);
		}

		$this->clear_attempts( $email, $ip );

		if ( password_needs_rehash( $hash, PASSWORD_DEFAULT ) ) {
			$this->db->update(
				$this->table(),
				array( 'password_hash' => self::hash( $password ) ),
				array( 'id' => (int) $user->id )
			);
		}

		$this->db->update(
			$this->table(),
			array( 'last_login_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $user->id )
		);

		$this->set_current( (int) $user->id );

		return (int) $user->id;
	}

	/**
	 * A hash to verify against when the account does not exist.
	 *
	 * Computed once per process from a fixed string, so an unknown address
	 * costs the same bcrypt work as a known one.
	 */
	private static function dummy_hash(): string {
		static $hash = null;

		if ( null === $hash ) {
			$hash = password_hash( 'not-a-real-password', PASSWORD_DEFAULT );
		}

		return (string) $hash;
	}

	/* ── Password resets ────────────────────────────────────────────────── */

	/** How long a reset link is good for. */
	private const RESET_SECONDS = 3600;

	/**
	 * Issue a reset link and email it.
	 *
	 * Returns true whether or not the address exists, and takes a comparable
	 * amount of time either way. A form that says "no such account" is an
	 * account-enumeration oracle, and on an application holding a
	 * congregation's spiritual gifts the list of who has an account is itself
	 * worth not leaking.
	 *
	 * @return bool Whether the caller should be told the mail is on its way.
	 */
	public function request_reset( string $email ): bool {
		$user = $this->user_by_email( $email );

		if ( null === $user || ! (int) $user->is_active ) {
			/*
			 * Same work, no mail. Without this the response time answers the
			 * question the message refuses to.
			 */
			password_hash( 'no-such-account', PASSWORD_DEFAULT );

			return true;
		}

		$token = bin2hex( random_bytes( 32 ) );

		$this->db->insert(
			$this->resets_table(),
			array(
				'user_id'      => (int) $user->id,
				'token_hash'   => self::token_hash( $token ),
				'requested_ip' => substr( self::client_ip(), 0, 45 ),
				'created_at'   => current_time( 'mysql', true ),
			)
		);

		$link = App::url( 'reset' ) . '?token=' . rawurlencode( $token );

		wp_mail(
			(string) $user->email,
			__( 'Set a new SERVE password' ),
			sprintf(
				/* translators: 1: display name, 2: reset link. */
				__(
					"Hello %1\$s,

"
					. "Somebody asked to set a new password for your SERVE account. To do it, open this link:

%2\$s

"
					. "The link works once and expires in an hour.

"
					. "If this was not you, you can ignore this email. Your current password still works and nothing has changed.

"
					. 'Fellowship Dubai SERVE team'
				),
				(string) $user->display_name,
				$link
			)
		);

		return true;
	}

	/**
	 * Spend a reset token and set the new password.
	 *
	 * @return true|\WP_Error
	 */
	public function complete_reset( string $token, string $password ) {
		$invalid = new \WP_Error(
			'serve_bad_token',
			__( 'That link is not valid any more. Ask for a new one.' ),
			array( 'status' => 400 )
		);

		// Tokens are hex, so anything else is not worth a database round trip.
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return $invalid;
		}

		$problem = self::password_problem( $password );

		if ( null !== $problem ) {
			return new \WP_Error( 'serve_weak_password', $problem, array( 'status' => 422 ) );
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT id, user_id, created_at, used_at FROM ' . $this->resets_table()
				. ' WHERE token_hash = %s',
				self::token_hash( $token )
			)
		);

		if ( null === $row || null !== $row->used_at ) {
			return $invalid;
		}

		if ( time() - (int) strtotime( (string) $row->created_at . ' UTC' ) > self::RESET_SECONDS ) {
			return $invalid;
		}

		/*
		 * Marked used before the password is written, and only if this is the
		 * request that marked it.
		 *
		 * Two submissions of the same link arriving together would otherwise
		 * both pass the check above. The conditional update makes the second one
		 * change no rows, so it is refused.
		 */
		$claimed = $this->db->query(
			$this->db->prepare(
				'UPDATE ' . $this->resets_table() . ' SET used_at = %s WHERE id = %d AND used_at IS NULL',
				current_time( 'mysql', true ),
				(int) $row->id
			)
		);

		if ( ! $claimed ) {
			return $invalid;
		}

		if ( ! $this->set_password( (int) $row->user_id, $password ) ) {
			return new \WP_Error( 'serve_save_failed', __( 'The password could not be saved.' ), array( 'status' => 500 ) );
		}

		/*
		 * Every existing session ends.
		 *
		 * Somebody resetting a password may be doing it because the account was
		 * taken, and leaving the intruder's session alive would make the reset
		 * pointless.
		 */
		$this->db->delete( $this->sessions_table(), array( 'user_id' => (int) $row->user_id ) );

		return true;
	}

	/** Clears spent and expired reset rows. Called from the retention sweep. */
	public function prune_resets(): int {
		$done = $this->db->query(
			$this->db->prepare(
				'DELETE FROM ' . $this->resets_table()
				. ' WHERE used_at IS NOT NULL'
				. ' OR created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )',
				self::RESET_SECONDS
			)
		);

		return false === $done ? 0 : (int) $done;
	}

	private function resets_table(): string {
		return $this->db->table( 'password_resets' );
	}

	/* ── Sessions ───────────────────────────────────────────────────────── */

	/**
	 * Start a session and send its cookie.
	 *
	 * Only the hash of the token is stored, for the same reason the
	 * verification tokens elsewhere in this application are hashed: a stolen
	 * database should not hand over live sessions.
	 */
	public function start_session( int $user_id ): string {
		$token = bin2hex( random_bytes( 32 ) );
		$now   = current_time( 'mysql', true );

		$this->db->insert(
			$this->sessions_table(),
			array(
				'user_id'    => $user_id,
				'token_hash' => self::token_hash( $token ),
				'created_at' => $now,
				'used_at'    => $now,
				'ip'         => substr( self::client_ip(), 0, 45 ),
			)
		);

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$token,
				array(
					'expires'  => time() + self::MAX_SECONDS,
					'path'     => '/',
					'secure'   => self::is_https(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$this->set_current( $user_id );

		return $token;
	}

	/**
	 * Resolve the request's cookie to a user, or leave nobody logged in.
	 */
	public function resume_session(): int {
		$token = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );

		if ( '' === $token || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return 0;
		}

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT id, user_id, created_at, used_at FROM ' . $this->sessions_table()
				. ' WHERE token_hash = %s',
				self::token_hash( $token )
			)
		);

		if ( null === $row ) {
			return 0;
		}

		$now     = time();
		$created = strtotime( (string) $row->created_at . ' UTC' );
		$used    = strtotime( (string) $row->used_at . ' UTC' );

		if ( $now - $created > self::MAX_SECONDS || $now - $used > self::IDLE_SECONDS ) {
			$this->db->delete( $this->sessions_table(), array( 'id' => (int) $row->id ) );

			return 0;
		}

		$user = $this->user( (int) $row->user_id );

		if ( null === $user || ! (int) $user->is_active ) {
			// A deactivated account's sessions die with it, immediately.
			$this->db->delete( $this->sessions_table(), array( 'user_id' => (int) $row->user_id ) );

			return 0;
		}

		// Touched at most once a minute: the write is not worth doing per request.
		if ( $now - $used > 60 ) {
			$this->db->update(
				$this->sessions_table(),
				array( 'used_at' => current_time( 'mysql', true ) ),
				array( 'id' => (int) $row->id )
			);
		}

		$this->set_current( (int) $row->user_id );

		return (int) $row->user_id;
	}

	public function logout(): void {
		$token = (string) ( $_COOKIE[ self::COOKIE ] ?? '' );

		if ( '' !== $token && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			$this->db->delete( $this->sessions_table(), array( 'token_hash' => self::token_hash( $token ) ) );
		}

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				'',
				array(
					'expires'  => time() - 3600,
					'path'     => '/',
					'secure'   => self::is_https(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$this->set_current( 0 );
	}

	/** Removes sessions that can no longer be used. Called from cron. */
	public function prune_sessions(): int {
		$sql = $this->db->prepare(
			'DELETE FROM ' . $this->sessions_table()
			. ' WHERE used_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )'
			. ' OR created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )',
			self::IDLE_SECONDS,
			self::MAX_SECONDS
		);

		$done = $this->db->query( $sql );

		return false === $done ? 0 : (int) $done;
	}

	private static function token_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, App::secret() );
	}

	/* ── Request forgery ────────────────────────────────────────────────── */

	/**
	 * A CSRF token bound to the session and the action.
	 *
	 * Replaces WordPress nonces. Bound to the session cookie so a token lifted
	 * from one person's page is useless in another's browser, and to the action
	 * so a token minted for a harmless form cannot authorise a destructive one.
	 */
	public function csrf_token( string $action = 'serve' ): string {
		$session = (string) ( $_COOKIE[ self::COOKIE ] ?? 'anonymous' );

		return hash_hmac( 'sha256', $action . '|' . $session, App::secret() );
	}

	public function csrf_ok( string $token, string $action = 'serve' ): bool {
		return hash_equals( $this->csrf_token( $action ), $token );
	}

	/* ── Throttling ─────────────────────────────────────────────────────── */

	private function too_many_attempts( string $email, string $ip ): bool {
		$log = $this->attempt_log();

		$by_email = $log[ 'e:' . strtolower( $email ) ] ?? array( 'n' => 0, 't' => 0 );
		$by_ip    = $log[ 'i:' . ( '' === $ip ? self::client_ip() : $ip ) ] ?? array( 'n' => 0, 't' => 0 );

		$window = time() - 900;

		return ( $by_email['t'] > $window && $by_email['n'] >= 8 )
			|| ( $by_ip['t'] > $window && $by_ip['n'] >= 20 );
	}

	private function record_attempt( string $email, string $ip ): void {
		$log    = $this->attempt_log();
		$now    = time();
		$window = $now - 900;

		foreach ( array( 'e:' . strtolower( $email ), 'i:' . ( '' === $ip ? self::client_ip() : $ip ) ) as $key ) {
			$entry = $log[ $key ] ?? array( 'n' => 0, 't' => 0 );

			$log[ $key ] = array(
				'n' => ( $entry['t'] > $window ? (int) $entry['n'] : 0 ) + 1,
				't' => $now,
			);
		}

		// Keep the log from growing without bound.
		$log = array_filter( $log, static fn( array $e ): bool => (int) $e['t'] > $window );

		$this->options->set( 'serve_login_attempts', $log );
	}

	private function clear_attempts( string $email, string $ip ): void {
		$log = $this->attempt_log();

		unset( $log[ 'e:' . strtolower( $email ) ] );

		$this->options->set( 'serve_login_attempts', $log );
	}

	/** @return array<string,array{n:int,t:int}> */
	private function attempt_log(): array {
		$log = $this->options->get( 'serve_login_attempts', array() );

		return is_array( $log ) ? $log : array();
	}

	public static function client_ip(): string {
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
	}

	public static function is_https(): bool {
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
			return true;
		}

		return 443 === (int) ( $_SERVER['SERVER_PORT'] ?? 0 );
	}

	private function table(): string {
		return $this->db->table( 'users' );
	}

	private function sessions_table(): string {
		return $this->db->table( 'sessions' );
	}
}
