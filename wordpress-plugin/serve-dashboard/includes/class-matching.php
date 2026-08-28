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

		foreach ( self::term_overlap( $heart, $team->name ) as $term ) {
			$reasons[] = array(
				'dimension' => 'heart',
				'label'     => sprintf(
					/* translators: %s: the person's stated passion. */
					__( '%s is one of their stated passions', 'serve-dashboard' ),
					$term
				),
			);
		}

		// 3. Abilities, including languages. The assessment has always
		// collected these and nothing has ever used them.
		$abilities = (array) ( $profile['abilities'] ?? array() );
		$languages = Submissions::decode_list( $submission->languages );

		foreach ( self::term_overlap( $abilities, $team->name ) as $term ) {
			$reasons[] = array(
				'dimension' => 'abilities',
				'label'     => sprintf(
					/* translators: %s: a relevant ability. */
					__( 'Relevant ability: %s', 'serve-dashboard' ),
					$term
				),
			);
		}

		if ( count( $languages ) > 1 ) {
			$reasons[] = array(
				'dimension' => 'abilities',
				'label'     => sprintf(
					/* translators: %s: comma-separated language list. */
					__( 'Speaks %s', 'serve-dashboard' ),
					implode( ', ', $languages )
				),
			);
		}

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
		);
	}

	/**
	 * Every suggested team for a submission, best-explained first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_submission( object $submission, array $profile ): array {
		$out = array();

		foreach ( Submissions::decode_list( $submission->suggested_teams ) as $slug ) {
			$team = Teams::get_by_slug( (string) $slug );
			if ( ! $team ) {
				continue;
			}

			$out[] = self::explain( $submission, $team, $profile );
		}

		// Rank by how many distinct SHAPE dimensions support the suggestion,
		// then by total reasons. Never by how badly the team needs people:
		// a vacancy is not evidence that a person fits.
		usort(
			$out,
			static function ( array $a, array $b ) {
				// Better-evidenced first: distinct dimensions, then how selective
				// the gift overlap is, then sheer number of reasons as a
				// tie-break. Team need is deliberately absent — a vacancy is not
				// evidence that a person fits.
				if ( $a['dimensions_hit'] !== $b['dimensions_hit'] ) {
					return $b['dimensions_hit'] <=> $a['dimensions_hit'];
				}

				if ( $a['selectivity'] !== $b['selectivity'] ) {
					return $b['selectivity'] <=> $a['selectivity'];
				}

				return count( $b['reasons'] ) <=> count( $a['reasons'] );
			}
		);

		return $out;
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
	 * Words a person's own answers share with a team name.
	 *
	 * Crude on purpose: it only ever produces a reason a human can read and
	 * immediately agree or disagree with, so a false positive is visible
	 * rather than buried inside a score.
	 *
	 * @param array<int,mixed> $values
	 * @return string[]
	 */
	private static function term_overlap( array $values, string $team_name ): array {
		$stop  = array( 'team', 'the', 'and', 'grow', 'ministry', 'of', 'for' );
		$words = array_filter(
			preg_split( '/[^a-z]+/', strtolower( $team_name ) ) ?: array(),
			static fn( $word ) => strlen( $word ) > 3 && ! in_array( $word, $stop, true )
		);

		if ( ! $words ) {
			return array();
		}

		$hits = array();
		foreach ( $values as $value ) {
			$haystack = strtolower( (string) $value );
			foreach ( $words as $word ) {
				if ( str_contains( $haystack, $word ) ) {
					$hits[] = (string) $value;
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
