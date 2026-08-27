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

delete_option( Serve_Dashboard\Schema::OPTION_DB_VERSION );
delete_option( 'serve_dashboard_retention_months' );
