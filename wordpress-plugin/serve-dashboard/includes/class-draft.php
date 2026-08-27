<?php
/**
 * Continue on another device.
 *
 * The journey is nineteen steps and autosaves to localStorage, which means
 * someone who starts on their phone at church cannot finish on a laptop at
 * home. That is a completion problem, not a technical curiosity: the longer
 * the journey, the more it costs to lose your place.
 *
 * The delicate part is that resuming requires the answers to leave the device,
 * and an unfinished journey is not something the person has agreed to share
 * with anyone. So a draft is:
 *
 *   - saved only when the person explicitly asks for it, with its own wording
 *     separate from the sharing consent
 *   - stored in its own table that no leader query ever touches
 *   - kept for 30 days, not the submission retention period
 *   - deleted the moment it is resumed
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Draft {

	private const TTL_DAYS = 30;

	/** One saved draft per address at a time, and a cap on abuse. */
	private const RATE_LIMIT  = 10;
	private const RATE_WINDOW = HOUR_IN_SECONDS;

	/**
	 * The wording shown beside the "email me a link" control. Stored nowhere,
	 * because a draft is not a disclosure to anyone but the person themselves.
	 */
	public static function purpose_text(): string {
		return sprintf(
			/* translators: %d: number of days a draft is kept. */
			__( 'Email me a private link so I can carry on where I left off. My answers are held for %d days for this purpose only, are not shown to any ministry leader, and are deleted as soon as I use the link.', 'serve-dashboard' ),
			self::TTL_DAYS
		);
	}

	/**
	 * Save a draft and email the resume link.
	 *
	 * @param string              $email   Where to send the link.
	 * @param array<string,mixed> $answers Raw journey state.
	 * @param int                 $step    Which step they were on.
	 * @return true|\WP_Error
	 */
	public static function save( string $email, array $answers, int $step ) {
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'serve_bad_email', __( 'That email address does not look right.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		$key   = 'serve_draft_rl_' . ( Privacy::hash_ip() ?: 'unknown' );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return new \WP_Error(
				'serve_rate_limited',
				__( 'That is a lot of saved links from one place. Please try again later.', 'serve-dashboard' ),
				array( 'status' => 429 )
			);
		}

		global $wpdb;
		$table = Schema::table( 'drafts' );

		// Replace any previous draft for this address rather than accumulating
		// them: the most recent progress is the only one anyone wants.
		$wpdb->delete( $table, array( 'email' => $email ), array( '%s' ) );

		$raw = bin2hex( random_bytes( 32 ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'token_hash'   => hash( 'sha256', $raw ),
				'email'        => $email,
				'answers_json' => wp_json_encode( $answers ) ?: '{}',
				'step'         => max( 0, min( 100, $step ) ),
				'created_at'   => current_time( 'mysql', true ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::TTL_DAYS . ' days' ) ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'serve_draft_failed', __( 'We could not save your place. Please try again.', 'serve-dashboard' ) );
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );

		if ( ! self::send_mail( $email, $raw, $step ) ) {
			return new \WP_Error(
				'serve_draft_mail_failed',
				__( 'Your place is saved, but we could not send the email. Please check the address and try again.', 'serve-dashboard' )
			);
		}

		// No identifying detail in the trail: this is the person's own draft,
		// not a disclosure, and the address is not a leader's business.
		Audit::log( 'draft.saved', 'draft', null, array( 'step' => $step ) );

		return true;
	}

	private static function send_mail( string $email, string $raw_token, int $step ): bool {
		$link = add_query_arg( 'serve_resume', $raw_token, Assessment::assessment_url() ?: home_url( '/' ) );

		$subject = __( 'Carry on with your S.H.A.P.E. journey', 'serve-dashboard' );

		$body = sprintf(
			/* translators: 1: resume link, 2: step number, 3: days kept. */
			__(
				"Hello,

Here is your private link to carry on with the S.H.A.P.E. journey at Fellowship Dubai. You were on step %2\$d.

%1\$s

Keep this link to yourself — anyone who opens it can see your answers so far.

Your saved answers are held for %3\$d days for this purpose only. No ministry leader can see them, and nothing is shared with anyone until you choose to send your finished profile.

Fellowship Dubai SERVE team",
				'serve-dashboard'
			),
			$link,
			max( 1, $step + 1 ),
			self::TTL_DAYS
		);

		return (bool) wp_mail( $email, $subject, $body );
	}

	/**
	 * Exchange a token for the saved answers, then delete the draft.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function resume( string $token ) {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return new \WP_Error( 'serve_draft_invalid', __( 'That link could not be read.', 'serve-dashboard' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table = Schema::table( 'drafts' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE token_hash = %s",
				hash( 'sha256', $token )
			)
		);

		if ( ! $row ) {
			return new \WP_Error( 'serve_draft_invalid', __( 'That link has already been used, or has expired.', 'serve-dashboard' ), array( 'status' => 404 ) );
		}

		if ( strtotime( (string) $row->expires_at . ' UTC' ) < time() ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );

			return new \WP_Error( 'serve_draft_expired', __( 'That link has expired.', 'serve-dashboard' ), array( 'status' => 410 ) );
		}

		$answers = json_decode( (string) $row->answers_json, true );

		// Single use. The answers are back on the person's device now, and
		// keeping a server-side copy past that point serves nobody.
		$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );

		Audit::log( 'draft.resumed', 'draft', null, array( 'step' => (int) $row->step ) );

		return array(
			'answers' => is_array( $answers ) ? $answers : array(),
			'step'    => (int) $row->step,
		);
	}

	/** Cron: drop anything past its window. */
	public static function purge_expired(): int {
		global $wpdb;
		$table = Schema::table( 'drafts' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE expires_at < UTC_TIMESTAMP()" );
	}

	/** Submitting the finished profile makes any draft redundant. */
	public static function clear_for_email( string $email ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'drafts' ), array( 'email' => $email ), array( '%s' ) );
	}
}
