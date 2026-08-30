<?php
/**
 * The tier rules, the ordering, and the promises that must not quietly break.
 *
 * Every profile here is built by Fixtures::profile(), which places all eighteen
 * gifts through the real taxonomy. Nothing in this file hand-authors a bucket,
 * because the bug these tests exist for was hidden behind a fixture the
 * assessment could never have produced.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Unit;

use Serve_Dashboard\Matching;
use Serve_Dashboard\Matching_Contract;
use Serve_Dashboard\Teams;

/** Convenience: the tier one profile gets for one named team. */
function tier_for( array $profile, string $team_name ): string {
	$match = Fixtures::find( Matching::rank_profile( $profile, PHP_INT_MAX ), $team_name );

	return $match['tier'] ?? Matching_Contract::TIER_NONE;
}

test(
	'an obvious Prayer profile is a strong gift match for Prayer',
	function ( Assert $a ) {
		// Prayer is recognised by faith, discernment, healing, mercy, wisdom.
		$profile = Fixtures::profile( array( 'faith', 'discernment', 'mercy' ) );

		$match = Fixtures::find( Matching::rank_profile( $profile, PHP_INT_MAX ), 'Prayer' );

		$a->same( Matching_Contract::TIER_STRONG, $match['tier'], 'Prayer is a strong gift match' );
		$a->same( 'Strong gift match', $match['tier_label'], 'and is labelled about gifts, not about S.H.A.P.E.' );
		$a->same( 3, $match['evidence']['likely_hit_count'], 'on three mapped likely gifts' );
		$a->same( 1.0, $match['evidence']['selectivity'], 'all of which are relevant here' );
		$a->same( 0.6, $match['evidence']['team_coverage'], 'covering three of five scorable gifts' );

		// The reasons name the actual gifts that contributed.
		$labels = implode( ' ', array_column( $match['reasons'], 'label' ) );
		foreach ( array( 'Faith', 'Discernment', 'Mercy' ) as $gift ) {
			$a->contains( $gift, $labels, "$gift is named as a contributing gift" );
		}
	}
);

test(
	'an obvious Serve profile is a strong gift match for Serve',
	function ( Assert $a ) {
		// Serve resolves to service, mercy, giving, hospitality, teaching.
		$profile = Fixtures::profile( array( 'service', 'giving', 'mercy' ) );

		$match = Fixtures::find( Matching::rank_profile( $profile, PHP_INT_MAX ), 'Serve' );

		$a->same( Matching_Contract::TIER_STRONG, $match['tier'], 'Serve is a strong gift match' );
		$a->same( 3, $match['evidence']['likely_hit_count'], 'on three mapped likely gifts' );
	}
);

test(
	'Administration is reachable now that Organization resolves',
	function ( Assert $a ) {
		$profile = Fixtures::profile( array( 'administration', 'leadership', 'wisdom' ) );

		$match = Fixtures::find( Matching::rank_profile( $profile, PHP_INT_MAX ), 'Administration' );

		$a->same( Matching_Contract::TIER_STRONG, $match['tier'], 'a strong gift match for Administration' );
		$a->contains( 'administration', $match['evidence']['likely_hits'], 'the gift of administration counted' );

		// Before the crosswalk this profile scored two hits here at best, and
		// the ministry could not see the gift named after it at all.
		$a->same( 3, $match['evidence']['likely_hit_count'], 'three gifts, not two' );
	}
);

test(
	'one likely gift is not a strong match',
	function ( Assert $a ) {
		$profile = Fixtures::profile( array( 'mercy' ) );

		foreach ( Matching::rank_profile( $profile, PHP_INT_MAX ) as $match ) {
			$a->not(
				Matching_Contract::TIER_STRONG === $match['tier'],
				"{$match['team_name']} is not strong on a single gift"
			);
		}
	}
);

test(
	'one possible gift is not a strong match, and cannot reach suggested alone',
	function ( Assert $a ) {
		$profile = Fixtures::profile( array(), array( 'mercy' ) );

		foreach ( Matching::rank_profile( $profile, PHP_INT_MAX ) as $match ) {
			$a->not( Matching_Contract::TIER_STRONG === $match['tier'], "{$match['team_name']} is not strong" );
			$a->not( Matching_Contract::TIER_SUGGESTED === $match['tier'], "{$match['team_name']} is not suggested" );
		}

		// It is still real evidence, so it explains an Explore result.
		$prayer = Fixtures::find( Matching::rank_profile( $profile, PHP_INT_MAX ), 'Prayer' );
		$a->same( Matching_Contract::TIER_EXPLORE, $prayer['tier'], 'it is worth exploring' );
	}
);

test(
	'possible gifts alone never satisfy a threshold, however many there are',
	function ( Assert $a ) {
		// Every gift Prayer is recognised by, all marked "may have".
		$profile = Fixtures::profile( array(), array( 'faith', 'discernment', 'healing', 'mercy', 'wisdom' ) );

		$match = Fixtures::find( Matching::rank_profile( $profile, PHP_INT_MAX ), 'Prayer' );

		$a->same( 5, $match['evidence']['possible_hit_count'], 'five possible gifts overlap' );
		$a->same( 0, $match['evidence']['likely_hit_count'], 'and none of them is likely' );
		$a->same( Matching_Contract::TIER_EXPLORE, $match['tier'], 'so the best it can be is Explore' );
	}
);

test(
	'marking twelve or more gifts likely produces nothing strong or suggested',
	function ( Assert $a ) {
		$twelve = array_slice( \Serve_Dashboard\Gift_Taxonomy::ids(), 0, 12 );
		$result = Matching::recommendations_for_profile( Fixtures::profile( $twelve ) );

		$a->same( 'explore', $result['state'], 'undifferentiated answers produce an explore state' );
		$a->contains( 'do not point clearly to one team', $result['caveat'], 'and say why' );

		foreach ( Matching::rank_profile( Fixtures::profile( $twelve ), PHP_INT_MAX ) as $match ) {
			$a->same( Matching_Contract::TIER_EXPLORE, $match['tier'], "{$match['team_name']} is explore, not strong" );
		}
	}
);

test(
	'marking every gift likely is undifferentiated too',
	function ( Assert $a ) {
		$result = Matching::recommendations_for_profile( Fixtures::profile( \Serve_Dashboard\Gift_Taxonomy::ids() ) );

		$a->same( 'explore', $result['state'], 'agreeing with everything is not evidence for anything' );
		$a->same( array(), Matching::slugs_for_profile( Fixtures::profile( \Serve_Dashboard\Gift_Taxonomy::ids() ) ), 'and no team is recorded' );
	}
);

test(
	'marking everything unlikely produces no recommendation at all',
	function ( Assert $a ) {
		$profile = Fixtures::profile( array() );
		$result  = Matching::recommendations_for_profile( $profile );

		$a->same( 'none', $result['state'], 'an honest no-match state' );
		$a->same( array(), $result['teams'], 'and no invented filler teams' );
		$a->same( array(), Matching::slugs_for_profile( $profile ), 'nothing is recorded against them' );
		$a->same( 0, count( Matching::suggestions_for_profile( $profile ) ), 'and nothing is suggested' );
	}
);

test(
	'Youth Ministry and Young Adults can be co-matches without table-order bias',
	function ( Assert $a ) {
		// The four gifts both teams share, and nothing else.
		$profile = Fixtures::profile( array( 'leadership', 'evangelism', 'pastoring', 'encouragement' ) );
		$ranked  = Matching::rank_profile( $profile, PHP_INT_MAX );

		$youth = Fixtures::find( $ranked, 'Youth Ministry' );
		$young = Fixtures::find( $ranked, 'GROW - Young Adults' );

		$a->same( Matching_Contract::TIER_STRONG, $youth['tier'], 'Youth Ministry is strong' );
		$a->same( Matching_Contract::TIER_STRONG, $young['tier'], 'Young Adults is strong' );
		$a->same(
			$youth['evidence']['team_coverage'],
			$young['evidence']['team_coverage'],
			'they cover the same proportion of each team'
		);
		$a->ok( $youth['co_match'], 'Youth Ministry is flagged as a co-match' );
		$a->ok( $young['co_match'], 'and so is Young Adults' );
	}
);

test(
	'ranking is deterministic however the teams arrive',
	function ( Assert $a ) {
		$profile = Fixtures::profile( array( 'teaching', 'encouragement', 'hospitality', 'wisdom' ) );

		$first = array_column( Matching::rank_profile( $profile, PHP_INT_MAX ), 'team_slug' );

		for ( $i = 0; $i < 8; $i++ ) {
			$shuffled = Fixtures::teams();
			shuffle( $shuffled );
			Teams::$rows = $shuffled;

			$a->same( $first, array_column( Matching::rank_profile( $profile, PHP_INT_MAX ), 'team_slug' ), 'same order from shuffled teams' );
		}

		Fixtures::install();
	}
);

test(
	'a longer gift list does not win on raw count',
	function ( Assert $a ) {
		/*
		 * Serve resolves to five gifts, Welcome to five, Men Connect to four.
		 * A profile matching two of Men Connect's four covers half of it; two
		 * of Serve's five covers less. Coverage, not the raw hit count, is what
		 * separates them.
		 */
		$profile = Fixtures::profile( array( 'leadership', 'pastoring' ) );
		$ranked  = Matching::rank_profile( $profile, PHP_INT_MAX );

		$men   = Fixtures::find( $ranked, 'GROW - Men Connect' );
		$serve = Fixtures::find( $ranked, 'Serve' );

		$a->same( 2, $men['evidence']['likely_hit_count'], 'two hits on Men Connect' );
		$a->same( 0.5, $men['evidence']['team_coverage'], 'covering half of it' );
		$a->ok( $men['evidence']['team_coverage'] > $serve['evidence']['team_coverage'], 'and ranking prefers the better-covered team' );
	}
);

test(
	'evidence counts stay sane and selectivity stays inside zero and one',
	function ( Assert $a ) {
		$profiles = array(
			Fixtures::profile( array() ),
			Fixtures::profile( array( 'mercy' ) ),
			Fixtures::profile( \Serve_Dashboard\Gift_Taxonomy::ids() ),
			array(),
			array( 'spiritualGifts' => array( 'likely' => 'not an array' ) ),
		);

		foreach ( $profiles as $index => $profile ) {
			foreach ( Matching::rank_profile( $profile, PHP_INT_MAX ) as $match ) {
				$e = $match['evidence'];

				$a->ok( $e['likely_hit_count'] >= 0, "profile $index: likely hits are not negative" );
				$a->ok( $e['possible_hit_count'] >= 0, "profile $index: possible hits are not negative" );
				$a->ok( $e['selectivity'] >= 0.0 && $e['selectivity'] <= 1.0, "profile $index: selectivity in [0,1]" );
				$a->ok( $e['team_coverage'] >= 0.0 && $e['team_coverage'] <= 1.0, "profile $index: coverage in [0,1]" );
			}
		}
	}
);

test(
	'moving a gift from unlikely to possible to likely never makes a team worse',
	function ( Assert $a ) {
		$teams = array_column( Fixtures::teams(), 'name' );

		foreach ( array( 'mercy', 'teaching', 'administration', 'pastoring', 'leadership' ) as $gift ) {
			$base = array( 'faith', 'discernment' );

			$unlikely = Fixtures::profile( $base );
			$possible = Fixtures::profile( $base, array( $gift ) );
			$likely   = Fixtures::profile( array_merge( $base, array( $gift ) ) );

			foreach ( $teams as $name ) {
				$u = Matching_Contract::tier_rank( tier_for( $unlikely, $name ) );
				$p = Matching_Contract::tier_rank( tier_for( $possible, $name ) );
				$l = Matching_Contract::tier_rank( tier_for( $likely, $name ) );

				// Lower rank is a better tier, so it must never increase.
				$a->ok( $p <= $u, "$name: promoting $gift to possible does not make it worse" );
				$a->ok( $l <= $p, "$name: promoting $gift to likely does not make it worse" );
			}
		}
	}
);

test(
	'a deactivated team is never recommended',
	function ( Assert $a ) {
		$profile = Fixtures::profile( array( 'faith', 'discernment', 'mercy' ) );

		$a->same( Matching_Contract::TIER_STRONG, tier_for( $profile, 'Prayer' ), 'strong while the team is running' );

		$rows = Fixtures::teams();
		foreach ( $rows as $row ) {
			if ( 'Prayer' === $row->name ) {
				$row->is_active = 0;
			}
		}
		Teams::$rows = $rows;

		$names = array_column( Matching::rank_profile( $profile, PHP_INT_MAX ), 'team_name' );
		$a->lacks( 'Prayer', $names, 'and absent once it is deactivated' );
		$a->lacks( 'prayer', Matching::slugs_for_profile( $profile ), 'and not recorded against them either' );

		Fixtures::install();
	}
);

test(
	'editable team keywords cannot promote a result to strong',
	function ( Assert $a ) {
		/*
		 * The defect this replaces: keyword overlap produced reasons, reasons
		 * produced a second dimension and a third reason, and that was enough
		 * to reach Strong — which created placement rows and opened the profile
		 * to that team's leader. An administrator typing into a text field was
		 * an access-control decision.
		 */
		$profile = Fixtures::profile(
			array( 'mercy' ),
			array(),
			array(
				'heart'       => array(
					'roles'  => array( 'Counting ability', 'Organize' ),
					'people' => array( 'Elementary Children' ),
					'causes' => array( 'Homelessness' ),
				),
				'abilities'   => array( 'Counting ability', 'Classifying ability', 'Planning ability' ),
				'experiences' => array( 'painful' => array( 'A bereavement' ) ),
			)
		);

		$rows = Fixtures::teams();
		foreach ( $rows as $row ) {
			// Stuff every one of the person's words into one team's vocabulary.
			$row->keywords = json_encode(
				array( 'counting', 'classifying', 'planning', 'organize', 'elementary children', 'homelessness', 'bereavement' )
			);
		}
		Teams::$rows = $rows;

		foreach ( Matching::rank_profile( $profile, PHP_INT_MAX ) as $match ) {
			$a->not(
				Matching_Contract::TIER_STRONG === $match['tier'],
				"{$match['team_name']} cannot be carried to strong by vocabulary"
			);

			// The context is still shown, and still says nothing about tier.
			foreach ( $match['reasons'] as $reason ) {
				$a->same( 'gifts', $reason['dimension'], 'only gifts are scored as reasons' );
			}
		}

		$a->same( array(), Matching::slugs_for_profile( $profile ), 'and no team is recorded from keyword overlap' );

		Fixtures::install();
	}
);

test(
	'painful experiences have no effect on tier or ordering',
	function ( Assert $a ) {
		$without = Fixtures::profile( array( 'faith', 'discernment', 'mercy' ) );
		$with    = Fixtures::profile(
			array( 'faith', 'discernment', 'mercy' ),
			array(),
			array(
				'experiences' => array(
					'What painful experiences have shaped you?' => array(
						'A bereavement', 'Other: something private and specific',
					),
				),
			)
		);

		$a->same(
			array_column( Matching::rank_profile( $without, PHP_INT_MAX ), 'team_slug' ),
			array_column( Matching::rank_profile( $with, PHP_INT_MAX ), 'team_slug' ),
			'the ranking is identical with and without the painful section'
		);

		foreach ( Matching::rank_profile( $with, PHP_INT_MAX ) as $i => $match ) {
			$bare = Matching::rank_profile( $without, PHP_INT_MAX )[ $i ];
			$a->same( $bare['tier'], $match['tier'], "{$match['team_name']} has the same tier" );
			$a->same( $bare['evidence'], $match['evidence'], "{$match['team_name']} has the same evidence" );
		}

		$a->same(
			Matching::slugs_for_profile( $without ),
			Matching::slugs_for_profile( $with ),
			'and the same teams are recorded'
		);
	}
);

test(
	'at most three recommendations, and never padded to three',
	function ( Assert $a ) {
		// Broad enough to clear Suggested on many teams at once.
		$result = Matching::recommendations_for_profile(
			Fixtures::profile( array( 'teaching', 'encouragement', 'hospitality', 'wisdom', 'leadership' ) )
		);

		$a->same( 'ready', $result['state'], 'there are recommendations' );
		$a->ok( count( $result['teams'] ) <= 3, 'no more than three are shown' );

		// And a profile with exactly one qualifying team gets one.
		$narrow = Matching::recommendations_for_profile( Fixtures::profile( array( 'giving', 'service', 'mercy' ) ) );
		$a->ok( count( $narrow['teams'] ) >= 1, 'a narrow profile still gets its match' );

		foreach ( $narrow['teams'] as $team ) {
			$a->ok( $team['evidence']['likely_hit_count'] > 0, 'every team shown has real evidence' );
		}
	}
);

test(
	'when nothing clears suggested, at most two explore options with real evidence',
	function ( Assert $a ) {
		$result = Matching::recommendations_for_profile( Fixtures::profile( array( 'mercy' ) ) );

		$a->same( 'explore', $result['state'], 'an explore state' );
		$a->ok( count( $result['teams'] ) <= 2, 'at most two options' );

		foreach ( $result['teams'] as $team ) {
			$a->ok(
				$team['evidence']['likely_hit_count'] > 0 || $team['evidence']['possible_hit_count'] > 0,
				"{$team['team_name']} has real evidence behind it"
			);
		}
	}
);

test(
	'team openings and headcount never change a tier or the order',
	function ( Assert $a ) {
		$profile = Fixtures::profile( array( 'faith', 'discernment', 'mercy' ) );
		$before  = Matching::rank_profile( $profile, PHP_INT_MAX );

		$rows = Fixtures::teams();
		foreach ( $rows as $row ) {
			// Make one team desperate and another fully staffed.
			if ( 'Worship' === $row->name ) {
				$row->target_headcount  = 50;
				$row->current_headcount = 1;
			}
			if ( 'Prayer' === $row->name ) {
				$row->target_headcount  = 2;
				$row->current_headcount = 99;
			}
		}
		Teams::$rows = $rows;

		$after = Matching::rank_profile( $profile, PHP_INT_MAX );

		$a->same(
			array_column( $before, 'team_slug' ),
			array_column( $after, 'team_slug' ),
			'a vacancy is not evidence that a person fits'
		);
		$a->same( Matching_Contract::TIER_STRONG, Fixtures::find( $after, 'Prayer' )['tier'], 'and a full team is still a strong match' );

		Fixtures::install();
	}
);

test(
	'a legacy label-only profile degrades to explore and never gains a strong match',
	function ( Assert $a ) {
		// What years of stored profiles look like: labels, and only the
		// buckets the old browser filled.
		$legacy = array(
			'spiritualGifts' => array(
				'likely' => array( 'Faith', 'Discernment', 'Mercy', 'Healing', 'Wisdom' ),
			),
		);

		$result = Matching::recommendations_for_profile( $legacy );

		$a->same( 'explore', $result['state'], 'it is explorable, not strong' );
		$a->contains( 'Only 5 of the 18 gifts', $result['caveat'], 'and says exactly what is missing' );

		foreach ( Matching::rank_profile( $legacy, PHP_INT_MAX ) as $match ) {
			$a->not( Matching_Contract::TIER_STRONG === $match['tier'], "{$match['team_name']} is not strong on a partial profile" );
		}

		$a->same( array(), Matching::slugs_for_profile( $legacy ), 'and no new team is recorded from it' );
	}
);

test(
	'the snapshot records versions and only non-sensitive reasons',
	function ( Assert $a ) {
		$snapshot = Matching::participant_match_snapshot(
			Fixtures::profile(
				array( 'faith', 'discernment', 'mercy' ),
				array(),
				array(
					'experiences' => array( 'painful' => array( 'A bereavement' ) ),
					'abilities'   => array( 'Counting ability' ),
					'personality' => array( 'Tends to be introverted' ),
					'contact'     => array( 'email' => 'someone@example.com' ),
				)
			)
		);

		$encoded = json_encode( $snapshot );

		$a->same( 'ready', $snapshot['state'], 'it records the state' );
		$a->same( 'gift-v2', $snapshot['versions']['contract'], 'the contract version' );
		$a->same( '2.0.0', $snapshot['versions']['crosswalk'], 'the mapping version' );
		$a->same( '0.0.0', $snapshot['versions']['corroboration'], 'and that no H/A/E mappings were approved' );

		$a->contains( 'Faith', $snapshot['teams'][0]['gifts'], 'the gifts that justified it are named' );

		foreach ( array( 'bereavement', 'someone@example.com', 'Counting ability', 'introverted' ) as $secret ) {
			$a->lacks( $secret, $encoded, "the snapshot carries no $secret" );
		}
	}
);

test(
	'no structured corroboration is approved, so route B cannot fire',
	function ( Assert $a ) {
		$a->ok( \Serve_Dashboard\Corroboration::is_empty(), 'the registry ships empty' );

		// Two mapped likely gifts plus every H/A/E answer imaginable: without
		// an approved corroborator this is Suggested, never Strong.
		$profile = Fixtures::profile(
			array( 'faith', 'discernment' ),
			array(),
			array(
				'heart'     => array( 'roles' => array( 'Organize' ), 'people' => array( 'Elementary Children' ), 'causes' => array() ),
				'abilities' => array( 'Counting ability' ),
			)
		);

		$match = Fixtures::find( Matching::rank_profile( $profile, PHP_INT_MAX ), 'Prayer' );

		$a->same( 2, $match['evidence']['likely_hit_count'], 'two mapped gifts' );
		$a->same( array(), $match['evidence']['corroborations'], 'nothing corroborates' );
		$a->same( Matching_Contract::TIER_SUGGESTED, $match['tier'], 'so it stops at suggested' );
	}
);
