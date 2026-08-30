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
 * It refuses an install that declares itself production, refuses to run at all
 * without SERVE_TEST_OK=1, and refuses a database that already holds
 * submissions it did not create.
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

/*
 * The third guard, and the one that would actually have caught it.
 *
 * The two above ask whether somebody *declared* this install safe. A local
 * WordPress holding a congregation's real profiles declares nothing at all, so
 * both of them wave it through — and this runner writes: it creates
 * submissions, users and placements, and deletes them again. Pointed at a real
 * database by a mistyped path or a copied command, it operates on real people's
 * pastoral history.
 *
 * So the last question is not about declarations but about the data: does this
 * database already hold submissions this runner did not create? If it does,
 * refuse. A development database is normally empty of people between runs,
 * because the suite cleans up after itself, so this costs a correctly used
 * install nothing.
 *
 * Teams are not counted. Sixteen of them are seeded on activation and are
 * configuration rather than anybody's data, so requiring an empty teams table
 * would refuse every properly set-up install.
 *
 * SERVE_TEST_ALLOW_EXISTING=1 exists for the case where somebody genuinely
 * wants to run against a scratch database that has test rows left in it from an
 * interrupted run. It is deliberately a second, differently named flag: a
 * person who has SERVE_TEST_OK=1 saved in their shell should still have to stop
 * and think here.
 */
$serve_people = (int) $wpdb->get_var(
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input.
	'SELECT COUNT(*) FROM ' . Serve_Dashboard\Schema::table( 'submissions' )
);

if ( $serve_people > 0 && ! getenv( 'SERVE_TEST_ALLOW_EXISTING' ) ) {
	fwrite(
		STDERR,
		sprintf(
			"REFUSING TO RUN.\n\n" .
			"%s on %s already holds %d submission(s) this runner did not create.\n\n" .
			"These tests write to the database they are pointed at. If those rows are\n" .
			"real people's profiles, running here would create and delete records\n" .
			"alongside them, and the Experiences section holds pastoral history.\n\n" .
			"Point --wp at a development install with an empty submissions table.\n" .
			"If this really is a scratch database with leftover test rows, set\n" .
			"SERVE_TEST_ALLOW_EXISTING=1 as well.\n",
			DB_NAME,
			DB_HOST,
			$serve_people
		)
	);
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
