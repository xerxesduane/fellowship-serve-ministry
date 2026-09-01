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
