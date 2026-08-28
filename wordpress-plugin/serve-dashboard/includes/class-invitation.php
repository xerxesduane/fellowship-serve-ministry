<?php
/**
 * Inviting somebody to a conversation, and letting them answer.
 *
 * Two separable things, built together because they share a token.
 *
 * **The invitation.** The button used to be called "Invite to a conversation"
 * and sent nothing at all — it moved a pipeline stage. That was honest given no
 * messaging workflow had been agreed, but it left the deck's Serve stage with no
 * invitation in it. A leader now chooses per person: send an email, or record
 * that they will ring them. Ringing somebody you know and emailing a newcomer
 * you have never met are different acts, and nothing here should force them to
 * be the same.
 *
 * **The answer.** The deck's model is system suggests, leader confirms, person
 * chooses — and the third had no surface anywhere. An emailed invitation carries
 * a link, the person answers for themselves, and their answer arrives in the
 * dashboard. Until now `Declined` was something a leader typed on somebody
 * else's behalf.
 *
 * What the wording is not: generated. A pastoral invitation signed with a
 * leader's name but written by software reads as a mass mailing at exactly the
 * moment personal contact matters, so the leader is given a draft and sends
 * whatever they actually wrote.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Invitation {

	/** How the leader chose to make contact. */
	public const METHOD_EMAIL    = 'email';
	public const METHOD_PERSONAL = 'personal';

	/** What the person said back. */
	public const RESPONSE_YES      = 'yes';
	public const RESPONSE_NOT_NOW  = 'not_now';
	public const RESPONSE_QUESTION = 'question';

	/** A link is a nudge, not a deadline; longer than a confirmation link. */
	private const TTL_DAYS = 30;

	public const QUERY_VAR = 'serve_invite';

	/** @return array<string,string> */
	public static function responses(): array {
		return array(
			self::RESPONSE_YES      => __( 'Yes, I would like to explore this', 'serve-dashboard' ),
			self::RESPONSE_NOT_NOW  => __( 'Not right now', 'serve-dashboard' ),
			self::RESPONSE_QUESTION => __( 'I have a question first', 'serve-dashboard' ),
		);
	}

	/**
	 * A starting point for the leader to edit.
	 *
	 * Deliberately short and plainly not a form letter: the leader's own second
	 * sentence is worth more than anything written here.
	 *
	 * @return array{subject:string,body:string}
	 */
	public static function draft( object $submission ): array {
		$first = trim( (string) strtok( (string) $submission->display_name, ' ' ) );
		$teams = Submissions::decode_list( $submission->suggested_teams );
		$team  = '';

		if ( $teams ) {
			$found = Teams::get_by_slug( (string) $teams[0] );
			$team  = $found ? $found->name : '';
		}

		$opening = $team
			? sprintf(
				/* translators: %s: team name. */
				__( 'Having read through it, I wondered whether %s might be worth a conversation.', 'serve-dashboard' ),
				$team
			)
			: __( 'I would love to talk with you about where you might serve.', 'serve-dashboard' );

		return array(
			'subject' => __( 'A conversation about serving at Fellowship Dubai', 'serve-dashboard' ),
			'body'    => sprintf(
				/* translators: 1: first name, 2: a sentence about a suggested team. */
				__(
					"Hi %1\$s,

Thank you for completing the S.H.A.P.E. journey — it was good to read.

%2\$s

There is no pressure at all, and nothing is decided. Would you like to talk it over?",
					'serve-dashboard'
				),
				$first ?: __( 'there', 'serve-dashboard' ),
				$opening
			),
		);
	}

	/**
	 * Record that a leader is handling this personally.
	 *
	 * No email, no token, nothing sent — but the pipeline moves and the choice
	 * is recorded, so nobody else rings the same person tomorrow.
	 *
	 * @return true|\WP_Error
	 */
	public static function record_personal( int $submission_id ) {
		global $wpdb;

		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'invited_at'    => current_time( 'mysql', true ),
				'invite_method' => self::METHOD_PERSONAL,
				'invite_token'  => null,
			),
			array( 'id' => $submission_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		Audit::log( 'invitation.personal', 'submission', $submission_id );

		return true;
	}

	/**
	 * Send the leader's invitation.
	 *
	 * `invited_at` is written only once the mailer has accepted it, for the same
	 * reason the confirmation email does: a row claiming to have invited
	 * somebody it never reached is worse than one that admits nothing went.
	 *
	 * @return true|\WP_Error
	 */
	public static function send( int $submission_id, string $subject, string $body ) {
		global $wpdb;

		$submission = Submissions::get( $submission_id );

		if ( ! $submission || '' === $submission->email ) {
			return new \WP_Error( 'serve_no_address', __( 'There is no email address to invite.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		$subject = trim( $subject );
		$body    = trim( $body );

		if ( '' === $subject || '' === $body ) {
			return new \WP_Error( 'serve_invite_empty', __( 'An invitation needs a subject and something to say.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		$raw = bin2hex( random_bytes( 32 ) );

		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'invite_token'        => hash( 'sha256', $raw ),
				'invited_at'          => null,
				'invite_method'       => self::METHOD_EMAIL,
				'invite_response'     => null,
				'invite_responded_at' => null,
			),
			array( 'id' => $submission_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$link = add_query_arg( self::QUERY_VAR, $raw, Assessment::consent_url() ?: home_url( '/' ) );

		$signature = "\n\n" . sprintf(
			/* translators: 1: leader's name, 2: number of days the link lasts. */
			__(
				"%1\$s
Fellowship Dubai SERVE team

You can answer here, whichever way it is — it saves us guessing:
%2\$s

If none of those fit, just reply to this email.",
				'serve-dashboard'
			),
			wp_get_current_user()->display_name,
			$link
		);

		if ( ! wp_mail( $submission->email, $subject, $body . $signature ) ) {
			Audit::log( 'invitation.mail_failed', 'submission', $submission_id );

			return new \WP_Error(
				'serve_invite_failed',
				__( 'That could not be sent — outgoing email is not working. Nothing has been recorded as invited.', 'serve-dashboard' ),
				array( 'status' => 500 )
			);
		}

		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'invited_at' => current_time( 'mysql', true ) ),
			array( 'id' => $submission_id ),
			array( '%s' ),
			array( '%d' )
		);

		Audit::log( 'invitation.sent', 'submission', $submission_id );

		return true;
	}

	/** The submission a link belongs to, if the link is still good. */
	public static function find( string $token ): ?object {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}

		$table = Schema::table( 'submissions' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE invite_token = %s",
				hash( 'sha256', $token )
			)
		);

		if ( ! $row ) {
			return null;
		}

		$sent = strtotime( (string) $row->invited_at . ' UTC' );

		if ( ! $sent || $sent < strtotime( '-' . self::TTL_DAYS . ' days' ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Record what the person said.
	 *
	 * The pipeline is not advanced on a yes. They have said they are willing to
	 * talk, which is not the same as a conversation having happened, and a
	 * leader deciding that is the whole point of the middle step.
	 *
	 * @return true|\WP_Error
	 */
	public static function respond( string $token, string $response, string $note = '' ) {
		global $wpdb;

		$submission = self::find( $token );

		if ( ! $submission ) {
			return new \WP_Error( 'serve_invite_invalid', __( 'That link has expired or has already been used.', 'serve-dashboard' ), array( 'status' => 404 ) );
		}

		if ( ! array_key_exists( $response, self::responses() ) ) {
			return new \WP_Error( 'serve_invite_bad_response', __( 'We did not recognise that answer.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		$id = (int) $submission->id;

		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				// Cleared on use, so the link cannot be replayed.
				'invite_token'        => null,
				'invite_response'     => $response,
				'invite_responded_at' => current_time( 'mysql', true ),
				'invite_note'         => mb_substr( trim( $note ), 0, 500 ),
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		/*
		 * "Not right now" pauses them rather than declining them — the two are
		 * not the same thing, and the difference matters to whoever reads this
		 * next. A return date is required for a pause, and the person has not
		 * given one, so three months is assumed and the note says so.
		 */
		if ( self::RESPONSE_NOT_NOW === $response ) {
			$wpdb->update(
				Schema::table( 'submissions' ),
				array(
					'status'         => Schema::STATUS_PAUSED,
					'snooze_until'   => gmdate( 'Y-m-d', strtotime( '+3 months' ) ),
					'next_action_at' => gmdate( 'Y-m-d', strtotime( '+3 months' ) ),
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		Audit::log( 'invitation.answered', 'submission', $id, array( 'response' => $response ) );
		Metrics::flush();

		return true;
	}

	/**
	 * How a response reads to a leader.
	 *
	 * @return array{label:string,tone:string,note:string}|null
	 */
	public static function summary( object $submission ): ?array {
		if ( ! $submission->invite_response ) {
			return null;
		}

		$tones = array(
			self::RESPONSE_YES      => 'ok',
			self::RESPONSE_NOT_NOW  => 'quiet',
			self::RESPONSE_QUESTION => 'warn',
		);

		return array(
			'label' => self::responses()[ $submission->invite_response ] ?? '',
			'tone'  => $tones[ $submission->invite_response ] ?? 'quiet',
			'note'  => (string) $submission->invite_note,
			'when'  => $submission->invite_responded_at
				? mysql2date( get_option( 'date_format' ), $submission->invite_responded_at )
				: '',
		);
	}
}
