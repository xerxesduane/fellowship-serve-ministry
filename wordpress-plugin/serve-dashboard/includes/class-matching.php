<?php
/**
 * Explainable matching.
 *
 * The product brief and the presentation's own speaker notes both describe the
 * match percentages in the concept mockups as illustrative. They are not
 * produced by a scoring model, and this plugin does not have one: the
 * assessment ranks ministries on spiritual-gift name overlap alone.
 *
 * So this class deliberately does not emit a percentage. It emits a qualitative
 * strength and, more importantly, the reasons behind it — drawn across gifts,
 * heart, abilities and experience — so a leader can judge the suggestion rather
 * than trust a number. A suggestion is a conversation starter, never a decision.
 *
 * Personality is handled separately and on purpose. See `personality_notes()`:
 * it describes *how* somebody is likely to serve rather than *which* team they
 * belong on, so it is surfaced once per person alongside the suggestions and is
 * kept out of ranking and strength entirely.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Matching {

	public const STRENGTH_STRONG   = 'strong';
	public const STRENGTH_POSSIBLE = 'possible';
	public const STRENGTH_UNCLEAR  = 'unclear';

	/**
	 * Marking this many of the 18 gifts "likely" means the answers do not
	 * distinguish between them.
	 *
	 * Someone answering agreeably — "yes, that sounds like me" to everything —
	 * used to come out with several confident matches, because raw overlap
	 * counts reward claiming more. That is the opposite of useful: the more
	 * someone claims, the *less* any single overlap tells you.
	 */
	private const UNDIFFERENTIATED_AT = 12;

	/** Minimum share of a person's claimed gifts that must be relevant. */
	private const MIN_SELECTIVITY = 0.25;

	/**
	 * @return array<string,string>
	 */
	public static function strength_labels(): array {
		return array(
			self::STRENGTH_STRONG   => __( 'Strong match', 'serve-dashboard' ),
			self::STRENGTH_POSSIBLE => __( 'Possible match', 'serve-dashboard' ),
			self::STRENGTH_UNCLEAR  => __( 'Needs a conversation', 'serve-dashboard' ),
		);
	}

	/**
	 * Build the explained suggestion for one submission against one team.
	 *
	 * @param object              $submission Row from the submissions table.
	 * @param object              $team       Row from the teams table.
	 * @param array<string,mixed> $profile    Decoded profile (may be redacted).
	 * @return array<string,mixed>
	 */
	public static function explain( object $submission, object $team, array $profile ): array {
		$team_gifts = array_map( 'strtolower', Teams::gift_list( $team ) );
		$vocabulary = self::vocabulary( $team );
		$reasons    = array();

		// 1. Spiritual gifts. The only dimension the underlying ranking uses,
		// so it carries the most weight here too — but it is named as one
		// factor among several rather than presented as the whole answer.
		$likely      = (array) ( $profile['spiritualGifts']['likely'] ?? array() );
		$gift_overlap = array_values(
			array_filter(
				$likely,
				static fn( $gift ) => in_array( strtolower( (string) $gift ), $team_gifts, true )
			)
		);

		foreach ( $gift_overlap as $gift ) {
			$reasons[] = array(
				'dimension' => 'gifts',
				'label'     => sprintf(
					/* translators: 1: spiritual gift, 2: ministry team name. */
					__( '%1$s aligns with %2$s', 'serve-dashboard' ),
					$gift,
					$team->name
				),
			);
		}

		// 2. Heart. A stated passion that names the team, or a word the team
		// name shares, is a strong signal because the person volunteered it.
		$heart = array_merge(
			(array) ( $profile['heart']['roles'] ?? array() ),
			(array) ( $profile['heart']['people'] ?? array() ),
			(array) ( $profile['heart']['causes'] ?? array() )
		);

		foreach ( self::term_overlap( $heart, $vocabulary ) as $term ) {
			$reasons[] = array(
				'dimension' => 'heart',
				'label'     => sprintf(
					/* translators: %s: the person's stated passion. */
					__( '%s is one of their stated passions', 'serve-dashboard' ),
					$term
				),
			);
		}

		// 3. Abilities. The assessment has always collected these and, before
		// 1.12.0, nothing had ever used them.
		$abilities = (array) ( $profile['abilities'] ?? array() );

		foreach ( self::term_overlap( $abilities, $vocabulary ) as $term ) {
			$reasons[] = array(
				'dimension' => 'abilities',
				'label'     => sprintf(
					/* translators: %s: a relevant ability. */
					__( 'Relevant ability: %s', 'serve-dashboard' ),
					$term
				),
			);
		}

		/*
		 * Speaking more than one language is not a reason for any particular
		 * team, and it used to be filed as one.
		 *
		 * It fired identically for all sixteen teams, so every multilingual
		 * person gained the abilities dimension everywhere whether a single
		 * ability of theirs matched or not — inflating the dimension count that
		 * ranking sorts on, and helping satisfy the two-dimension requirement
		 * for "Strong match" with evidence that says nothing about the team.
		 * That is exactly the reasoning that keeps personality out of this list,
		 * and it applied here too; it was found by seeding demo people with real
		 * languages and watching unrelated teams climb.
		 *
		 * Languages are still in front of the leader: they are part of the
		 * Abilities section of the profile, they are their own column in the
		 * people list, and they are a filter on it. In a congregation speaking
		 * six languages that is worth surfacing — but as something a leader
		 * reads about a person, not as evidence for a team.
		 */

		// 4. Experience. Redacted profiles legitimately have none of this, and
		// the absence must not read as "no relevant experience".
		if ( isset( $profile['experiences'] ) ) {
			$experiences = array();
			foreach ( (array) $profile['experiences'] as $values ) {
				$experiences = array_merge( $experiences, (array) $values );
			}

			foreach ( self::term_overlap( $experiences, $vocabulary ) as $term ) {
				$reasons[] = array(
					'dimension' => 'experience',
					'label'     => sprintf(
						/* translators: %s: a relevant past experience. */
						__( 'Past experience may transfer: %s', 'serve-dashboard' ),
						$term
					),
				);
			}
		}

		/*
		 * 5. Personality is deliberately absent from this list.
		 *
		 * The workbook is explicit that personality governs how and where a
		 * person exercises a gift, not which team they belong on: two people
		 * with the same gift of evangelism express it differently if one is
		 * introverted and the other extroverted.
		 *
		 * It is also constant across teams. Nothing in the teams table
		 * describes what a role is actually like, so the same four tendencies
		 * would fire identically for every suggestion — carrying no
		 * information about any of them. Adding it to `$reasons` would inflate
		 * both the dimension count and the reason count for every team at
		 * once, which is worse than useless: it would let any team with two
		 * gift overlaps reach "Strong match" on evidence that says nothing
		 * about that team, defeating the corroboration rule below.
		 *
		 * So it is reported by `personality_notes()`, once per person, as
		 * context for the conversation. Making it genuinely team-specific
		 * needs per-team role attributes that do not exist yet.
		 */

		$claimed    = count( $likely );
		$dimensions = count( array_unique( array_column( $reasons, 'dimension' ) ) );
		$strength   = self::strength( count( $gift_overlap ), count( $reasons ), $claimed, $dimensions );

		return array(
			'team_id'          => (int) $team->id,
			'team_name'        => $team->name,
			'strength'         => $strength,
			'strength_label'   => self::strength_labels()[ $strength ],
			'reasons'          => $reasons,
			'dimensions_hit'   => $dimensions,
			'gift_overlap'     => count( $gift_overlap ),
			'gifts_claimed'    => $claimed,
			// How much of what this person claims is actually relevant here.
			// Surfaced so a leader can see the reasoning, not just its verdict.
			'selectivity'      => $claimed > 0 ? round( count( $gift_overlap ) / $claimed, 2 ) : 0.0,
			'undifferentiated' => self::is_undifferentiated( $claimed ),
			'caveat'           => self::is_undifferentiated( $claimed )
				? self::undifferentiated_note( $claimed )
				: '',
			'has_opening'      => self::has_opening( $team ),
			'opening_note'     => self::opening_note( $team ),
			'profile_redacted' => ! empty( $profile['_redacted'] ),
			/*
			 * Whether the assessment itself named this team, i.e. whether the
			 * person has seen it on their own profile. Only `rank()` knows, so
			 * it overwrites this; the default is here so every caller of
			 * `explain()` gets the key rather than an undefined index.
			 */
			'from_assessment'  => false,
		);
	}

	/**
	 * How many teams a profile is worth suggesting.
	 *
	 * Five rather than the assessment's three: the point of ranking every team
	 * is that better fits exist outside the three it picked, and a list capped
	 * at three would mostly just reshuffle them.
	 */
	public static function suggestion_limit(): int {
		/**
		 * Filters how many suggested teams a profile gets.
		 *
		 * @param int $limit Default 5.
		 */
		return max( 1, (int) apply_filters( 'serve_dashboard_suggestion_limit', 5 ) );
	}

	/**
	 * Better-evidenced suggestion first.
	 *
	 * Distinct SHAPE dimensions, then how many readable reasons there are, then
	 * how selective the gift overlap is. Team need is deliberately absent: a
	 * vacancy is not evidence that a person fits.
	 *
	 * Reasons used to come last and selectivity second, which quietly undid the
	 * point of ranking every team. Selectivity is a gifts-only measure —
	 * overlapping gifts divided by gifts claimed — so somebody who marked one
	 * gift scored a perfect 1.0 against all five teams sharing it, and those
	 * five filled the list ahead of a team with five separate reasons drawn from
	 * their abilities. Gifts would have gone on deciding the suggestions while
	 * appearing not to. It stays as the final tie-break, where it settles teams
	 * whose evidence is otherwise equal.
	 *
	 * This does not touch `strength()`. Reaching "Strong match" still needs two
	 * overlapping gifts and a second dimension corroborating them: ordering is
	 * about which conversation to have first, and a strength label is a claim
	 * about the evidence. Non-gift evidence alone can lead the list, and tops
	 * out at "Possible match" while doing so.
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 */
	private static function compare( array $a, array $b ): int {
		if ( $a['dimensions_hit'] !== $b['dimensions_hit'] ) {
			return $b['dimensions_hit'] <=> $a['dimensions_hit'];
		}

		if ( count( $a['reasons'] ) !== count( $b['reasons'] ) ) {
			return count( $b['reasons'] ) <=> count( $a['reasons'] );
		}

		return $b['selectivity'] <=> $a['selectivity'];
	}

	/**
	 * The teams worth suggesting for one profile, best-evidenced first.
	 *
	 * Every active team is considered. This is the change: the suggestions used
	 * to be whichever three the assessment's own `recommendMinistries()` picked
	 * in the browser, which ranks on spiritual-gift name overlap and nothing
	 * else. `explain()` then decorated that already-narrowed set with heart,
	 * abilities and experience — so those dimensions could improve the *order*
	 * of three teams chosen on gifts alone, but could never put forward a team
	 * the gift ranking had missed. Somebody whose real fit was a passion or a
	 * past job simply never saw that team unless a leader happened to come at
	 * it from the team side.
	 *
	 * What the assessment suggested is still carried, always, and flagged: it
	 * is what the person was told at the end of their journey and what their
	 * downloaded profile says, so a leader has to be able to see it even when
	 * the evidence now ranks it low. Nothing here rewrites `suggested_teams` —
	 * that column is the record of what the person saw, and the placement rows
	 * built from it are what scope a ministry leader's visibility. Widening
	 * suggestions must not widen who can see whom.
	 *
	 * A team that has since been deactivated drops out even if the assessment
	 * named it. Suggesting a team the church has closed is worse than a gap in
	 * the history.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( object $submission, array $profile, ?int $limit = null ): array {
		$limit    = $limit ?? self::suggestion_limit();
		$from_assessment = array_map( 'strval', Submissions::decode_list( $submission->suggested_teams ) );

		$ranked = array();
		foreach ( Teams::all() as $team ) {
			$match                    = self::explain( $submission, $team, $profile );
			$match['from_assessment'] = in_array( (string) $team->slug, $from_assessment, true );

			$ranked[] = $match;
		}

		usort( $ranked, array( self::class, 'compare' ) );

		/*
		 * Take the best-evidenced up to the limit, and keep every team the
		 * assessment named whether it made the cut or not. Appending from an
		 * already-sorted list means the result stays in evidence order, and a
		 * named team with no evidence at all sorts to the bottom on its own.
		 */
		$out   = array();
		$taken = 0;

		foreach ( $ranked as $match ) {
			if ( $match['reasons'] && $taken < $limit ) {
				$out[] = $match;
				++$taken;
				continue;
			}

			if ( $match['from_assessment'] ) {
				$out[] = $match;
			}
		}

		return $out;
	}

	/**
	 * Every suggested team for a submission, best-explained first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_submission( object $submission, array $profile ): array {
		return self::rank( $submission, $profile );
	}

	/**
	 * What a person's temperament suggests about how they would serve.
	 *
	 * The assessment has collected the four personality couplets since the
	 * beginning and has shown them on the profile, but nothing has ever
	 * interpreted them — while the presentation lists personality among the
	 * five things matching considers. This closes that gap in the only
	 * direction the data honestly supports.
	 *
	 * These are notes about the person, not about any one team, and that is
	 * the workbook's own position: personality affects the manner in which a
	 * gift is exercised, and serving against the grain of it "creates tension
	 * and discomfort, requires extra effort and energy, and produces less than
	 * the best results". Useful for shaping a role. Useless for choosing
	 * between two teams, and never a reason to rule somebody out — the
	 * workbook is equally clear that there is no right or wrong temperament
	 * and that the church needs opposites.
	 *
	 * Returned once per person rather than per suggestion, because repeating
	 * an identical block under every team would imply it discriminated between
	 * them.
	 *
	 * @param array<string,mixed> $profile Decoded profile.
	 * @return array<int,array<string,string>> Tendency and what it suggests.
	 */
	public static function personality_notes( array $profile ): array {
		$out = array();

		foreach ( (array) ( $profile['personality'] ?? array() ) as $tendency ) {
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
	 *
	 * Every note is phrased as something to explore. None of them says a
	 * person is unsuited to anything.
	 */
	private static function personality_note( string $label ): string {
		$notes = array(
			'extroverted'  => __( 'Gains energy from people and variety, so a role with plenty of contact is likely to suit.', 'serve-dashboard' ),
			'introverted'  => __( 'Gains energy from quiet reflection and listens well. Depth with a few people may suit better than a busy front-of-house role.', 'serve-dashboard' ),
			'expressive'   => __( 'Open and verbal about their thoughts, so a role where speaking up is expected should feel natural.', 'serve-dashboard' ),
			'controlled'   => __( 'Tends to keep their thoughts to themselves. Worth asking directly what they would like rather than waiting for them to offer it.', 'serve-dashboard' ),
			'routine'      => __( 'More comfortable where expectations are clear, and likes finishing one thing before starting the next.', 'serve-dashboard' ),
			'variety'      => __( 'More fulfilled by work that changes, and does not need one task closed before the next begins.', 'serve-dashboard' ),
			'cooperative'  => __( 'Sees other points of view easily and likes being part of a team effort.', 'serve-dashboard' ),
			'competitive'  => __( 'Motivated by a challenge, which raises their effort when there are obstacles to get past.', 'serve-dashboard' ),
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
	 * Qualitative strength, normalised against how much the person claimed.
	 *
	 * Three matching gifts out of four claimed is strong evidence. The same
	 * three out of eighteen is noise. Raw overlap cannot tell those apart,
	 * which is why selectivity is part of the test.
	 *
	 * "Strong" additionally requires corroboration from a second SHAPE
	 * dimension, so gifts alone can never reach it.
	 *
	 * @param int $gift_hits    Gifts shared with this team.
	 * @param int $reason_count Total reasons found.
	 * @param int $claimed      How many gifts the person marked likely.
	 * @param int $dimensions   Distinct SHAPE dimensions supporting the match.
	 */
	private static function strength( int $gift_hits, int $reason_count, int $claimed, int $dimensions ): string {
		// Undifferentiated answers can never produce a strong match. The
		// honest reading is "we cannot tell from this — go and talk".
		if ( self::is_undifferentiated( $claimed ) ) {
			return $gift_hits > 0 ? self::STRENGTH_POSSIBLE : self::STRENGTH_UNCLEAR;
		}

		$selectivity = $claimed > 0 ? $gift_hits / $claimed : 0.0;

		if ( $gift_hits >= 2
			&& $dimensions >= 2
			&& $reason_count >= 3
			&& $selectivity >= self::MIN_SELECTIVITY ) {
			return self::STRENGTH_STRONG;
		}

		if ( $gift_hits >= 1 || $reason_count >= 2 ) {
			return self::STRENGTH_POSSIBLE;
		}

		return self::STRENGTH_UNCLEAR;
	}

	public static function is_undifferentiated( int $claimed_gifts ): bool {
		return $claimed_gifts >= self::UNDIFFERENTIATED_AT;
	}

	/**
	 * Note shown when someone's gift answers do not discriminate.
	 *
	 * Deliberately phrased as something to explore rather than as a fault:
	 * a person new to the language of spiritual gifts may genuinely not know
	 * yet, and that is a conversation, not a data problem.
	 */
	public static function undifferentiated_note( int $claimed_gifts ): string {
		return sprintf(
			/* translators: %d: number of gifts marked likely. */
			__( 'This person marked %d of the 18 gifts as likely, so their answers do not point clearly to one team. Worth exploring together rather than relying on a suggestion.', 'serve-dashboard' ),
			$claimed_gifts
		);
	}

	/**
	 * Everything a team's work can be recognised by.
	 *
	 * The team name alone was not enough and was the reason heart, abilities
	 * and experience almost never produced a reason: a passion for "Elementary
	 * Children" shares no word with "Fellowship Kids", so the person looked
	 * like a gifts-only match for a team they were plainly suited to. Teams now
	 * carry a seeded vocabulary as well, editable on the Teams screen.
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
	 * Crude on purpose: it only ever produces a reason a human can read and
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
			$term = trim( (string) $term );
			if ( '' === $term ) {
				continue;
			}

			$patterns[] = '/\b' . preg_quote( $term, '/' ) . '\b/i';
		}

		$hits = array();
		foreach ( $values as $value ) {
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
