<?php
/**
 * Ministry teams and their capacity.
 *
 * The gift lists below are the seed. They were once a mirror of a table in the
 * assessment's JavaScript; that copy is gone, and the assessment now reads these
 * teams from the server, so there is one list and nothing to drift.
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
	 * The teams a church starts with, before anybody edits them.
	 *
	 * target/min headcounts start at zero deliberately: a made-up target is
	 * worse than a visibly unset one, because it produces a gap number a
	 * leader might act on. The Teams screen prompts for the real figures.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function seed(): array {
		return array(
			array( 'Production', array( 'Assisting', 'Crafting', 'Creativity', 'Organization', 'Service' ), false, array( 'technical', 'audio', 'lighting', 'video', 'media', 'recording', 'powerpoint', 'mechanical', 'repairing' ) ),
			array( 'Livestream Team', array( 'Assisting', 'Creativity', 'Evangelism', 'Knowledge', 'Organization', 'Vision' ), false, array( 'video', 'audio', 'technical', 'recording', 'media', 'graphics', 'information systems', 'promoting' ) ),
			array( 'GROW - Small Group', array( 'Teaching', 'Encouragement', 'Mentoring', 'Hospitality', 'Wisdom', 'Discernment' ), false, array( 'fellowship', 'counseling', 'families', 'couples', 'singles', 'teaching', 'young marrieds' ) ),
			array( 'GROW - Compass', array( 'Teaching', 'Knowledge', 'Wisdom', 'Discernment', 'Encouragement', 'Leadership' ), false, array( 'education', 'teaching', 'researching', 'evangelism', 'ethics' ) ),
			array( 'GROW - Men Connect', array( 'Leadership', 'Mentoring', 'Encouragement', 'Wisdom', 'Justice' ), false, array( 'men', 'fathers', 'accountability', 'fellowship' ) ),
			array( 'GROW - Women Connect', array( 'Encouragement', 'Mentoring', 'Hospitality', 'Mercy', 'Teaching', 'Wisdom' ), false, array( 'women', 'mothers', 'parenting', 'hospitality', 'fellowship' ) ),
			array( 'GROW - Young Adults', array( 'Evangelism', 'Leadership', 'Mentoring', 'Encouragement', 'Vision', 'Mission' ), false, array( 'college', 'career', 'singles', 'young marrieds', 'students', 'evangelism' ) ),
			array( 'Fellowship Kids', array( 'Teaching', 'Creativity', 'Mercy', 'Assisting', 'Encouragement', 'Hospitality' ), true, array( 'children', 'infants', 'babies', 'toddlers', 'preschool', 'elementary', 'parenting', 'at-risk', 'teaching', 'artistic' ) ),
			array( 'Youth Ministry', array( 'Leadership', 'Evangelism', 'Mentoring', 'Teaching', 'Encouragement', 'Vision' ), true, array( 'high school', 'jr. high', 'students', 'athletic', 'teaching', 'evangelism' ) ),
			array( 'Events', array( 'Organization', 'Creativity', 'Hospitality', 'Assisting', 'Service', 'Leadership' ), false, array( 'planning', 'decorating', 'feeding', 'managing', 'promoting', 'organize', 'hospitality' ) ),
			array( 'Worship', array( 'Creativity', 'Vision', 'Encouragement', 'Discernment', 'Faith', 'Leadership' ), false, array( 'worship', 'musical', 'entertaining', 'artistic' ) ),
			array( 'Prayer', array( 'Prayer', 'Faith', 'Discernment', 'Healing', 'Mercy', 'Wisdom' ), false, array( 'prayer', 'illness', 'injury', 'recovery', 'disabilities', 'sanctity of life' ) ),
			array( 'Serve', array( 'Service', 'Assisting', 'Mercy', 'Giving', 'Hospitality', 'Teaching', 'Knowledge' ), false, array( 'homelessness', 'relief', 'community', 'neighborhood', 'feeding', 'resourceful', 'financial management' ) ),
			array( 'Welcome', array( 'Hospitality', 'Encouragement', 'Assisting', 'Mercy', 'Evangelism' ), false, array( 'welcoming', 'hospitality', 'recall', 'public relations', 'fellowship' ) ),
			array( 'Newcomers Pathway', array( 'Hospitality', 'Encouragement', 'Teaching', 'Mentoring', 'Organization', 'Leadership' ), false, array( 'welcoming', 'fellowship', 'mobilizing people for ministry', 'interview', 'recall' ) ),
			array( 'Administration', array( 'Organization', 'Leadership', 'Knowledge', 'Wisdom', 'Assisting', 'Vision' ), false, array( 'counting', 'classifying', 'evaluating', 'planning', 'editing', 'writing', 'researching', 'financial management' ) ),
		);
	}

	/** Populate the teams table on activation, without clobbering edits. */
	public static function seed_defaults(): void {
		global $wpdb;
		$table = Schema::table( 'teams' );

		foreach ( self::seed() as list( $name, $gifts, $safeguarded, $keywords ) ) {
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
					'keywords'              => wp_json_encode( $keywords ) ?: '[]',
					'target_headcount'      => 0,
					'min_headcount'         => 0,
					'current_headcount'     => 0,
					'requires_safeguarding' => $safeguarded ? 1 : 0,
					'is_active'             => 1,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d' )
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
	/*
	 * Deliberately uncached.
	 *
	 * A per-request cache was tried, to save the repeated query when a list
	 * ranks every row. It broke four tests, and the one that mattered was "a
	 * deactivated team is not suggested": the teams table is written from
	 * several places, including raw SQL in fixtures, so a static cache
	 * confidently reported a retired team as still open. Flush calls would have
	 * covered the paths that go through save() and missed exactly the ones that
	 * do not.
	 *
	 * A team list that lies about which teams exist is worse than a query per
	 * row. If this ever costs something real, the fix is to read the teams once
	 * and hand them to the ranking, not to guess from a static.
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

		$formats = array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' );

		/*
		 * Only touched when the caller actually passed it. An absent key is not
		 * an empty field: the Teams form always submits this, but anything
		 * saving a team programmatically would otherwise wipe its vocabulary as
		 * a side effect of adjusting a headcount — which is exactly what
		 * happened to two seeded teams the first time the suite ran.
		 */
		if ( array_key_exists( 'keywords', $data ) ) {
			$fields['keywords'] = wp_json_encode( self::parse_keywords( (string) $data['keywords'] ) ) ?: '[]';
			$formats[]          = '%s';
		}

		$updated = $wpdb->update(
			Schema::table( 'teams' ),
			$fields,
			array( 'id' => $id ),
			$formats,
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
	 * than from a profile.
	 *
	 * Anybody with any evidence for this team is a candidate. The filter used to
	 * be "already suggested, or a likely gift overlaps" — the same gifts-only
	 * narrowing that limited the person-first suggestions, and with the same
	 * consequence: somebody whose fit was a passion, an ability or a past job
	 * never appeared, so neither view could surface them and the fit was
	 * invisible from both directions. Evidence now means anything `explain()`
	 * can produce a readable reason from.
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

		/*
		 * Discovery is a SERVE-wide view, and only a SERVE-wide role gets it.
		 *
		 * This ran for any leader, and was circular for most of them:
		 * Submissions::query() scopes an ordinary leader to people who already
		 * have a placement row on one of their teams, so "find me candidates
		 * for my team" could only ever return people already assigned to it.
		 * The tests that appeared to demonstrate otherwise all ran as a pastor.
		 *
		 * The circularity is not fixable by widening the query. Answering it
		 * properly means reading the profiles of people who have not been
		 * assigned to this leader, which is exactly the disclosure the whole
		 * routing model exists to require a human decision for. So the feature
		 * belongs to the central intake role, and a ministry leader gets an
		 * explicit "ask SERVE" state rather than a list that silently means
		 * something else.
		 *
		 * can_discover_candidates() is the same question the REST layer asks,
		 * so the screen and the endpoint cannot disagree.
		 */
		if ( ! self::can_discover_candidates() ) {
			return array();
		}

		/*
		 * Ordered the way this list is ordered, then judged in PHP.
		 *
		 * It used to take the first 200 rows by next_action_at — a follow-up
		 * date that says nothing about fit — filter them, then re-sort the
		 * survivors by strength of evidence. So the best candidate for a team
		 * could be excluded before the comparator ever saw them, purely for
		 * having a distant follow-up date, and no amount of paging would
		 * surface them. Every verified submission is considered now, and the
		 * caller is told when the ceiling was hit rather than being handed a
		 * silently truncated answer.
		 *
		 * Heart, abilities and experience live inside profile_json, so there is
		 * no cheap SQL pre-filter that does not reintroduce the gifts-only
		 * narrowing this is here to remove — every row therefore gets its
		 * profile decoded. That is a few hundred json_decode calls per team
		 * view, which is nothing at the scale one church produces and would
		 * want revisiting long before it became one.
		 */
		$rows = Submissions::query(
			array(
				'include_snoozed' => true,
				'orderby'         => 'submitted_at',
				'limit'           => self::CANDIDATE_POOL,
			)
		);

		$out = array();

		foreach ( $rows as $row ) {
			// Already placed or declined elsewhere: not a candidate.
			if ( in_array( $row->status, array( Schema::STATUS_PLACED, Schema::STATUS_DECLINED ), true ) ) {
				continue;
			}

			$suggested = Submissions::decode_list( $row->suggested_teams );
			$already   = in_array( $team->slug, $suggested, true );

			$profile = Submissions::profile( $row, false );
			$match   = Matching::explain( $team, $profile );

			/*
			 * Nothing to say about them and nobody suggested them: not a
			 * candidate, and padding the list with names would make the list
			 * worth less than an empty one.
			 *
			 * Context counts here where it does not count towards a tier. A
			 * passion for Elementary Children is a real reason for a pastor to
			 * look at somebody for Fellowship Kids, and dropping it would
			 * reintroduce the gifts-only narrowing this view exists to remove.
			 * It is safe here and unsafe in a tier for the same reason: this
			 * list is read by somebody who can already see every profile, and
			 * appearing on it grants nobody anything.
			 */
			if ( ! $already && ! $match['reasons'] && ! $match['context'] ) {
				continue;
			}

			$out[] = array(
				'id'            => (int) $row->id,
				'name'          => $row->display_name,
				'initials'      => strtoupper( mb_substr( $row->display_name, 0, 1 ) ),
				'status'        => $row->status,
				'statusLabel'   => Schema::status_labels()[ $row->status ] ?? $row->status,
				'alreadySuggested' => $already,
				'needsCheck'    => (int) $team->requires_safeguarding
					&& Safeguarding::STATUS_CLEARED !== $row->safeguarding_status,
				'match'         => $match,
			);
		}

		/*
		 * The same order the person-first ranking uses, so a leader reading
		 * both views is not shown two different accounts of the same evidence.
		 * Tier, then coverage, then selectivity, then the raw hit count, with
		 * the name last for display determinism only.
		 */
		usort(
			$out,
			static function ( array $a, array $b ) {
				$ma = $a['match'];
				$mb = $b['match'];

				$ta = Matching_Contract::tier_rank( (string) $ma['tier'] );
				$tb = Matching_Contract::tier_rank( (string) $mb['tier'] );

				if ( $ta !== $tb ) {
					return $ta <=> $tb;
				}

				foreach ( array( 'team_coverage', 'selectivity', 'likely_hit_count' ) as $key ) {
					if ( $ma['evidence'][ $key ] !== $mb['evidence'][ $key ] ) {
						return $mb['evidence'][ $key ] <=> $ma['evidence'][ $key ];
					}
				}

				return strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return array_slice( $out, 0, $limit );
	}

	/**
	 * How many submissions the candidate view considers.
	 *
	 * A ceiling rather than a page: filtering a pre-truncated slice with one
	 * comparator and then re-sorting it with another is how the best candidate
	 * gets dropped before anything looks at them. Sized so a church would have
	 * to grow a great deal before it bound, and reported when it does.
	 */
	private const CANDIDATE_POOL = 2000;

	/**
	 * Whether the current user may discover candidates across the intake pool.
	 *
	 * The all-teams capability, not merely dashboard access. Asked here so the
	 * screen, the REST endpoint and the tests share one answer.
	 */
	public static function can_discover_candidates(): bool {
		return current_user_can( Roles::CAP_VIEW_ALL );
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

	/**
	 * The words a team's work is about, decoded.
	 *
	 * Tolerates a missing property so a row read before the 1.17.0 column
	 * existed cannot fatal — matching simply falls back to the team name, which
	 * is what it did before.
	 *
	 * @return string[]
	 */
	public static function keyword_list( object $team ): array {
		$decoded = json_decode( (string) ( $team->keywords ?? '' ), true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded as $word ) {
			$word = trim( (string) $word );
			if ( '' !== $word ) {
				$out[] = $word;
			}
		}

		return $out;
	}

	/**
	 * Turn what a pastor typed into a keyword list.
	 *
	 * Comma-separated, because that is how anybody would write a list of words
	 * without being told a format. Duplicates and blanks are dropped; case is
	 * kept as typed since the reason shown to a leader quotes the person's own
	 * wording, not this.
	 *
	 * @return string[]
	 */
	public static function parse_keywords( string $raw ): array {
		$out = array();

		foreach ( explode( ',', $raw ) as $word ) {
			$word = trim( sanitize_text_field( $word ) );

			if ( '' === $word || in_array( $word, $out, true ) ) {
				continue;
			}

			$out[] = $word;
		}

		return $out;
	}

	/**
	 * Give seeded teams their vocabulary on upgrade.
	 *
	 * Matched by slug and only where the column is still empty, so a site that
	 * has edited its own wording keeps it and a team somebody added themselves
	 * is left alone rather than handed another team's words.
	 */
	public static function backfill_keywords(): void {
		global $wpdb;
		$table = Schema::table( 'teams' );

		foreach ( self::seed() as list( $name, $gifts, $safeguarded, $keywords ) ) {
			unset( $gifts, $safeguarded );

			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					"UPDATE {$table} SET keywords = %s
					 WHERE slug = %s AND ( keywords = '' OR keywords = '[]' OR keywords IS NULL )",
					wp_json_encode( $keywords ) ?: '[]',
					sanitize_title( $name )
				)
			);
		}
	}
}
