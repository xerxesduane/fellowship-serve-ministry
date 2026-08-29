<?php
/**
 * Runs when the plugin is deleted, not merely deactivated.
 *
 * Deleting the plugin removes the profiles, because keeping sensitive personal
 * data in the database after its only consumer has been removed is exactly the
 * situation retention rules exist to prevent.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-schema.php';

global $wpdb;

foreach ( Serve_Dashboard\Schema::table_names() as $name ) {
	$table = Serve_Dashboard\Schema::table( $name );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from a fixed list.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

/*
 * Every option the plugin writes, not only the two that were easy to remember.
 *
 * None of these hold personal data, so leaving them behind was untidy rather
 * than unsafe — except the funnel counters, which would have carried a previous
 * installation's drop-off figures into a fresh one and quietly misreported
 * where people stop.
 *
 * Named as literals rather than via the class constants because uninstall.php
 * loads Schema alone; requiring seven more classes here, each with its own
 * dependencies, to read seven strings would be the more fragile choice. The
 * test in tests/test-lifecycle.php asserts this list stays complete.
 */
foreach (
	array(
		'serve_dashboard_db_version',
		'serve_dashboard_retention_months',
		'serve_dashboard_contact_email',
		'serve_dashboard_assessment_page',
		'serve_dashboard_consent_page',
		'serve_dashboard_digest_enabled',
		'serve_dashboard_pco_subdomain',
		'serve_dashboard_serving_form_url',
		'serve_dashboard_catchall_team',
		'serve_dashboard_funnel',
	) as $option
) {
	delete_option( $option );
}

// One per visitor per step per day, so a busy month leaves a lot of them.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_serve\_fn\_%'
	    OR option_name LIKE '\_transient\_timeout\_serve\_fn\_%'
	    OR option_name LIKE '\_transient\_serve\_gifts\_%'
	    OR option_name LIKE '\_transient\_timeout\_serve\_gifts\_%'"
);
