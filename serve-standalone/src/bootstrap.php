<?php
/**
 * One entry point for the web front controller, the CLI and the tests.
 *
 * Loads the compatibility layer before the domain classes, because the domain
 * classes call those functions at load time (constants, class constants
 * referencing Auth). Order here is not cosmetic.
 *
 * @package Serve
 */

declare(strict_types=1);

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	fwrite( STDERR, "SERVE needs PHP 8.1 or newer; this is " . PHP_VERSION . "\n" );
	exit( 1 );
}

foreach ( array( 'pdo_mysql', 'mbstring', 'json' ) as $serve_ext ) {
	if ( ! extension_loaded( $serve_ext ) ) {
		fwrite( STDERR, "SERVE needs the {$serve_ext} extension.\n" );
		exit( 1 );
	}
}

unset( $serve_ext );

define( 'SERVE_ROOT', dirname( __DIR__ ) );
define( 'SERVE_VERSION', '1.31.0' );

/*
 * A plain autoloader.
 *
 * Serve\Platform\Foo lives in src/Platform/Foo.php. The domain classes keep the
 * Serve_Dashboard namespace and the class-foo-bar.php filename they were ported
 * with, so their history stays greppable against the plugin they came from.
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( str_starts_with( $class, 'Serve\\Platform\\' ) ) {
			$file = SERVE_ROOT . '/src/Platform/' . substr( $class, strlen( 'Serve\\Platform\\' ) ) . '.php';

			if ( is_file( $file ) ) {
				require $file;
			}

			return;
		}

		if ( str_starts_with( $class, 'Serve_Dashboard\\' ) ) {
			$short = substr( $class, strlen( 'Serve_Dashboard\\' ) );
			// Rest_Dashboard -> class-rest-dashboard.php. The class names are already
			// underscore-separated, so no camel-case splitting: doing that would turn
			// Rest_Dashboard into rest--dashboard.
			$name  = strtolower( str_replace( '_', '-', $short ) );
			$file  = SERVE_ROOT . '/src/Domain/class-' . $name . '.php';

			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);

require SERVE_ROOT . '/src/compat-core.php';
require SERVE_ROOT . '/src/compat-app.php';

/**
 * Read configuration and start the application.
 *
 * @param string $path Config file; defaults to config/config.php.
 */
function serve_boot( string $path = '' ): void {
	$path = '' === $path ? SERVE_ROOT . '/config/config.php' : $path;

	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "No config at {$path}. Copy config/config.example.php and edit it.\n" );
		exit( 1 );
	}

	/** @var array<string,mixed> $config */
	$config = require $path;

	/*
	 * Nothing PHP says goes into the response.
	 *
	 * A notice printed mid-request does not just look untidy: it lands in the
	 * middle of a JSON body and the client fails to parse a response the server
	 * thinks it sent successfully. That is exactly what happened -- an undefined
	 * property warning appeared before the opening brace of a dashboard payload.
	 *
	 * Everything is still logged, and $config['debug'] puts it back on screen
	 * for local work. A stack trace names table columns and file paths, so it is
	 * off wherever a volunteer can reach.
	 */
	$serve_debug = ! empty( $config['debug'] );

	ini_set( 'display_errors', $serve_debug ? '1' : '0' );
	ini_set( 'log_errors', '1' );
	error_reporting( E_ALL );

	\Serve\Platform\App::boot( $config );

	/*
	 * The plugin's three constants, pointed at standalone locations.
	 *
	 * Defined rather than edited out of the ported code: Assessment builds the
	 * journey's asset URLs and its config blob from them, and that code is
	 * correct -- only the paths changed. Set here rather than at file scope
	 * because SERVE_URL depends on configuration that App::boot() has only just
	 * read.
	 */
	if ( ! defined( 'SERVE_DASHBOARD_DIR' ) ) {
		define( 'SERVE_DASHBOARD_DIR', SERVE_ROOT . '/' );
		define( 'SERVE_DASHBOARD_URL', rtrim( \Serve\Platform\App::url(), '/' ) . '/' );
		define( 'SERVE_DASHBOARD_VERSION', SERVE_VERSION );
	}

	// Everything the domain code needs registered: options, routes, cron hooks.
	\Serve_Dashboard\Bootstrap::init();
}
