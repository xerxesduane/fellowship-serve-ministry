<?php
/**
 * Run the suite against the standalone stack.
 *
 * The test files themselves are the plugin's, unchanged. That is the whole
 * point: 168 tests written against the WordPress build are the only honest way
 * to show that dropping WordPress did not change the behaviour underneath. A
 * port "verified" by tests rewritten alongside it proves nothing.
 *
 * Usage:
 *   php tools/run-tests.php [--config=…] [--tests=…] [--filter=substring]
 *
 * Refuses to run against a database that holds real submissions unless
 * SERVE_TEST_ALLOW_EXISTING=1, because the fixtures write and delete rows.
 *
 * @package Serve
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit;
}

if ( '1' !== (string) getenv( 'SERVE_TEST_OK' ) ) {
	fwrite(
		STDERR,
		"Refusing to run without SERVE_TEST_OK=1.\n"
		. "These tests write to the configured database. Point the config at a test\n"
		. "database first, then:  SERVE_TEST_OK=1 php tools/run-tests.php\n"
	);
	exit( 1 );
}

require dirname( __DIR__ ) . '/src/bootstrap.php';

use Serve\Platform\App;
use Serve\Platform\Migrator;
use Serve_Dashboard\Schema;

/** Read a --flag=value argument. */
function serve_flag( string $name, string $default = '' ): string {
	foreach ( (array) ( $_SERVER['argv'] ?? array() ) as $arg ) {
		if ( is_string( $arg ) && str_starts_with( $arg, "--{$name}=" ) ) {
			return substr( $arg, strlen( $name ) + 3 );
		}
	}

	return $default;
}

serve_boot( serve_flag( 'config' ) );

/*
 * The schema has to be there before the fixtures run.
 *
 * A test database is usually empty, and failing with "table does not exist"
 * when the fix is one command away wastes everybody's time.
 */
$migrator = new Migrator( App::db() );

if ( array() !== $migrator->pending() ) {
	$result = $migrator->migrate();

	if ( '' !== $result['error'] ) {
		fwrite( STDERR, "Could not prepare the schema: {$result['error']}\n" );
		exit( 1 );
	}
}

Schema::maybe_upgrade();

// The seeded teams are real rows the tests rely on, not fixtures.
\Serve_Dashboard\Teams::seed_defaults();

$db          = App::db();
$submissions = (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'submissions' ) );

if ( $submissions > 0 && '1' !== (string) getenv( 'SERVE_TEST_ALLOW_EXISTING' ) ) {
	fwrite(
		STDERR,
		"There are {$submissions} submissions in this database.\n"
		. "The fixtures create and delete rows, so this is refused by default.\n"
		. "Set SERVE_TEST_ALLOW_EXISTING=1 if this really is a test database.\n"
	);
	exit( 1 );
}

/*
 * The tests are shared with the plugin build.
 *
 * Pointed at the plugin's tests/ directory by default so there is exactly one
 * copy of them. Two copies would drift, and the version that drifts is always
 * the one nobody is watching.
 */
$tests_dir = serve_flag( 'tests', dirname( SERVE_ROOT ) . '/tests' );

if ( ! is_dir( $tests_dir ) ) {
	fwrite( STDERR, "No tests directory at {$tests_dir}\n" );
	exit( 1 );
}

require $tests_dir . '/bootstrap.php';

$filter = serve_flag( 'filter' );

$files = array_merge(
	(array) glob( $tests_dir . '/test-*.php' ),
	// Tests for behaviour that only exists here, such as the privacy notice
	// being a rendered route rather than a stored page.
	(array) glob( SERVE_ROOT . '/tests/test-*.php' )
);

$files = array_values( array_filter( $files, 'is_string' ) );

sort( $files );

foreach ( $files as $file ) {
	require $file;
}

/*
 * Tests that cannot run here, listed rather than quietly dropped.
 *
 * Each of these asserts something about a WordPress wp_post: that a page exists,
 * carries a shortcode, is created as a draft, or is left alone once edited. That
 * is machinery for getting content into WordPress, not a promise made to anyone
 * whose data this holds -- and there is no posts table to assert against.
 *
 * Named individually, with a reason, and printed on every run. A suite that
 * silently skips is one whose pass count means less than it appears to, and the
 * guarantee these protected is covered by tests/test-privacy-notice.php.
 */
$not_applicable = array(
	'both public pages exist and carry their shortcode'               => 'WordPress pages; the journey and the notice are routes here',
	'the privacy notice never overwrites text somebody has edited'    => 'no stored page to overwrite; the notice renders per request',
	'a privacy notice created from nothing is left as a draft'        => 'no wp_post to create; see tests/test-privacy-notice.php',
	'the notice states the retention period the system actually uses' => 'reads post content; replaced by a direct test of render()',
);

/*
 * Fixture leaks are checked the way the plugin's runner checks them: count the
 * rows before and after, and report a difference rather than letting the next
 * run inherit it.
 */
/*
 * Only two tables decide pass or fail, matching the shared harness:
 * submissions and feedback. Everything else is counted and reported without
 * failing the run, because a difference there is not necessarily a leak.
 *
 * The audit trail is the clear case. It is append-only by design -- who looked
 * at whose spiritual gifts, and who changed what -- so tests legitimately add
 * hundreds of rows and none of them should be deleted afterwards. Treating that
 * as a leak was this runner inventing a failure, and it reported one for several runs before the shared harness was actually checked.
 */
$fail_on = array( 'submissions', 'feedback' );

$before = array();

foreach ( Schema::table_names() as $name ) {
	$before[ $name ] = (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) );
}

$passed     = 0;
$failed     = 0;
$skipped    = 0;
$assertions = 0;

echo "\n";

foreach ( $GLOBALS['serve_tests'] as $test ) {
	$name = (string) $test['name'];

	if ( '' !== $filter && ! str_contains( $name, $filter ) ) {
		continue;
	}

	if ( isset( $not_applicable[ $name ] ) ) {
		echo "  - {$name}\n      not applicable: {$not_applicable[ $name ]}\n";
		++$skipped;
		continue;
	}

	$assert  = new \Serve_Test\Assert();
	$fixture = new \Serve_Test\Fixtures();

	try {
		( $test['fn'] )( $assert, $fixture );

		if ( 0 === $assert->count ) {
			throw new \Serve_Test\Failure( 'no assertions: a test that cannot fail is worse than no test' );
		}

		echo "  \u{2713} {$name}\n";
		++$passed;
	} catch ( \Throwable $e ) {
		echo "  \u{2717} {$name}\n      " . $e->getMessage() . "\n";

		if ( ! $e instanceof \Serve_Test\Failure ) {
			echo '      ' . $e->getFile() . ':' . $e->getLine() . "\n";
		}

		++$failed;
	} finally {
		$assertions += $assert->count;

		try {
			$fixture->cleanup();
		} catch ( \Throwable $e ) {
			echo '      cleanup failed: ' . $e->getMessage() . "\n";
		}

		wp_set_current_user( 0 );
	}
}

echo "\n  {$passed} passed, {$failed} failed, {$skipped} not applicable, {$assertions} assertions\n\n";

$leaked = array();
$grew   = array();

foreach ( Schema::table_names() as $name ) {
	$now  = (int) $db->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $name ) );
	$diff = $now - $before[ $name ];

	if ( 0 === $diff ) {
		continue;
	}

	$line = sprintf( '%s %+d', $name, $diff );

	if ( in_array( $name, $fail_on, true ) ) {
		$leaked[] = $line;
	} else {
		$grew[] = $line;
	}
}

if ( array() !== $leaked ) {
	echo '  fixtures left rows behind: ' . implode( ', ', $leaked ) . "\n\n";
}

if ( array() !== $grew ) {
	echo '  (append-only tables grew, which is expected: ' . implode( ', ', $grew ) . ")\n\n";
}

exit( $failed > 0 || array() !== $leaked ? 1 : 0 );
