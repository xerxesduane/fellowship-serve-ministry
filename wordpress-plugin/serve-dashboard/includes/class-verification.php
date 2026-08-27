<?php
/**
 * Email verification for public submissions.
 *
 * The submission endpoint is anonymous by design — newcomers should be able to
 * complete the journey without an account, and the Newcomers Pathway team
 * exists to meet exactly those people. But anonymous plus unverified means
 * anyone can post a fabricated profile under someone else's address, and a
 * leader then rings a real person about answers they never gave.
 *
 * So a submission is stored immediately (the person's work is never lost) and
 * kept invisible to leaders until the address is proven. Nothing reaches a
 * follow-up queue on the strength of a typed email alone.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verification {

	/** How long a link stays usable. */
	private const TTL_HOURS = 48;

	/** Unverified submissions are deleted after this long. */
	private const PURGE_DAYS = 7;

	/** Query var carrying the token on the public link. */
	public const QUERY_VAR = 'serve_verify';

	public const RESULT_OK      = 'ok';
	public const RESULT_EXPIRED = 'expired';
	public const RESULT_INVALID = 'invalid';
	public const RESULT_ALREADY = 'already';

	public static function register(): void {
		// Priority 1: settle verification before any template work happens.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_verify' ), 1 );
	}

	/**
	 * Issue a token and email the link.
	 *
	 * The raw token goes in the email; only its hash is stored, so a leaked
	 * database cannot be used to verify anyone's address.
	 *
	 * @return bool Whether the mail was handed to WordPress successfully.
	 */
	public static function issue( int $submission_id ): bool {
		global $wpdb;

		$submission = Submissions::get( $submission_id );
		if ( ! $submission || '' === $submission->email ) {
			return false;
		}

		$raw = bin2hex( random_bytes( 32 ) );

		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'verify_token'   => hash( 'sha256', $raw ),
				'verify_sent_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $submission_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return self::send_mail( $submission, $raw );
	}

	private static function send_mail( object $submission, string $raw_token ): bool {
		$link = add_query_arg( self::QUERY_VAR, $raw_token, home_url( '/' ) );

		$subject = __( 'Confirm your S.H.A.P.E. profile', 'serve-dashboard' );

		$body = sprintf(
			/* translators: 1: person's name, 2: confirmation link, 3: hours until expiry. */
			__(
				"Hi %1\$s,

Thank you for completing the S.H.A.P.E. journey at Fellowship Dubai.

Please confirm this is your email address by opening the link below. Until you do, your profile is not passed to any ministry leader.

%2\$s

This link works once and expires in %3\$d hours.

If you did not fill in a S.H.A.P.E. profile, you can ignore this email and nothing will be shared.

Fellowship Dubai SERVE team",
				'serve-dashboard'
			),
			$submission->display_name,
			$link,
			self::TTL_HOURS
		);

		return (bool) wp_mail( $submission->email, $subject, $body );
	}

	/**
	 * Handle the incoming link.
	 *
	 * Redirects to the consent page carrying a result code, so the outcome is
	 * shown in the same styling as the rest of the journey rather than as a
	 * bare JSON response.
	 */
	public static function maybe_verify(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a one-time token in a mailed link is the credential.
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		$result = self::consume( $token );

		$target = Assessment::consent_url() ?: home_url( '/' );

		wp_safe_redirect( add_query_arg( 'serve_verified', $result, $target ) );
		exit;
	}

	/**
	 * Verify one token, single use.
	 *
	 * @return string One of the RESULT_* constants.
	 */
	public static function consume( string $token ): string {
		global $wpdb;

		// Tokens are hex, so anything else is not worth a database round trip.
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return self::RESULT_INVALID;
		}

		$table = Schema::table( 'submissions' );
		$hash  = hash( 'sha256', $token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id, verified_at, verify_sent_at FROM {$table} WHERE verify_token = %s",
				$hash
			)
		);

		if ( ! $row ) {
			return self::RESULT_INVALID;
		}

		if ( null !== $row->verified_at ) {
			return self::RESULT_ALREADY;
		}

		$sent = strtotime( (string) $row->verify_sent_at . ' UTC' );
		if ( ! $sent || $sent < strtotime( '-' . self::TTL_HOURS . ' hours' ) ) {
			return self::RESULT_EXPIRED;
		}

		$wpdb->update(
			$table,
			array(
				// Cleared on use, so the link cannot be replayed.
				'verify_token' => null,
				'verified_at'  => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $row->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		Audit::log( 'submission.verified', 'submission', (int) $row->id );
		Metrics::flush();

		return self::RESULT_OK;
	}

	/**
	 * Messages shown on the consent page after following a link.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function messages(): array {
		return array(
			self::RESULT_OK      => array(
				'tone'  => 'ok',
				'title' => __( 'Thank you — your profile is confirmed', 'serve-dashboard' ),
				'body'  => __( 'A ministry leader from the SERVE team will look at your profile and get in touch. There is nothing else you need to do.', 'serve-dashboard' ),
			),
			self::RESULT_ALREADY => array(
				'tone'  => 'ok',
				'title' => __( 'This profile is already confirmed', 'serve-dashboard' ),
				'body'  => __( 'No further action is needed. A ministry leader will be in touch.', 'serve-dashboard' ),
			),
			self::RESULT_EXPIRED => array(
				'tone'  => 'warn',
				'title' => __( 'That link has expired', 'serve-dashboard' ),
				'body'  => __( 'Confirmation links last 48 hours. Please send your profile again from the end of the journey, and we will email a fresh link.', 'serve-dashboard' ),
			),
			self::RESULT_INVALID => array(
				'tone'  => 'warn',
				'title' => __( 'We could not read that link', 'serve-dashboard' ),
				'body'  => __( 'It may have been broken across two lines by your email app. Try copying the whole link, or send your profile again.', 'serve-dashboard' ),
			),
		);
	}

	/** Unverified submissions waiting longer than the purge window. */
	public static function purge_unverified(): int {
		global $wpdb;

		$table = Schema::table( 'submissions' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id FROM {$table}
				 WHERE verified_at IS NULL
				   AND submitted_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )",
				self::PURGE_DAYS
			)
		);

		foreach ( $ids as $id ) {
			Privacy::erase_submission( (int) $id );
		}

		return count( (array) $ids );
	}

	/** How many are currently waiting on confirmation. */
	public static function pending_count(): int {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE verified_at IS NULL" );
	}
}
