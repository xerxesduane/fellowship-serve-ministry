<?php
/**
 * Matching, and specifically what personality is and is not allowed to do.
 *
 * The assessment has collected the four personality couplets from the
 * beginning, the profile has always displayed them, and nothing ever read
 * them — while the presentation lists personality among the five things
 * matching considers. Interpreting them closes that gap.
 *
 * The risk in closing it is the interesting part. Personality is constant
 * across teams: nothing in the teams table describes what a role is like, so
 * the same four tendencies fire identically for every suggestion. Had they
 * been added to the reason list, every team would have gained a dimension and
 * several reasons at once, and the corroboration rule that stops spiritual
 * gifts alone reaching "Strong match" would have been satisfied by evidence
 * that says nothing about any team. These tests hold that line.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Matching;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Submissions;
use Serve_Dashboard\Teams;

test(
	'each personality tendency is read as something about how they would serve',
	function ( Assert $a, Fixtures $f ) {
		$notes = Matching::personality_notes(
			array(
				'personality' => array(
					'Be Introverted',
					'Be Self-controlled',
					'Prefer Routine',
					'Be Cooperative',
				),
			)
		);

		$a->same( 4, count( $notes ), 'all four couplets are interpreted' );

		$by_tendency = array_column( $notes, 'note', 'tendency' );

		$a->contains( 'listens well', $by_tendency['Be Introverted'], 'the introvert note reflects the workbook' );
		$a->contains( 'asking directly', $by_tendency['Be Self-controlled'], 'a quiet person is drawn out, not passed over' );
		$a->contains( 'expectations are clear', $by_tendency['Prefer Routine'], 'routine is described, not judged' );
		$a->contains( 'team effort', $by_tendency['Be Cooperative'], 'cooperative is described' );

		// The opposite pole of every couplet is equally serviceable. There is
		// no right or wrong temperament, and no note may read as a deficiency.
		$opposites = Matching::personality_notes(
			array(
				'personality' => array(
					'Be Extroverted',
					'Be Self-expressive',
					'Prefer Variety',
					'Be Competitive',
				),
			)
		);

		$a->same( 4, count( $opposites ), 'and so is the other side of each couplet' );
	}
);

test(
	'a profile with no personality answers produces nothing rather than a guess',
	function ( Assert $a, Fixtures $f ) {
		$a->same( 0, count( Matching::personality_notes( array() ) ), 'missing section' );
		$a->same( 0, count( Matching::personality_notes( array( 'personality' => array() ) ) ), 'empty section' );

		// An unrecognised label is skipped. Inventing a note for a tendency
		// nobody knows about would be worse than saying nothing, and this is
		// what protects the list if the assessment's wording is ever edited.
		$a->same(
			0,
			count( Matching::personality_notes( array( 'personality' => array( 'Be Something Nobody Added Yet' ) ) ) ),
			'unknown tendency'
		);

		// Whereas a reworded label still matches on its keyword.
		$reworded = Matching::personality_notes( array( 'personality' => array( 'Tends to be introverted' ) ) );
		$a->same( 1, count( $reworded ), 'matching is on the keyword, not the exact stored string' );
	}
);

test(
	'personality never strengthens a match or counts as a SHAPE dimension',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$team = Teams::get_by_slug( 'welcome' );
		$a->ok( null !== $team, 'the welcome team is seeded' );

		$id         = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$submission = Submissions::get( $id );

		/*
		 * Two of the Welcome team's gifts and nothing else that overlaps: no
		 * passion, no ability and no experience naming the team, and a single
		 * language so the multilingual reason cannot fire either. Gifts are
		 * therefore the only dimension supporting this suggestion.
		 */
		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Mercy', 'Hospitality' ) ),
			'heart'          => array( 'roles' => array(), 'people' => array(), 'causes' => array() ),
			'abilities'      => array(),
			'personality'    => array( 'Be Extroverted', 'Be Self-expressive', 'Prefer Variety', 'Be Cooperative' ),
		);

		$match = Matching::explain( $submission, $team, $profile );

		$a->same( 2, (int) $match['gift_overlap'], 'both gifts are recognised' );
		$a->same( 1, (int) $match['dimensions_hit'], 'gifts are the only dimension, personality did not become a second' );

		$dimensions = array_unique( array_column( $match['reasons'], 'dimension' ) );
		$a->not( in_array( 'personality', $dimensions, true ), 'no reason is attributed to personality' );

		/*
		 * The guarantee that matters. Two gift overlaps plus a full set of
		 * personality answers must not reach "Strong match": strong requires
		 * corroboration from a second dimension, and four tendencies that
		 * apply identically to all sixteen teams are not corroboration.
		 */
		$a->same( Matching::STRENGTH_POSSIBLE, $match['strength'], 'it stays a possible match' );

		// And the notes do exist — they are reported, just not counted.
		$a->same( 4, count( Matching::personality_notes( $profile ) ), 'the notes are still available to the leader' );
	}
);
