<?php
/**
 * Runner for the parts of matching that need no database.
 *
 * The main suite boots the application against a real MySQL, because every
 * guarantee it checks is a SQL predicate or a capability check and none of them
 * would survive being mocked. That is still true, and
 * `serve-standalone/tools/run-tests.php` is still where those live.
 *
 * But the taxonomy, the crosswalk and the tier contract are arithmetic over
 * arrays. They are the part most likely to be wrong in a way nobody notices —
 * the whole reason this work happened was a string comparison that silently
 * matched twelve of twenty-two terms — and they were reachable only through a
 * runner that needs a database to be stood up first. So they get a runner that
 * needs nothing, and can be run on any machine, in CI, and in a container with
 * no MySQL in it.
 *
 * The team rows are supplied as plain objects rather than read from a database,
 * which is the one thing being faked here, and it is a fair thing to fake: the
 * seeded rows are a constant in `Teams::seed()` and the tests assert they match
 * the church's own Ministry–Gift Table.
 *
 * Usage:
 *   php tools/run-unit-tests.php [--filter=substring]
 *
 * Writes nothing, reads nothing, needs no confirmation flag.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( "Run this from the command line.\n" );
}

define( 'ABSPATH', __DIR__ . '/' );

$src = dirname( __DIR__ ) . '/serve-standalone/src';

/*
 * The handful of WordPress functions the matching stack touches. Each is the
 * real behaviour for the inputs used here: translation is identity, the filter
 * hooks return their default, and current_time returns a UTC timestamp.
 */
require __DIR__ . '/../tests/unit/wp-stubs.php';

require "$src/Domain/class-gift-taxonomy.php";
require "$src/Domain/class-gift-crosswalk.php";
require "$src/Domain/class-matching-contract.php";
require "$src/Domain/class-corroboration.php";
require "$src/Domain/class-gift-ratings.php";

/*
 * Teams and Matching are loaded through a shim that supplies the four static
 * methods the matcher calls on Teams — all() and gift_list() are what the
 * crosswalk and the ranking read, keyword_list() feeds unscored context, and
 * get() is unused here. The shim is defined before the real class file is
 * required, so the real Teams (which needs $wpdb) is never loaded.
 */
require __DIR__ . '/../tests/unit/teams-shim.php';
require "$src/Domain/class-matching.php";

require __DIR__ . '/../tests/unit/harness.php';

$options = getopt( '', array( 'filter::' ) );
$filter  = (string) ( $options['filter'] ?? '' );

foreach ( glob( __DIR__ . '/../tests/unit/test-*.php' ) ?: array() as $file ) {
	require $file;
}

exit( Serve_Unit\run( $filter ) );
