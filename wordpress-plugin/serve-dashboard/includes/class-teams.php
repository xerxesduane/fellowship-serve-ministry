<?php
/**
 * Ministry teams and their capacity.
 *
 * The gift lists are seeded from the assessment's own ministryGiftTable so
 * that matching in the dashboard and matching in the public form cannot drift.
 * The headcount columns are new: the assessment never knew how many people a
 * team actually needs, which is why "team gaps" could not be computed before.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Teams {

	/**
	 * Seed data mirroring src/data/ministryGiftTable.ts.
	 *
	 * target/min headcounts start at zero deliberately: a made-up target is
	 * worse than a visibly unset one, because it produces a gap number a
	 * leader might act on. The Teams screen prompts for the real figures.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function seed(): array {
		return array(
			array( 'Production', array( 'Assisting', 'Crafting', 'Creativity', 'Organization', 'Service' ), false ),
			array( 'Livestream Team', array( 'Assisting', 'Creativity', 'Evangelism', 'Knowledge', 'Organization', 'Vision' ), false ),
			array( 'GROW - Small Group', array( 'Teaching', 'Encouragement', 'Mentoring', 'Hospitality', 'Wisdom', 'Discernment' ), false ),
			array( 'GROW - Compass', array( 'Teaching', 'Knowledge', 'Wisdom', 'Discernment', 'Encouragement', 'Leadership' ), false ),
			array( 'GROW - Men Connect', array( 'Leadership', 'Mentoring', 'Encouragement', 'Wisdom', 'Justice' ), false ),
			array( 'GROW - Women Connect', array( 'Encouragement', 'Mentoring', 'Hospitality', 'Mercy', 'Teaching', 'Wisdom' ), false ),
			array( 'GROW - Young Adults', array( 'Evangelism', 'Leadership', 'Mentoring', 'Encouragement', 'Vision', 'Mission' ), false ),
			array( 'Fellowship Kids', array( 'Teaching', 'Creativity', 'Mercy', 'Assisting', 'Encouragement', 'Hospitality' ), true ),
			array( 'Youth Ministry', array( 'Leadership', 'Evangelism', 'Mentoring', 'Teaching', 'Encouragement', 'Vision' ), true ),
			array( 'Events', array( 'Organization', 'Creativity', 'Hospitality', 'Assisting', 'Service', 'Leadership' ), false ),
			array( 'Worship', array( 'Creativity', 'Vision', 'Encouragement', 'Discernment', 'Faith', 'Leadership' ), false ),
			array( 'Prayer', array( 'Prayer', 'Faith', 'Discernment', 'Healing', 'Mercy', 'Wisdom' ), false ),
			array( 'Serve', array( 'Service', 'Assisting', 'Mercy', 'Giving', 'Hospitality', 'Teaching', 'Knowledge' ), false ),
			array( 'Welcome', array( 'Hospitality', 'Encouragement', 'Assisting', 'Mercy', 'Evangelism' ), false ),
			array( 'Newcomers Pathway', array( 'Hospitality', 'Encouragement', 'Teaching', 'Mentoring', 'Organization', 'Leadership' ), false ),
			array( 'Administration', array( 'Organization', 'Leadership', 'Knowledge', 'Wisdom', 'Assisting', 'Vision' ), false ),
		);
	}

	/** Populate the teams table on activation, without clobbering edits. */
	public static function seed_defaults(): void {
		global $wpdb;
		$table = Schema::table( 'teams' );

		foreach ( self::seed() as list( $name, $gifts, $safeguarded ) ) {
			$slug = sanitize_title( $name );

			$exists = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					"SELECT id FROM {$table} WHERE slug = %s",
					$slug
				)
			);

			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'slug'                  => $slug,
					'name'                  => $name,
					'gifts'                 => wp_json_encode( $gifts ) ?: '[]',
					'target_headcount'      => 0,
					'min_headcount'         => 0,
					'current_headcount'     => 0,
					'requires_safeguarding' => $safeguarded ? 1 : 0,
					'is_active'             => 1,
				),
				array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
			);
		}
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = Schema::table( 'teams' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);

		return $row ?: null;
	}

	public static function get_by_slug( string $slug ): ?object {
		global $wpdb;
		$table = Schema::table( 'teams' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE slug = %s",
				$slug
			)
		);

		return $row ?: null;
	}

	/**
	 * @return array<int,object>
	 */
	public static function all( bool $active_only = true ): array {
		global $wpdb;
		$table = Schema::table( 'teams' );

		$sql = "SELECT * FROM {$table}";
		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}
		$sql .= ' ORDER BY name ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input in this statement.
		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Teams that are short of people, worst gap first.
	 *
	 * Teams with no target set are excluded rather than reported as fully
	 * staffed, so an unconfigured team never masquerades as a healthy one.
	 *
	 * @return array<int,object>
	 */
	/**
	 * People placed on a team since anybody last confirmed its headcount.
	 *
	 * `current_headcount` is typed in by hand and means everyone serving on the
	 * team, most of whom never completed a S.H.A.P.E. assessment — so placements
	 * made through the dashboard cannot simply be added to it without
	 * double-counting the moment somebody updates the number themselves.
	 *
	 * What can be said without guessing is how many placements have happened
	 * since the figure was last looked at. Those are unambiguously not in it
	 * yet. Before the pipeline could be worked at all this was always zero; now
	 * that people can actually be placed, an untouched headcount goes stale a
	 * little further with each one.
	 *
	 * @return array<int,int> Team id => count.
	 */
	public static function placed_since_check(): array {
		global $wpdb;

		$placements  = Schema::table( 'placements' );
		$teams       = Schema::table( 'teams' );
		$submissions = Schema::table( 'submissions' );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input.
				"SELECT p.team_id, COUNT(*) AS placed
				 FROM {$placements} p
				 INNER JOIN {$teams} t ON t.id = p.team_id
				 INNER JOIN {$submissions} s ON s.id = p.submission_id AND s.verified_at IS NOT NULL
				 WHERE p.status = %s
				   AND ( t.headcount_checked_at IS NULL OR p.updated_at > t.headcount_checked_at )
				 GROUP BY p.team_id",
				Schema::STATUS_PLACED
			)
		);

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->team_id ] = (int) $row->placed;
		}

		return $out;
	}

	public static function gaps(): array {
		$gaps  = array();
		$since = self::placed_since_check();

		foreach ( self::all() as $team ) {
			$target = (int) $team->target_headcount;
			if ( $target <= 0 ) {
				continue;
			}

			$gap = $target - (int) $team->current_headcount;
			if ( $gap <= 0 ) {
				continue;
			}

			$team->gap           = $gap;
			$team->below_minimum = (int) $team->current_headcount < (int) $team->min_headcount;

			/*
			 * Reported beside the gap rather than subtracted from it. A leader
			 * can see that the shortfall is smaller than it looks and go and
			 * correct the number; software quietly adjusting it would be a guess
			 * dressed up as a fact.
			 */
			$team->placed_since  = $since[ (int) $team->id ] ?? 0;

			$gaps[]              = $team;
		}

		usort(
			$gaps,
			static function ( $a, $b ) {
				if ( $a->below_minimum !== $b->below_minimum ) {
					return $a->below_minimum ? -1 : 1;
				}

				return $b->gap <=> $a->gap;
			}
		);

		return $gaps;
	}

	/**
	 * How many placements make a headcount worth re-checking.
	 *
	 * One. A figure that is wrong by one is wrong, and during a pilot with a
	 * single team and a handful of profiles one placement is a meaningful
	 * share of the total. Set higher only if the weekly nudge becomes noise at
	 * real volume.
	 */
	private const DRIFT_NUDGE_AT = 1;

	/**
	 * Teams whose headcount somebody now needs to look at.
	 *
	 * `placed_since_check()` states the drift and `gaps()` carries it to the
	 * panel, but nothing so far has asked anybody to do something about it.
	 * The inline note is only read by whoever scrolls to it, and it looks
	 * exactly the same in week ten as in week one — so across a pilot it
	 * becomes wallpaper while the figure it qualifies drifts further from the
	 * truth. Disclosing a stale number is not the same as getting it fixed.
	 *
	 * Only teams with actual drift are returned. A figure nobody has touched
	 * in months is perfectly fine if nobody has been placed on that team; age
	 * alone is not evidence of error, and nudging on it would train leaders to
	 * ignore the nudge.
	 *
	 * @param int[]|null $team_ids Restrict to these team ids. Null means every
	 *                             team, matching `Roles::visible_team_ids()`,
	 *                             which returns null for an unrestricted user.
	 * @return array<int,object> Team rows carrying placed_since and
	 *                           days_since_check, worst drift first.
	 */
	public static function needs_headcount_check( ?array $team_ids = null ): array {
		$since = self::placed_since_check();

		if ( ! $since ) {
			return array();
		}

		$out = array();

		foreach ( self::all() as $team ) {
			$id = (int) $team->id;

			if ( null !== $team_ids && ! in_array( $id, $team_ids, true ) ) {
				continue;
			}

			$drift = $since[ $id ] ?? 0;

			if ( $drift < self::DRIFT_NUDGE_AT ) {
				continue;
			}

			$team->placed_since     = $drift;
			$team->days_since_check = self::days_since_check( $team );

			$out[] = $team;
		}

		/*
		 * Worst drift first, then the longest unchecked. Deliberately not by
		 * gap size: this list is about the accuracy of a number, not about
		 * which team is shortest of people.
		 */
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a->placed_since !== $b->placed_since ) {
					return $b->placed_since <=> $a->placed_since;
				}

				return ( $b->days_since_check ?? PHP_INT_MAX ) <=> ( $a->days_since_check ?? PHP_INT_MAX );
			}
		);

		return $out;
	}

	/**
	 * Whole days since anybody confirmed this team's headcount.
	 *
	 * Null when it has never been confirmed — which is not zero days, and must
	 * not be rendered as "checked today".
	 */
	private static function days_since_check( object $team ): ?int {
		if ( empty( $team->headcount_checked_at ) ) {
			return null;
		}

		// Stored by `save()` as UTC via current_time( 'mysql', true ).
		$checked = strtotime( (string) $team->headcount_checked_at . ' UTC' );

		if ( ! $checked ) {
			return null;
		}

		return max( 0, (int) floor( ( time() - $checked ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Save the editable fields of one team.
	 *
	 * @param array<string,mixed> $data Raw, unsanitised input.
	 */
	public static function save( int $id, array $data ): bool {
		if ( ! current_user_can( Roles::CAP_MANAGE_TEAMS ) ) {
			return false;
		}

		global $wpdb;

		$fields = array(
			'target_headcount'      => max( 0, (int) ( $data['target_headcount'] ?? 0 ) ),
			'min_headcount'         => max( 0, (int) ( $data['min_headcount'] ?? 0 ) ),
			'current_headcount'     => max( 0, (int) ( $data['current_headcount'] ?? 0 ) ),
			'requires_safeguarding' => empty( $data['requires_safeguarding'] ) ? 0 : 1,
			'is_active'             => empty( $data['is_active'] ) ? 0 : 1,
			'leader_user_id'        => (int) ( $data['leader_user_id'] ?? 0 ) ?: null,
			/*
			 * Saving the form counts as confirming the headcount, whether or not
			 * the number moved: somebody has just looked at it. Placements made
			 * after this moment are the ones it cannot yet account for.
			 */
			'headcount_checked_at'  => current_time( 'mysql', true ),
		);

		$updated = $wpdb->update(
			Schema::table( 'teams' ),
			$fields,
			array( 'id' => $id ),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			Audit::log( Audit::ACTION_TEAM_SAVED, 'team', $id, $fields );
		}

		return false !== $updated;
	}

	/**
	 * People who might fit one team, best-explained first.
	 *
	 * The inverse of the person-first view: a leader starts from the gap rather
	 * than from a profile. Candidates come from those already suggested to this
	 * team plus anyone whose likely gifts overlap it, so the list is not limited
	 * to whatever the assessment's own ranking happened to pick.
	 *
	 * Ordered by strength of evidence. Team need never enters the ordering —
	 * that a team is short of people says nothing about whether any given
	 * person belongs on it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function candidates( int $team_id, int $limit = 20 ): array {
		$team = self::get( $team_id );

		if ( ! $team || ! current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) {
			return array();
		}

		$gifts = array_map( 'strtolower', self::gift_list( $team ) );

		// Pull a wide slice and filter in PHP: the gift lists live in JSON, and
		// a LIKE per gift would be both uglier and no faster at pilot scale.
		$rows = Submissions::query(
			array(
				'include_snoozed' => true,
				'limit'           => 200,
			)
		);

		$out = array();

		foreach ( $rows as $row ) {
			// Already placed or declined elsewhere: not a candidate.
			if ( in_array( $row->status, array( Schema::STATUS_PLACED, Schema::STATUS_DECLINED ), true ) ) {
				continue;
			}

			$suggested = Submissions::decode_list( $row->suggested_teams );
			$likely    = array_map( 'strtolower', Submissions::decode_list( $row->gifts_likely ) );
			$overlap   = array_intersect( $likely, $gifts );

			if ( ! in_array( $team->slug, $suggested, true ) && ! $overlap ) {
				continue;
			}

			$profile = Submissions::profile( $row, false );
			$match   = Matching::explain( $row, $team, $profile );

			$out[] = array(
				'id'            => (int) $row->id,
				'name'          => $row->display_name,
				'initials'      => strtoupper( mb_substr( $row->display_name, 0, 1 ) ),
				'status'        => $row->status,
				'statusLabel'   => Schema::status_labels()[ $row->status ] ?? $row->status,
				'alreadySuggested' => in_array( $team->slug, $suggested, true ),
				'needsCheck'    => (int) $team->requires_safeguarding
					&& Safeguarding::STATUS_CLEARED !== $row->safeguarding_status,
				'match'         => $match,
			);
		}

		usort(
			$out,
			static function ( array $a, array $b ) {
				$order = array( 'strong' => 0, 'possible' => 1, 'unclear' => 2 );
				$sa    = $order[ $a['match']['strength'] ] ?? 3;
				$sb    = $order[ $b['match']['strength'] ] ?? 3;

				if ( $sa !== $sb ) {
					return $sa <=> $sb;
				}

				return $b['match']['selectivity'] <=> $a['match']['selectivity'];
			}
		);

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Gift names for a team, decoded.
	 *
	 * @return string[]
	 */
	public static function gift_list( object $team ): array {
		$decoded = json_decode( (string) $team->gifts, true );

		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}
}
