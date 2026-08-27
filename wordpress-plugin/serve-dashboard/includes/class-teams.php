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
	public static function gaps(): array {
		$gaps = array();

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
		);

		$updated = $wpdb->update(
			Schema::table( 'teams' ),
			$fields,
			array( 'id' => $id ),
			array( '%d', '%d', '%d', '%d', '%d', '%d' ),
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
