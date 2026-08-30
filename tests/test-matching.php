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
use Serve_Dashboard\Matching_Contract;
use Serve_Dashboard\Placements;
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
		$profile = Fixtures::gift_profile(
			array( 'mercy', 'hospitality' ),
			array(),
			array(
				'heart' => array( 'roles' => array(), 'people' => array(), 'causes' => array() ),
				'abilities' => array(),
				'personality' => array( 'Be Extroverted', 'Be Self-expressive', 'Prefer Variety', 'Be Cooperative' ),
			)
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
		$a->same( Matching_Contract::TIER_SUGGESTED, $match['tier'], 'it stays a suggestion, not a strong gift match' );

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
	'abilities are shown as context and can no longer reach a tier or lead the list',
	function ( Assert $a, Fixtures $f ) {
		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		/*
		 * This test used to assert the opposite, and the opposite was the
		 * defect.
		 *
		 * Five ability keywords matching a team's editable vocabulary produced
		 * five reasons, five reasons produced a second SHAPE dimension, and
		 * that was enough to reach the old "Strong match" — which wrote
		 * suggested_teams, created a placement row, and opened the profile to
		 * that team's leader. An administrator typing words into a text field
		 * on the Teams screen was making an access-control decision, and
		 * nothing said so.
		 *
		 * Free-text overlap is unvalidated evidence. It is still shown, under
		 * `context`, because "a heart for Elementary Children" is worth a
		 * leader seeing next to Fellowship Kids — but it carries no weight in
		 * a tier, no weight in the ordering, and it grants nothing.
		 */
		$id         = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		$submission = Submissions::get( $id );

		$profile = Fixtures::gift_profile(
			array( 'mercy' ),
			array(),
			array(
				'abilities' => array(
					'Counting ability',
					'Classifying ability',
					'Evaluating ability',
					'Editing ability',
					'Writing ability',
				),
			)
		);

		$admin = null;
		foreach ( Matching::rank_profile( $profile, PHP_INT_MAX ) as $match ) {
			if ( 'Administration' === $match['team_name'] ) {
				$admin = $match;
			}
		}

		$a->ok( null !== $admin, 'the team is still considered' );
		$a->same( 0, (int) $admin['evidence']['likely_hit_count'], 'not one spiritual gift overlaps it' );
		$a->same( 0, count( $admin['reasons'] ), 'and the abilities produce no scored reason' );
		$a->same( 5, count( $admin['context'] ), 'they are kept as context for the conversation' );

		// Context alone is not a recommendation, and cannot become one.
		$a->same( Matching_Contract::TIER_NONE, $admin['tier'], 'so the team reaches no tier at all' );
		$a->not(
			in_array( 'administration', Matching::slugs_for_profile( $profile ), true ),
			'nothing is recorded against them from vocabulary alone'
		);

		// And the ranking is not led by it.
		$names = array_column( Matching::rank( $submission, $profile ), 'team_name' );
		$a->not( 'Administration' === ( $names[0] ?? '' ), 'nor does it lead the list' );
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

		$profile = Fixtures::gift_profile(
			array( 'administration' ),
			array(),
			array(
				'abilities' => array( 'Counting ability', 'Classifying ability' ),
			)
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

		$profile = Fixtures::gift_profile(
			array(),
			array(),
			array(
				'heart' => array(
					'people' => array( 'Women' ),
					'roles'  => array(),
					'causes' => array(),
				),
			)
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

		$profile = Fixtures::gift_profile(
			array( 'mercy' ),
			array(),
			array(
				'abilities' => array( 'Counting ability', 'Classifying ability', 'Evaluating ability', 'Editing ability', 'Writing ability' ),
			)
		);

		$before = array_column( Matching::rank( $submission, $profile ), 'team_name' );

		// Open a large hole in a team the evidence does not support at all.
		$f->set_team_capacity( 'prayer', 40, 1 );

		$after = array_column( Matching::rank( $submission, $profile ), 'team_name' );

		$a->same( $before, $after, 'the order is identical with a gaping vacancy in play' );

		/*
		 * Prayer is where the gift evidence points and where the vacancy was
		 * opened, so it is the sharpest available test that need changes
		 * nothing: it neither rose nor fell for having forty empty places.
		 */
		$a->same(
			array_search( 'Prayer', $before, true ),
			array_search( 'Prayer', $after, true ),
			'and the team with the hole in it did not move'
		);
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

		$profile = Fixtures::gift_profile(
			array( 'mercy' ),
			array(),
			array(
				'abilities' => array( 'Counting ability', 'Classifying ability', 'Evaluating ability', 'Editing ability', 'Writing ability' ),
			)
		);

		$ranked = array_column( Matching::rank( $submission, $profile ), 'team_name' );
		$a->ok( count( $ranked ) > 0, 'the profile ranks against something' );

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
		 * And the mechanism itself. Visibility comes from placement rows, and
		 * no row is created from a ranking any more — not from the wider
		 * ranking, and not from suggested_teams either. Intake writes one
		 * central owner and a human assigns from there, so there is nothing
		 * here for a suggestion to widen.
		 */
		global $wpdb;
		$sources = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				'SELECT source FROM ' . Schema::table( 'placements' ) . ' WHERE submission_id = %d',
				$id
			)
		);

		$a->not(
			in_array( Placements::SOURCE_MATCH, (array) $sources, true ),
			'no placement row is sourced to the matcher'
		);
		$a->same(
			array( Placements::SOURCE_CATCHALL ),
			array_values( array_unique( (array) $sources ) ),
			'only the central intake owner exists'
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
				'gifts_likely'    => array( 'Administration' ),
				'profile'         => Fixtures::gift_profile(
					array( 'administration' ),
					array(),
					array(
						'heart' => array(
							'people' => array( 'Elementary Children' ),
							'roles'  => array(),
							'causes' => array(),
						),
					)
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
		/*
		 * Under `context` rather than `reasons`. Heart, abilities and
		 * experience are shown to whoever may see the profile and carry no
		 * weight in a tier — but a passion for Elementary Children is still
		 * exactly why a pastor should look at this person for Fellowship Kids,
		 * so discovery keeps it.
		 */
		$a->contains( 'Elementary Children', $found['match']['context'][0]['label'], 'their own words quoted as the reason' );
		$a->same( 0, count( $found['match']['reasons'] ), 'and it is not counted as gift evidence' );

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
		$profile    = Fixtures::gift_profile( array( 'administration' ) );

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
				'profile'   => Fixtures::gift_profile( array( 'mercy' ) ),
			)
		);
		$poly = $f->verified_submission(
			array(
				'languages' => array( 'Tagalog', 'Arabic', 'English' ),
				'profile'   => Fixtures::gift_profile( array( 'mercy' ) ),
			)
		);

		$profile = Fixtures::gift_profile( array( 'mercy' ) );

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
		$profile = Fixtures::gift_profile(
			array( 'mercy' ),
			array(),
			array(
				'abilities' => array( 'Counting ability' ),
			)
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
		$profile = Fixtures::gift_profile(
			array( 'faith', 'leadership', 'service' ),
			array(),
			array(
				'abilities' => array(
					'Interview ability', 'Researching ability', 'Graphics ability', 'Athletic ability',
					'Teaching ability', 'Repairing ability', 'Promoting ability', 'Welcoming ability',
					'Musical ability',
				),
				'heart' => array(
					'roles'  => array( 'DESIGN/DEVELOP', 'PIONEER', 'SERVE/HELP', 'INFLUENCE', 'REPAIR', 'LEAD/BE IN CHARGE', 'FOLLOW THE RULES' ),
					'people' => array( 'Jr. High Students', 'Elementary Children', 'Men' ),
					'causes' => array( 'Abuse/Violence', 'Financial Management', 'Blindness', 'Law and/or Justice System', 'Health and/or Fitness' ),
				),
			)
		);

		$suggestions = Matching::suggestions_for_profile( $profile );

		/*
		 * Asserted as the invariant rather than against a hand-tuned fixture:
		 * every strong match in the complete ranking, up to the limit, is
		 * suggested. A fixture pinned to one exact ordering would stop testing
		 * anything the first time somebody edits a team's vocabulary.
		 */
		$recommendable = array( Matching_Contract::TIER_STRONG, Matching_Contract::TIER_SUGGESTED );

		$complete = Matching::rank_profile( $profile, PHP_INT_MAX );
		$expected = array_slice(
			array_column(
				array_values(
					array_filter( $complete, static fn( $m ) => in_array( $m['tier'], $recommendable, true ) )
				),
				'team_slug'
			),
			0,
			Matching::suggestion_limit()
		);

		$a->ok( count( $expected ) > 0, 'this profile does reach a recommendable tier somewhere in the ranking' );
		$a->same( $expected, array_column( $suggestions, 'team_slug' ), 'and the best of them are what is recommended' );

		// The cut happens after the filter, never before it.
		$capped   = Matching::rank_profile( $profile );
		$from_top = array_filter( $capped, static fn( $m ) => in_array( $m['tier'], $recommendable, true ) );
		$a->ok(
			count( $suggestions ) >= count( $from_top ),
			'filtering the capped ranking could only ever have found fewer'
		);

		/*
		 * Strong before Suggested, always. The comparator sorts on tier first,
		 * so a suggestion can never displace a strong gift match from the list.
		 */
		$seen_suggested = false;
		foreach ( $suggestions as $suggestion ) {
			$a->ok( in_array( $suggestion['tier'], $recommendable, true ), 'each one clears the bar' );

			if ( Matching_Contract::TIER_SUGGESTED === $suggestion['tier'] ) {
				$seen_suggested = true;
			} elseif ( $seen_suggested ) {
				throw new Failure( 'a strong gift match was listed below a weaker suggestion' );
			}
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
		$profile = Fixtures::gift_profile(
			array( 'mercy', 'hospitality', 'service', 'leadership' ),
			array(),
			array(
				'abilities' => array( 'Feeding ability', 'Welcoming ability', 'Planning ability', 'Managing ability', 'Recall ability' ),
				'heart' => array(
					'roles'  => array( 'SERVE/HELP', 'ORGANIZE', 'LEAD/BE IN CHARGE' ),
					'people' => array( 'Older Adults 60+', 'Families', 'Men' ),
					'causes' => array( 'Fellowship', 'Homelessness', 'Illness and/or Injury' ),
				),
			)
		);

		$all_recommendable = array_filter(
			Matching::rank_profile( $profile, PHP_INT_MAX ),
			static fn( $m ) => in_array(
				$m['tier'],
				array( Matching_Contract::TIER_STRONG, Matching_Contract::TIER_SUGGESTED ),
				true
			)
		);

		$a->ok(
			count( $all_recommendable ) > Matching::suggestion_limit(),
			'this profile genuinely reaches more teams than may be recommended'
		);

		$a->same(
			Matching::suggestion_limit(),
			count( Matching::suggestions_for_profile( $profile ) ),
			'and it is cut to the limit'
		);

		$a->same(
			Matching::suggestion_limit(),
			count( Matching::slugs_for_profile( $profile ) ),
			'and the same cut applies to what is recorded as shown to them'
		);

		/*
		 * The limit used to be an access-control bound, because these slugs
		 * became placement rows. They do not any more — a recommendation grants
		 * nothing — so this is now a display and honesty bound, and the access
		 * guarantee is asserted directly in tests/test-routing.php instead of
		 * being inferred from a count here.
		 */
	}
);

test(
	"the leader's panel is the same strong matches as everything else",
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The drawer's current-analysis panel answers to the same rule as
		 * everything else that recommends a team. It used to call the full
		 * ranking, which would have shown a leader weak matches the person was
		 * never told about.
		 */
		$id = $f->submission(
			array(
				'profile' => Fixtures::gift_profile(
					array( 'administration', 'leadership', 'wisdom' ),
					array(),
					array(
						'abilities' => array( 'Counting ability', 'Classifying ability', 'Editing ability' ),
					)
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
			$a->ok(
				in_array(
					$match['tier'],
					array( Matching_Contract::TIER_STRONG, Matching_Contract::TIER_SUGGESTED ),
					true
				),
				'and every row of it cleared the bar to be recommended'
			);
		}

		// The same teams the person was shown and the same ones placed.
		$a->same(
			array_column( Matching::suggestions_for_profile( $profile ), 'team_slug' ),
			array_column( $panel, 'team_slug' ),
			'identical to what the person was shown'
		);
	}
);

test(
	'the list and the drawer read one record and cannot disagree',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * Three versions of this defect, in order.
		 *
		 * First the list printed the stored `suggested_teams` column while the
		 * drawer ranked the profile live; those agreed until suggestions became
		 * strong-only, at which point every older profile listed teams the
		 * drawer would not show. Then the list ranked live too — which fixed
		 * the disagreement and introduced a worse problem, because ranking in
		 * a list serializer meant decoding profile_json raw, so a team name in
		 * a column an ordinary leader may read could be derived from a painful
		 * Experience they may not.
		 *
		 * Now both read the stored snapshot: what this person was actually
		 * shown, holding team names, tiers and gift labels and nothing
		 * sensitive. One record, no ranking in the list at all, and nothing
		 * that could differ by who is looking.
		 */
		global $wpdb;

		// Stored teams the current rules would not produce, exactly like a
		// profile submitted before the change.
		$id = $f->submission(
			array(
				'suggested_teams' => array( 'production', 'livestream-team' ),
				'profile'         => Fixtures::gift_profile(
					array( 'administration', 'leadership', 'wisdom' ),
					array(),
					array(
						'abilities' => array( 'Counting ability', 'Classifying ability', 'Editing ability' ),
					)
				),
			)
		);
		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'verified_at' => current_time( 'mysql', true ), 'verify_token' => null ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$submission = Submissions::get( $id );
		$snapshot   = json_decode( (string) $submission->match_snapshot, true ) ?: array();
		$shown      = array_column( (array) ( $snapshot['teams'] ?? array() ), 'team_name' );

		$rows = new \ReflectionMethod( \Serve_Dashboard\Rest_Dashboard::class, 'rows' );
		$rows->setAccessible( true );
		$listed = $rows->invoke( null, array( $submission ) );

		$a->same( 1, count( $listed ), 'the row is built' );
		$a->same( $shown, $listed[0]['suggestedTeams'], 'the list prints exactly the stored snapshot' );
		$a->ok( $listed[0]['hasSnapshot'], 'and knows it has one' );

		// Not the legacy column, which still says otherwise and must survive.
		$a->not(
			in_array( 'Production', $listed[0]['suggestedTeams'], true ),
			'not the team the old rules stored'
		);
		$a->same(
			array( 'production', 'livestream-team' ),
			Submissions::decode_list( $submission->suggested_teams ),
			'and the legacy record is left intact'
		);
	}
);

test(
	'a row with no snapshot reads as unrecorded, not as unmatched',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * "Nothing matched" and "this predates the record" are different facts
		 * and used to render identically. A profile from before the snapshot
		 * column existed was produced by a matcher that compared display
		 * strings and could not see ten of the ministry table's terms, so
		 * calling it unmatched would assert something nobody ever decided.
		 */
		global $wpdb;

		$id = $f->submission();
		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'verified_at'    => current_time( 'mysql', true ),
				'verify_token'   => null,
				// Exactly what an upgraded row looks like: the column exists
				// and is empty, because the migration deliberately does not
				// backfill it.
				'match_snapshot' => null,
				'match_version'  => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$rows = new \ReflectionMethod( \Serve_Dashboard\Rest_Dashboard::class, 'rows' );
		$rows->setAccessible( true );
		$listed = $rows->invoke( null, array( Submissions::get( $id ) ) );

		$a->same( array(), $listed[0]['suggestedTeams'], 'no teams are printed' );
		$a->not( $listed[0]['hasSnapshot'], 'the row knows it has no snapshot' );
		$a->not( $listed[0]['unmatched'], 'and does not claim nothing matched' );
	}
);

test(
	'a person nothing matched reads as unmatched in the list',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		// One gift and nothing behind it: explorable at best, never recommended.
		$id = $f->submission(
			array(
				'suggested_teams' => array(),
				'profile'         => Fixtures::gift_profile( array( 'mercy' ) ),
			)
		);
		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'verified_at' => current_time( 'mysql', true ), 'verify_token' => null ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		$rows = new \ReflectionMethod( \Serve_Dashboard\Rest_Dashboard::class, 'rows' );
		$rows->setAccessible( true );
		$listed = $rows->invoke( null, array( Submissions::get( $id ) ) );

		$a->same( array(), $listed[0]['suggestedTeams'], 'no teams are printed' );
		$a->ok( $listed[0]['hasSnapshot'], 'the snapshot exists' );
		$a->ok( $listed[0]['unmatched'], 'and the row says nothing matched' );
	}
);

test(
	'a team is listed under the name the church gave it',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The list title-cased the slug, so GROW - Small Group was printed as
		 * Grow Small Group. Ranking returns the team's actual name.
		 */
		global $wpdb;

		$id = $f->submission(
			array(
				'profile' => Fixtures::gift_profile(
					array( 'administration', 'leadership', 'wisdom' ),
					array(),
					array(
						'abilities' => array( 'Counting ability', 'Classifying ability', 'Editing ability' ),
					)
				),
			)
		);
		$wpdb->update(
			Schema::table( 'submissions' ),
			array( 'verified_at' => current_time( 'mysql', true ), 'verify_token' => null ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_set_current_user( $f->user( \Serve_Dashboard\Roles::ROLE_PASTOR ) );

		$rows = new \ReflectionMethod( \Serve_Dashboard\Rest_Dashboard::class, 'rows' );
		$rows->setAccessible( true );
		$listed = $rows->invoke( null, array( \Serve_Dashboard\Submissions::get( $id ) ) );

		$names = array_column( \Serve_Dashboard\Teams::all(), 'name' );

		foreach ( $listed[0]['suggestedTeams'] as $shown ) {
			$a->ok( in_array( $shown, $names, true ), "{$shown} is a real team name" );
		}
	}
);
