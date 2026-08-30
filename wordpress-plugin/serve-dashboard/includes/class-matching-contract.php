<?php
/**
 * The launch matching rules, isolated so they can be argued with.
 *
 * These thresholds are provisional. They were chosen to be conservative on the
 * evidence the assessment actually collects — one required three-way answer per
 * gift, and nothing else that has been validated against a ministry — and they
 * are expected to move once there are real outcomes to calibrate against. They
 * are not a statement about how ministry works.
 *
 * Everything numeric about a tier decision lives here, in one file with a
 * version on it, so that "why did this person get a strong match in March" has
 * an answer after the numbers change in June.
 *
 * What the assessment gives us, and its limits:
 *
 *   likely   — primary positive evidence
 *   possible — secondary evidence; may explain and order, never qualify alone
 *   unlikely — absence of positive evidence, never a penalty
 *
 * There are no item scores, no weights and no confidence intervals underneath
 * this, so the tiers are qualitative and deliberately have no percentage.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Matching_Contract {

	/**
	 * Bumped whenever a threshold or a route changes.
	 *
	 * Stored with every snapshot. A participant's result keeps the version it
	 * was produced under, so the dashboard can say "the rules have changed
	 * since" rather than rewriting what they were told.
	 */
	public const VERSION = 'gift-v2';

	/* Tiers, strongest first. */
	public const TIER_STRONG    = 'strong';
	public const TIER_SUGGESTED = 'suggested';
	public const TIER_EXPLORE   = 'explore';
	public const TIER_NONE      = 'none';

	/**
	 * Marking this many of the eighteen gifts "likely" stops distinguishing
	 * between them.
	 *
	 * Someone who agrees with everything used to come out with several
	 * confident matches, because raw overlap rewards claiming more. The more
	 * somebody claims, the less any single overlap tells you.
	 */
	public const UNDIFFERENTIATED_AT = 12;

	/* Strong, route A: selective gift-only evidence. */
	public const MIN_LIKELY_STRONG      = 3;
	public const MIN_SELECTIVITY_STRONG = 0.50;
	public const MIN_COVERAGE_STRONG    = 0.50;

	/* Strong, route B: fewer gifts, but corroborated by a structured H/A/E dimension. */
	public const MIN_LIKELY_STRONG_CORROBORATED      = 2;
	public const MIN_SELECTIVITY_STRONG_CORROBORATED = 0.25;
	public const MIN_COVERAGE_STRONG_CORROBORATED    = 0.33;

	/* Suggested, route A: real but weaker gift evidence. */
	public const MIN_LIKELY_SUGGESTED      = 2;
	public const MIN_SELECTIVITY_SUGGESTED = 0.20;
	public const MIN_COVERAGE_SUGGESTED    = 0.25;

	/* Suggested, route B: one gift, corroborated. */
	public const MIN_LIKELY_SUGGESTED_CORROBORATED      = 1;
	public const MIN_SELECTIVITY_SUGGESTED_CORROBORATED = 0.15;
	public const MIN_COVERAGE_SUGGESTED_CORROBORATED    = 0.20;

	/** How many Strong/Suggested recommendations a person is shown. */
	public const MAX_RECOMMENDATIONS = 3;

	/** How many Explore options are offered when nothing clears Suggested. */
	public const MAX_EXPLORE = 2;

	/**
	 * @return array<string,string>
	 */
	public static function tier_labels(): array {
		return array(
			/*
			 * "Strong gift match", not "Strong S.H.A.P.E. match".
			 *
			 * The supplied table validates ministry-to-gift relationships and
			 * nothing else. Until there are owner-approved structured mappings
			 * for heart, abilities and experience, calling this a S.H.A.P.E.
			 * match would claim four dimensions of evidence the tier does not
			 * have.
			 */
			self::TIER_STRONG    => __( 'Strong gift match', 'serve-dashboard' ),
			self::TIER_SUGGESTED => __( 'Suggested team to explore', 'serve-dashboard' ),
			self::TIER_EXPLORE   => __( 'Explore with an advisor', 'serve-dashboard' ),
			self::TIER_NONE      => __( 'No clear match yet', 'serve-dashboard' ),
		);
	}

	public static function tier_label( string $tier ): string {
		return self::tier_labels()[ $tier ] ?? $tier;
	}

	/**
	 * Decide one team's tier from its evidence.
	 *
	 * Pure arithmetic over counts that were computed elsewhere, so it can be
	 * read against the written rules line by line.
	 *
	 * @param int  $likely_hits    Unique approved mapped Likely gifts on this team.
	 * @param int  $possible_hits  Unique approved mapped Possible gifts on this team.
	 * @param int  $all_likely     Every gift marked Likely, mapped or not.
	 * @param int  $scorable       Unique canonical gifts this team can be scored on.
	 * @param int  $corroborations Owner-approved structured H/A/E dimensions supporting this team.
	 * @param bool $valid          Whether the participant's gift partition validated.
	 */
	public static function tier(
		int $likely_hits,
		int $possible_hits,
		int $all_likely,
		int $scorable,
		int $corroborations,
		bool $valid
	): string {
		/*
		 * A team nothing can be scored against is not a weak match, it is an
		 * unanswerable question. Coverage would divide by zero, and the
		 * configuration screen reports it rather than the participant carrying
		 * the consequence.
		 */
		if ( $scorable <= 0 ) {
			return self::TIER_NONE;
		}

		$has_evidence = $likely_hits > 0 || $possible_hits > 0;

		if ( ! $has_evidence ) {
			return self::TIER_NONE;
		}

		/*
		 * An invalid or legacy-incomplete partition can still say "worth a
		 * conversation" — there is real evidence in it — but it can never say
		 * strong or suggested, because we cannot tell what was left out.
		 */
		if ( ! $valid ) {
			return self::TIER_EXPLORE;
		}

		// Agreeing with everything is not evidence for anything in particular.
		if ( self::is_undifferentiated( $all_likely ) ) {
			return self::TIER_EXPLORE;
		}

		$selectivity = self::selectivity( $likely_hits, $all_likely );
		$coverage    = self::coverage( $likely_hits, $scorable );

		if ( $likely_hits >= self::MIN_LIKELY_STRONG
			&& $selectivity >= self::MIN_SELECTIVITY_STRONG
			&& $coverage >= self::MIN_COVERAGE_STRONG ) {
			return self::TIER_STRONG;
		}

		if ( $corroborations >= 1
			&& $likely_hits >= self::MIN_LIKELY_STRONG_CORROBORATED
			&& $selectivity >= self::MIN_SELECTIVITY_STRONG_CORROBORATED
			&& $coverage >= self::MIN_COVERAGE_STRONG_CORROBORATED ) {
			return self::TIER_STRONG;
		}

		if ( $likely_hits >= self::MIN_LIKELY_SUGGESTED
			&& $selectivity >= self::MIN_SELECTIVITY_SUGGESTED
			&& $coverage >= self::MIN_COVERAGE_SUGGESTED ) {
			return self::TIER_SUGGESTED;
		}

		if ( $corroborations >= 1
			&& $likely_hits >= self::MIN_LIKELY_SUGGESTED_CORROBORATED
			&& $selectivity >= self::MIN_SELECTIVITY_SUGGESTED_CORROBORATED
			&& $coverage >= self::MIN_COVERAGE_SUGGESTED_CORROBORATED ) {
			return self::TIER_SUGGESTED;
		}

		return self::TIER_EXPLORE;
	}

	/**
	 * How much of what this person claims is relevant to this team.
	 *
	 * The denominator is every gift they marked Likely, including ones the
	 * ministry table does not measure. That is intentionally unkind to the
	 * software: the alternative would let the system look more certain about
	 * somebody the less of their profile it can actually read.
	 */
	public static function selectivity( int $likely_hits, int $all_likely ): float {
		if ( $all_likely <= 0 ) {
			return 0.0;
		}

		return min( 1.0, max( 0.0, $likely_hits / $all_likely ) );
	}

	/** How much of what this team is recognised by, this person has. */
	public static function coverage( int $hits, int $scorable ): float {
		if ( $scorable <= 0 ) {
			return 0.0;
		}

		return min( 1.0, max( 0.0, $hits / $scorable ) );
	}

	public static function is_undifferentiated( int $all_likely ): bool {
		return $all_likely >= self::UNDIFFERENTIATED_AT;
	}

	/** Rank order of a tier, for sorting. Lower is better. */
	public static function tier_rank( string $tier ): int {
		$order = array(
			self::TIER_STRONG    => 0,
			self::TIER_SUGGESTED => 1,
			self::TIER_EXPLORE   => 2,
			self::TIER_NONE      => 3,
		);

		return $order[ $tier ] ?? 4;
	}
}
