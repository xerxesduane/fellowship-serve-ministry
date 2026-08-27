<?php
/**
 * Plugin Name:       SERVE Dashboard
 * Plugin URI:        https://serve.fellowshipdubai.com/
 * Description:       Stores completed S.H.A.P.E. profiles and gives ministry leaders a scoped, auditable view of who is ready to serve, what follow-up is due, and where teams are short of people.
 * Version:           1.10.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Fellowship Dubai
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       serve-dashboard
 * Domain Path:       /languages
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SERVE_DASHBOARD_VERSION', '1.10.0' );
define( 'SERVE_DASHBOARD_FILE', __FILE__ );
define( 'SERVE_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVE_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );

/** Cron hook for the retention sweep. */
const CRON_RETENTION = 'serve_dashboard_retention_sweep';

foreach (
	array(
		'class-schema.php',
		'class-privacy.php',
		'class-audit.php',
		'class-roles.php',
		'class-consent.php',
		'class-teams.php',
		'class-safeguarding.php',
		'class-verification.php',
		'class-followup.php',
		'class-draft.php',
		'class-digest.php',
		'class-export.php',
		'class-funnel.php',
		'class-pilot.php',
		'class-hardening.php',
		'class-security-status.php',
		'class-submissions.php',
		'class-matching.php',
		'class-planning-center.php',
		'class-metrics.php',
		'class-rest.php',
		'class-rest-dashboard.php',
		'class-admin.php',
		'class-app.php',
		'class-assessment.php',
		'class-shortcode.php',
	) as $file
) {
	require_once SERVE_DASHBOARD_DIR . 'includes/' . $file;
}

/**
 * Activation: tables, roles, seed teams, retention cron.
 */
function activate(): void {
	Schema::install();
	Roles::install();
	Teams::seed_defaults();
	Assessment::install_pages();
	Digest::schedule();

	if ( ! wp_next_scheduled( CRON_RETENTION ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', CRON_RETENTION );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Deactivation: drop roles and the cron job. Data is left untouched, so a
 * reactivation does not lose anyone's profile.
 */
function deactivate(): void {
	Roles::uninstall();
	Digest::unschedule();

	$timestamp = wp_next_scheduled( CRON_RETENTION );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, CRON_RETENTION );
	}
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

/**
 * Boot.
 */
function bootstrap(): void {
	load_plugin_textdomain( 'serve-dashboard', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	Schema::maybe_upgrade();
	Rest::register();
	Rest_Dashboard::register();
	Admin::register();
	App::register();
	Assessment::register();
	Verification::register();
	Digest::register();
	Export::register();
	Funnel::register();
	Hardening::register();
	Shortcode::register();

	add_action( CRON_RETENTION, array( Privacy::class, 'run_retention_sweep' ) );
	add_action( CRON_RETENTION, array( Verification::class, 'purge_unverified' ) );
	add_action( CRON_RETENTION, array( Draft::class, 'purge_expired' ) );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
