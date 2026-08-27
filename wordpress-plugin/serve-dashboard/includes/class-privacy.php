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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

		$deleted = $wpdb->delete( Schema::table( 'submissions' ), array( 'id' => $submission_id ), array( '%d' ) );

		$wpdb->delete( Schema::table( 'consents' ), array( 'submission_id' => $submission_id ), array( '%d' ) );
		$wpdb->delete( Schema::table( 'placements' ), array( 'submission_id' => $submission_id ), array( '%d' ) );
		// Pastoral notes must not outlive the profile they describe.
		Followup::delete_for_submission( $submission_id );

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

		$table  = Schema::table( 'submissions' );
		$months = self::retention_months();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id FROM {$table} WHERE submitted_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d MONTH )",
				$months
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/** Cron callback: delete everything past its retention window. */
	public static function run_retention_sweep(): void {
		foreach ( self::expired_submission_ids() as $id ) {
			self::erase_submission( $id );
		}
	}
}
