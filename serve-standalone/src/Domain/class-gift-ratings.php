<?php
/**
 * A participant's eighteen gift answers, canonicalised and checked.
 *
 * The normalisation boundary in one place. A profile arrives holding whatever
 * the browser wrote — canonical ids on anything built since this release,
 * display labels on everything stored before it — and comes out of here as
 * three disjoint sets of gift ids plus an honest account of whether they add up.
 *
 * The validity flag is the point. Matching used to read
 * `spiritualGifts.likely` and take its length as "gifts claimed", so a profile
 * missing half its answers produced a small denominator, a high selectivity,
 * and a confident-looking result built on absence. Now a partition that does
 * not cover all eighteen exactly once is *known* not to, and the contract caps
 * it at Explore.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Gift_Ratings {

	public const LIKELY   = 'likely';
	public const POSSIBLE = 'possible';
	public const UNLIKELY = 'unlikely';

	/** @var array<string,string[]> */
	private array $buckets;

	/** @var string[] Values nothing recognised. */
	private array $unrecognised;

	/** @var string[] Gifts that arrived in more than one bucket. */
	private array $conflicts;

	private bool $valid;

	/**
	 * @param array<string,string[]> $buckets
	 * @param string[]               $unrecognised
	 * @param string[]               $conflicts
	 */
	private function __construct( array $buckets, array $unrecognised, array $conflicts, bool $valid ) {
		$this->buckets      = $buckets;
		$this->unrecognised = $unrecognised;
		$this->conflicts    = $conflicts;
		$this->valid        = $valid;
	}

	/**
	 * Read the gift section of a profile.
	 *
	 * Accepts canonical ids and, for stored profiles written before ids were
	 * carried, exact display labels or assessment alternate names. Nothing
	 * else: no stemming, no prefix matching, no nearest guess. An unreadable
	 * value is collected and reported, never dropped and never rounded to a
	 * gift it resembles.
	 *
	 * @param array<string,mixed> $profile
	 */
	public static function from_profile( array $profile ): self {
		$raw = $profile['spiritualGifts'] ?? array();
		$raw = is_array( $raw ) ? $raw : array();

		$buckets      = array( self::LIKELY => array(), self::POSSIBLE => array(), self::UNLIKELY => array() );
		$unrecognised = array();
		$placed       = array();
		$conflicts    = array();

		foreach ( array_keys( $buckets ) as $bucket ) {
			foreach ( (array) ( $raw[ $bucket ] ?? array() ) as $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$text = trim( (string) $value );
				if ( '' === $text ) {
					continue;
				}

				$id = Gift_Taxonomy::is_gift( $text ) ? $text : Gift_Taxonomy::id_for_label( $text );

				if ( '' === $id ) {
					$unrecognised[] = $text;
					continue;
				}

				/*
				 * The same gift in two buckets is an error, not a race to be
				 * settled by whichever was read last. It means either a broken
				 * client or a label and an id for one gift arriving separately,
				 * and both make the whole partition untrustworthy.
				 */
				if ( isset( $placed[ $id ] ) ) {
					if ( $placed[ $id ] !== $bucket ) {
						$conflicts[] = $id;
					}
					continue;
				}

				$placed[ $id ]     = $bucket;
				$buckets[ $bucket ][] = $id;
			}
		}

		/*
		 * Valid means: all eighteen present, each exactly once, nothing
		 * unrecognised, no gift in two buckets. Anything less and we do not
		 * know what the person did not say.
		 */
		$valid = ! $unrecognised
			&& ! $conflicts
			&& count( $placed ) === count( Gift_Taxonomy::ids() )
			&& ! array_diff( Gift_Taxonomy::ids(), array_keys( $placed ) );

		return new self(
			$buckets,
			array_values( array_unique( $unrecognised ) ),
			array_values( array_unique( $conflicts ) ),
			$valid
		);
	}

	/** @return string[] */
	public function likely(): array {
		return $this->buckets[ self::LIKELY ];
	}

	/** @return string[] */
	public function possible(): array {
		return $this->buckets[ self::POSSIBLE ];
	}

	/** @return string[] */
	public function unlikely(): array {
		return $this->buckets[ self::UNLIKELY ];
	}

	/**
	 * How many gifts the person marked Likely in total.
	 *
	 * Including ones no ministry maps, which is what keeps selectivity honest:
	 * the denominator must not shrink to the part of somebody the table happens
	 * to be able to read.
	 */
	public function all_likely_count(): int {
		return count( $this->buckets[ self::LIKELY ] );
	}

	public function is_valid(): bool {
		return $this->valid;
	}

	/** @return string[] */
	public function unrecognised(): array {
		return $this->unrecognised;
	}

	/** @return string[] */
	public function conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Likely gifts no active ministry can be scored on.
	 *
	 * Shown to the participant as a fact about the table — "Pastoring is one of
	 * your likely gifts, and the ministry list does not map it yet" — rather
	 * than resolved to the nearest-looking concept.
	 *
	 * @return string[]
	 */
	public function unmapped_likely(): array {
		return array_values( array_intersect( $this->likely(), Gift_Crosswalk::unmapped_gifts() ) );
	}

	/**
	 * Why this partition is not usable, in one readable line, or ''.
	 */
	public function invalid_reason(): string {
		if ( $this->valid ) {
			return '';
		}

		if ( $this->conflicts ) {
			return sprintf(
				/* translators: %s: comma-separated gift names. */
				__( 'These gifts arrived with more than one answer, so the responses could not be read: %s.', 'serve-dashboard' ),
				implode( ', ', Gift_Taxonomy::labels( $this->conflicts ) )
			);
		}

		if ( $this->unrecognised ) {
			return sprintf(
				/* translators: %s: comma-separated unrecognised values. */
				__( 'These answers did not match any of the 18 gifts: %s.', 'serve-dashboard' ),
				implode( ', ', $this->unrecognised )
			);
		}

		$answered = count( $this->likely() ) + count( $this->possible() ) + count( $this->unlikely() );

		return sprintf(
			/* translators: 1: gifts answered, 2: total gifts. */
			__( 'Only %1$d of the %2$d gifts were answered, so this profile cannot produce a strong or suggested match.', 'serve-dashboard' ),
			$answered,
			count( Gift_Taxonomy::ids() )
		);
	}
}
