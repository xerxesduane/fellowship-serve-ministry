<?php
/**
 * Owner-approved structured Heart / Abilities / Experience mappings.
 *
 * Empty, deliberately, and that is the launch state.
 *
 * The supplied Ministry–Gift Table validates ministry-to-gift relationships and
 * nothing else. There is no approved mapping from a heart option, an ability or
 * a past job to a particular ministry, so nothing here may corroborate anything
 * yet — which means Strong route B and Suggested route B cannot fire, and only
 * gift evidence can produce a recommendation.
 *
 * That is the honest position, not an omission. Seeding plausible-looking
 * corroborators to make more teams qualify would be inventing the validation
 * this file exists to require.
 *
 * What this is *not*: the old keyword overlap. That matched a team's editable
 * vocabulary against a person's free text — including their Experiences, which
 * hold painful history — and the resulting reasons could carry a team to a
 * strength that created placement rows and opened the profile to that team's
 * leader. Free text will never appear here. Corroboration is by canonical
 * option id, chosen by ministry owners, versioned, and reviewed.
 *
 * When the church is ready to add some, the shape is:
 *
 *     'fellowship-kids' => array(
 *         'heart'     => array( 'people:elementary-children' ),
 *         'abilities' => array( 'teaching-ability' ),
 *     ),
 *
 * — canonical option ids from the assessment, never substrings, and at most one
 * corroboration per dimension per team however many ids match.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Corroboration {

	/**
	 * Bumped when a mapping is added, removed or repointed.
	 *
	 * `0.0.0` means "no approved mappings exist", which is a real version and
	 * is recorded on every snapshot produced under it.
	 */
	public const VERSION = '0.0.0';

	/** Dimensions that may ever corroborate. Personality is not one of them. */
	public const DIMENSIONS = array( 'heart', 'abilities', 'experiences' );

	/**
	 * team slug => dimension => canonical option ids.
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function registry(): array {
		/**
		 * Filters the approved corroboration registry.
		 *
		 * A church piloting structured mappings can supply them here without
		 * editing the plugin. Anything returned is still validated against the
		 * dimension list and still capped at one corroboration per dimension.
		 *
		 * @param array<string,array<string,string[]>> $registry Default empty.
		 */
		return (array) apply_filters( 'serve_dashboard_corroboration_registry', array() );
	}

	public static function is_empty(): bool {
		return array() === self::registry();
	}

	/**
	 * Which dimensions corroborate this team for this participant.
	 *
	 * Returns dimension names, not the matched ids, and never more than one
	 * entry per dimension — selecting six approved options in one dimension is
	 * one piece of corroboration, not six.
	 *
	 * Reads only `selectedOptionIds`, which the assessment fills with canonical
	 * ids. A legacy profile that has only labels contributes nothing and is not
	 * guessed at.
	 *
	 * @param array<string,mixed> $profile
	 * @return string[] Dimension names.
	 */
	public static function for_team( string $team_slug, array $profile ): array {
		$registry = self::registry();

		if ( empty( $registry[ $team_slug ] ) ) {
			return array();
		}

		$selected = $profile['selectedOptionIds'] ?? array();
		if ( ! is_array( $selected ) ) {
			return array();
		}

		$hits = array();

		foreach ( $registry[ $team_slug ] as $dimension => $option_ids ) {
			if ( ! in_array( $dimension, self::DIMENSIONS, true ) || ! is_array( $option_ids ) ) {
				continue;
			}

			$chosen = array_map( 'strval', (array) ( $selected[ $dimension ] ?? array() ) );

			foreach ( $option_ids as $option_id ) {
				if ( in_array( (string) $option_id, $chosen, true ) ) {
					$hits[] = $dimension;
					// One per dimension, whatever else matches below.
					break;
				}
			}
		}

		return array_values( array_unique( $hits ) );
	}
}
