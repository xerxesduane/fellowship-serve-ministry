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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {

	/**
	 * Bumped whenever a CREATE TABLE statement below changes, so that
	 * maybe_upgrade() knows to re-run dbDelta.
	 */
	public const DB_VERSION = '1.9.0';

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
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$submissions = self::table( 'submissions' );
		$consents    = self::table( 'consents' );
		$teams       = self::table( 'teams' );
		$placements  = self::table( 'placements' );
		$audit       = self::table( 'audit' );
		$feedback    = self::table( 'feedback' );

		/*
		 * Submissions. profile_json holds the full SHAPE payload; the broken
		 * out columns exist purely so the leader list can filter and sort
		 * without unpacking JSON for every row.
		 */
		$sql = "CREATE TABLE {$submissions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			display_name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'submitted',
			tenure_months smallint(5) unsigned DEFAULT NULL,
			gifts_likely text NOT NULL,
			languages text NOT NULL,
			suggested_teams text NOT NULL,
			profile_json longtext NOT NULL,
			verified_at datetime DEFAULT NULL,
			verify_token char(64) DEFAULT NULL,
			verify_sent_at datetime DEFAULT NULL,
			invite_token char(64) DEFAULT NULL,
			invited_at datetime DEFAULT NULL,
			invite_method varchar(16) DEFAULT NULL,
			invite_response varchar(16) DEFAULT NULL,
			invite_responded_at datetime DEFAULT NULL,
			invite_note text NOT NULL,
			safeguarding_status varchar(32) NOT NULL DEFAULT 'not_required',
			safeguarding_verified_at datetime DEFAULT NULL,
			snooze_until date DEFAULT NULL,
			next_action_at date DEFAULT NULL,
			assigned_user_id bigint(20) unsigned DEFAULT NULL,
			submitted_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY email (email),
			KEY next_action_at (next_action_at),
			KEY snooze_until (snooze_until),
			KEY assigned_user_id (assigned_user_id),
			KEY submitted_at (submitted_at),
			KEY verified_at (verified_at),
			KEY verify_token (verify_token),
			KEY invite_token (invite_token),
			KEY invite_response (invite_response)
		) {$charset};";
		dbDelta( $sql );

		/*
		 * Consent is recorded once per submission and never mutated. IP and
		 * user agent are stored hashed, so the record proves consent was given
		 * without retaining the raw identifiers.
		 */
		$sql = "CREATE TABLE {$consents} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			policy_version varchar(32) NOT NULL,
			purpose_text text NOT NULL,
			retention_months smallint(5) unsigned NOT NULL DEFAULT 24,
			ip_hash char(64) NOT NULL DEFAULT '',
			user_agent_hash char(64) NOT NULL DEFAULT '',
			consented_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY policy_version (policy_version)
		) {$charset};";
		dbDelta( $sql );

		/*
		 * Teams carry the capacity numbers the deck's "team gaps" tile needs.
		 * A gap is target_headcount minus current_headcount; without both
		 * numbers that tile has nothing to render.
		 *
		 * `keywords` is the vocabulary of what a team's work is about. Matching
		 * could previously compare a person's passions, abilities and
		 * experience only against the team's *name*, so a heart for Elementary
		 * Children said nothing about Fellowship Kids and every dimension
		 * except spiritual gifts was effectively dead weight. Seeded for all
		 * sixteen teams, so it works before anybody edits anything.
		 */
		$sql = "CREATE TABLE {$teams} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(80) NOT NULL,
			name varchar(190) NOT NULL,
			gifts text NOT NULL,
			keywords text NOT NULL,
			target_headcount smallint(5) unsigned NOT NULL DEFAULT 0,
			min_headcount smallint(5) unsigned NOT NULL DEFAULT 0,
			current_headcount smallint(5) unsigned NOT NULL DEFAULT 0,
			headcount_checked_at datetime DEFAULT NULL,
			requires_safeguarding tinyint(1) NOT NULL DEFAULT 0,
			leader_user_id bigint(20) unsigned DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY leader_user_id (leader_user_id),
			KEY is_active (is_active)
		) {$charset};";
		dbDelta( $sql );

		/*
		 * What leaders say is not working.
		 *
		 * The pilot is meant to surface friction, and the figures cannot: they
		 * count what happened, not whether a suggestion made sense. Kept apart
		 * from notes deliberately — notes are pastoral and are deleted with the
		 * person, this is about the tool and has to outlive them.
		 */
		$sql = "CREATE TABLE {$feedback} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			area varchar(32) NOT NULL DEFAULT 'general',
			body text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY area (area)
		) {$charset};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$placements} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			team_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'submitted',
			source varchar(16) NOT NULL DEFAULT 'match',
			decline_reason varchar(190) NOT NULL DEFAULT '',
			leader_user_id bigint(20) unsigned DEFAULT NULL,
			notes text NOT NULL,
			next_action_at date DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY submission_team (submission_id,team_id),
			KEY team_id (team_id),
			KEY status (status),
			KEY next_action_at (next_action_at)
		) {$charset};";
		dbDelta( $sql );

		/*
		 * Conversation history. Append-only rather than one editable field, so
		 * "spoke to her Tuesday, travelling until September" survives the next
		 * leader's update instead of being overwritten by it.
		 */
		$notes = self::table( 'notes' );

		$sql = "CREATE TABLE {$notes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			submission_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			body text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );

		/*
		 * Unfinished journeys, held so a person can continue on another device.
		 *
		 * Kept apart from submissions on purpose. A draft is not something the
		 * person has agreed to share with anyone — it is their own work in
		 * progress, saved at their request, and no leader query touches this
		 * table. Short retention, and the row is deleted the moment it is
		 * resumed or the journey is submitted.
		 */
		$drafts = self::table( 'drafts' );

		$sql = "CREATE TABLE {$drafts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			email varchar(190) NOT NULL,
			answers_json longtext NOT NULL,
			step smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY email (email),
			KEY expires_at (expires_at)
		) {$charset};";
		dbDelta( $sql );

		/*
		 * Append only. Who looked at whose spiritual gifts, and who changed
		 * what. Written to by nothing except Audit::log().
		 */
		$sql = "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned DEFAULT NULL,
			action varchar(64) NOT NULL,
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned DEFAULT NULL,
			meta_json text NOT NULL,
			ip_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action (action),
			KEY object (object_type,object_id),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/** Re-run install() when the stored schema version has fallen behind. */
	public static function maybe_upgrade(): void {
		$from = (string) get_option( self::OPTION_DB_VERSION, '' );

		if ( $from === self::DB_VERSION ) {
			return;
		}

		self::install();
		self::migrate( $from );
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
