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

		$match = Matching::explain( $team, $profile );

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

/*
 * The defect the tests below exist for.
 *
 * Which teams got suggested was decided by `recommendMinistries()` in the
 * browser, which ranks on spiritual-gift name overlap and nothing else.
 * `explain()` then added heart, abilities and experience to that already-chosen
 * set — so those dimensions could reorder three teams picked on gifts, and
 * could never put forward a team the gift ranking had missed. Somebody whose
 * real fit was a passion or a past job never saw that team from either side:
 * the team-first list pre-filtered on gift overlap too.
 */
test(
	'a team supported only by abilities is suggested, and leads the list',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		/*
		 * Mercy overlaps five teams and none of them is Administration, whose
		 * vocabulary these five abilities do match. Under the old ranking
		 * Administration could not appear at all: it was not one of the
		 * assessment's picks, and nothing outside those picks was considered.
		 */
		$id         = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$submission = Submissions::get( $id );

		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
			'abilities'      => array(
				'Counting ability',
				'Classifying ability',
				'Evaluating ability',
				'Editing ability',
				'Writing ability',
			),
		);

		$ranked = Matching::rank( $submission, $profile );
		$names  = array_column( $ranked, 'team_name' );

		$a->ok( in_array( 'Administration', $names, true ), 'the team its abilities point to is suggested at all' );
		$a->same( 'Administration', $names[0], 'and is the best-evidenced suggestion' );

		$admin = null;
		foreach ( $ranked as $match ) {
			if ( 'Administration' === $match['team_name'] ) {
				$admin = $match;
			}
		}

		$a->same( 0, (int) $admin['gift_overlap'], 'not one spiritual gift overlaps it' );
		$a->same( 5, count( $admin['reasons'] ), 'five abilities do' );
		$a->same(
			array( 'abilities' ),
			array_values( array_unique( array_column( $admin['reasons'], 'dimension' ) ) ),
			'all from abilities'
		);

		// Evidence with no gift behind it never claims to be strong.
		$a->same( Matching::STRENGTH_POSSIBLE, $admin['strength'], 'offered as possible, not strong' );

		// The person has not seen this one; their profile lists the assessment picks.
		$a->not( (bool) $admin['from_assessment'], 'flagged as not on their own profile' );
	}
);

test(
	'what the assessment told the person is always carried, and marked as theirs',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		/*
		 * Prayer is what the assessment named. Nothing in this profile supports
		 * it, so on evidence alone it would fall off the end. It has to survive
		 * anyway: it is what the person was told, and a leader who cannot see it
		 * cannot answer "but it said Prayer".
		 */
		$id         = $f->verified_submission( array( 'suggested_teams' => array( 'prayer' ) ) );
		$submission = Submissions::get( $id );

		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Organization' ) ),
			'abilities'      => array( 'Counting ability', 'Classifying ability' ),
		);

		$ranked = Matching::rank( $submission, $profile );

		$prayer = null;
		foreach ( $ranked as $match ) {
			if ( 'Prayer' === $match['team_name'] ) {
				$prayer = $match;
			}
		}

		$a->ok( null !== $prayer, 'the assessment pick is present' );
		$a->ok( (bool) $prayer['from_assessment'], 'and marked as one the person has seen' );
		$a->same( 0, count( $prayer['reasons'] ), 'even with nothing supporting it' );

		// Sorted on evidence like everything else, not promoted for being theirs.
		$last = end( $ranked );
		$a->same( 'Prayer', $last['team_name'], 'ranked on evidence, not privileged' );
	}
);

test(
	'a passion for Women is not evidence for Men Connect',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		/*
		 * "men" sits inside "women". Matching vocabulary as a substring made
		 * every passion for women count as evidence for the men's ministry —
		 * wrong, and the kind of wrong a leader notices before the software
		 * does. Vocabulary is matched on word boundaries.
		 */
		$id         = $f->verified_submission();
		$submission = Submissions::get( $id );

		$profile = array(
			'spiritualGifts' => array( 'likely' => array() ),
			'heart'          => array(
				'people' => array( 'Women' ),
				'roles'  => array(),
				'causes' => array(),
			),
		);

		$mens   = Teams::get_by_slug( 'grow-men-connect' );
		$womens = Teams::get_by_slug( 'grow-women-connect' );

		$a->same( 0, count( Matching::explain( $mens, $profile )['reasons'] ), 'no reason for the men' );
		$a->same( 1, count( Matching::explain( $womens, $profile )['reasons'] ), 'and one for the women' );
	}
);

test(
	'a team being short of people still never improves its ranking',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$id         = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$submission = Submissions::get( $id );

		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
			'abilities'      => array( 'Counting ability', 'Classifying ability', 'Evaluating ability', 'Editing ability', 'Writing ability' ),
		);

		$before = array_column( Matching::rank( $submission, $profile ), 'team_name' );

		// Open a large hole in a team the evidence does not support at all.
		$f->set_team_capacity( 'prayer', 40, 1 );

		$after = array_column( Matching::rank( $submission, $profile ), 'team_name' );

		$a->same( $before, $after, 'the order is identical with a gaping vacancy in play' );
		$a->same( 'Administration', $after[0], 'evidence still leads' );
	}
);

/*
 * The safety property of this change.
 *
 * A ministry leader sees a person because a placement row ties that person to a
 * team they lead, and those rows are built at submission time from
 * `suggested_teams`. Ranking every team must touch neither: if a wider set of
 * suggestions widened the placement rows, it would hand every leader whose team
 * happened to match a profile they were never meant to open. A suggestion is
 * advice to whoever can already see the person.
 */
test(
	'suggesting a team to a leader does not let that team see the person',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$id         = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$submission = Submissions::get( $id );

		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
			'abilities'      => array( 'Counting ability', 'Classifying ability', 'Evaluating ability', 'Editing ability', 'Writing ability' ),
		);

		$top = array_column( Matching::rank( $submission, $profile ), 'team_name' )[0];
		$a->same( 'Administration', $top, 'Administration is the top suggestion' );

		/*
		 * Re-read from the database rather than trusting the object fetched
		 * before ranking. Checking the in-memory copy is how the first version
		 * of this test passed while a mutation that rewrote the column sailed
		 * through: the stale object still said "welcome" no matter what had been
		 * written underneath it.
		 */
		$reread = Submissions::get( $id );
		$a->same(
			array( 'welcome' ),
			Submissions::decode_list( $reread->suggested_teams ),
			'suggested_teams still records only what they saw'
		);

		/*
		 * And the mechanism itself: visibility comes from placement rows, which
		 * are written once at submission time from suggested_teams. Ranking must
		 * not add to them.
		 */
		global $wpdb;
		$placement_teams = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT team_id FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d',
				$id
			)
		);
		$welcome = Teams::get_by_slug( 'welcome' );
		$a->same(
			array( (int) $welcome->id ),
			array_map( 'intval', (array) $placement_teams ),
			'and one placement row exists, for the team they were actually shown'
		);

		$leader     = $f->user( Roles::ROLE_LEADER );
		$admin_team = $f->lead_team( $leader, 'administration' );
		wp_set_current_user( $leader );

		$a->same( array( $admin_team ), Roles::visible_team_ids(), 'the leader leads the suggested team' );
		$a->not( Roles::can_view_submission( $id ), 'and still cannot open the profile' );
		$a->not(
			in_array( $id, wp_list_pluck( Submissions::query( array( 'limit' => 200 ) ), 'id' ), false ),
			'nor find them in any list'
		);
	}
);

test(
	'the team-first list finds someone whose fit is not in their gifts',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		/*
		 * The other half of the same defect. A leader starting from Fellowship
		 * Kids used to see only people already suggested to it or with an
		 * overlapping likely gift, so a heart for Elementary Children was
		 * invisible from the team side as well as the person side.
		 */
		$team = Teams::get_by_slug( 'fellowship-kids' );

		$id = $f->verified_submission(
			array(
				'display_name'    => 'Ifeoma Balogun',
				'suggested_teams' => array( 'administration' ),
				'gifts_likely'    => array( 'Organization' ),
				'profile'         => array(
					'spiritualGifts' => array( 'likely' => array( 'Organization' ) ),
					'heart'          => array(
						'people' => array( 'Elementary Children' ),
						'roles'  => array(),
						'causes' => array(),
					),
				),
			)
		);

		$found = null;
		foreach ( Teams::candidates( (int) $team->id, 200 ) as $candidate ) {
			if ( (int) $candidate['id'] === $id ) {
				$found = $candidate;
			}
		}

		$a->ok( null !== $found, 'they are a candidate for the team their heart points to' );
		$a->not( (bool) $found['alreadySuggested'], 'without the assessment having suggested it' );
		$a->contains( 'Elementary Children', $found['match']['reasons'][0]['label'], 'their own words quoted as the reason' );

		// Fellowship Kids is safeguarded, so the gate is flagged rather than silent.
		$a->ok( (bool) $found['needsCheck'], 'and the background check is flagged' );
	}
);

test(
	'a deactivated team is not suggested, even if the assessment named it',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$id         = $f->verified_submission( array( 'suggested_teams' => array( 'events' ) ) );
		$submission = Submissions::get( $id );
		$profile    = array( 'spiritualGifts' => array( 'likely' => array( 'Organization' ) ) );

		$names = array_column( Matching::rank( $submission, $profile ), 'team_name' );
		$a->ok( in_array( 'Events', $names, true ), 'suggested while the team is running' );

		$events = Teams::get_by_slug( 'events' );
		$teams  = Schema::table( 'teams' );
		$wpdb->update( $teams, array( 'is_active' => 0 ), array( 'id' => (int) $events->id ), array( '%d' ), array( '%d' ) );

		try {
			$after = array_column( Matching::rank( $submission, $profile ), 'team_name' );

			// Suggesting a team the church has closed is worse than a gap in the
			// history the suggestion came from.
			$a->not( in_array( 'Events', $after, true ), 'and dropped once the church closes it' );
		} finally {
			$wpdb->update( $teams, array( 'is_active' => 1 ), array( 'id' => (int) $events->id ), array( '%d' ), array( '%d' ) );
		}
	}
);

test(
	'a team keeps its vocabulary through a save that does not mention it',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * Found by the suite doing it. Two seeded teams lost their keywords on
		 * the first run, because the headcount tests call Teams::save() with the
		 * capacity fields only and the first version of that method treated a
		 * missing key as an empty field. A team quietly losing the words that
		 * make heart, abilities and experience count is invisible: suggestions
		 * simply narrow back to spiritual gifts for that one team.
		 */
		$team   = Teams::get_by_slug( 'production' );
		$before = Teams::keyword_list( $team );

		$a->ok( count( $before ) > 0, 'the seeded team starts with a vocabulary' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		Teams::save(
			(int) $team->id,
			array(
				'target_headcount'      => 4,
				'min_headcount'         => 1,
				'current_headcount'     => 2,
				'requires_safeguarding' => 0,
				'is_active'             => 1,
				'leader_user_id'        => 0,
			)
		);

		$a->same( $before, Teams::keyword_list( Teams::get_by_slug( 'production' ) ), 'and still has it afterwards' );

		// Submitted-but-blank is a real instruction, and is obeyed.
		Teams::save(
			(int) $team->id,
			array(
				'keywords'              => '',
				'target_headcount'      => 4,
				'min_headcount'         => 1,
				'current_headcount'     => 2,
				'requires_safeguarding' => 0,
				'is_active'             => 1,
				'leader_user_id'        => 0,
			)
		);

		$a->same( array(), Teams::keyword_list( Teams::get_by_slug( 'production' ) ), 'clearing the field on purpose does clear it' );

		// Typed wording is parsed, trimmed and de-duplicated.
		Teams::save(
			(int) $team->id,
			array(
				'keywords'              => ' audio ,video,  audio , ,lighting ',
				'target_headcount'      => 4,
				'min_headcount'         => 1,
				'current_headcount'     => 2,
				'requires_safeguarding' => 0,
				'is_active'             => 1,
				'leader_user_id'        => 0,
			)
		);

		$a->same(
			array( 'audio', 'video', 'lighting' ),
			Teams::keyword_list( Teams::get_by_slug( 'production' ) ),
			'commas separate, blanks and repeats are dropped'
		);

		// Put the seeded wording back, so this test does not become the thing it
		// is testing against.
		Teams::save(
			(int) $team->id,
			array(
				'keywords'              => implode( ', ', $before ),
				'target_headcount'      => (int) $team->target_headcount,
				'min_headcount'         => (int) $team->min_headcount,
				'current_headcount'     => (int) $team->current_headcount,
				'requires_safeguarding' => (int) $team->requires_safeguarding,
				'is_active'             => 1,
				'leader_user_id'        => (int) $team->leader_user_id,
			)
		);

		$a->same( $before, Teams::keyword_list( Teams::get_by_slug( 'production' ) ), 'restored for the next run' );
	}
);

test(
	'speaking several languages is not evidence for any particular team',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		/*
		 * Found by giving the demo people real languages and watching unrelated
		 * teams climb. "Speaks Tagalog, English" was filed as an abilities
		 * reason, and it fired the same way for all sixteen teams — so every
		 * multilingual person gained the abilities dimension everywhere, whether
		 * a single ability of theirs matched or not. That inflates the dimension
		 * count ranking sorts on, and helps satisfy the two-dimension
		 * requirement for "Strong match" on evidence that says nothing about the
		 * team. It is the same reasoning that keeps personality out of the
		 * reason list.
		 */
		$mono = $f->verified_submission(
			array(
				'languages' => array( 'English' ),
				'profile'   => array( 'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ) ),
			)
		);
		$poly = $f->verified_submission(
			array(
				'languages' => array( 'Tagalog', 'Arabic', 'English' ),
				'profile'   => array( 'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ) ),
			)
		);

		$profile = array( 'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ) );

		// Prayer shares Mercy; Administration shares nothing with this profile.
		foreach ( array( 'prayer', 'administration' ) as $slug ) {
			$team = Teams::get_by_slug( $slug );

			$one  = Matching::explain( $team, $profile );
			$many = Matching::explain( $team, $profile );

			$a->same(
				count( $one['reasons'] ),
				count( $many['reasons'] ),
				"{$slug}: three languages add no reasons a single one does not"
			);
			$a->same(
				(int) $one['dimensions_hit'],
				(int) $many['dimensions_hit'],
				"{$slug}: nor a SHAPE dimension"
			);
			$a->same(
				$one['strength'],
				$many['strength'],
				"{$slug}: nor any strength the evidence does not support"
			);
		}

		// And nothing claims it as a reason on a team that has no language need.
		$labels = array_column(
			Matching::explain( Teams::get_by_slug( 'administration' ), $profile )['reasons'],
			'label'
		);
		foreach ( $labels as $label ) {
			$a->lacks( 'Speaks', $label, 'no team is offered a language as its reason' );
		}
	}
);

/*
 * Suggestions are strong matches only.
 *
 * "Possible" was never a claim that the person and the team fit; it is the
 * ranking saying it found something and not enough of it. Putting that in front
 * of a ministry leader as a suggestion spends their attention on the matches
 * least likely to be right, and gives the person an expectation of a
 * conversation nobody is going to start.
 */

test(
	'a possible match is never suggested, and never opens a profile',
	function ( Assert $a, Fixtures $f ) {
		// One gift and nothing behind it. Strong needs two shared gifts and a
		// second S.H.A.P.E. dimension, so this can only ever be possible.
		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
			'abilities'      => array( 'Counting ability' ),
		);

		$ranked = Matching::rank_profile( $profile );
		$a->ok( count( $ranked ) > 0, 'the ranking still finds teams' );
		$a->same(
			0,
			count( array_filter( $ranked, static fn( $m ) => 'strong' === $m['strength'] ) ),
			'none of them reaching strong'
		);

		// So nothing is suggested, and nothing is stored.
		$a->same( array(), Matching::suggestions_for_profile( $profile ), 'no suggestions' );
		$a->same( array(), Matching::slugs_for_profile( $profile ), 'and no team is recorded' );

		/*
		 * The evidence is not thrown away. rank_profile is the explanation and
		 * still answers, so a pastor reading the profile can see what was
		 * considered and why it fell short.
		 */
		$a->ok( ! empty( $ranked[0]['reasons'] ), 'the reasons survive for whoever reads the profile' );
	}
);

test(
	'a strong match below the top three is still suggested',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The ranking orders by evidence, not by strength, so a person's only
		 * strong matches can sit below possible ones. Filtering the top three
		 * would throw them away and leave the person with nothing at all --
		 * which is what happened to a real profile in the pilot data before
		 * this was written the other way round.
		 */
		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Faith', 'Leadership', 'Service' ) ),
			'abilities'      => array(
				'Interview ability', 'Researching ability', 'Graphics ability', 'Athletic ability',
				'Teaching ability', 'Repairing ability', 'Promoting ability', 'Welcoming ability',
				'Musical ability',
			),
			'heart'          => array(
				'roles'  => array( 'DESIGN/DEVELOP', 'PIONEER', 'SERVE/HELP', 'INFLUENCE', 'REPAIR', 'LEAD/BE IN CHARGE', 'FOLLOW THE RULES' ),
				'people' => array( 'Jr. High Students', 'Elementary Children', 'Men' ),
				'causes' => array( 'Abuse/Violence', 'Financial Management', 'Blindness', 'Law and/or Justice System', 'Health and/or Fitness' ),
			),
		);

		$suggestions = Matching::suggestions_for_profile( $profile );

		/*
		 * Asserted as the invariant rather than against a hand-tuned fixture:
		 * every strong match in the complete ranking, up to the limit, is
		 * suggested. A fixture pinned to one exact ordering would stop testing
		 * anything the first time somebody edits a team's vocabulary.
		 */
		$complete = Matching::rank_profile( $profile, PHP_INT_MAX );
		$expected = array_slice(
			array_column(
				array_values( array_filter( $complete, static fn( $m ) => 'strong' === $m['strength'] ) ),
				'team_slug'
			),
			0,
			Matching::suggestion_limit()
		);

		$a->ok( count( $expected ) > 0, 'this profile does have strong matches somewhere in the ranking' );
		$a->same( $expected, array_column( $suggestions, 'team_slug' ), 'and every one of them is suggested' );

		// The cut happens after the filter, never before it.
		$capped     = Matching::rank_profile( $profile );
		$from_top   = array_filter( $capped, static fn( $m ) => 'strong' === $m['strength'] );
		$a->ok(
			count( $suggestions ) >= count( $from_top ),
			'filtering the capped ranking could only ever have found fewer'
		);

		foreach ( $suggestions as $suggestion ) {
			$a->same( 'strong', $suggestion['strength'], 'each one strong' );
		}

		$a->ok(
			count( $suggestions ) <= Matching::suggestion_limit(),
			'still no more than the limit'
		);
	}
);

test(
	'no more teams are suggested than the limit allows',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * Broad, genuine evidence across several teams. Without the cap this
		 * profile would hand its answers to six ministry leaders rather than
		 * three, and `suggested_teams` is what decides who may open it -- so
		 * the limit is an access-control bound, not a display preference.
		 */
		$profile = array(
			'spiritualGifts' => array( 'likely' => array( 'Mercy', 'Hospitality', 'Service', 'Leadership' ) ),
			'abilities'      => array( 'Feeding ability', 'Welcoming ability', 'Planning ability', 'Managing ability', 'Recall ability' ),
			'heart'          => array(
				'roles'  => array( 'SERVE/HELP', 'ORGANIZE', 'LEAD/BE IN CHARGE' ),
				'people' => array( 'Older Adults 60+', 'Families', 'Men' ),
				'causes' => array( 'Fellowship', 'Homelessness', 'Illness and/or Injury' ),
			),
		);

		$all_strong = array_filter(
			Matching::rank_profile( $profile, PHP_INT_MAX ),
			static fn( $m ) => 'strong' === $m['strength']
		);

		$a->ok(
			count( $all_strong ) > Matching::suggestion_limit(),
			'this profile genuinely matches more teams than may be suggested'
		);

		$a->same(
			Matching::suggestion_limit(),
			count( Matching::suggestions_for_profile( $profile ) ),
			'and it is cut to the limit'
		);

		$a->same(
			Matching::suggestion_limit(),
			count( Matching::slugs_for_profile( $profile ) ),
			'so no more than that many teams are given access'
		);
	}
);

test(
	"the leader's panel is the same strong matches as everything else",
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The drawer heading says "Suggested teams", so it is a suggestion
		 * surface and answers to the same rule. It used to call the full
		 * ranking, which would have shown a leader possible matches the person
		 * was never told about and no placement row exists for.
		 */
		$id = $f->submission(
			array(
				'profile' => array(
					'spiritualGifts' => array( 'likely' => array( 'Administration', 'Leadership', 'Wisdom' ) ),
					'abilities'      => array( 'Counting ability', 'Classifying ability', 'Editing ability' ),
				),
			)
		);

		global $wpdb;
		$submission = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT * FROM ' . Schema::table( 'submissions' ) . ' WHERE id = %d',
				$id
			)
		);
		$a->ok( null !== $submission, 'the submission is there to read' );

		$profile = json_decode( (string) $submission->profile_json, true ) ?: array();
		$panel   = Matching::for_submission( $submission, $profile );

		$a->ok( count( $panel ) > 0, 'the leader is shown something' );

		foreach ( $panel as $match ) {
			$a->same( 'strong', $match['strength'], 'and every row of it is a strong match' );
		}

		// The same teams the person was shown and the same ones placed.
		$a->same(
			array_column( Matching::suggestions_for_profile( $profile ), 'team_slug' ),
			array_column( $panel, 'team_slug' ),
			'identical to what the person was shown'
		);
	}
);
