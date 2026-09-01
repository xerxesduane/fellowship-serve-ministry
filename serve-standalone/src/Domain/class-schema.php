<?php
/**
 * Custom table definitions for the SERVE dashboard.
 *
 * Everything the dashboard needs lives in dedicated tables rather than post
 * meta. Submissions carry sensitive religious and pastoral data, so they stay
 * out of the WordPress post tables and therefore off the public REST surface.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

use Serve\Platform\App;
use Serve\Platform\Migrator;

final class Schema {

	/**
	 * The data-migration version, which is a separate series from the
	 * application's own version number.
	 *
	 * Schema changes are files in migrations/ and are tracked in a table, so
	 * this no longer gates table creation. It gates migrate() below -- the data
	 * fixes a CREATE TABLE cannot express.
	 */
	public const DB_VERSION = '1.10.0';

	public const OPTION_DB_VERSION = 'serve_dashboard_db_version';

	/* Pipeline states a submission moves through. */
	public const STATUS_SUBMITTED           = 'submitted';
	public const STATUS_CONTACTED           = 'contacted';
	public const STATUS_CONVERSATION_BOOKED = 'conversation_booked';
	public const STATUS_TRIAL_SERVE         = 'trial_serve';
	public const STATUS_PLACED              = 'placed';
	public const STATUS_PAUSED              = 'paused';
	public const STATUS_DECLINED            = 'declined';

	/**
	 * The forward pipeline, in order. Position matters: the safeguarding gate
	 * compares indexes to decide whether a transition moves someone closer to
	 * actually serving. Paused and declined sit outside the ladder.
	 *
	 * @return string[]
	 */
	public static function pipeline(): array {
		return array(
			self::STATUS_SUBMITTED,
			self::STATUS_CONTACTED,
			self::STATUS_CONVERSATION_BOOKED,
			self::STATUS_TRIAL_SERVE,
			self::STATUS_PLACED,
		);
	}

	/**
	 * Every valid status, including the two off-ladder ones.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array_merge(
			self::pipeline(),
			array( self::STATUS_PAUSED, self::STATUS_DECLINED )
		);
	}

	/**
	 * Human-readable labels for the pipeline states.
	 *
	 * @return array<string,string>
	 */
	/**
	 * The stages that are positions on the serving journey, in order.
	 *
	 * `status_labels()` happens to list these first, but nothing enforced that,
	 * and a UI that draws "step 2 of 5" from an array's ordering breaks silently
	 * the day somebody inserts a stage. Named here so the order is a decision
	 * rather than a coincidence.
	 *
	 * @return string[]
	 */
	public static function stage_path(): array {
		return array(
			self::STATUS_SUBMITTED,
			self::STATUS_CONTACTED,
			self::STATUS_CONVERSATION_BOOKED,
			self::STATUS_TRIAL_SERVE,
			self::STATUS_PLACED,
		);
	}

	/**
	 * Stages that are not points on the path.
	 *
	 * Paused and Declined are real outcomes, and neither is a position. Drawing
	 * either as "2 of 5" would state something untrue, so they are drawn
	 * differently — see the stage indicator in admin/js/app.js.
	 *
	 * @return string[]
	 */
	public static function stage_off_path(): array {
		return array( self::STATUS_PAUSED, self::STATUS_DECLINED );
	}

	/**
	 * The deck's three phases, and which stages sit in each.
	 *
	 * Discover, Connect, Serve is the spine of the presentation this was built
	 * from — slide 3 is nothing else — and the dashboard had never used the
	 * words. That is a real cost, not a cosmetic one: leadership approved a
	 * pilot described in three phases and then opened a tool that talks about
	 * seven statuses instead, so the two do not obviously describe the same
	 * thing.
	 *
	 * Discover holds no stage on purpose. It is the assessment itself, which is
	 * finished before a person appears in this dashboard at all, and saying so
	 * is more useful than pretending a status covers it.
	 *
	 * @return array<string,array{label:string,note:string,stages:string[]}>
	 */
	public static function stage_phases(): array {
		return array(
			'discover' => array(
				'label'  => __( 'Discover', 'serve-dashboard' ),
				'note'   => __( 'Already done. They completed the S.H.A.P.E. journey before appearing here.', 'serve-dashboard' ),
				'stages' => array(),
			),
			'connect'  => array(
				'label'  => __( 'Connect', 'serve-dashboard' ),
				'note'   => __( 'Where a leader does the work: noticing, reaching out, and having the conversation.', 'serve-dashboard' ),
				'stages' => array(
					self::STATUS_SUBMITTED,
					self::STATUS_CONTACTED,
					self::STATUS_CONVERSATION_BOOKED,
				),
			),
			'serve'    => array(
				'label'  => __( 'Serve', 'serve-dashboard' ),
				'note'   => __( 'Trying a team, then joining one. The point of everything above it.', 'serve-dashboard' ),
				'stages' => array(
					self::STATUS_TRIAL_SERVE,
					self::STATUS_PLACED,
				),
			),
			'aside'    => array(
				'label'  => __( 'Not on the path', 'serve-dashboard' ),
				'note'   => __( 'Both are complete answers, and neither is a failure.', 'serve-dashboard' ),
				'stages' => array(
					self::STATUS_PAUSED,
					self::STATUS_DECLINED,
				),
			),
		);
	}

	/** Which phase a stage belongs to, or an empty string. */
	public static function phase_of( string $status ): string {
		foreach ( self::stage_phases() as $phase ) {
			if ( in_array( $status, $phase['stages'], true ) ) {
				return (string) $phase['label'];
			}
		}

		return '';
	}

	public static function status_labels(): array {
		return array(
			self::STATUS_SUBMITTED           => __( 'Submitted', 'serve-dashboard' ),
			self::STATUS_CONTACTED           => __( 'Contacted', 'serve-dashboard' ),
			self::STATUS_CONVERSATION_BOOKED => __( 'Conversation booked', 'serve-dashboard' ),
			self::STATUS_TRIAL_SERVE         => __( 'Trial serve', 'serve-dashboard' ),
			self::STATUS_PLACED              => __( 'Placed', 'serve-dashboard' ),
			self::STATUS_PAUSED              => __( 'Paused', 'serve-dashboard' ),
			self::STATUS_DECLINED            => __( 'Declined', 'serve-dashboard' ),
		);
	}

	/**
	 * What each stage actually means, in words.
	 *
	 * The labels are short enough to fit in a badge and short enough to be
	 * guessed at wrongly: "Submitted" could as easily mean a form was sent to
	 * the person as by them, and nothing on screen said which. A leader who has
	 * not been trained on the vocabulary should not have to ask.
	 *
	 * Keyed by the same constants as `status_labels()`, so the two cannot drift
	 * apart without it being obvious.
	 *
	 * @return array<string,string>
	 */
	public static function status_descriptions(): array {
		return array(
			self::STATUS_SUBMITTED           => __( 'They finished the questions. Nobody has spoken to them yet.', 'serve-dashboard' ),
			self::STATUS_CONTACTED           => __( 'Somebody has reached out. Waiting to hear back, or to fix a time.', 'serve-dashboard' ),
			self::STATUS_CONVERSATION_BOOKED => __( 'A conversation is arranged and has not happened yet.', 'serve-dashboard' ),
			self::STATUS_TRIAL_SERVE         => __( 'Trying a team out, before either side commits.', 'serve-dashboard' ),
			self::STATUS_PLACED              => __( 'Serving on a team now.', 'serve-dashboard' ),
			self::STATUS_PAUSED              => __( 'Not this season. They asked to be picked up again later.', 'serve-dashboard' ),
			self::STATUS_DECLINED            => __( 'They decided against it for now. That is a complete answer, not a failure.', 'serve-dashboard' ),
		);
	}

	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'serve_' . $name;
	}

	/**
	 * Every table this plugin owns, for install and uninstall alike.
	 *
	 * @return string[]
	 */
	public static function table_names(): array {
		return array( 'submissions', 'consents', 'teams', 'placements', 'notes', 'drafts', 'audit', 'feedback' );
	}

	/**
	 * Create or update the custom tables.
	 *
	 * dbDelta is whitespace sensitive: two spaces before PRIMARY KEY, one
	 * definition per line, lowercase column types. Do not reformat.
	 */
	/**
	 * Create or update the tables.
	 *
	 * dbDelta used to do this by parsing a CREATE TABLE statement and diffing it
	 * against the live table, which is why the definitions had to obey its
	 * formatting rules and why getting them wrong failed quietly. The schema now
	 * lives in migrations/, and what has been applied is recorded in a table.
	 */
	public static function install(): void {
		$result = ( new Migrator( App::db() ) )->migrate();

		if ( '' !== $result['error'] ) {
			throw new \RuntimeException( 'migration failed: ' . $result['error'] );
		}

		/*
		 * The sixteen teams, before any data migration runs.
		 *
		 * Not decoration: the 1.9.0 migration below chooses the catch-all team
		 * by looking up the Welcome team's id. Run against an empty teams table
		 * it finds nothing, stores 0, and -- because the migration only fires
		 * once -- the catch-all is permanently absent. Every submission then
		 * lands with no owner at all, which is the exact failure the catch-all
		 * exists to prevent.
		 */
		Teams::seed_defaults();
	}

	/**
	 * Bring the schema and the data up to this version, in that order.
	 *
	 * The version used to be stamped at the end of install(), which runs before
	 * migrate(). So a data migration that died partway -- a query killed by a
	 * lock timeout, a fatal on a row nobody anticipated, the request simply
	 * being cut off -- left the stored version already claiming to be current,
	 * and the branch that had not finished was never entered again. The tables
	 * would be right and the data half-converted, permanently, with nothing
	 * reporting it.
	 *
	 * Stamping last means a failed upgrade is retried next time. That requires
	 * every migrate() branch to be safe to run twice, which is the property they
	 * were written with anyway: each is gated on the version it upgrades from,
	 * and each either backfills a column that is still empty or sets an option
	 * that is still absent.
	 */
	public static function maybe_upgrade(): void {
		$from = (string) get_option( self::OPTION_DB_VERSION, '' );

		if ( $from === self::DB_VERSION ) {
			return;
		}

		self::install();
		self::migrate( $from );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * Data fixes that a schema change alone does not cover.
	 *
	 * @param string $from Version being upgraded from, empty on a fresh install.
	 */
	private static function migrate( string $from ): void {
		global $wpdb;

		/*
		 * Email verification arrived in 1.1.0. Rows created before it were
		 * accepted under the old rules, so treating them as unverified would
		 * silently empty every leader's queue on upgrade. They are grandfathered
		 * to their submission date instead.
		 */
		if ( '' !== $from && version_compare( $from, '1.1.0', '<' ) ) {
			$submissions = self::table( 'submissions' );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input.
			$wpdb->query( "UPDATE {$submissions} SET verified_at = submitted_at WHERE verified_at IS NULL" );
		}

		/*
		 * 1.4.0 records when somebody last confirmed a team's headcount, so the
		 * gap panel can say how many placements have happened since.
		 *
		 * Existing rows are stamped now rather than left null. The alternative
		 * is treating every placement ever made as unreconciled, which would
		 * greet an upgrading site with a backlog it has no way to judge. Taking
		 * the typed numbers as accurate on the day of the upgrade is the only
		 * honest starting point; drift accumulates from here.
		 */
		if ( '' !== $from && version_compare( $from, '1.4.0', '<' ) ) {
			$teams = self::table( 'teams' );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input.
			$wpdb->query( "UPDATE {$teams} SET headcount_checked_at = UTC_TIMESTAMP() WHERE headcount_checked_at IS NULL" );
		}

		/*
		 * Schema 1.7.0 — plugin 1.17.0 — gives every team a vocabulary, so
		 * heart, abilities and experience can support a suggestion rather than
		 * only spiritual gifts. Note the gate is the *schema* version, which
		 * runs its own numbering: these comparisons are against DB_VERSION, not
		 * against the plugin header.
		 *
		 * Backfilled from the seed list by slug, and only where the column is
		 * still empty: an upgrading site keeps any wording it has edited, and a
		 * team somebody renamed or added themselves is left alone rather than
		 * given another team's words.
		 */
		if ( '' !== $from && version_compare( $from, '1.7.0', '<' ) ) {
			Teams::backfill_keywords();
		}

		/*
		 * Schema 1.8.0 — plugin 1.23.0 — moves the serving-form address out of
		 * app.js and into Settings.
		 *
		 * Seeded with the URL it used to be hardcoded to, and only when the
		 * option has never been set. Without this the upgrade would quietly
		 * remove the church's serving form from the end of the journey, which
		 * is a worse failure than the one being fixed.
		 *
		 * `false` rather than `''` as the default is the whole point: an
		 * administrator who has deliberately cleared the field must not have it
		 * refilled on the next upgrade.
		 */
		/*
		 * Schema 1.10.0 stores what each participant was actually shown, with
		 * the mapping and contract versions in force at the time.
		 *
		 * Purely additive, and deliberately not backfilled. Rows created before
		 * this column existed were produced under a different matcher — one
		 * comparing display strings, which could not see ten of the ministry
		 * table's terms — so recomputing them today and writing the result into
		 * a column named "what they were shown" would put a claim in the
		 * database that was never true of anybody.
		 *
		 * A null snapshot means "predates the record", and the dashboard says
		 * exactly that rather than substituting today's answer. Anyone wanting
		 * to know how those profiles rank now can read the current analysis,
		 * which is labelled as current analysis.
		 */
		if ( '' !== $from && version_compare( $from, '1.8.0', '<' )
			&& false === get_option( Assessment::OPTION_SERVING_FORM, false ) ) {
			add_option( Assessment::OPTION_SERVING_FORM, Assessment::LEGACY_SERVING_FORM );
		}

		/*
		 * Schema 1.9.0 — plugin 1.26.0 — records how a placement came about.
		 *
		 * Every row that already exists came from a match, which is the column
		 * default, so nothing needs rewriting. The new value is 'catchall': a
		 * row created because nothing matched at all, so somebody still owns the
		 * first conversation. It is deliberately not a suggestion and must never
		 * be read as one.
		 *
		 * The catch-all team is an option rather than a constant, because the
		 * last two things hardcoded in this plugin — a team list and a form URL
		 * — both had to be dug back out again. Seeded with Welcome, and blank is
		 * a real answer meaning pastors keep it.
		 */
		if ( '' !== $from && version_compare( $from, '1.9.0', '<' )
			&& false === get_option( Placements::OPTION_CATCHALL_TEAM, false ) ) {
			add_option( Placements::OPTION_CATCHALL_TEAM, Placements::DEFAULT_CATCHALL_TEAM );
		}
	}
}
