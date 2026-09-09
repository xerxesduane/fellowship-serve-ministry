<?php
/**
 * Wiring.
 *
 * Replaces the plugin main file's bootstrap() and the 'plugins_loaded' hook it
 * hung off. Every ::register() call below is the same call the plugin made, in
 * the same order, because the order matters in one place: Rest before
 * Rest_Dashboard, since both add routes to the same namespace and the more
 * specific ones must be registered first.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve_Dashboard;

final class Bootstrap {

	/*
	 * The same hook names the plugin used.
	 *
	 * Digest registers its own hook in Digest::register(), so the digest name
	 * here has to be that one -- inventing a second name would register a job
	 * the runner schedules and nothing listens to, which is worse than not
	 * scheduling it at all because the schedule would look correct.
	 */
	public const CRON_RETENTION = 'serve_dashboard_retention_sweep';
	public const CRON_DIGEST    = Digest::CRON_HOOK;

	private static bool $done = false;

	public static function init(): void {
		if ( self::$done ) {
			return;
		}

		self::$done = true;

		/*
		 * Routes.
		 *
		 * These registered themselves on WordPress's 'rest_api_init'. Here they
		 * register at boot, which is simpler and has one real consequence: the
		 * route table is built on every request including page views, not only
		 * on API calls. That is a few hundred array writes and no queries.
		 */
		Rest::register();
		Rest_Dashboard::register();

		Assessment::register();
		Verification::register();
		Digest::register();
		Export::register();
		Funnel::register();
		Hardening::register();

		/*
		 * Scheduled work.
		 *
		 * Run by bin/serve cron from the system scheduler rather than by
		 * WP-Cron, which only fired when somebody happened to visit the site --
		 * so the nightly digest of a quiet church was exactly the job that
		 * would not run.
		 */
		add_action( self::CRON_RETENTION, array( Privacy::class, 'run_retention_sweep' ) );
		add_action( self::CRON_RETENTION, array( Verification::class, 'purge_unverified' ) );
		add_action( self::CRON_RETENTION, array( Draft::class, 'purge_expired' ) );
		add_action( self::CRON_RETENTION, array( self::class, 'prune_sessions' ) );

		// Digest::register() above already hooked CRON_DIGEST to send_all().

		/*
		 * Now actually register the routes.
		 *
		 * Every ::register() above only *hooks* its route registration to
		 * 'rest_api_init', which WordPress fired on API requests. Nothing fires
		 * it here, so without this line the route table was empty and every
		 * request returned rest_no_route -- which is precisely what happened,
		 * and what seventy tests reported.
		 *
		 * Fired rather than bypassed by calling register_routes() directly,
		 * because the hook is a real extension point: anything else attached to
		 * it registers here too.
		 */
		do_action( 'rest_api_init' );

		self::ensure_cron_scheduled();
	}

	/**
	 * Make sure the scheduled jobs exist, every time this boots.
	 *
	 * Checked on boot rather than only at install, and the plugin learned this
	 * the hard way: a site upgraded rather than freshly activated never
	 * scheduled the digest, and its leaders simply never received the Monday
	 * email they had been told to expect. Nothing reported it -- the job was
	 * absent rather than failing.
	 *
	 * Retention is the one that matters most. It is a legal commitment, not a
	 * nicety: if the sweep is not scheduled then nothing is ever deleted and
	 * nobody finds out.
	 *
	 * Each guard is a no-op once the job exists, so booting costs two reads of
	 * an options row that is already in memory.
	 */
	public static function ensure_cron_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_RETENTION ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_RETENTION );
		}

		Digest::schedule();
	}

	/**
	 * Sessions that can no longer be used are removed with the rest of the
	 * retention sweep, because an abandoned session is retained personal data
	 * like anything else here.
	 */
	public static function prune_sessions(): void {
		\Serve\Platform\App::auth()->prune_sessions();
	}

	/** For the tests, which boot once per process and assert on a clean slate. */
	public static function reset(): void {
		self::$done = false;
	}
}
