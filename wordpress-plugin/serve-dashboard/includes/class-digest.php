<?php
/**
 * Weekly digest.
 *
 * Nothing in the dashboard pushes. A leader has to remember to open it, which
 * makes "fewer people overlooked" depend on the very memory the tool was meant
 * to replace. One email a week turns it from a place you visit into something
 * that reminds you.
 *
 * Each leader's digest is built as that leader, so the team scoping and the
 * sensitive-field redaction that protect the dashboard protect the email too.
 * A ministry leader never receives names from a team they do not lead.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digest {

	public const CRON_HOOK = 'serve_dashboard_weekly_digest';

	public const OPTION_ENABLED = 'serve_dashboard_digest_enabled';

	/** Nobody wants a wall of names; the dashboard is one click away. */
	private const MAX_NAMES = 8;

	public static function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'send_all' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Monday morning, so the week starts with the list.
			wp_schedule_event( strtotime( 'next monday 7am' ), 'weekly', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	/**
	 * Send one digest per leader who has something waiting.
	 *
	 * @return int Number of emails sent.
	 */
	public static function send_all(): int {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		/*
		 * Selected by capability, not by role name. Churches commonly set the
		 * pastor up as a WordPress administrator, and an administrator holds
		 * every SERVE capability — so filtering on the two custom roles alone
		 * would quietly exclude the very person most likely to want this.
		 */
		$candidates = get_users(
			array(
				'number'  => 500,
				'fields'  => array( 'ID', 'user_email' ),
				'orderby' => 'ID',
			)
		);

		$leaders = array_filter(
			$candidates,
			static fn( $user ) => user_can( (int) $user->ID, Roles::CAP_VIEW_DASHBOARD )
				&& is_email( $user->user_email )
		);

		$original = get_current_user_id();
		$sent     = 0;

		foreach ( $leaders as $leader ) {
			// Become the leader so every query is scoped exactly as it would be
			// were they looking at the dashboard themselves.
			wp_set_current_user( (int) $leader->ID );

			$digest = self::build();

			if ( $digest['total'] < 1 ) {
				continue;
			}

			if ( wp_mail( $leader->user_email, $digest['subject'], $digest['body'] ) ) {
				++$sent;
			}
		}

		wp_set_current_user( $original );

		Audit::log( 'digest.sent', 'digest', null, array( 'emails' => $sent ) );

		return $sent;
	}

	/**
	 * Build the digest for whoever is currently the acting user.
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		$overdue = Submissions::query(
			array(
				'due_only' => true,
				'limit'    => 50,
			)
		);

		$today = gmdate( 'Y-m-d' );

		$past_due = array_values(
			array_filter(
				$overdue,
				static fn( $row ) => $row->next_action_at && $row->next_action_at < $today
			)
		);

		$waiting = Submissions::query(
			array(
				'status' => Schema::STATUS_SUBMITTED,
				'limit'  => 50,
			)
		);

		$unclaimed = array_values(
			array_filter( $waiting, static fn( $row ) => empty( $row->assigned_user_id ) )
		);

		$total = count( $past_due ) + count( $unclaimed );

		$lines = array( __( 'Here is where things stand with the people in your care this week.', 'serve-dashboard' ), '' );

		if ( $past_due ) {
			$lines[] = sprintf(
				/* translators: %d: number of overdue follow-ups. */
				_n( '%d follow-up is overdue:', '%d follow-ups are overdue:', count( $past_due ), 'serve-dashboard' ),
				count( $past_due )
			);
			$lines = array_merge( $lines, self::name_lines( $past_due ) );
			$lines[] = '';
		}

		if ( $unclaimed ) {
			$lines[] = sprintf(
				/* translators: %d: number of people nobody has picked up. */
				_n(
					'%d person is waiting and nobody has picked them up yet:',
					'%d people are waiting and nobody has picked them up yet:',
					count( $unclaimed ),
					'serve-dashboard'
				),
				count( $unclaimed )
			);
			$lines = array_merge( $lines, self::name_lines( $unclaimed ) );
			$lines[] = '';
		}

		$lines[] = __( 'Open the dashboard:', 'serve-dashboard' );
		$lines[] = admin_url( 'admin.php?page=' . Admin::PAGE_DASHBOARD );
		$lines[] = '';
		$lines[] = __( 'A suggested team is a conversation starter, not a decision. Nothing here needs to happen today.', 'serve-dashboard' );

		return array(
			'total'   => $total,
			'subject' => 0 === count( $past_due )
				? __( 'SERVE: people waiting for a conversation', 'serve-dashboard' )
				: sprintf(
					/* translators: %d: number of overdue follow-ups. */
					_n( 'SERVE: %d follow-up overdue', 'SERVE: %d follow-ups overdue', count( $past_due ), 'serve-dashboard' ),
					count( $past_due )
				),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * Names, truncated.
	 *
	 * Only names and dates: no gifts, no notes, no pastoral detail. Email is
	 * not a place to put a S.H.A.P.E. profile.
	 *
	 * @param array<int,object> $rows
	 * @return string[]
	 */
	private static function name_lines( array $rows ): array {
		$lines = array();

		foreach ( array_slice( $rows, 0, self::MAX_NAMES ) as $row ) {
			$lines[] = '  - ' . $row->display_name;
		}

		$extra = count( $rows ) - self::MAX_NAMES;
		if ( $extra > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of additional people not listed. */
				_n( '  ...and %d more', '  ...and %d more', $extra, 'serve-dashboard' ),
				$extra
			);
		}

		return $lines;
	}
}
