<?php
/**
 * Test runner for the SERVE Dashboard plugin.
 *
 * The plugin has no build step and no package manager, and adding Composer and
 * the WordPress PHPUnit scaffold to run a dozen tests would be a bigger change
 * to this repository than anything it tests. So this boots a real WordPress and
 * exercises the plugin against a real database — which is what the guarantees
 * being checked here actually depend on. Every one of them is a SQL predicate
 * or a capability check; none of them would survive being mocked.
 *
 * Usage:
 *   php tools/run-tests.php --wp=/path/to/wordpress
 *   SERVE_WP_ROOT=/path/to/wordpress php tools/run-tests.php
 *
 * Add --filter=<substring> to run a subset.
 *
 * THIS WRITES TO THE DATABASE. It creates submissions, users and placements,
 * and deletes them again afterwards. Point it at a development install.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( "Run this from the command line.\n" );
}

$options = getopt( '', array( 'wp::', 'filter::' ) );

$wp_root = $options['wp'] ?? getenv( 'SERVE_WP_ROOT' ) ?: '';
if ( '' === $wp_root ) {
	fwrite(
		STDERR,
		"Where is WordPress? Pass --wp=/path/to/wordpress or set SERVE_WP_ROOT.\n" .
		"It must be an install with the plugin active — the tests exercise the\n" .
		"real schema, not a fixture of it.\n"
	);
	exit( 2 );
}

$wp_root = rtrim( str_replace( '\\', '/', $wp_root ), '/' );
if ( ! file_exists( "$wp_root/wp-load.php" ) ) {
	fwrite( STDERR, "No wp-load.php in $wp_root\n" );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require "$wp_root/wp-load.php";

/*
 * Two separate guards.
 *
 * The first cannot be overridden: if somebody has explicitly declared this
 * install to be production, no flag here should be able to talk the runner out
 * of it. The second exists because an unconfigured WordPress reports itself as
 * production by default, so absence of a declaration proves nothing either way
 * and the operator has to say so.
 */
if ( ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'production' === WP_ENVIRONMENT_TYPE )
	|| 'production' === getenv( 'WP_ENVIRONMENT_TYPE' ) ) {
	fwrite( STDERR, "This install declares itself production. Refusing to run.\n" );
	exit( 2 );
}

if ( ! getenv( 'SERVE_TEST_OK' ) ) {
	fwrite(
		STDERR,
		"These tests write to " . DB_NAME . " on " . DB_HOST . ".\n" .
		"Set SERVE_TEST_OK=1 to confirm that is a development database.\n"
	);
	exit( 2 );
}

if ( ! class_exists( 'Serve_Dashboard\\Schema' ) ) {
	fwrite( STDERR, "The serve-dashboard plugin is not active on this install.\n" );
	exit( 2 );
}

require __DIR__ . '/../tests/bootstrap.php';

$filter = (string) ( $options['filter'] ?? '' );

$files = glob( __DIR__ . '/../tests/test-*.php' );
sort( $files );
foreach ( $files as $file ) {
	require $file;
}

exit( Serve_Test\run( $filter ) );
