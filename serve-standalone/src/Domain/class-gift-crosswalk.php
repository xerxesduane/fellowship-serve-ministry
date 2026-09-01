<?php
/**
 * The approved bridge between the ministry table's words and the assessed gifts.
 *
 * The Ministry–Gift Table and the assessment were written by different people
 * for different purposes and share only part of a vocabulary. Twelve of the
 * table's twenty-two terms are spelt exactly like an assessed gift. The other
 * ten are not, and comparing display strings meant those ten matched nothing
 * ever: Production and the Livestream Team could each be recognised by exactly
 * one of their listed gifts, the Administration ministry could not see the gift
 * of administration, and the Prayer ministry could not see prayer.
 *
 * Every entry below is a ministry-owner decision, not a programming one. The
 * rules the church set for this file:
 *
 * 1. Exact identity and an alternate name printed in the assessment are safe.
 * 2. A legacy alias is evidence of past behaviour, not approval.
 * 3. Never fuzzy-match, stem, substring or reach for a thesaurus.
 * 4. Never silently discard an unknown term — surface it.
 * 5. Resolve each ministry to a set of unique ids; two terms landing on one id
 *    count that participant's gift once.
 *
 * `docs/ministry-gift-crosswalk.md` records who approved what and why the
 * rejected relationships were rejected. Change one here and change it there.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Gift_Crosswalk {

	/**
	 * Bumped whenever an entry is added, removed or repointed.
	 *
	 * Stored with matching output, so a result produced under an older mapping
	 * can still be explained rather than silently re-interpreted under today's.
	 */
	public const VERSION = '2.0.0';

	/**
	 * Ministry-table term (lowercased) => canonical assessed gift id.
	 *
	 * Only the terms that are *not* already spelt like a gift id appear here;
	 * exact identity is handled by the taxonomy itself and does not need an
	 * entry. Keeping this list to the genuinely bridged terms is what makes it
	 * short enough to be read by the people who have to approve it.
	 *
	 * @return array<string,string>
	 */
	private static function approved(): array {
		return array(
			/*
			 * "Organization" is deliberately absent.
			 *
			 * It is the assessment's own alternate name for Administration —
			 * printed beside it on the page the participant reads — so
			 * Gift_Taxonomy::id_for_label() resolves it before this map is ever
			 * consulted. Rule 1: the instrument states the equivalence and the
			 * church did not invent it. Listing it here as well would be a
			 * second, church-owned copy of a decision the assessment already
			 * makes, free to disagree with it, and it would put the entry that
			 * matters most behind an approval it does not need.
			 *
			 * A mutation test found this: deleting the duplicate entry changed
			 * no behaviour and failed no test, which is what dead code does.
			 * The taxonomy test asserts the alternate name directly instead.
			 *
			 * That single resolution is what stops Production and the
			 * Livestream Team being unrecommendable at any tier.
			 */

			/*
			 * Approved 2026-08-30. The gift of service is defined as taking
			 * "the initiative to provide practical assistance"; the table's
			 * Assisting names the same work. Present in the legacy browser
			 * alias table, and re-approved here on the definition rather than
			 * on the precedent.
			 */
			'assisting'    => 'service',

			/*
			 * Approved 2026-08-30. Pastoring is "the ability to nurture a small
			 * group in spiritual growth and assume responsibility for their
			 * welfare", which is what the GROW and Youth teams mean by
			 * Mentoring. This is the entry that makes the pastoring gift
			 * visible to any ministry at all.
			 */
			'mentoring'    => 'pastoring',

			/*
			 * Approved 2026-08-30. Apostle is "the ability to start new
			 * churches/ventures and oversee their development" — the sending
			 * sense the Young Adults row means by Mission.
			 */
			'mission'      => 'apostle',

			/*
			 * Approved 2026-08-30, and pointed at leadership rather than at
			 * apostle, which is where the legacy browser table sent it.
			 *
			 * The assessment defines leadership as "the ability to clarify and
			 * communicate the purpose and direction ('vision') of a ministry" —
			 * it uses the word itself. Apostle is the narrower church-planting
			 * claim, and four of the five teams listing Vision mean the former.
			 *
			 * Consequence worth knowing: four of those five teams already list
			 * Leadership, so under the unique-id rule this adds nothing there.
			 * It matters for the Livestream Team, which lists Vision and not
			 * Leadership, and which this lifts from Suggested-only to
			 * Strong-capable.
			 */
			'vision'       => 'leadership',
		);
	}

	/**
	 * Relationships considered and deliberately not approved.
	 *
	 * Recorded because "we never thought about it" and "we thought about it and
	 * said no" are different states, and only one of them should be quiet. The
	 * admin audit screen reads this so a reviewer can see the reasoning without
	 * opening a PHP file.
	 *
	 * @return array<int,array{term:string,proposed:string,reason:string}>
	 */
	public static function rejected(): array {
		return array(
			array(
				'term'     => 'Prayer',
				'proposed' => 'praying-with-my-spirit',
				'reason'   => __( 'That gift\'s alternate name in the assessment is "Tongues/Interpretation" and it is defined as praying in a language understood only by God. Treating it as the Prayer ministry\'s "Prayer" would make the gift of tongues a requirement for matching an intercession team. It also changes no team\'s reachable tier: Prayer already scores on faith, discernment, healing, mercy and wisdom.', 'serve-dashboard' ),
			),
			array(
				'term'     => 'Leadership',
				'proposed' => 'apostle, pastoring',
				'reason'   => __( 'The legacy browser table let apostle and pastoring both satisfy a ministry\'s Leadership entry. Leadership already has an exact assessed counterpart, so this would let three different gifts satisfy one term and quietly inflate every team that lists it.', 'serve-dashboard' ),
			),
			array(
				'term'     => 'Teaching, Evangelism',
				'proposed' => 'preaching',
				'reason'   => __( 'The legacy table treated the preaching/prophecy gift as satisfying Teaching and Evangelism. Both already have exact counterparts, and equating them is a theological claim rather than a normalisation. Preaching stays visible and unmapped, and a participant who has it is told so.', 'serve-dashboard' ),
			),
		);
	}

	/**
	 * Ministry-table terms nobody has bridged, and that scoring must not use.
	 *
	 * Unknown coverage, never "unlikely". A ministry recognised partly by
	 * Creativity is not thereby a ministry whose creative people score badly —
	 * it is a ministry the instrument does not measure that part of. The guide
	 * still shows these terms to participants and leaders; they simply carry no
	 * weight, and configuration validation names them so nobody assumes they do.
	 *
	 * @return string[]
	 */
	public static function unmapped_terms(): array {
		return array( 'Crafting', 'Creativity', 'Justice', 'Knowledge', 'Prayer' );
	}

	/**
	 * Assessed gifts no ministry row can currently be matched on.
	 *
	 * Reported to the participant as a fact about the table rather than hidden:
	 * somebody whose strongest gift is pastoring should be told the church's
	 * ministry list does not map it yet, not quietly handed the nearest-looking
	 * team.
	 *
	 * Derived rather than typed, so it cannot drift from the mapping. A gift
	 * appears here when no active team resolves to it.
	 *
	 * @return string[]
	 */
	public static function unmapped_gifts(): array {
		$covered = array();

		foreach ( Teams::all() as $team ) {
			foreach ( self::resolve( Teams::gift_list( $team ) )['ids'] as $id ) {
				$covered[ $id ] = true;
			}
		}

		return array_values( array_diff( Gift_Taxonomy::ids(), array_keys( $covered ) ) );
	}

	/**
	 * Turn one ministry's listed terms into unique canonical gift ids.
	 *
	 * Returns the unrecognised terms as well as the resolved ids, because the
	 * whole failure mode this replaces was a term that matched nothing and said
	 * nothing about it. Every caller either scores with `ids` or reports
	 * `unmapped`; none of them may drop both.
	 *
	 * Duplicates collapse. Serve lists both Service and Assisting, which resolve
	 * to the same gift, and a participant who has it must be counted once —
	 * otherwise a team's coverage denominator rewards it for saying the same
	 * thing twice.
	 *
	 * @param string[] $terms Raw terms from a team's gift column.
	 * @return array{ids:string[],unmapped:string[],duplicates:string[]}
	 */
	public static function resolve( array $terms ): array {
		$approved   = self::approved();
		$ids        = array();
		$unmapped   = array();
		$duplicates = array();

		foreach ( $terms as $term ) {
			if ( ! is_scalar( $term ) ) {
				continue;
			}

			$raw = trim( (string) $term );
			if ( '' === $raw ) {
				continue;
			}

			$key = strtolower( $raw );

			// Exact identity and assessment alternate names first, then the
			// approved bridge. Nothing else is consulted, ever.
			$id = Gift_Taxonomy::id_for_label( $raw );

			if ( '' === $id && isset( $approved[ $key ] ) ) {
				$id = $approved[ $key ];
			}

			if ( '' === $id ) {
				$unmapped[] = $raw;
				continue;
			}

			if ( isset( $ids[ $id ] ) ) {
				$duplicates[] = $raw;
				continue;
			}

			$ids[ $id ] = true;
		}

		return array(
			'ids'        => array_keys( $ids ),
			'unmapped'   => array_values( array_unique( $unmapped ) ),
			'duplicates' => array_values( array_unique( $duplicates ) ),
		);
	}

	/**
	 * Check the whole configuration and report what is wrong with it.
	 *
	 * Read by the admin audit screen and by the tests. Nothing here throws or
	 * repairs anything: a church that has edited its teams into a state this
	 * dislikes should be told, not overruled.
	 *
	 * @return array<int,array{level:string,team:string,message:string}>
	 */
	public static function validate(): array {
		$problems = array();
		$seen     = array();

		foreach ( Teams::all( false ) as $team ) {
			$name     = (string) $team->name;
			$slug     = (string) $team->slug;
			$resolved = self::resolve( Teams::gift_list( $team ) );

			if ( isset( $seen[ $slug ] ) ) {
				$problems[] = array(
					'level'   => 'error',
					'team'    => $name,
					'message' => __( 'Two teams share this slug, so a stored suggestion cannot say which one it meant.', 'serve-dashboard' ),
				);
			}
			$seen[ $slug ] = true;

			if ( empty( $team->is_active ) ) {
				// Not a fault. Reported so a reviewer reading this list is not
				// puzzled by a team that never appears in any result.
				$problems[] = array(
					'level'   => 'info',
					'team'    => $name,
					'message' => __( 'Deactivated, so it is never recommended or assigned.', 'serve-dashboard' ),
				);
				continue;
			}

			foreach ( $resolved['unmapped'] as $term ) {
				$problems[] = array(
					'level'   => 'warning',
					'team'    => $name,
					/* translators: %s: a ministry-table term. */
					'message' => sprintf( __( '"%s" is not mapped to an assessed gift, so it is shown but never scored. Unknown coverage, not evidence against anyone.', 'serve-dashboard' ), $term ),
				);
			}

			foreach ( $resolved['duplicates'] as $term ) {
				$problems[] = array(
					'level'   => 'info',
					'team'    => $name,
					/* translators: %s: a ministry-table term. */
					'message' => sprintf( __( '"%s" resolves to a gift this team already lists, so it is counted once.', 'serve-dashboard' ), $term ),
				);
			}

			$count = count( $resolved['ids'] );

			if ( 0 === $count ) {
				$problems[] = array(
					'level'   => 'error',
					'team'    => $name,
					'message' => __( 'No scorable gifts at all. This team can never be recommended automatically, and its coverage is reported as zero rather than dividing by it.', 'serve-dashboard' ),
				);
			} elseif ( $count < Matching_Contract::MIN_LIKELY_STRONG ) {
				$problems[] = array(
					'level'   => 'warning',
					'team'    => $name,
					'message' => sprintf(
						/* translators: 1: how many gifts resolve, 2: how many a strong match needs. */
						__( 'Only %1$d scorable gift(s); a strong gift match needs %2$d. This team can reach "Suggested to explore" at most.', 'serve-dashboard' ),
						$count,
						Matching_Contract::MIN_LIKELY_STRONG
					),
				);
			}
		}

		foreach ( self::unmapped_gifts() as $id ) {
			$problems[] = array(
				'level'   => 'warning',
				'team'    => '',
				'message' => sprintf(
					/* translators: %s: an assessed spiritual gift. */
					__( 'No ministry maps the gift of %s, so a participant whose strongest gift it is will be told the table does not measure it yet.', 'serve-dashboard' ),
					Gift_Taxonomy::label( $id )
				),
			);
		}

		return $problems;
	}
}
