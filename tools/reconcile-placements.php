<?php
/**
 * Report, and optionally withdraw, team access the ranking no longer gives.
 *
 * Suggestions became strong matches only, and that applies from the moment it
 * shipped. Everybody already in the system keeps the placement rows they were
 * given under the old rules, and those rows are what decide which ministry
 * leaders may read a profile — so the drift is an access question, not a
 * tidiness one.
 *
 * Usage:
 *   php tools/reconcile-placements.php --wp=/path/to/wordpress
 *   php tools/reconcile-placements.php --wp=/path/to/wordpress --apply
 *
 * IT REPORTS BY DEFAULT AND CHANGES NOTHING. --apply deletes the rows it listed,
 * and additionally requires SERVE_RECONCILE_OK=1, because the person running it
 * should have read the list first. Every deletion is written to the audit trail.
 *
 * What is never listed, and the distinction is the whole safety of this:
 *
 *   - A placement anything has happened on. Past `submitted`, or carrying an
 *     owner, a follow-up date, a decline reason or notes. Those are
 *     relationships: somebody halfway through a trial serve must not lose the
 *     leader walking them through it because a team's vocabulary was edited.
 *   - A catch-all row, which was never a claim of fit and so cannot have drifted
 *     from one.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( "Run this from the command line.\n" );
}

$options = getopt( '', array( 'wp::', 'apply' ) );

$wp_root = $options['wp'] ?? getenv( 'SERVE_WP_ROOT' ) ?: '';
if ( '' === $wp_root ) {
	fwrite( STDERR, "Where is WordPress? Pass --wp=/path/to/wordpress or set SERVE_WP_ROOT.\n" );
	exit( 2 );
}

$wp_root = rtrim( str_replace( '\\', '/', $wp_root ), '/' );
if ( ! file_exists( "$wp_root/wp-load.php" ) ) {
	fwrite( STDERR, "No wp-load.php in $wp_root\n" );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require "$wp_root/wp-load.php";

if ( ! class_exists( 'Serve_Dashboard\\Placements' ) ) {
	fwrite( STDERR, "The serve-dashboard plugin is not active on this install.\n" );
	exit( 2 );
}

$apply = isset( $options['apply'] );

/*
 * Deleting access rows on a live install is not something to do by reflex, and
 * a flag alone is one keystroke. The second confirmation is the same shape as
 * the test runner's, for the same reason.
 */
if ( $apply && ! getenv( 'SERVE_RECONCILE_OK' ) ) {
	fwrite(
		STDERR,
		"--apply deletes placement rows on " . DB_NAME . " at " . DB_HOST . ".\n" .
		"Run it without --apply first and read the list.\n" .
		"Then set SERVE_RECONCILE_OK=1 to confirm.\n"
	);
	exit( 2 );
}

$stale = Serve_Dashboard\Placements::stale_rows();

if ( ! $stale ) {
	echo "\nNothing to reconcile. Every team with access is one the ranking still suggests.\n\n";
	exit( 0 );
}

printf(
	"\n%d placement%s grant%s access to a team the ranking would not suggest today.\n",
	count( $stale ),
	1 === count( $stale ) ? '' : 's',
	1 === count( $stale ) ? 's' : ''
);
echo "Nothing has happened on any of them: no conversation, no owner, no notes.\n\n";

$by_person = array();
foreach ( $stale as $row ) {
	$by_person[ $row['person'] ][] = $row;
}

foreach ( $by_person as $person => $rows ) {
	printf( "  %s\n", $person );

	foreach ( $rows as $row ) {
		printf( "    - %s loses access\n", $row['team_name'] );
	}

	$would = $rows[0]['would_suggest'];
	printf(
		"      suggested today: %s\n",
		$would ? implode( ', ', $would ) : 'nothing — a pastor keeps them'
	);
}

if ( ! $apply ) {
	echo "\nThis changed nothing. To carry it out:\n";
	echo "  SERVE_RECONCILE_OK=1 php tools/reconcile-placements.php --wp=" . $wp_root . " --apply\n\n";
	exit( 0 );
}

$retired = 0;
$skipped = 0;

foreach ( $stale as $row ) {
	if ( Serve_Dashboard\Placements::retire( (int) $row['placement_id'] ) ) {
		++$retired;
		continue;
	}

	/*
	 * Re-checked at the moment of deletion rather than trusted from the list
	 * above, so a conversation that started while this was being read keeps its
	 * placement.
	 */
	++$skipped;
	printf( "  kept %s / %s — it is no longer untouched\n", $row['person'], $row['team_name'] );
}

printf( "\n%d withdrawn, %d kept. Each one is in the audit trail.\n", $retired, $skipped );

/*
 * Somebody whose last matched placement was just withdrawn now matches nothing
 * and owns nobody, which is not a state the current rules can produce: a new
 * submission in that position is handed to the catch-all. Without this, running
 * the reconcile would leave people worse off than if they had submitted today.
 */
$adopted = 0;

foreach ( array_unique( array_column( $stale, 'submission_id' ) ) as $submission_id ) {
	$submission = Serve_Dashboard\Submissions::get( (int) $submission_id );
	if ( ! $submission ) {
		continue;
	}

	if ( Serve_Dashboard\Submissions::decode_list( $submission->suggested_teams ) ) {
		continue;
	}

	if ( Serve_Dashboard\Placements::assign_catchall( (int) $submission_id, array() ) ) {
		++$adopted;
	}
}

if ( $adopted ) {
	printf( "%d now match nothing and were handed to the catch-all team.\n", $adopted );
}

echo "\n";

exit( 0 );
