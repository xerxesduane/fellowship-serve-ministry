<?php
/**
 * Privacy helpers.
 *
 * UAE PDPL and the DIFC Data Protection Law both treat religious belief as
 * sensitive personal data, and a SHAPE profile is exactly that plus contact
 * details plus, in the Experiences section, pastoral history. So: identifiers
 * are hashed where we only need to prove sameness, retention has an end date,
 * and deletion is a first-class operation rather than a manual SQL job.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Privacy {

	/**
	 * Current consent text version. Bump this whenever the wording of the
	 * purpose statement changes, so old records stay attributable to the
	 * text the person actually agreed to.
	 */
	public const POLICY_VERSION = '2026-08-1';

	public const OPTION_RETENTION_MONTHS = 'serve_dashboard_retention_months';
	public const DEFAULT_RETENTION_MONTHS = 24;

	/** Where somebody writes to have their profile removed. */
	public const OPTION_CONTACT_EMAIL = 'serve_dashboard_contact_email';

	/**
	 * When the retention sweep last actually ran.
	 *
	 * The presence of a scheduled job proves only that WordPress intends to run
	 * it. With DISABLE_WP_CRON set, as the runbook instructs, whether it runs at
	 * all depends on a system crontab nothing in here can see. This is the only
	 * evidence that the deletion the privacy notice promises is happening.
	 */
	public const OPTION_LAST_SWEEP = 'serve_dashboard_last_retention_sweep';

	/**
	 * The SERVE team's own address.
	 *
	 * A default rather than a setting nobody remembers to fill in. This plugin
	 * is built for one church, and the alternative fallback — whatever address
	 * happens to be in WordPress' administrator field — is a guess that on this
	 * very install pointed at an entirely different domain. Settings still
	 * overrides it.
	 */
	public const DEFAULT_CONTACT_EMAIL = 'serve@fellowshipdubai.com';

	/**
	 * Salted hash of the caller's IP.
	 *
	 * Salted with the site's auth salt so the hashes are useless if the table
	 * leaks on its own, and truncation-free so collisions stay negligible.
	 */
	public static function hash_ip(): string {
		$ip = self::client_ip();

		return '' === $ip ? '' : hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	public static function hash_user_agent(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		return '' === $agent ? '' : hash( 'sha256', $agent . wp_salt( 'auth' ) );
	}

	/**
	 * Best-effort client IP.
	 *
	 * Only REMOTE_ADDR is trusted by default. Proxy headers are spoofable, so
	 * a site behind a real reverse proxy must opt in through the filter and
	 * take responsibility for stripping untrusted hops.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filters the resolved client IP before hashing.
		 *
		 * @param string $remote Value of REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'serve_dashboard_client_ip', $remote );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function retention_months(): int {
		$months = (int) get_option( self::OPTION_RETENTION_MONTHS, self::DEFAULT_RETENTION_MONTHS );

		return $months > 0 ? $months : self::DEFAULT_RETENTION_MONTHS;
	}

	/**
	 * The address published on the consent page for removal requests.
	 *
	 * Settings first, then the SERVE team's own address. The WordPress
	 * administrator's address is not used: whoever installed the site is not
	 * necessarily whoever should receive "please delete my profile", and on the
	 * development install it belonged to a different organisation entirely.
	 * Printing a guess on a data-removal notice sends that request to the wrong
	 * person.
	 */
	public static function contact_email(): string {
		$configured = sanitize_email( (string) get_option( self::OPTION_CONTACT_EMAIL, '' ) );

		if ( '' !== $configured && is_email( $configured ) ) {
			return $configured;
		}

		return self::DEFAULT_CONTACT_EMAIL;
	}

	/**
	 * The consent wording shown on the form and stored verbatim alongside the
	 * submission. Kept in one place so the form and the stored record can
	 * never drift apart.
	 */
	public static function purpose_text(): string {
		$months = self::retention_months();

		return sprintf(
			/* translators: %d: retention period in months. */
			__(
				'I am sharing my S.H.A.P.E. answers with the Fellowship Dubai SERVE team so a ministry leader can contact me about serving. I understand this includes information about my spiritual gifts, passions, abilities, personality, and experiences, that only authorised ministry leaders will see it, that it will be kept for %d months and then deleted, and that I can ask for it to be removed at any time.',
				'serve-dashboard'
			),
			$months
		);
	}

	/**
	 * A profile this old no longer describes the person who filled it in.
	 * Surfaced as a "stale" flag on the leader list rather than auto-deleted.
	 */
	public static function staleness_months(): int {
		/**
		 * Filters how many months before a profile is flagged stale.
		 *
		 * @param int $months Default 18.
		 */
		return (int) apply_filters( 'serve_dashboard_staleness_months', 18 );
	}

	/**
	 * Hard-delete every trace of one submission.
	 *
	 * The audit row recording the deletion is deliberately kept: it stores no
	 * personal data beyond the numeric id, and removing it would defeat the
	 * point of having a trail.
	 */
	public static function erase_submission( int $submission_id ): bool {
		global $wpdb;

		/*
		 * Read before the row goes, because the draft is keyed on the address
		 * rather than on the submission id, and once the submission is deleted
		 * there is nothing left to look it up from.
		 */
		$email = (string) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT email FROM ' . Schema::table( 'submissions' ) . ' WHERE id = %d',
				$submission_id
			)
		);

		$deleted = $wpdb->delete( Schema::table( 'submissions' ), array( 'id' => $submission_id ), array( '%d' ) );

		$wpdb->delete( Schema::table( 'consents' ), array( 'submission_id' => $submission_id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'placements' ), array( 'submission_id' => $submission_id ), array( '%d' ) );
		// Pastoral notes must not outlive the profile they describe.
		Followup::delete_for_submission( $submission_id );

		/*
		 * And any saved draft under the same address.
		 *
		 * A draft holds a full copy of the person's raw answers, including the
		 * Experiences section, in its own table for up to thirty days. Erasure
		 * cleaned four tables and not that one, so "erased" left the most
		 * sensitive copy of somebody's answers sitting there -- reachable by
		 * anyone holding the resume link -- for weeks after the church had told
		 * them, or a regulator, that it was gone.
		 *
		 * Submitting a profile already clears the draft behind it, so in the
		 * ordinary case there is nothing here to find. It bites on the paths
		 * that matter most: an erasure request, and the retention sweep on
		 * somebody who saved a draft again after submitting.
		 */
		if ( '' !== $email ) {
			Draft::clear_for_email( $email );
		}

		if ( $deleted ) {
			Audit::log( Audit::ACTION_DELETED, 'submission', $submission_id );
		}

		return (bool) $deleted;
	}

	/**
	 * Submissions whose retention window has closed.
	 *
	 * @return int[]
	 */
	public static function expired_submission_ids(): array {
		global $wpdb;

		$submissions = Schema::table( 'submissions' );
		$consents    = Schema::table( 'consents' );

		/*
		 * Each person's own promise, not whatever the setting says today.
		 *
		 * `consents.retention_months` is frozen when they agree, the consent
		 * text quotes that number back to them, and the drawer shows it to their
		 * leader. Deletion read the live option instead, so the three could
		 * disagree the moment a pastor adjusted Settings: drop it from 24 to 6
		 * and the next sweep deleted people who had been promised 24, with the
		 * drawer still showing 24 until the row vanished. Raise it and the
		 * church kept religious-belief data years past what anyone agreed to.
		 *
		 * COALESCE for rows with no consent row at all -- there should be none,
		 * since create() refuses a submission whose consent could not be
		 * recorded, but a sweep is the wrong place to discover otherwise, and
		 * the global setting is the safe reading for a row we know nothing about.
		 */
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input.
				"SELECT s.id
				 FROM {$submissions} s
				 LEFT JOIN {$consents} c ON c.submission_id = s.id
				 WHERE s.submitted_at < DATE_SUB(
					UTC_TIMESTAMP(),
					INTERVAL COALESCE( NULLIF( c.retention_months, 0 ), %d ) MONTH
				 )",
				self::retention_months()
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/** Cron callback: delete everything past its retention window. */
	public static function run_retention_sweep(): void {
		foreach ( self::expired_submission_ids() as $id ) {
			self::erase_submission( $id );
		}

		// Written whether or not anything was due. "Nothing expired today" and
		// "the job has not run in three weeks" look identical without it.
		update_option( self::OPTION_LAST_SWEEP, current_time( 'mysql', true ) );
	}

	/** When the sweep last ran, or an empty string if it never has. */
	public static function last_sweep(): string {
		return (string) get_option( self::OPTION_LAST_SWEEP, '' );
	}
}
