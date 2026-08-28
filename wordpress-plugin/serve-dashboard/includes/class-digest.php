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

		/*
		 * Headcounts that placements have overtaken.
		 *
		 * This is the one item in the digest that is about the tool rather than
		 * about a person, and it is here because the dashboard alone could not
		 * fix it: the drift note lives on a panel, and a panel has to be
		 * visited. Nobody was ever asked to correct the number, so across a
		 * pilot it would quietly get worse while the gap figures the deck
		 * promises got less true.
		 *
		 * Restricted to leaders who can edit team capacity. Everyone else would
		 * be asked to fix something they have no permission to touch.
		 */
		$headcount = current_user_can( Roles::CAP_MANAGE_TEAMS )
			? Teams::needs_headcount_check( Roles::visible_team_ids() )
			: array();

		$total = count( $past_due ) + count( $unclaimed ) + count( $headcount );

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

		if ( $headcount ) {
			$lines[] = sprintf(
				/* translators: %d: number of teams whose headcount is out of date. */
				_n(
					'%d team headcount is out of date — people have been placed since it was last confirmed:',
					'%d team headcounts are out of date — people have been placed since they were last confirmed:',
					count( $headcount ),
					'serve-dashboard'
				),
				count( $headcount )
			);
			$lines = array_merge( $lines, self::headcount_lines( $headcount ) );
			$lines[] = '';
			$lines[] = __( 'Correct them here:', 'serve-dashboard' );
			$lines[] = admin_url( 'admin.php?page=' . Admin::PAGE_TEAMS );
			$lines[] = '';
		}

		$lines[] = __( 'Open the dashboard:', 'serve-dashboard' );
		$lines[] = admin_url( 'admin.php?page=' . Admin::PAGE_DASHBOARD );
		$lines[] = '';
		$lines[] = __( 'A suggested team is a conversation starter, not a decision. Nothing here needs to happen today.', 'serve-dashboard' );

		return array(
			'total'   => $total,
			'subject' => self::subject( count( $past_due ), count( $unclaimed ), count( $headcount ) ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * Subject line, naming whichever thing is most pressing.
	 *
	 * Overdue people first, then people waiting, then the headcount nudge. The
	 * third case exists because the nudge can now be the only reason an email
	 * goes out, and "people waiting for a conversation" would then be simply
	 * untrue — a subject line that cries wolf teaches leaders to stop opening
	 * the digest at all.
	 */
	private static function subject( int $past_due, int $unclaimed, int $headcount ): string {
		if ( $past_due > 0 ) {
			return sprintf(
				/* translators: %d: number of overdue follow-ups. */
				_n( 'SERVE: %d follow-up overdue', 'SERVE: %d follow-ups overdue', $past_due, 'serve-dashboard' ),
				$past_due
			);
		}

		if ( $unclaimed > 0 ) {
			return __( 'SERVE: people waiting for a conversation', 'serve-dashboard' );
		}

		return sprintf(
			/* translators: %d: number of teams whose headcount needs confirming. */
			_n(
				'SERVE: %d team headcount needs confirming',
				'SERVE: %d team headcounts need confirming',
				$headcount,
				'serve-dashboard'
			),
			$headcount
		);
	}

	/**
	 * One line per team whose number has been overtaken.
	 *
	 * Team names and counts only. No names of the people placed: this is an
	 * administrative correction, and the digest's rule that email is not a
	 * place to put profile detail applies here too.
	 *
	 * @param array<int,object> $teams
	 * @return string[]
	 */
	private static function headcount_lines( array $teams ): array {
		$lines = array();

		foreach ( array_slice( $teams, 0, self::MAX_NAMES ) as $team ) {
			$days = $team->days_since_check;

			$when = null === $days
				? __( 'never confirmed', 'serve-dashboard' )
				: sprintf(
					/* translators: %d: whole days since the headcount was confirmed. */
					_n( 'confirmed %d day ago', 'confirmed %d days ago', $days, 'serve-dashboard' ),
					$days
				);

			$lines[] = sprintf(
				/* translators: 1: team name, 2: recorded headcount, 3: placements since, 4: when it was last confirmed. */
				__( '  - %1$s: %2$d recorded, %3$d placed since (%4$s)', 'serve-dashboard' ),
				$team->name,
				(int) $team->current_headcount,
				(int) $team->placed_since,
				$when
			);
		}

		$extra = count( $teams ) - self::MAX_NAMES;
		if ( $extra > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: number of additional teams not listed. */
				_n( '  ...and %d more', '  ...and %d more', $extra, 'serve-dashboard' ),
				$extra
			);
		}

		return $lines;
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
