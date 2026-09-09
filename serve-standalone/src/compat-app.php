<?php
/**
 * Application-level compatibility: REST, users, URLs, mail, cron.
 *
 * The REST classes below are the shapes the ported route handlers already speak
 * -- get_param(), set_body(), get_status(), get_data() -- so nineteen route
 * registrations and their tests carry over unchanged. Underneath, the router in
 * Platform\Router does the actual dispatching.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
use Serve\Platform\Router;

/* ── Time constants the ported code uses ─────────────────────────────────── */

foreach (
	array(
		'MINUTE_IN_SECONDS' => 60,
		'HOUR_IN_SECONDS'   => 3600,
		'DAY_IN_SECONDS'    => 86400,
		'WEEK_IN_SECONDS'   => 604800,
		'MONTH_IN_SECONDS'  => 2592000,
		'YEAR_IN_SECONDS'   => 31536000,
	) as $serve_const => $serve_value
) {
	if ( ! defined( $serve_const ) ) {
		define( $serve_const, $serve_value );
	}
}

unset( $serve_const, $serve_value );

/* ── REST request and response ───────────────────────────────────────────── */

if ( ! class_exists( 'WP_REST_Server' ) ) {
	final class WP_REST_Server {
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
		public const EDITABLE  = 'POST, PUT, PATCH';
		public const DELETABLE = 'DELETE';
		public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {

		private string $method;

		private string $route;

		/** @var array<string,mixed> */
		private array $params = array();

		/** @var array<string,string> */
		private array $headers = array();

		private string $body = '';

		/** @var array<string,mixed>|null */
		private ?array $json = null;

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = strtoupper( $method );
			$this->route  = $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		public function get_header( string $name ): ?string {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
			$this->json = null;
		}

		public function get_body(): string {
			return $this->body;
		}

		/** @param array<string,mixed> $params */
		public function set_url_params( array $params ): void {
			foreach ( $params as $k => $v ) {
				$this->params[ $k ] = $v;
			}
		}

		/** @param array<string,mixed> $params */
		public function set_query_params( array $params ): void {
			foreach ( $params as $k => $v ) {
				$this->params[ $k ] = $v;
			}
		}

		/** @param mixed $value */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * URL and query parameters first, then the JSON body.
		 *
		 * The order matters: a route's {id} must not be overridable by a body
		 * that also carries "id".
		 *
		 * @return mixed
		 */
		public function get_param( string $key ) {
			if ( array_key_exists( $key, $this->params ) ) {
				return $this->params[ $key ];
			}

			$json = $this->get_json_params();

			return $json[ $key ] ?? null;
		}

		/** @return array<string,mixed> */
		public function get_json_params(): array {
			if ( null === $this->json ) {
				$decoded    = '' === $this->body ? null : json_decode( $this->body, true );
				$this->json = is_array( $decoded ) ? $decoded : array();
			}

			return $this->json;
		}

		/** @return array<string,mixed> */
		public function get_params(): array {
			return array_merge( $this->get_json_params(), $this->params );
		}

		/*
		 * Array access, because the ported handlers read $request['id'].
		 *
		 * Nineteen route registrations use that form for URL parameters, so it
		 * is supported rather than edited out of each one.
		 */

		/** @param mixed $key */
		public function offsetExists( $key ): bool {
			return null !== $this->get_param( (string) $key );
		}

		/**
		 * @param mixed $key
		 * @return mixed
		 */
		#[\ReturnTypeWillChange]
		public function offsetGet( $key ) {
			return $this->get_param( (string) $key );
		}

		/** @param mixed $key @param mixed $value */
		public function offsetSet( $key, $value ): void {
			$this->set_param( (string) $key, $value );
		}

		/** @param mixed $key */
		public function offsetUnset( $key ): void {
			$this->set_param( (string) $key, null );
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		/** @var mixed */
		private $data;

		private int $status;

		/** @var array<string,string> */
		private array $headers = array();

		/** @param mixed $data */
		public function __construct( $data = null, int $status = 200, array $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		/** @param mixed $data */
		public function set_data( $data ): void {
			$this->data = $data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function set_status( int $status ): void {
			$this->status = $status;
		}

		/** @return array<string,string> */
		public function get_headers(): array {
			return $this->headers;
		}

		public function header( string $name, string $value ): void {
			$this->headers[ $name ] = $value;
		}
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * @param mixed $thing
	 * @return WP_REST_Response|WP_Error
	 */
	function rest_ensure_response( $thing ) {
		if ( $thing instanceof WP_REST_Response || $thing instanceof WP_Error ) {
			return $thing;
		}

		return new WP_REST_Response( $thing );
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code(): int {
		// 401 when nobody is logged in, 403 when somebody is and still may not.
		return is_user_logged_in() ? 403 : 401;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * @param array<string,mixed> $args
	 */
	function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
		Router::register( $namespace, $route, $args );

		return true;
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return App::url( 'api/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'rest_do_request' ) ) {
	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	function rest_do_request( $request ): WP_REST_Response {
		return Router::dispatch( $request );
	}
}

if ( ! function_exists( 'rest_get_server' ) ) {
	/**
	 * The thing the tests dispatch through.
	 *
	 * WordPress's REST server is a large object; the only part any caller here
	 * uses is dispatch(), so that is what this provides -- over the same router
	 * the live requests go through, which is the point. A test that dispatched
	 * through a different path from production would not be testing production.
	 */
	function rest_get_server(): object {
		return new class() {

			public function dispatch( \WP_REST_Request $request ): \WP_REST_Response {
				return Router::dispatch( $request );
			}

			/** @return array<string,mixed> */
			public function get_routes(): array {
				return Router::routes();
			}
		};
	}
}

/* ── Settings ────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		return App::options()->get( $name, $default );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param mixed $value
	 * @return bool True when the stored value changed.
	 */
	function update_option( string $name, $value ): bool {
		return App::options()->set( $name, $value );
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Only writes when the option is absent.
	 *
	 * The distinction from update_option matters: the installer uses this to
	 * seed defaults without overwriting a choice somebody has already made.
	 *
	 * @param mixed $value
	 */
	function add_option( string $name, $value ): bool {
		if ( false !== App::options()->get( $name, false ) ) {
			return false;
		}

		return App::options()->set( $name, $value );
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		return App::options()->delete( $name );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Expiry stored alongside the value.
	 *
	 * WordPress had a separate cache layer for these; here a transient is an
	 * option that knows when it goes stale. Only used for short-lived counters,
	 * so a row per transient is not a problem.
	 *
	 * @return mixed
	 */
	function get_transient( string $name ) {
		$held = App::options()->get( '_transient_' . $name, false );

		if ( ! is_array( $held ) || ! array_key_exists( 'expires', $held ) ) {
			return false;
		}

		if ( 0 !== (int) $held['expires'] && (int) $held['expires'] < time() ) {
			App::options()->delete( '_transient_' . $name );

			return false;
		}

		return $held['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/** @param mixed $value */
	function set_transient( string $name, $value, int $expires = 0 ): bool {
		return App::options()->set(
			'_transient_' . $name,
			array(
				'value'   => $value,
				'expires' => 0 === $expires ? 0 : time() + $expires,
			)
		);
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $name ): bool {
		return App::options()->delete( '_transient_' . $name );
	}
}

/* ── Users ───────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return App::auth()->current_can( $cap );
	}
}

if ( ! function_exists( 'get_role' ) ) {
	/**
	 * A role's capabilities, in the shape the ported code and its tests read.
	 *
	 * WordPress returned a WP_Role with a ->capabilities map and has_cap().
	 * Both are provided, because one test asserts the two roles carry exactly
	 * the capabilities they are documented to have -- which is the security
	 * model stated as an assertion, and worth keeping runnable.
	 */
	function get_role( string $role ): ?object {
		$caps = \Serve\Platform\Auth::role_caps()[ $role ] ?? null;

		if ( null === $caps ) {
			return null;
		}

		return new class( $role, $caps ) {

			public string $name;

			/** @var array<string,bool> */
			public array $capabilities;

			/** @param array<int,string> $caps */
			public function __construct( string $role, array $caps ) {
				$this->name         = $role;
				$this->capabilities = array_fill_keys( $caps, true );

				// WordPress gave every role 'read'; some checks look for it.
				$this->capabilities['read'] = true;
			}

			public function has_cap( string $cap ): bool {
				return ! empty( $this->capabilities[ $cap ] );
			}

			/**
			 * Adding a capability at runtime is refused.
			 *
			 * In WordPress this wrote to the database and persisted. Here the
			 * capability sets are declared in code, which is the point: what a
			 * ministry leader may do is reviewable in one function rather than
			 * being whatever a sequence of past calls left in a table.
			 */
			public function add_cap( string $cap, bool $grant = true ): void {
				throw new \LogicException(
					'Capabilities are declared in Serve\Platform\Auth::role_caps(), not added at runtime.'
				);
			}
		};
	}
}

if ( ! function_exists( 'user_can' ) ) {
	/**
	 * Whether one specific user has a capability.
	 *
	 * Distinct from current_user_can() and load-bearing: the team-scoping in
	 * Roles asks about a named user, not about whoever is logged in, and
	 * conflating the two is how a leader's own permissions get used to answer a
	 * question about somebody else's records.
	 *
	 * @param int|object $user
	 */
	function user_can( $user, string $cap ): bool {
		$id = is_object( $user ) ? (int) ( $user->id ?? $user->ID ?? 0 ) : (int) $user;

		return App::auth()->user_can( $id, $cap );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return App::auth()->current_id();
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return App::auth()->current_id() > 0;
	}
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
	function wp_set_current_user( int $user_id ): void {
		App::auth()->set_current( $user_id );
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/** @return object|false */
	function get_userdata( int $user_id ) {
		$user = App::auth()->user( $user_id );

		if ( null === $user ) {
			return false;
		}

		/*
		 * The properties the ported code reads off a user.
		 *
		 * first_name is used for the dashboard's greeting, and WordPress had it
		 * as separate user meta. There is one display_name here, so the first
		 * word of it is the first name -- which is what a greeting wants and is
		 * right for the great majority of names. It is only ever used to say
		 * hello; nothing decides anything from it.
		 */
		$user->ID         = (int) $user->id;
		$user->user_email = $user->email;

		$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
		$parts = is_array( $parts ) ? $parts : array();

		$user->first_name = (string) ( $parts[0] ?? '' );
		$user->last_name  = count( $parts ) > 1 ? (string) end( $parts ) : '';

		return $user;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/** @return object */
	function wp_get_current_user() {
		$user = get_userdata( App::auth()->current_id() );

		return false === $user
			? (object) array(
				'ID'           => 0,
				'display_name' => '',
				'user_email'   => '',
				'email'        => '',
			)
			: $user;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Only the one query the ported code makes: everybody with a capability.
	 *
	 * @param array<string,mixed> $args
	 * @return array<int,object>
	 */
	function get_users( array $args = array() ): array {
		$cap = (string) ( $args['capability'] ?? ( $args['capability__in'][0] ?? '' ) );

		if ( '' === $cap ) {
			return array();
		}

		$users = App::auth()->users_with_cap( $cap );

		foreach ( $users as $user ) {
			$user->user_email = $user->email;
			$user->ID         = (int) $user->id;
		}

		return $users;
	}
}

if ( ! function_exists( 'wp_insert_user' ) ) {
	/**
	 * Create an account from the array shape WordPress used.
	 *
	 * Present so the test fixtures port unchanged: they create a user per role
	 * to exercise the capability model, which is the most valuable thing the
	 * suite does and not worth rewriting.
	 *
	 * @param array<string,mixed> $data
	 * @return int|WP_Error
	 */
	function wp_insert_user( array $data ) {
		$email = (string) ( $data['user_email'] ?? '' );
		$name  = (string) ( $data['display_name'] ?? $data['user_login'] ?? $email );
		$role  = (string) ( $data['role'] ?? \Serve\Platform\Auth::ROLE_LEADER );
		$pass  = (string) ( $data['user_pass'] ?? wp_generate_password( 24 ) );

		return App::auth()->create_user( $email, $name, $role, $pass );
	}
}

if ( ! function_exists( 'wp_delete_user' ) ) {
	/**
	 * Remove an account and everything that let it act.
	 *
	 * Sessions go with it rather than being left to expire: a deleted account
	 * whose cookie still works is the same hole as a deactivated one, and the
	 * id could later be reissued to somebody else.
	 */
	function wp_delete_user( int $user_id ): bool {
		$db = App::db();

		$db->delete( $db->table( 'sessions' ), array( 'user_id' => $user_id ) );

		/*
		 * Teams this person led are released rather than left pointing at a
		 * user that no longer exists -- which would make visible_team_ids()
		 * match on a dangling id.
		 */
		$db->update(
			$db->table( 'teams' ),
			array( 'leader_user_id' => null ),
			array( 'leader_user_id' => $user_id )
		);

		return false !== $db->delete( $db->table( 'users' ), array( 'id' => $user_id ) );
	}
}

/* ── URLs ────────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return App::url( $path );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '' ): string {
		return App::url( $path );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * The dashboard's own URLs.
	 *
	 * WordPress admin pages were admin.php?page=serve-dashboard; here they are
	 * plain paths, and this translates the calls that remain.
	 */
	function admin_url( string $path = '' ): string {
		if ( str_contains( $path, 'page=serve-dashboard-teams' ) || str_contains( $path, 'page=serve-teams' ) ) {
			return App::url( 'teams' );
		}

		if ( str_contains( $path, 'page=serve-dashboard-settings' ) ) {
			return App::url( 'settings' );
		}

		if ( str_contains( $path, 'page=serve-settings' ) ) {
			return App::url( 'settings' );
		}

		if ( str_contains( $path, 'page=serve-security' ) ) {
			return App::url( 'security' );
		}

		if ( str_contains( $path, 'page=serve' ) ) {
			return App::url( 'dashboard' );
		}

		return App::url( $path );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Add or replace query parameters on a URL.
	 *
	 * WordPress accepts two call shapes and the ported code uses both:
	 *
	 *   add_query_arg( array( 'a' => 1 ), $url )
	 *   add_query_arg( 'a', 1, $url )
	 *
	 * The first version of this only handled the array form. Given the
	 * three-argument form it read the value as the URL and then threw the real
	 * URL away, so it returned a bare query string.
	 *
	 * That was not cosmetic. Verification::issue() builds the email
	 * confirmation link this way, and a submission stays invisible to every
	 * leader until that link is opened -- so every confirmation email carried a
	 * link that went nowhere, and no profile could ever have been confirmed on
	 * a real install. Nothing failed loudly; the mail sent, and the link simply
	 * did not work.
	 *
	 * @param array<string,mixed>|string $args
	 * @param mixed                      $value_or_url
	 */
	function add_query_arg( $args, $value_or_url = null, ?string $url = null ): string {
		if ( is_array( $args ) ) {
			// add_query_arg( array(...), $url )
			$base = (string) ( $value_or_url ?? '' );
		} else {
			// add_query_arg( $key, $value, $url )
			$base = (string) ( $url ?? '' );
			$args = array( (string) $args => $value_or_url );
		}

		$parts = parse_url( $base );
		$query = array();

		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
		}

		$query = array_merge( $query, $args );

		/*
		 * Rebuilt from the parsed pieces rather than by cutting at the first
		 * "?", so a fragment survives and lands after the query where it
		 * belongs.
		 */
		$out = '';

		if ( isset( $parts['scheme'] ) ) {
			$out .= $parts['scheme'] . '://';
		}

		if ( isset( $parts['host'] ) ) {
			$out .= $parts['host'];

			if ( isset( $parts['port'] ) ) {
				$out .= ':' . $parts['port'];
			}
		}

		$out .= $parts['path'] ?? '';

		if ( array() !== $query ) {
			$out .= '?' . http_build_query( $query );
		}

		if ( isset( $parts['fragment'] ) ) {
			$out .= '#' . $parts['fragment'];
		}

		return $out;
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302 ): void {
		// Same-origin only: an open redirect is a phishing primitive.
		$host = parse_url( $location, PHP_URL_HOST );
		$ours = parse_url( App::url(), PHP_URL_HOST );

		if ( null !== $host && $host !== $ours ) {
			$location = App::url( 'dashboard' );
		}

		if ( ! headers_sent() ) {
			header( 'Location: ' . $location, true, $status );
		}
	}
}

if ( ! function_exists( 'wp_redirect' ) ) {
	function wp_redirect( string $location, int $status = 302 ): void {
		wp_safe_redirect( $location, $status );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/** @param mixed $message */
	function wp_die( $message = '', string $title = '', array $args = array() ): void {
		$status = (int) ( $args['response'] ?? 500 );

		if ( ! headers_sent() ) {
			http_response_code( $status );
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html( '' === $title ? 'Error' : $title )
			. '</title><p>' . esc_html( is_scalar( $message ) ? (string) $message : 'Error' ) . '</p>';

		exit;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl(): bool {
		return \Serve\Platform\Auth::is_https();
	}
}

/* ── Request forgery ─────────────────────────────────────────────────────── */

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = 'serve' ): string {
		return App::auth()->csrf_token( $action );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/** @param mixed $nonce */
	function wp_verify_nonce( $nonce, string $action = 'serve' ): bool {
		return App::auth()->csrf_ok( (string) $nonce, $action );
	}
}

/*
 * The field is called _wpnonce, as it is in WordPress.
 *
 * This layer briefly used _serve_nonce, which was self-consistent and broke
 * ported code that names the parameter itself: Export::url() builds a download
 * link carrying _wpnonce, so check_admin_referer() looked for a field that was
 * never sent and the export 403'd. Matching the name WordPress uses is the
 * whole point of a compatibility layer.
 */

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action = 'serve', string $name = '_wpnonce', bool $referer = true, bool $echo = true ): string {
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="'
			. esc_attr( wp_create_nonce( $action ) ) . '">';

		if ( $echo ) {
			echo $field;
		}

		return $field;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( string $action = 'serve', string $name = '_wpnonce' ): bool {
		$nonce = (string) ( $_POST[ $name ] ?? $_GET[ $name ] ?? '' );

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( __( 'That form has expired. Go back, reload the page and try again.' ), 'Expired', array( 'response' => 403 ) );
		}

		return true;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return hash_hmac( 'sha256', $scheme, App::secret() );
	}
}

/* ── Mail ────────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * @param string|array<int,string> $to
	 * @param string|array<int,string> $headers
	 */
	function wp_mail( $to, string $subject, string $message, $headers = '', array $attachments = array() ): bool {
		/*
		 * An interception point before anything is sent.
		 *
		 * WordPress offered this and it is worth keeping for two reasons. The
		 * tests use it to read the message that would have gone out -- which is
		 * how "the confirmation link carries a token" is checked without a mail
		 * server -- and it is the natural place to put a queue later, so a slow
		 * SMTP handshake stops blocking the request that triggered it.
		 *
		 * Returning anything other than null means "already handled".
		 */
		$atts = array(
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $headers,
			'attachments' => $attachments,
		);

		$handled = apply_filters( 'pre_wp_mail', null, $atts );

		if ( null !== $handled ) {
			return (bool) $handled;
		}

		return App::mail()->send( $to, $subject, $message, $headers );
	}
}

/* ── Assets ──────────────────────────────────────────────────────────────── */

/*
 * A list, not a dependency graph.
 *
 * Two stylesheets and two modules, none depending on another. What matters is
 * that wp_localize_script() keeps working: it is how the journey receives its
 * endpoints, its logo, its consent copy and its estimate, and dropping it leaves
 * a page that renders and silently cannot save a draft or share a profile.
 */

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $version = false, string $media = 'all' ): void {
		if ( '' !== $src ) {
			\Serve\Platform\Assets::style( $handle, $src );
		}
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $version = false, $in_footer = false ): void {
		if ( '' !== $src ) {
			\Serve\Platform\Assets::module( $handle, $src );
		}
	}
}

if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
	function wp_enqueue_script_module( string $handle, string $src = '', array $deps = array(), $version = false ): void {
		if ( '' !== $src ) {
			\Serve\Platform\Assets::module( $handle, $src );
		}
	}
}

if ( ! function_exists( 'wp_register_script_module' ) ) {
	function wp_register_script_module( string $handle, string $src = '', array $deps = array(), $version = false ): void {
		wp_enqueue_script_module( $handle, $src, $deps, $version );
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/** @param array<string,mixed> $data */
	function wp_localize_script( string $handle, string $object, array $data ): bool {
		\Serve\Platform\Assets::localize( $handle, $object, $data );

		return true;
	}
}

if ( ! function_exists( 'wp_head' ) ) {
	function wp_head(): void {
		echo \Serve\Platform\Assets::render_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'wp_footer' ) ) {
	function wp_footer(): void {
		echo \Serve\Platform\Assets::render_scripts(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/* ── Cron ────────────────────────────────────────────────────────────────── */

/*
 * Scheduling lives in the options table and is run by bin/serve cron from the
 * system scheduler. This is a real improvement on WP-Cron, which only fires
 * when somebody visits the site -- the nightly digest of a quiet church was
 * exactly the job that would not run.
 */

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/** @return int|false */
	function wp_next_scheduled( string $hook, array $args = array() ) {
		$jobs = App::options()->get( 'serve_cron', array() );

		return isset( $jobs[ $hook ]['next'] ) ? (int) $jobs[ $hook ]['next'] : false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		$jobs = App::options()->get( 'serve_cron', array() );
		$jobs = is_array( $jobs ) ? $jobs : array();

		$jobs[ $hook ] = array(
			'next'       => $timestamp,
			'recurrence' => $recurrence,
		);

		App::options()->set( 'serve_cron', $jobs );

		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		return wp_schedule_event( $timestamp, 'once', $hook, $args );
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( int $timestamp, string $hook, array $args = array() ): bool {
		$jobs = App::options()->get( 'serve_cron', array() );
		$jobs = is_array( $jobs ) ? $jobs : array();

		unset( $jobs[ $hook ] );

		App::options()->set( 'serve_cron', $jobs );

		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = array() ): int {
		wp_unschedule_event( 0, $hook, $args );

		return 1;
	}
}

/* ── Things with no standalone meaning ───────────────────────────────────── */

/*
 * Declared rather than silently missing, and each returns something honest.
 * The plugin lifecycle, the admin menu and the asset queue are WordPress
 * concepts; this application has migrations, its own routes and its own layout.
 */

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, callable $fn ): void {}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( string $file, callable $fn ): void {}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( $file );
	}
}

if ( ! function_exists( 'get_privacy_policy_url' ) ) {
	function get_privacy_policy_url(): string {
		$page = App::options()->get( 'serve_privacy_url', '' );

		return is_string( $page ) ? $page : '';
	}
}
