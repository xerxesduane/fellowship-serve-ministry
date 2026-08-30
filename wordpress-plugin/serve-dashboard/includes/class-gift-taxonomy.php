<?php
/**
 * The eighteen assessed spiritual gifts, as stable identifiers.
 *
 * The assessment has always had these ids — they are what `shapeContent.js`
 * keys its questions on — but nothing on the server knew them. Profiles arrive
 * carrying display labels, matching compared those labels against a second
 * vocabulary written for the ministry table, and the two only agreed by
 * accident. Twelve of the ministry table's twenty-two terms happened to be spelt
 * the same as an assessed gift; the other ten could never match anything, and
 * six assessed gifts were invisible to every ministry in the church.
 *
 * So this is the normalisation boundary. Labels are display text and stop here.
 * Everything past it — the crosswalk, scoring, the tier contract, the stored
 * snapshot — speaks in ids.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gift_Taxonomy {

	/**
	 * Bumped when the set of assessed gifts changes.
	 *
	 * Stored alongside matching output so an old result can still be explained
	 * after the instrument moves. It is not the crosswalk's version: the gifts
	 * and the ministry mapping change for different reasons and on different
	 * approvals, so they version separately.
	 */
	public const VERSION = '1.0.0';

	/**
	 * The eighteen gifts, in the order the assessment asks them.
	 *
	 * `alternate` mirrors `alternateName` in shapeContent.js and is part of the
	 * instrument itself — the page a participant reads prints "Administration"
	 * with "Organization" beside it. That makes an alternate name a safe
	 * normalisation in a way a product alias is not: the church did not decide
	 * it, the assessment states it.
	 *
	 * @return array<string,array{label:string,alternate:string}>
	 */
	public static function gifts(): array {
		return array(
			'administration'         => array( 'label' => 'Administration', 'alternate' => 'Organization' ),
			'apostle'                => array( 'label' => 'Apostle', 'alternate' => '' ),
			'discernment'            => array( 'label' => 'Discernment', 'alternate' => '' ),
			'encouragement'          => array( 'label' => 'Encouragement', 'alternate' => 'Exhortation' ),
			'evangelism'             => array( 'label' => 'Evangelism', 'alternate' => '' ),
			'faith'                  => array( 'label' => 'Faith', 'alternate' => '' ),
			'giving'                 => array( 'label' => 'Giving', 'alternate' => '' ),
			'healing'                => array( 'label' => 'Healing', 'alternate' => '' ),
			'hospitality'            => array( 'label' => 'Hospitality', 'alternate' => '' ),
			'leadership'             => array( 'label' => 'Leadership', 'alternate' => '' ),
			'mercy'                  => array( 'label' => 'Mercy', 'alternate' => '' ),
			'miracles'               => array( 'label' => 'Miracles', 'alternate' => '' ),
			'pastoring'              => array( 'label' => 'Pastoring', 'alternate' => 'Shepherding' ),
			'praying-with-my-spirit' => array( 'label' => 'Praying With My Spirit', 'alternate' => 'Tongues/Interpretation' ),
			'preaching'              => array( 'label' => 'Preaching', 'alternate' => 'Prophecy' ),
			'service'                => array( 'label' => 'Service', 'alternate' => '' ),
			'teaching'               => array( 'label' => 'Teaching', 'alternate' => '' ),
			'wisdom'                 => array( 'label' => 'Wisdom', 'alternate' => '' ),
		);
	}

	/** @return string[] */
	public static function ids(): array {
		return array_keys( self::gifts() );
	}

	public static function is_gift( string $id ): bool {
		return array_key_exists( $id, self::gifts() );
	}

	/** Display text for one id. Falls back to the id so nothing renders blank. */
	public static function label( string $id ): string {
		$gifts = self::gifts();

		return $gifts[ $id ]['label'] ?? $id;
	}

	/**
	 * @param string[] $ids
	 * @return string[]
	 */
	public static function labels( array $ids ): array {
		return array_map( array( self::class, 'label' ), $ids );
	}

	/**
	 * Resolve a stored display label back to its id.
	 *
	 * Profiles written before this class existed hold labels, and there are
	 * years of them. Accepted only on an exact, unique match against a label or
	 * an assessment alternate name, case- and space-insensitively — never a
	 * prefix, a stem or a nearest guess. An unrecognised string returns '' and
	 * the caller reports it rather than dropping it.
	 *
	 * Ambiguity is impossible by construction here — no two gifts share a label
	 * or an alternate — but the lookup is built as a collision-checked map
	 * rather than a first-match loop so that adding one later fails loudly
	 * instead of silently preferring whichever came first.
	 */
	public static function id_for_label( string $label ): string {
		static $index = null;

		if ( null === $index ) {
			$index = array();

			foreach ( self::gifts() as $id => $gift ) {
				foreach ( array( $id, $gift['label'], $gift['alternate'] ) as $name ) {
					$key = self::normalise( (string) $name );

					if ( '' === $key ) {
						continue;
					}

					// Two gifts claiming one name is a taxonomy bug, not an
					// input problem. Neither wins.
					if ( isset( $index[ $key ] ) && $index[ $key ] !== $id ) {
						$index[ $key ] = '';
						continue;
					}

					$index[ $key ] = $id;
				}
			}
		}

		return $index[ self::normalise( $label ) ] ?? '';
	}

	/**
	 * Fold a name to its comparison key.
	 *
	 * Case and surrounding whitespace only. Deliberately not a slug: collapsing
	 * punctuation would make "Tongues/Interpretation" and "Tongues Interpretation"
	 * the same string, which is the sort of helpfulness that turns into an
	 * invented equivalence the first time two gifts differ only by a hyphen.
	 */
	private static function normalise( string $name ): string {
		return strtolower( trim( $name ) );
	}
}
