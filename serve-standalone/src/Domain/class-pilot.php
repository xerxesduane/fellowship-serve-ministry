<?php
/**
 * Pilot measurement.
 *
 * The deck asks for approval of a focused pilot on the understanding that
 * whether to expand is decided on evidence. Nothing in the plugin could produce
 * any, so the pilot would have ended in impressions — and impressions of a tool
 * you built yourself are worth very little.
 *
 * Everything here is derived from the audit trail and the submissions table.
 * No new storage, no per-person tracking beyond what is already recorded, and
 * nothing that was not already being written for other reasons.
 *
 * Two deliberate omissions:
 *
 * - **No per-leader figures.** "Who is slowest to ring people back" is
 *   performance monitoring of volunteers, which is a different activity from
 *   finding out whether a tool helps, and it is not what the deck asks. The
 *   audit trail could answer it; this deliberately does not.
 * - **No invented precision.** Every figure carries the number of people it was
 *   computed from, because a median of three is not a median, and a pilot is
 *   exactly where sample sizes are small enough to mislead.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Pilot {

	/** Below this many people, a median says more about luck than about process. */
	private const MIN_SAMPLE = 5;

	/**
	 * Whether a figure drawn from this many people is worth stating plainly.
	 *
	 * Its own method so the rule can be checked at its boundary rather than
	 * against whatever the database happens to hold. Asserting that the report's
	 * `enough` flag matches the report's own sample size passes trivially on any
	 * install where the sample is already large, which is to say it passes
	 * without testing anything.
	 */
	public static function is_enough( int $sample ): bool {
		return $sample >= self::MIN_SAMPLE;
	}

	/**
	 * The whole report.
	 *
	 * @return array<string,mixed>
	 */
	public static function report(): array {
		return array(
			'period'      => self::period(),
			'reach'       => self::reach(),
			'overlooked'  => self::overlooked(),
			'speed'       => self::time_to_first_action(),
			'progression' => self::progression(),
			'recorded'    => self::conversations_recorded(),
		);
	}

	/** What span of time these figures cover. */
	private static function period(): array {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		$first = $wpdb->get_var( "SELECT MIN( submitted_at ) FROM {$table}" );

		return array(
			'from' => $first,
			'days' => $first ? max( 1, (int) round( ( time() - strtotime( $first . ' UTC' ) ) / DAY_IN_SECONDS ) ) : 0,
		);
	}

	/** How many people got as far as sharing, and how many confirmed. */
	private static function reach(): array {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		$shared    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$confirmed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE verified_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'shared'    => $shared,
			'confirmed' => $confirmed,
			'waiting'   => $shared - $confirmed,
			// The share of people whose address was never proven. A high number
			// here is almost always a mail problem, not a people problem.
			'rate'      => $shared > 0 ? (int) round( $confirmed / $shared * 100 ) : null,
		);
	}

	/**
	 * The number the pilot exists to move.
	 *
	 * "Fewer people overlooked" was the claim. This is the count of people who
	 * confirmed their address and have had nothing happen to them since.
	 */
	private static function overlooked(): array {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		$rows = (array) $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
			"SELECT submitted_at FROM {$table}
			 WHERE verified_at IS NOT NULL
			   AND status = '" . Schema::STATUS_SUBMITTED . "'
			 ORDER BY submitted_at ASC"
		);

		return array(
			'count'        => count( $rows ),
			'longest_days' => $rows ? (int) floor( ( time() - strtotime( $rows[0] . ' UTC' ) ) / DAY_IN_SECONDS ) : null,
		);
	}

	/**
	 * How long until somebody acts.
	 *
	 * Measured from the profile being shared to its status first moving off
	 * Submitted — not to "contacted" specifically, because a leader who books a
	 * conversation straight away has plainly acted.
	 */
	private static function time_to_first_action(): array {
		global $wpdb;

		$submissions = Schema::table( 'submissions' );
		$audit       = Schema::table( 'audit' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input.
				"SELECT s.submitted_at, MIN( a.created_at ) AS first_action
				 FROM {$submissions} s
				 INNER JOIN {$audit} a
				         ON a.object_id = s.id
				        AND a.object_type = 'submission'
				        AND a.action = %s
				 WHERE s.verified_at IS NOT NULL
				 GROUP BY s.id, s.submitted_at",
				Audit::ACTION_STATUS_CHANGED
			)
		);

		$days = array();
		foreach ( $rows as $row ) {
			$from = strtotime( $row->submitted_at . ' UTC' );
			$to   = strtotime( $row->first_action . ' UTC' );

			if ( $from && $to && $to >= $from ) {
				$days[] = ( $to - $from ) / DAY_IN_SECONDS;
			}
		}

		sort( $days );

		return array(
			'sample'  => count( $days ),
			'enough'  => self::is_enough( count( $days ) ),
			'median'  => self::median( $days ),
			'slowest' => $days ? round( end( $days ), 1 ) : null,
		);
	}

	/**
	 * How far people actually get.
	 *
	 * A submission counts as having reached a stage if it is there now, or the
	 * audit trail says it passed through on the way.
	 *
	 * Deliberately restricted to profiles that still exist. The audit trail
	 * outlives the people in it — by design, since it records the deletion
	 * itself — so counting every id it mentions would mix people erased long ago
	 * into a numerator whose denominator only knows about current profiles. The
	 * first version did exactly that and reported sixty-five people reaching
	 * Trial serve out of seven. Both halves of a proportion have to describe the
	 * same population.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function progression(): array {
		global $wpdb;

		$submissions = Schema::table( 'submissions' );
		$audit       = Schema::table( 'audit' );

		/** @var array<int,array<string,bool>> $reached */
		$reached = array();

		$current = (array) $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
			"SELECT id, status FROM {$submissions} WHERE verified_at IS NOT NULL"
		);
		foreach ( $current as $row ) {
			$reached[ (int) $row->id ][ $row->status ] = true;

			/*
			 * Everybody starts here. Submitted is set when the row is created
			 * rather than through a status change, so it leaves no audit trail
			 * and would otherwise count only the people still sitting in it —
			 * a funnel whose first bar was shorter than its second.
			 */
			$reached[ (int) $row->id ][ Schema::STATUS_SUBMITTED ] = true;
		}

		$moves = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input.
				"SELECT a.object_id, a.meta_json
				 FROM {$audit} a
				 INNER JOIN {$submissions} s
				         ON s.id = a.object_id
				        AND s.verified_at IS NOT NULL
				 WHERE a.object_type = 'submission' AND a.action = %s",
				Audit::ACTION_STATUS_CHANGED
			)
		);
		foreach ( $moves as $move ) {
			$meta = json_decode( (string) $move->meta_json, true );
			$to   = is_array( $meta ) ? ( $meta['to'] ?? '' ) : '';

			if ( $to ) {
				$reached[ (int) $move->object_id ][ $to ] = true;
			}
		}

		$labels = Schema::status_labels();
		$out    = array();

		foreach ( Schema::pipeline() as $status ) {
			$count = 0;
			foreach ( $reached as $stages ) {
				if ( isset( $stages[ $status ] ) ) {
					++$count;
				}
			}

			$out[] = array(
				'status' => $status,
				'label'  => $labels[ $status ] ?? $status,
				'count'  => $count,
			);
		}

		return $out;
	}

	/**
	 * How often a conversation left a trace.
	 *
	 * Not a measure of how good the conversations were — only of whether the
	 * next leader can find out what was said, which is the specific thing the
	 * notes exist for.
	 */
	private static function conversations_recorded(): array {
		global $wpdb;

		$notes       = Schema::table( 'notes' );
		$submissions = Schema::table( 'submissions' );

		$with_notes = (int) $wpdb->get_var( "SELECT COUNT( DISTINCT submission_id ) FROM {$notes}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$contacted  = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
			"SELECT COUNT(*) FROM {$submissions}
			 WHERE verified_at IS NOT NULL AND status <> '" . Schema::STATUS_SUBMITTED . "'"
		);

		return array(
			'with_notes' => $with_notes,
			'acted_on'   => $contacted,
		);
	}

	/**
	 * @param array<int,float> $sorted
	 */
	private static function median( array $sorted ): ?float {
		$count = count( $sorted );

		if ( 0 === $count ) {
			return null;
		}

		$middle = (int) floor( $count / 2 );

		$value = 0 === $count % 2
			? ( $sorted[ $middle - 1 ] + $sorted[ $middle ] ) / 2
			: $sorted[ $middle ];

		return round( $value, 1 );
	}

	/**
	 * The questions this cannot answer.
	 *
	 * Four of the deck's five success questions are about how something felt to
	 * a person, and no amount of database will produce them. Printing them next
	 * to the figures is the difference between a report and a scoreboard.
	 *
	 * @return string[]
	 */
	public static function unanswerable(): array {
		return array(
			__( 'Do leaders understand why a team was suggested, and do they agree with the reasons?', 'serve-dashboard' ),
			__( 'Is what the profiles say about people actually accurate, once a leader has met them?', 'serve-dashboard' ),
			__( 'Did the invitation feel personal and unhurried to the person receiving it?', 'serve-dashboard' ),
			__( 'Could leaders move into Planning Center without confusion when they needed to?', 'serve-dashboard' ),
		);
	}
}
