<?php
/**
 * The canonical taxonomy and the approved crosswalk.
 *
 * These are the tests that would have caught the regression: a display-string
 * comparison that matched twelve of the ministry table's twenty-two terms and
 * silently ignored the other ten, leaving two ministries unrecommendable and
 * six assessed gifts invisible to the whole church.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Unit;

use Serve_Dashboard\Gift_Crosswalk;
use Serve_Dashboard\Gift_Ratings;
use Serve_Dashboard\Gift_Taxonomy;
use Serve_Dashboard\Matching_Contract;
use Serve_Dashboard\Teams;

test(
	'the sixteen ministry rows are the ones the church supplied',
	function ( Assert $a ) {
		$expected = array(
			'Production'           => array( 'Assisting', 'Crafting', 'Creativity', 'Organization', 'Service' ),
			'Livestream Team'      => array( 'Assisting', 'Creativity', 'Evangelism', 'Knowledge', 'Organization', 'Vision' ),
			'GROW - Small Group'   => array( 'Teaching', 'Encouragement', 'Mentoring', 'Hospitality', 'Wisdom', 'Discernment' ),
			'GROW - Compass'       => array( 'Teaching', 'Knowledge', 'Wisdom', 'Discernment', 'Encouragement', 'Leadership' ),
			'GROW - Men Connect'   => array( 'Leadership', 'Mentoring', 'Encouragement', 'Wisdom', 'Justice' ),
			'GROW - Women Connect' => array( 'Encouragement', 'Mentoring', 'Hospitality', 'Mercy', 'Teaching', 'Wisdom' ),
			'GROW - Young Adults'  => array( 'Evangelism', 'Leadership', 'Mentoring', 'Encouragement', 'Vision', 'Mission' ),
			'Fellowship Kids'      => array( 'Teaching', 'Creativity', 'Mercy', 'Assisting', 'Encouragement', 'Hospitality' ),
			'Youth Ministry'       => array( 'Leadership', 'Evangelism', 'Mentoring', 'Teaching', 'Encouragement', 'Vision' ),
			'Events'               => array( 'Organization', 'Creativity', 'Hospitality', 'Assisting', 'Service', 'Leadership' ),
			'Worship'              => array( 'Creativity', 'Vision', 'Encouragement', 'Discernment', 'Faith', 'Leadership' ),
			'Prayer'               => array( 'Prayer', 'Faith', 'Discernment', 'Healing', 'Mercy', 'Wisdom' ),
			'Serve'                => array( 'Service', 'Assisting', 'Mercy', 'Giving', 'Hospitality', 'Teaching', 'Knowledge' ),
			'Welcome'              => array( 'Hospitality', 'Encouragement', 'Assisting', 'Mercy', 'Evangelism' ),
			'Newcomers Pathway'    => array( 'Hospitality', 'Encouragement', 'Teaching', 'Mentoring', 'Organization', 'Leadership' ),
			'Administration'       => array( 'Organization', 'Leadership', 'Knowledge', 'Wisdom', 'Assisting', 'Vision' ),
		);

		$actual = array();
		foreach ( Teams::all() as $team ) {
			$actual[ $team->name ] = Teams::gift_list( $team );
		}

		$a->same( 16, count( $actual ), 'sixteen ministries are seeded' );
		$a->same( $expected, $actual, 'and each lists exactly the gifts the table gives it' );
	}
);

test(
	'all eighteen assessed gifts are present and uniquely addressable',
	function ( Assert $a ) {
		$ids = Gift_Taxonomy::ids();

		$a->same( 18, count( $ids ), 'eighteen gifts' );
		$a->same( count( $ids ), count( array_unique( $ids ) ), 'no duplicate ids' );

		$a->same(
			array(
				'administration', 'apostle', 'discernment', 'encouragement', 'evangelism',
				'faith', 'giving', 'healing', 'hospitality', 'leadership', 'mercy',
				'miracles', 'pastoring', 'praying-with-my-spirit', 'preaching',
				'service', 'teaching', 'wisdom',
			),
			$ids,
			'and they are the ids the assessment uses'
		);

		// Every label and alternate name resolves back to exactly its own gift.
		foreach ( Gift_Taxonomy::gifts() as $id => $gift ) {
			$a->same( $id, Gift_Taxonomy::id_for_label( $gift['label'] ), "the label for $id resolves to it" );

			if ( '' !== $gift['alternate'] ) {
				$a->same( $id, Gift_Taxonomy::id_for_label( $gift['alternate'] ), "the alternate name for $id resolves to it" );
			}
		}
	}
);

test(
	'an unknown gift name resolves to nothing rather than the nearest one',
	function ( Assert $a ) {
		foreach ( array( 'Admin', 'administrative', 'Organis', 'Prayerfulness', 'Teach', 'Shepherd', '' ) as $guess ) {
			$a->same( '', Gift_Taxonomy::id_for_label( $guess ), "'$guess' is not resolved by guesswork" );
		}

		// The exact alternate name is fine; a prefix of it is not.
		$a->same( 'administration', Gift_Taxonomy::id_for_label( 'Organization' ), 'the exact alternate name works' );
	}
);

test(
	'every approved crosswalk entry resolves, and unmapped terms are reported',
	function ( Assert $a ) {
		/*
		 * Organization is not in this list because it is not an approved
		 * alias: it is the assessment's own alternate name for Administration
		 * and resolves through the taxonomy. It is asserted on its own below,
		 * because it is the single most load-bearing resolution in the system
		 * and must not depend on a church decision that could be reversed.
		 */
		$a->same(
			'administration',
			Gift_Taxonomy::id_for_label( 'Organization' ),
			'Organization resolves through the assessment\'s own alternate name'
		);
		$a->same(
			array( 'administration' ),
			Gift_Crosswalk::resolve( array( 'Organization' ) )['ids'],
			'and a ministry listing it can therefore see the gift of administration'
		);

		$approved = array(
			'Assisting' => 'service',
			'Mentoring' => 'pastoring',
			'Mission'   => 'apostle',
			'Vision'    => 'leadership',
		);

		foreach ( $approved as $term => $id ) {
			$resolved = Gift_Crosswalk::resolve( array( $term ) );
			$a->same( array( $id ), $resolved['ids'], "$term resolves to $id" );
			$a->same( array(), $resolved['unmapped'], "$term is not reported as unmapped" );
		}

		// The five terms the church left deliberately unmapped.
		$resolved = Gift_Crosswalk::resolve( Gift_Crosswalk::unmapped_terms() );
		$a->same( array(), $resolved['ids'], 'unmapped terms score nothing' );
		$a->same(
			array( 'Crafting', 'Creativity', 'Justice', 'Knowledge', 'Prayer' ),
			$resolved['unmapped'],
			'and every one of them is surfaced rather than dropped'
		);
	}
);

test(
	'the rejected relationships are not quietly in force',
	function ( Assert $a ) {
		// Prayer must not become the gift of tongues.
		$prayer = Gift_Crosswalk::resolve( array( 'Prayer' ) );
		$a->same( array(), $prayer['ids'], 'Prayer maps to no gift' );
		$a->contains( 'Prayer', $prayer['unmapped'], 'and is reported unmapped' );

		// Leadership resolves to leadership alone, not also apostle/pastoring.
		$a->same( array( 'leadership' ), Gift_Crosswalk::resolve( array( 'Leadership' ) )['ids'], 'Leadership is not widened' );

		// Teaching is not satisfiable by preaching.
		$a->same( array( 'teaching' ), Gift_Crosswalk::resolve( array( 'Teaching' ) )['ids'], 'Teaching is not widened' );

		// And each rejection is on the record with a reason.
		$a->same( 3, count( Gift_Crosswalk::rejected() ), 'three rejections are documented' );
		foreach ( Gift_Crosswalk::rejected() as $entry ) {
			$a->ok( '' !== $entry['reason'], "{$entry['term']} records why it was rejected" );
		}
	}
);

test(
	'every unmapped assessed gift is reported',
	function ( Assert $a ) {
		$unmapped = Gift_Crosswalk::unmapped_gifts();
		sort( $unmapped );

		$a->same(
			array( 'miracles', 'praying-with-my-spirit', 'preaching' ),
			$unmapped,
			'three gifts no ministry maps, and they are named rather than treated as unlikely'
		);
	}
);

test(
	'Administration matches through the canonical map',
	function ( Assert $a ) {
		foreach ( Teams::all() as $team ) {
			if ( 'Administration' !== $team->name ) {
				continue;
			}

			$resolved = Gift_Crosswalk::resolve( Teams::gift_list( $team ) );

			$a->contains( 'administration', $resolved['ids'], 'the Administration ministry can see the gift of administration' );
			$a->contains( 'service', $resolved['ids'], 'Assisting resolves to service' );
			$a->contains( 'leadership', $resolved['ids'], 'Vision folds into leadership' );
			$a->contains( 'Knowledge', $resolved['unmapped'], 'Knowledge stays unmapped and is reported' );
		}
	}
);

test(
	'Serve lists Service and Assisting and counts one gift once',
	function ( Assert $a ) {
		foreach ( Teams::all() as $team ) {
			if ( 'Serve' !== $team->name ) {
				continue;
			}

			$resolved = Gift_Crosswalk::resolve( Teams::gift_list( $team ) );

			$a->same(
				count( $resolved['ids'] ),
				count( array_unique( $resolved['ids'] ) ),
				'the resolved ids are unique'
			);
			$a->same( 1, count( array_keys( $resolved['ids'], 'service', true ) ), 'service appears once, not twice' );
			$a->contains( 'Assisting', $resolved['duplicates'], 'and the collapsed term is reported rather than hidden' );
		}
	}
);

test(
	'no active team divides by zero, and Production is honestly capped',
	function ( Assert $a ) {
		foreach ( Teams::all() as $team ) {
			$scorable = count( Gift_Crosswalk::resolve( Teams::gift_list( $team ) )['ids'] );

			$a->ok( $scorable > 0, "{$team->name} has at least one scorable gift" );
			$a->same(
				0.0,
				Matching_Contract::coverage( 0, 0 ),
				'coverage of a team with nothing scorable is zero, not a division by zero'
			);

			if ( 'Production' === $team->name ) {
				// Crafting and Creativity are unmeasured, so this ministry can
				// reach Suggested and no further. That is the table's limit
				// being reported, not an alias waiting to be invented.
				$a->same( 2, $scorable, 'Production scores on two gifts' );
				$a->ok( $scorable < Matching_Contract::MIN_LIKELY_STRONG, 'so it cannot reach a strong gift match' );
			}
		}
	}
);

test(
	'a complete profile validates and an incomplete one does not',
	function ( Assert $a ) {
		$complete = Gift_Ratings::from_profile( Fixtures::profile( array( 'mercy', 'hospitality' ) ) );

		$a->ok( $complete->is_valid(), 'all eighteen answered exactly once is valid' );
		$a->same( 2, $complete->all_likely_count(), 'and the likely count is what was marked' );
		$a->same( array(), $complete->unrecognised(), 'nothing unrecognised' );

		// The shape the old tests used, which the assessment cannot produce.
		$partial = Gift_Ratings::from_profile(
			array( 'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ) )
		);

		$a->not( $partial->is_valid(), 'a profile carrying one answer out of eighteen is not valid' );
		$a->contains( 'Only 1 of the 18 gifts were answered', $partial->invalid_reason(), 'and says why' );
	}
);

test(
	'an unreadable answer is reported, never rounded to a gift it resembles',
	function ( Assert $a ) {
		$ratings = Gift_Ratings::from_profile(
			array( 'spiritualGifts' => array( 'likely' => array( 'Administrative Gifting' ) ) )
		);

		$a->same( array(), $ratings->likely(), 'it is not filed as administration' );
		$a->contains( 'Administrative Gifting', $ratings->unrecognised(), 'it is reported verbatim' );
		$a->not( $ratings->is_valid(), 'and the partition is not valid' );
	}
);

test(
	'a gift arriving in two buckets is an error, not last-value-wins',
	function ( Assert $a ) {
		$ratings = Gift_Ratings::from_profile(
			array(
				'spiritualGifts' => array(
					// The label and the alternate name for one gift, split
					// across two buckets: the exact collision an alias layer
					// makes possible.
					'likely'   => array( 'Administration' ),
					'possible' => array( 'Organization' ),
				),
			)
		);

		$a->contains( 'administration', $ratings->conflicts(), 'the conflict is recorded' );
		$a->not( $ratings->is_valid(), 'and the partition is invalid' );
		$a->contains( 'more than one answer', $ratings->invalid_reason(), 'and says so' );
	}
);

test(
	'a participant is told which of their likely gifts the table cannot measure',
	function ( Assert $a ) {
		$ratings = Gift_Ratings::from_profile( Fixtures::profile( array( 'pastoring', 'miracles', 'mercy' ) ) );

		$unmapped = $ratings->unmapped_likely();

		$a->contains( 'miracles', $unmapped, 'miracles is named as unmeasured' );
		$a->lacks( 'mercy', $unmapped, 'mercy is measured and so is not listed' );
		$a->lacks( 'pastoring', $unmapped, 'pastoring is measured, through the approved Mentoring bridge' );
	}
);

test(
	'configuration validation reports the unmapped terms rather than hiding them',
	function ( Assert $a ) {
		$messages = array();
		foreach ( Gift_Crosswalk::validate() as $problem ) {
			$messages[] = $problem['message'];
		}

		$joined = implode( "\n", $messages );

		foreach ( Gift_Crosswalk::unmapped_terms() as $term ) {
			$a->contains( "\"$term\" is not mapped", $joined, "$term is surfaced in validation" );
		}

		$a->contains( 'Only 2 scorable gift(s)', $joined, 'and Production is flagged as Suggested-only' );
		$a->contains( 'No ministry maps the gift of Miracles', $joined, 'and unmapped gifts are named' );
	}
);
