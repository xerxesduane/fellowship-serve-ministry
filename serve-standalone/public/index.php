<?php
/**
 * The front controller.
 *
 * Every request enters here. This is the whole of what WordPress's index.php,
 * admin.php, wp-login.php and the REST bootstrap did between them, for this one
 * application.
 *
 * Only this directory needs to be web-accessible. src/, config/, migrations/
 * and bin/ sit above it and are unreachable over HTTP no matter how the server
 * is configured, which is a real improvement on a plugin living inside a
 * document root.
 *
 * @package Serve
 */

declare(strict_types=1);

require dirname( __DIR__ ) . '/src/bootstrap.php';

use Serve\Platform\App;
use Serve\Platform\Auth;
use Serve\Platform\Router;
use Serve_Dashboard\Assessment;
use Serve_Dashboard\Hardening;
use Serve_Dashboard\Privacy_Page;
use Serve_Dashboard\Roles;

serve_boot();

/*
 * Resolve the path this application was asked for.
 *
 * Taken from the request rather than from PATH_INFO so it works with a plain
 * "everything to index.php" rewrite and with the PHP development server.
 */
$serve_uri  = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$serve_base = rtrim( (string) parse_url( App::url(), PHP_URL_PATH ), '/' );

if ( '' !== $serve_base && str_starts_with( $serve_uri, $serve_base ) ) {
	$serve_uri = substr( $serve_uri, strlen( $serve_base ) );
}

$serve_path = trim( rawurldecode( $serve_uri ), '/' );

// Whoever is asking, if anyone.
App::auth()->resume_session();

/* ── The API ─────────────────────────────────────────────────────────────── */

if ( str_starts_with( $serve_path, 'api/' ) ) {
	nocache_headers();

	$route = substr( $serve_path, strlen( 'api' ) );

	/*
	 * Cross-site request forgery, for the writes only.
	 *
	 * The intake endpoint is deliberately open -- a participant filling in the
	 * journey has no session and no token -- and it defends itself by rate
	 * limiting and by refusing to trust anything the browser claims. Everything
	 * that acts on somebody's behalf needs the header.
	 */
	$serve_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
	$serve_public = '/serve/v1/submissions' === $route || str_starts_with( $route, '/serve/v1/drafts' );

	if ( 'GET' !== $serve_method && ! $serve_public ) {
		$serve_token = (string) ( $_SERVER['HTTP_X_WP_NONCE'] ?? '' );

		/*
		 * 'wp_rest' is the action name App::config() mints the token under.
		 *
		 * The two have to agree and an earlier version of this had them minted
		 * as 'wp_rest' and verified as 'serve_rest', which fails every write
		 * with a 403 that reads like a permissions problem.
		 */
		if ( ! App::auth()->csrf_ok( $serve_token, 'wp_rest' ) ) {
			Router::send(
				Router::error_response(
					new WP_Error(
						'serve_bad_nonce',
						__( 'That page has been open a while. Reload it and try again.' ),
						array( 'status' => 403 )
					)
				)
			);
			exit;
		}
	}

	Router::send( Router::dispatch( Router::from_globals( $route ) ) );
	exit;
}

/* ── Static assets ───────────────────────────────────────────────────────── */

/*
 * Served by PHP only as a fallback.
 *
 * A real deployment lets the web server handle these; this exists so the app
 * works under `php -S` and behind a rewrite that sends everything here. The
 * allowlist of extensions and the realpath check together mean a crafted path
 * cannot read config.php.
 */
/*
 * A leading "public/" is accepted as an alias.
 *
 * The ported code builds asset URLs as SERVE_DASHBOARD_URL . 'public/...',
 * because in the plugin the assets sat in a public/ subdirectory of the plugin
 * folder. Here public/ *is* the document root, so those URLs arrive as
 * /public/assessment/styles.css.
 *
 * Accepting the prefix costs one alternation and leaves the ported asset paths
 * untouched. Rewriting them instead would mean editing several files to change
 * a string that is correct in the build they came from.
 */
if ( preg_match( '#^(?:public/)?(?:assessment|admin)/#', $serve_path ) ) {
	$serve_relative = preg_replace( '#^public/#', '', $serve_path );
	$serve_file     = realpath( __DIR__ . '/' . $serve_relative );
	$serve_ok   = false !== $serve_file && str_starts_with( $serve_file, realpath( __DIR__ ) . DIRECTORY_SEPARATOR );

	$serve_types = array(
		'js'   => 'text/javascript; charset=utf-8',
		'mjs'  => 'text/javascript; charset=utf-8',
		'css'  => 'text/css; charset=utf-8',
		'jpeg' => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'svg'  => 'image/svg+xml',
	);

	$serve_ext = strtolower( pathinfo( (string) $serve_relative, PATHINFO_EXTENSION ) );

	if ( $serve_ok && is_file( $serve_file ) && isset( $serve_types[ $serve_ext ] ) ) {
		header( 'Content-Type: ' . $serve_types[ $serve_ext ] );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=300' );
		readfile( $serve_file );
		exit;
	}

	http_response_code( 404 );
	exit;
}

/* ── Pages ───────────────────────────────────────────────────────────────── */

Hardening::send_headers( ! in_array( $serve_path, array( '', 'assessment', 'privacy' ), true ) );

/** Render a view with the shared layout. */
function serve_view( string $view, array $data = array(), string $title = 'SERVE' ): void {
	$serve_title = $title;
	$serve_body  = $view;

	extract( $data, EXTR_SKIP );

	require SERVE_ROOT . '/views/layout.php';
}

/** Refuse a signed-in caller who may not use this screen. */
function serve_denied(): void {
	http_response_code( 403 );

	serve_view(
		'message',
		array(
			'heading' => __( 'You do not have access to this screen' ),
			'detail'  => __( 'If you think you should, ask whoever set up your account to check your role.' ),
		),
		__( 'No access' )
	);

	exit;
}

/** Send somebody to the login screen, remembering where they were going. */
function serve_require_login( string $wanted ): void {
	if ( App::auth()->current_id() > 0 ) {
		return;
	}

	wp_safe_redirect( App::url( 'login' ) . ( '' === $wanted ? '' : '?next=' . rawurlencode( $wanted ) ) );
	exit;
}

switch ( $serve_path ) {

	case '':
	case 'assessment':
		/*
		 * The journey.
		 *
		 * Assessment::render() is the plugin's shortcode handler, unchanged --
		 * it was never WordPress-specific, it just needed something to call it.
		 */
		require SERVE_ROOT . '/views/canvas.php';
		exit;

	case 'privacy':
		serve_view( 'privacy', array( 'notice' => Privacy_Page::render() ), __( 'Privacy notice' ) );
		exit;

	case 'login':
		require SERVE_ROOT . '/views/login.php';
		exit;

	case 'logout':
		/*
		 * A POST, because a GET logout is a one-pixel-image attack: anybody can
		 * put <img src=".../logout"> on a page and sign your leaders out.
		 */
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			check_admin_referer( 'serve_logout' );
			App::auth()->logout();
		}

		wp_safe_redirect( App::url( 'login' ) );
		exit;

	case 'dashboard':
		serve_require_login( 'dashboard' );

		if ( ! current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) {
			serve_denied();
		}

		/*
		 * Its own document, not wrapped in views/layout.php.
		 *
		 * The dashboard shell carries its own sidebar and mobile bar, copied
		 * from the plugin. Putting the layout's top bar above it would give the
		 * screen two competing navigations -- and the requirement is that this
		 * looks exactly like the plugin's, which has one.
		 */
		require SERVE_ROOT . '/views/dashboard.php';
		exit;

	case 'teams':
		serve_require_login( 'teams' );

		if ( ! current_user_can( Roles::CAP_MANAGE_TEAMS ) ) {
			serve_denied();
		}

		serve_view( 'teams', array(), __( 'Teams and gaps' ) );
		exit;

	case 'confirm':
		// The email confirmation link. Handled by the domain class that issued it.
		require SERVE_ROOT . '/views/confirm.php';
		exit;

	default:
		http_response_code( 404 );
		serve_view(
			'message',
			array(
				'heading' => __( 'That page does not exist' ),
				'detail'  => '',
			),
			__( 'Not found' )
		);
		exit;
}
