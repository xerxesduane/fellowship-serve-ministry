<?php
/**
 * Explainable matching, on canonical gift ids.
 *
 * This class does not emit a percentage and does not have a scoring model. The
 * assessment records one required three-way answer per gift and nothing else
 * that has been validated against a ministry, so what comes out is a
 * qualitative tier plus the gifts that produced it — enough for a leader to
 * agree or disagree with, which a number would not be.
 *
 * Three separations the code has to keep visible, because conflating them is
 * what went wrong before:
 *
 *   rank_profile()                every active team with evidence and diagnostics
 *   recommendations_for_profile() what the participant and the dashboard show
 *   participant_match_snapshot()  the immutable record of what they were shown
 *
 * None of them is an authorisation. A tier is a heuristic over self-assessed
 * answers; access comes from an audited human assignment, and lives in
 * Placements and Roles.
 *
 * Two dimensions are handled and named separately on purpose:
 *
 * Personality — see `personality_notes()`. The workbook is explicit that
 * personality governs *how* a gift is exercised, not which team somebody
 * belongs on, and nothing in the teams table describes what a role is like, so
 * it would fire identically for all sixteen. Reported once per person, kept out
 * of tier and ordering entirely.
 *
 * Heart, abilities and experience — see `context()`. Still shown, clearly
 * labelled, and deliberately unscored. They used to reach tier through keyword
 * overlap against an editable free-text vocabulary, which meant an
 * administrator typing a word into the Teams screen could carry somebody to a
 * strength that created a placement row and opened their profile to that team.
 * Free-text vocabulary is not an access-control mechanism. Structured,
 * owner-approved option-id mappings can corroborate a tier — see
 * Corroboration — and the registry for them ships empty.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Matching {

	/*
	 * Tier constants live on the contract. These aliases remain because a
	 * release's worth of callers and stored strings use them, and because
	 * "strong" means the same thing on both sides.
	 */
	public const STRENGTH_STRONG   = Matching_Contract::TIER_STRONG;
	public const STRENGTH_POSSIBLE = Matching_Contract::TIER_SUGGESTED;
	public const STRENGTH_UNCLEAR  = Matching_Contract::TIER_EXPLORE;

	/**
	 * @return array<string,string>
	 */
	public static function strength_labels(): array {
		return Matching_Contract::tier_labels();
	}

	/**
	 * How many Strong/Suggested teams a profile gets.
	 *
	 * Three, and the same three everywhere: shown to the person, written into
	 * their snapshot, and displayed on the dashboard. One number governing all
	 * of it is what lets those agree.
	 */
	public static function suggestion_limit(): int {
		/**
		 * Filters how many recommended teams a profile gets.
		 *
		 * @param int $limit Default 3.
		 */
		return max( 1, (int) apply_filters( 'serve_dashboard_suggestion_limit', Matching_Contract::MAX_RECOMMENDATIONS ) );
	}

	/**
	 * Build the explained evidence for one profile against one team.
	 *
	 * Takes a profile rather than a submission row, which is what lets a person
	 * be shown the same reasoning their leader sees: the evidence is a pure
	 * function of what somebody answered and what a team is recognised by, so
	 * it can be worked out before a submission exists.
	 *
	 * @param object              $team    Row from the teams table.
	 * @param array<string,mixed> $profile Decoded profile (may be redacted).
	 * @param Gift_Ratings|null   $ratings Parsed once by the caller when ranking
	 *                                     every team, since it is per-profile
	 *                                     work that does not vary by team.
	 * @return array<string,mixed>
	 */
	public static function explain( object $team, array $profile, ?Gift_Ratings $ratings = null ): array {
		$ratings  = $ratings ?? Gift_Ratings::from_profile( $profile );
		$resolved = Gift_Crosswalk::resolve( Teams::gift_list( $team ) );
		$scorable = $resolved['ids'];

		// Unique canonical ids on both sides, so a team listing two terms that
		// mean one gift cannot count a participant's answer twice.
		$likely_hits   = array_values( array_intersect( $ratings->likely(), $scorable ) );
		$possible_hits = array_values( array_intersect( $ratings->possible(), $scorable ) );

		$all_likely     = $ratings->all_likely_count();
		$corroborations = Corroboration::for_team( (string) $team->slug, $profile );

		$tier = Matching_Contract::tier(
			count( $likely_hits ),
			count( $possible_hits ),
			$all_likely,
			count( $scorable ),
			count( $corroborations ),
			$ratings->is_valid()
		);

		$reasons = array();

		foreach ( $likely_hits as $id ) {
			$reasons[] = array(
				'dimension' => 'gifts',
				'weight'    => 'likely',
				'gift_id'   => $id,
				'label'     => sprintf(
					/* translators: 1: spiritual gift, 2: ministry team name. */
					__( 'Your likely gift of %1$s aligns with %2$s', 'serve-dashboard' ),
					Gift_Taxonomy::label( $id ),
					$team->name
				),
			);
		}

		/*
		 * Possible gifts are shown and can order two Explore results against
		 * each other, but the contract will not let them satisfy a Strong or
		 * Suggested threshold on their own. Describing "I may have this gift"
		 * as a strong match is exactly the false precision this avoids.
		 */
		foreach ( $possible_hits as $id ) {
			$reasons[] = array(
				'dimension' => 'gifts',
				'weight'    => 'possible',
				'gift_id'   => $id,
				'label'     => sprintf(
					/* translators: 1: spiritual gift, 2: ministry team name. */
					__( '%1$s is a gift you may have, and %2$s draws on it', 'serve-dashboard' ),
					Gift_Taxonomy::label( $id ),
					$team->name
				),
			);
		}

		foreach ( $corroborations as $dimension ) {
			$reasons[] = array(
				'dimension' => $dimension,
				'weight'    => 'corroborating',
				'gift_id'   => '',
				'label'     => sprintf(
					/* translators: 1: SHAPE dimension, 2: ministry team name. */
					__( 'Your %1$s answers also point towards %2$s', 'serve-dashboard' ),
					$dimension,
					$team->name
				),
			);
		}

		return array(
			'team_id'          => (int) $team->id,
			'team_slug'        => (string) $team->slug,
			'team_name'        => $team->name,

			'tier'             => $tier,
			'tier_label'       => Matching_Contract::tier_label( $tier ),
			// Kept so a release's worth of callers and templates keep working.
			'strength'         => $tier,
			'strength_label'   => Matching_Contract::tier_label( $tier ),

			'reasons'          => $reasons,

			/*
			 * Heart, abilities and experience: shown, never scored, and
			 * computed from the same capability-aware profile everything else
			 * reads — so a leader who may not see the Experiences section
			 * cannot receive a line derived from it either.
			 */
			'context'          => self::context( $team, $profile ),

			/*
			 * Everything the tier was decided from, kept for the audit trail
			 * and for explaining an old result after the rules move.
			 */
			'evidence'         => array(
				'likely_hits'        => $likely_hits,
				'likely_labels'      => Gift_Taxonomy::labels( $likely_hits ),
				'possible_hits'      => $possible_hits,
				'possible_labels'    => Gift_Taxonomy::labels( $possible_hits ),
				'likely_hit_count'   => count( $likely_hits ),
				'possible_hit_count' => count( $possible_hits ),
				'all_likely_count'   => $all_likely,
				'scorable_ids'       => $scorable,
				'scorable_count'     => count( $scorable ),
				'selectivity'        => round( Matching_Contract::selectivity( count( $likely_hits ), $all_likely ), 4 ),
				'team_coverage'      => round( Matching_Contract::coverage( count( $likely_hits ), count( $scorable ) ), 4 ),
				'possible_coverage'  => round( Matching_Contract::coverage( count( $possible_hits ), count( $scorable ) ), 4 ),
				'corroborations'     => $corroborations,
				'unmapped_terms'     => $resolved['unmapped'],
				'partition_valid'    => $ratings->is_valid(),
			),

			'versions'         => self::versions(),

			// Retained under its old name; several templates read it.
			'selectivity'      => round( Matching_Contract::selectivity( count( $likely_hits ), $all_likely ), 2 ),
			'gift_overlap'     => count( $likely_hits ),
			'gifts_claimed'    => $all_likely,
			'dimensions_hit'   => count( array_unique( array_column( $reasons, 'dimension' ) ) ),

			'undifferentiated' => Matching_Contract::is_undifferentiated( $all_likely ),
			'caveat'           => self::caveat( $ratings ),

			// Context about the team, never evidence about the person.
			'has_opening'      => self::has_opening( $team ),
			'opening_note'     => self::opening_note( $team ),

			'profile_redacted' => ! empty( $profile['_redacted'] ),
			'from_assessment'  => false,
		);
	}

	/**
	 * The mapping and rule versions a result was produced under.
	 *
	 * Stored with every snapshot so the dashboard can say "the rules have
	 * changed since this person was told" instead of quietly restating today's
	 * answer as what they saw.
	 *
	 * @return array<string,string>
	 */
	public static function versions(): array {
		return array(
			'taxonomy'      => Gift_Taxonomy::VERSION,
			'crosswalk'     => Gift_Crosswalk::VERSION,
			'contract'      => Matching_Contract::VERSION,
			'corroboration' => Corroboration::VERSION,
		);
	}

	/**
	 * Supporting context from heart, abilities and experience.
	 *
	 * Read by a human, weighed by nobody. This is the old `term_overlap`
	 * evidence with its effect on the outcome removed: it never enters a tier,
	 * a threshold or the comparator, and it cannot create or widen access.
	 *
	 * It stays because it is genuinely useful in a conversation — "a heart for
	 * Elementary Children" is worth a leader seeing next to Fellowship Kids —
	 * and because deleting it would leave team-first discovery with nothing but
	 * gift overlap, which is the narrowing the church already fixed once.
	 *
	 * Experiences appear only when the caller's profile still contains them,
	 * which is decided by Submissions::profile() and CAP_VIEW_SENSITIVE. There
	 * is no path here that reads evidence the caller may not read.
	 *
	 * @param array<string,mixed> $profile
	 * @return array<int,array<string,string>>
	 */
	private static function context( object $team, array $profile ): array {
		$vocabulary = self::vocabulary( $team );
		$out        = array();

		$heart = array_merge(
			(array) ( $profile['heart']['roles'] ?? array() ),
			(array) ( $profile['heart']['people'] ?? array() ),
			(array) ( $profile['heart']['causes'] ?? array() )
		);

		foreach ( self::term_overlap( $heart, $vocabulary ) as $term ) {
			$out[] = array(
				'dimension' => 'heart',
				/* translators: %s: the person's stated passion. */
				'label'     => sprintf( __( '%s is one of their stated passions', 'serve-dashboard' ), $term ),
			);
		}

		foreach ( self::term_overlap( (array) ( $profile['abilities'] ?? array() ), $vocabulary ) as $term ) {
			$out[] = array(
				'dimension' => 'abilities',
				/* translators: %s: a relevant ability. */
				'label'     => sprintf( __( 'Relevant ability: %s', 'serve-dashboard' ), $term ),
			);
		}

		if ( isset( $profile['experiences'] ) ) {
			$experiences = array();
			foreach ( (array) $profile['experiences'] as $values ) {
				$experiences = array_merge( $experiences, (array) $values );
			}

			foreach ( self::term_overlap( $experiences, $vocabulary ) as $term ) {
				$out[] = array(
					'dimension' => 'experience',
					/* translators: %s: a relevant past experience. */
					'label'     => sprintf( __( 'Past experience may transfer: %s', 'serve-dashboard' ), $term ),
				);
			}
		}

		return $out;
	}

	/**
	 * Better-evidenced first, and deterministic.
	 *
	 * Tier, then how many approved structured dimensions corroborate it, then
	 * how much of the team this person covers, then how selective that is, then
	 * the raw likely count, then possible coverage. Slug last, and only so that
	 * two genuinely equal teams appear in a stable order between requests —
	 * alphabetical position is not evidence and `co_match` says so explicitly.
	 *
	 * Team need, vacancies, headcount, safeguarding state, table order and
	 * timestamps are all absent. A vacancy is not evidence that a person fits.
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 */
	private static function compare( array $a, array $b ): int {
		foreach ( self::comparable( $a ) as $key => $value ) {
			$other = self::comparable( $b )[ $key ];

			if ( $value !== $other ) {
				// Tier rank sorts ascending; every other measure descending.
				return 'tier' === $key ? $value <=> $other : $other <=> $value;
			}
		}

		return strcmp( (string) $a['team_slug'], (string) $b['team_slug'] );
	}

	/**
	 * The ordered measures two matches are compared on, before the slug.
	 *
	 * Extracted so `co_match` can ask the same question the sort asked, rather
	 * than a second copy of it that could drift.
	 *
	 * @param array<string,mixed> $match
	 * @return array<string,int|float>
	 */
	private static function comparable( array $match ): array {
		return array(
			'tier'              => Matching_Contract::tier_rank( (string) $match['tier'] ),
			'corroborations'    => count( $match['evidence']['corroborations'] ),
			'team_coverage'     => (float) $match['evidence']['team_coverage'],
			'selectivity'       => (float) $match['evidence']['selectivity'],
			'likely_hit_count'  => (int) $match['evidence']['likely_hit_count'],
			'possible_coverage' => (float) $match['evidence']['possible_coverage'],
		);
	}

	/**
	 * Every active team, ranked, with the assessment's own picks always present.
	 *
	 * A team the church has deactivated drops out even if the assessment named
	 * it: suggesting a closed team is worse than a gap in the history.
	 *
	 * @param array<string,mixed> $profile
	 * @param string[]            $from_assessment Slugs the assessment named, if known.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank_profile( array $profile, ?int $limit = null, array $from_assessment = array() ): array {
		$limit           = $limit ?? self::suggestion_limit();
		$from_assessment = array_map( 'strval', $from_assessment );

		// Parsed once. It is per-profile work and does not vary by team.
		$ratings = Gift_Ratings::from_profile( $profile );

		$ranked = array();
		foreach ( Teams::all() as $team ) {
			$match                    = self::explain( $team, $profile, $ratings );
			$match['from_assessment'] = in_array( (string) $team->slug, $from_assessment, true );

			$ranked[] = $match;
		}

		usort( $ranked, array( self::class, 'compare' ) );
		$ranked = self::mark_co_matches( $ranked );

		$out   = array();
		$taken = 0;

		foreach ( $ranked as $match ) {
			$has_evidence = $match['evidence']['likely_hit_count'] > 0
				|| $match['evidence']['possible_hit_count'] > 0;

			if ( $has_evidence && $taken < $limit ) {
				$out[] = $match;
				++$taken;
				continue;
			}

			// What the person was told at the end of their journey, whether or
			// not today's evidence still supports it. A leader has to be able
			// to answer "but it said Prayer".
			if ( $match['from_assessment'] ) {
				$out[] = $match;
			}
		}

		return $out;
	}

	/**
	 * Flag runs of teams that are equal on every measure that counts.
	 *
	 * Without this the display numbers them 1, 2, 3 and the person reads the
	 * order as a finding. Two teams identical through possible-hit coverage are
	 * separated only by their slug, which is not evidence about anyone, and
	 * saying so is more honest than manufacturing a winner.
	 *
	 * @param array<int,array<string,mixed>> $ranked Already sorted.
	 * @return array<int,array<string,mixed>>
	 */
	private static function mark_co_matches( array $ranked ): array {
		$count = count( $ranked );

		foreach ( $ranked as $i => $match ) {
			$equal = false;

			if ( $i > 0 && self::comparable( $ranked[ $i - 1 ] ) === self::comparable( $match ) ) {
				$equal = true;
			}

			if ( $i + 1 < $count && self::comparable( $ranked[ $i + 1 ] ) === self::comparable( $match ) ) {
				$equal = true;
			}

			// Only meaningful where there is something to be equal about.
			$ranked[ $i ]['co_match'] = $equal
				&& Matching_Contract::TIER_NONE !== $match['tier'];
		}

		return $ranked;
	}

	/**
	 * What the participant and the dashboard show, as one decided answer.
	 *
	 * Up to three Strong/Suggested teams; failing that, up to two Explore
	 * options that have real evidence; failing that, nothing, said plainly.
	 * Never padded, never forced.
	 *
	 * @param array<string,mixed> $profile
	 * @param string[]            $from_assessment
	 * @return array{state:string,tier:string,teams:array<int,array<string,mixed>>,caveat:string,unmapped_likely:string[],versions:array<string,string>}
	 */
	public static function recommendations_for_profile( array $profile, array $from_assessment = array() ): array {
		$ratings = Gift_Ratings::from_profile( $profile );
		$ranked  = self::rank_profile( $profile, PHP_INT_MAX, $from_assessment );

		$recommended = array();
		$explore     = array();

		foreach ( $ranked as $match ) {
			if ( in_array( $match['tier'], array( Matching_Contract::TIER_STRONG, Matching_Contract::TIER_SUGGESTED ), true ) ) {
				$recommended[] = $match;
			} elseif ( Matching_Contract::TIER_EXPLORE === $match['tier'] ) {
				$explore[] = $match;
			}
		}

		if ( $recommended ) {
			$teams = array_slice( $recommended, 0, self::suggestion_limit() );

			return array(
				'state'           => 'ready',
				// The best tier present, which is what the page headline says.
				'tier'            => $teams[0]['tier'],
				'teams'           => $teams,
				'caveat'          => self::caveat( $ratings ),
				'unmapped_likely' => Gift_Taxonomy::labels( $ratings->unmapped_likely() ),
				'versions'        => self::versions(),
			);
		}

		if ( $explore ) {
			return array(
				'state'           => 'explore',
				'tier'            => Matching_Contract::TIER_EXPLORE,
				'teams'           => array_slice( $explore, 0, Matching_Contract::MAX_EXPLORE ),
				'caveat'          => self::caveat( $ratings ),
				'unmapped_likely' => Gift_Taxonomy::labels( $ratings->unmapped_likely() ),
				'versions'        => self::versions(),
			);
		}

		return array(
			'state'           => 'none',
			'tier'            => Matching_Contract::TIER_NONE,
			'teams'           => array(),
			'caveat'          => self::caveat( $ratings ),
			'unmapped_likely' => Gift_Taxonomy::labels( $ratings->unmapped_likely() ),
			'versions'        => self::versions(),
		);
	}

	/**
	 * The immutable record of what one participant was shown.
	 *
	 * Server-generated from sanitised canonical data. Nothing a client sends —
	 * team ids, tiers, reasons, a whole snapshot — is trusted or copied in.
	 *
	 * Carries only non-sensitive reasons: team identity, tier, the gift ids and
	 * labels that produced it, the arithmetic behind them, and the versions in
	 * force. No heart, abilities, experience or personality, because a stored
	 * snapshot is read in more places than the profile is and must not become a
	 * second, less-guarded copy of the sensitive sections.
	 *
	 * @param array<string,mixed> $profile
	 * @return array<string,mixed>
	 */
	public static function participant_match_snapshot( array $profile ): array {
		$result = self::recommendations_for_profile( $profile );

		$teams = array();
		foreach ( $result['teams'] as $match ) {
			$teams[] = array(
				'team_id'    => $match['team_id'],
				'team_slug'  => $match['team_slug'],
				'team_name'  => $match['team_name'],
				'tier'       => $match['tier'],
				'co_match'   => ! empty( $match['co_match'] ),
				'gifts'      => $match['evidence']['likely_labels'],
				'evidence'   => array(
					'likely_hit_count'   => $match['evidence']['likely_hit_count'],
					'possible_hit_count' => $match['evidence']['possible_hit_count'],
					'all_likely_count'   => $match['evidence']['all_likely_count'],
					'scorable_count'     => $match['evidence']['scorable_count'],
					'selectivity'        => $match['evidence']['selectivity'],
					'team_coverage'      => $match['evidence']['team_coverage'],
					'corroborations'     => $match['evidence']['corroborations'],
				),
			);
		}

		return array(
			'state'           => $result['state'],
			'tier'            => $result['tier'],
			'teams'           => $teams,
			'caveat'          => $result['caveat'],
			'unmapped_likely' => $result['unmapped_likely'],
			'versions'        => $result['versions'],
			'created_at'      => current_time( 'mysql', true ),
		);
	}

	/**
	 * The teams worth putting in front of somebody, Strong and Suggested only.
	 *
	 * @param array<string,mixed> $profile
	 * @param string[]            $from_assessment
	 * @return array<int,array<string,mixed>>
	 */
	public static function suggestions_for_profile( array $profile, array $from_assessment = array() ): array {
		$result = self::recommendations_for_profile( $profile, $from_assessment );

		return 'ready' === $result['state'] ? $result['teams'] : array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function suggestions_for_submission( object $submission, array $profile ): array {
		return self::suggestions_for_profile(
			$profile,
			Submissions::decode_list( $submission->suggested_teams )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( object $submission, array $profile, ?int $limit = null ): array {
		return self::rank_profile(
			$profile,
			$limit,
			Submissions::decode_list( $submission->suggested_teams )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_submission( object $submission, array $profile ): array {
		return self::suggestions_for_submission( $submission, $profile );
	}

	/**
	 * The team slugs a new submission records as what the person was shown.
	 *
	 * This is `suggested_teams`, and it is now only a record. It used to build
	 * the placement rows that decided which ministry leaders could open the
	 * profile; it does not any more, and nothing downstream may treat it as an
	 * authorisation. See Placements::assign_intake_owner().
	 *
	 * @param array<string,mixed> $profile
	 * @return string[]
	 */
	public static function slugs_for_profile( array $profile ): array {
		$slugs = array();

		foreach ( self::suggestions_for_profile( $profile ) as $match ) {
			if ( '' !== $match['team_slug'] ) {
				$slugs[] = $match['team_slug'];
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * The caveat a result carries, if any.
	 *
	 * Ordered by how much it invalidates: an unreadable partition first, then
	 * answers that do not discriminate. Both are phrased as something to
	 * explore rather than as a fault — somebody new to the language of
	 * spiritual gifts may genuinely not know yet, and that is a conversation.
	 */
	private static function caveat( Gift_Ratings $ratings ): string {
		if ( ! $ratings->is_valid() ) {
			return $ratings->invalid_reason();
		}

		if ( Matching_Contract::is_undifferentiated( $ratings->all_likely_count() ) ) {
			return self::undifferentiated_note( $ratings->all_likely_count() );
		}

		return '';
	}

	public static function is_undifferentiated( int $claimed_gifts ): bool {
		return Matching_Contract::is_undifferentiated( $claimed_gifts );
	}

	public static function undifferentiated_note( int $claimed_gifts ): string {
		return sprintf(
			/* translators: 1: number of gifts marked likely, 2: total gifts. */
			__( 'This person marked %1$d of the %2$d gifts as likely, so their answers do not point clearly to one team. Worth exploring together rather than relying on a suggestion.', 'serve-dashboard' ),
			$claimed_gifts,
			count( Gift_Taxonomy::ids() )
		);
	}

	/**
	 * What a person's temperament suggests about how they would serve.
	 *
	 * Notes about the person, not about any one team, which is the workbook's
	 * own position: personality affects the manner in which a gift is
	 * exercised, and serving against the grain of it "creates tension and
	 * discomfort, requires extra effort and energy, and produces less than the
	 * best results". Useful for shaping a role. Useless for choosing between
	 * two teams, and never a reason to rule somebody out.
	 *
	 * Returned once per person rather than per suggestion, because repeating an
	 * identical block under every team would imply it discriminated between
	 * them.
	 *
	 * @param array<string,mixed> $profile Decoded profile.
	 * @return array<int,array<string,string>>
	 */
	public static function personality_notes( array $profile ): array {
		$out = array();

		foreach ( (array) ( $profile['personality'] ?? array() ) as $tendency ) {
			if ( ! is_scalar( $tendency ) ) {
				continue;
			}

			$label = trim( (string) $tendency );
			$note  = self::personality_note( $label );

			if ( '' === $label || '' === $note ) {
				continue;
			}

			$out[] = array(
				'tendency' => $label,
				'note'     => $note,
			);
		}

		return $out;
	}

	/**
	 * One tendency, in terms of serving.
	 *
	 * Matched on a keyword rather than the whole stored label so that editing
	 * the assessment's wording does not silently empty this list. An unknown
	 * label returns an empty string and is skipped: inventing a note for a
	 * tendency nobody recognises would be worse than saying nothing.
	 */
	private static function personality_note( string $label ): string {
		$notes = array(
			'extroverted' => __( 'Gains energy from people and variety, so a role with plenty of contact is likely to suit.', 'serve-dashboard' ),
			'introverted' => __( 'Gains energy from quiet reflection and listens well. Depth with a few people may suit better than a busy front-of-house role.', 'serve-dashboard' ),
			'expressive'  => __( 'Open and verbal about their thoughts, so a role where speaking up is expected should feel natural.', 'serve-dashboard' ),
			'controlled'  => __( 'Tends to keep their thoughts to themselves. Worth asking directly what they would like rather than waiting for them to offer it.', 'serve-dashboard' ),
			'routine'     => __( 'More comfortable where expectations are clear, and likes finishing one thing before starting the next.', 'serve-dashboard' ),
			'variety'     => __( 'More fulfilled by work that changes, and does not need one task closed before the next begins.', 'serve-dashboard' ),
			'cooperative' => __( 'Sees other points of view easily and likes being part of a team effort.', 'serve-dashboard' ),
			'competitive' => __( 'Motivated by a challenge, which raises their effort when there are obstacles to get past.', 'serve-dashboard' ),
		);

		$haystack = strtolower( $label );

		foreach ( $notes as $keyword => $note ) {
			if ( str_contains( $haystack, $keyword ) ) {
				return $note;
			}
		}

		return '';
	}

	/**
	 * Everything a team's work can be recognised by, for context only.
	 *
	 * Editable on the Teams screen, and no longer able to affect a tier. That
	 * separation is the point: administrative vocabulary is display copy, and
	 * display copy must not be able to open a profile.
	 *
	 * @return string[]
	 */
	private static function vocabulary( object $team ): array {
		$stop  = array( 'team', 'the', 'and', 'grow', 'ministry', 'of', 'for' );
		$words = array_filter(
			preg_split( '/[^a-z]+/', strtolower( (string) $team->name ) ) ?: array(),
			static fn( $word ) => strlen( $word ) > 3 && ! in_array( $word, $stop, true )
		);

		return array_values( array_unique( array_merge( $words, Teams::keyword_list( $team ) ) ) );
	}

	/**
	 * A person's own answers that this team's vocabulary recognises.
	 *
	 * Crude on purpose: it only ever produces a line a human can read and
	 * immediately agree or disagree with, so a false positive is visible rather
	 * than buried inside a score. The value returned is always the person's own
	 * wording, never the vocabulary word that matched it.
	 *
	 * Matched on word boundaries rather than as a substring. Substring matching
	 * made "Women" a match for the Men Connect vocabulary, which is both wrong
	 * and the kind of wrong a leader would notice before the software did.
	 *
	 * @param array<int,mixed> $values
	 * @param string[]         $vocabulary
	 * @return string[]
	 */
	private static function term_overlap( array $values, array $vocabulary ): array {
		if ( ! $vocabulary ) {
			return array();
		}

		$patterns = array();
		foreach ( $vocabulary as $term ) {
			if ( ! is_scalar( $term ) ) {
				continue;
			}

			$term = trim( (string) $term );
			if ( '' === $term ) {
				continue;
			}

			$patterns[] = '/\b' . preg_quote( $term, '/' ) . '\b/i';
		}

		$hits = array();
		foreach ( $values as $value ) {
			/*
			 * Only scalars. A profile reaching this from storage has been
			 * through sanitize_profile() and holds strings, but a public
			 * endpoint is handed whatever a caller sends: an array where a
			 * string belongs used to be cast to "Array" — emitting a PHP
			 * warning per value, so a small request could fill a log on any
			 * site with WP_DEBUG_LOG on. Skipped rather than stringified,
			 * because "Array" is not something a person answered.
			 */
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$haystack = (string) $value;
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $haystack ) ) {
					$hits[] = $haystack;
					break;
				}
			}
		}

		return array_values( array_unique( $hits ) );
	}

	private static function has_opening( object $team ): bool {
		$target = (int) $team->target_headcount;

		return $target > 0 && (int) $team->current_headcount < $target;
	}

	/**
	 * Context about team need, phrased so it never reads as a reason the
	 * person should join.
	 */
	private static function opening_note( object $team ): string {
		if ( ! self::has_opening( $team ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: team name. */
			__( '%s currently has openings. This is context, not a reason to place someone.', 'serve-dashboard' ),
			$team->name
		);
	}
}
