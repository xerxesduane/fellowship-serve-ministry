<?php
/**
 * Plugin Name:       SERVE Dashboard
 * Plugin URI:        https://serve.fellowshipdubai.com/
 * Description:       Stores completed S.H.A.P.E. profiles and gives ministry leaders a scoped, auditable view of who is ready to serve, what follow-up is due, and where teams are short of people.
 * Version:           1.30.1
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

define( 'SERVE_DASHBOARD_VERSION', '1.30.1' );
define( 'SERVE_DASHBOARD_FILE', __FILE__ );
define( 'SERVE_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SERVE_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );

/** Cron hook for the retention sweep. */
const CRON_RETENTION = 'serve_dashboard_retention_sweep';

foreach (
	array(
		'class-schema.php',
		'class-privacy.php',
		'class-privacy-page.php',
		'class-audit.php',
		'class-roles.php',
		'class-consent.php',
		'class-teams.php',
		'class-placements.php',
		'class-safeguarding.php',
		'class-verification.php',
		'class-followup.php',
		'class-invitation.php',
		'class-draft.php',
		'class-digest.php',
		'class-export.php',
		'class-funnel.php',
		'class-friction.php',
		'class-pilot.php',
		'class-hardening.php',
		'class-security-status.php',
		'class-submissions.php',
		/*
		 * The matching stack, innermost first. Nothing here touches the
		 * database or WordPress state at load time, which is what lets the
		 * taxonomy and contract be exercised by a runner with no WordPress at
		 * all — see tools/run-unit-tests.php.
		 */
		'class-gift-taxonomy.php',
		'class-gift-crosswalk.php',
		'class-matching-contract.php',
		'class-corroboration.php',
		'class-gift-ratings.php',
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
	/*
	 * maybe_upgrade() rather than install(), so activation and an in-place
	 * upgrade take the same path and the version is stamped in exactly one
	 * place. Every migrate() branch is gated on a non-empty previous version,
	 * so a fresh activation runs none of them.
	 */
	Schema::maybe_upgrade();
	Roles::install();
	Teams::seed_defaults();
	Assessment::install_pages();

	// Left as a draft. Text about religious belief goes live when a person
	// decides it should, not when a plugin is switched on.
	Privacy_Page::install();

	ensure_cron_scheduled();
}

/**
 * Make sure every job this version needs is on the schedule.
 *
 * Called on activation and on every boot, because activation runs once and the
 * set of jobs has grown since. The weekly digest arrived after this plugin was
 * first activated anywhere, so an install that upgraded in place never
 * scheduled it and its leaders simply never received the Monday email they were
 * told to expect. Nothing said so: the job was absent rather than failing.
 *
 * Each guard is a no-op once the job exists, so booting costs two cheap reads.
 */
function ensure_cron_scheduled(): void {
	if ( ! wp_next_scheduled( CRON_RETENTION ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', CRON_RETENTION );
	}

	Digest::schedule();
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
	ensure_cron_scheduled();
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
