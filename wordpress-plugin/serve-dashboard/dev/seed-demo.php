<?php
/**
 * Seeds illustrative submissions so the dashboard can be reviewed with content
 * in it. Development only.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/serve-dashboard/dev/seed-demo.php
 *
 * Every name below is invented. Nothing here should ever run on production.
 *
 * Each person carries their own heart, abilities, personality and experience.
 * They used not to: one hardcoded profile was shared by all five, so every demo
 * person had the same passions and the same two languages-as-abilities. That
 * was survivable while suggestions were ranked on spiritual gifts alone, and
 * stopped being survivable in 1.17.0 — matching now reads all five dimensions,
 * and identical answers made the same team lead the list for everybody. Demo
 * data that cannot show the feature is worse than no demo data, because it is
 * what gets shown to leadership.
 *
 * The values are taken from the assessment's own option labels in
 * public/assessment/shapeContent.js. That matters: matching compares a person's
 * wording against each team's vocabulary, so invented labels — the personality
 * values here used to be "Relational" and "Structured", which the workbook has
 * never offered — quietly match nothing at all.
 *
 * @package ServeDashboard
 */

// No declare(strict_types=1) here: `wp eval-file` eval()s this file, and a
// strict_types declaration must be the first statement in a script.

namespace Serve_Dashboard;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "This script is for WP-CLI only.\n" );
}

$people = array(
	/*
	 * Her gifts point at Welcome and Fellowship Kids, which is what the
	 * assessment picked. Her abilities and passions point somewhere neither of
	 * them is: welcoming, remembering faces, and a passion for fellowship are
	 * the Newcomers Pathway almost exactly, and nothing about her spiritual
	 * gifts alone would have surfaced it.
	 */
	array(
		'name'        => 'Aiza Ramos',
		'email'       => 'aiza.demo@example.com',
		'tenure'      => 24,
		'likely'      => array( 'Hospitality', 'Encouragement', 'Mercy' ),
		'teams'       => array( 'welcome', 'fellowship-kids' ),
		'langs'       => array( 'Tagalog', 'English' ),
		'due'         => '-4 days',
		'status'      => Schema::STATUS_SUBMITTED,
		'roles'       => array( 'SERVE/HELP', 'INFLUENCE' ),
		'group'       => array( 'Families', 'Singles' ),
		'causes'      => array( 'Fellowship' ),
		'abilities'   => array( 'Welcoming ability', 'Recall ability', 'Public Relations ability' ),
		'personality' => array( 'Be Extroverted', 'Be Self-expressive', 'Prefer Variety', 'Be Cooperative' ),
		'spiritual'   => array( 'Member of a Small Group', 'Community Outreach' ),
		'work'        => array( 'Business: Service/Customer Relations' ),
		'priority'    => 'Somewhere I can meet people properly rather than in passing.',
		'hours'       => '3-5 hours',
		'timing'      => array( 'Weekend' ),
		'next_step'   => 'Explore a team where welcoming people is the actual job.',
	),

	/*
	 * The opposite case, and worth keeping: everything corroborates the
	 * assessment's own pick. Not every profile should change under the new
	 * ranking, and a demo where every suggestion moves would be its own kind of
	 * misleading.
	 */
	array(
		'name'        => 'Daniel Okonkwo',
		'email'       => 'daniel.demo@example.com',
		'tenure'      => 60,
		'likely'      => array( 'Leadership', 'Teaching', 'Apostle' ),
		'teams'       => array( 'youth-ministry', 'grow-young-adults' ),
		'langs'       => array( 'English' ),
		'due'         => '-1 day',
		'status'      => Schema::STATUS_CONTACTED,
		'roles'       => array( 'LEAD/BE IN CHARGE', 'INFLUENCE' ),
		'group'       => array( 'Jr. High Students', 'High School' ),
		'causes'      => array( 'Education' ),
		'abilities'   => array( 'Teaching ability', 'Athletic ability', 'Recruiting ability' ),
		'personality' => array( 'Be Extroverted', 'Be Self-expressive', 'Prefer Variety', 'Be Competitive' ),
		'spiritual'   => array( 'Serving in a Ministry', 'Led Someone to Christ' ),
		'educational' => array( 'Bachelor Degree' ),
		'work'        => array( 'The Field of Education: Physical Education/Coaching' ),
		'priority'    => 'Teenagers. I have never really wanted to work with anyone else.',
		'hours'       => '6+ hours',
		'timing'      => array( 'Weeknight', 'Weekend' ),
		'next_step'   => 'Confirm the background check, then talk about Youth.',
	),

	/*
	 * Four separate abilities and a cause all pointing at Administration, none
	 * of them a spiritual gift. Under the old ranking her suggestions were
	 * whichever teams shared Organization or Assisting.
	 */
	array(
		'name'        => 'Priya Nair',
		'email'       => 'priya.demo@example.com',
		'tenure'      => 6,
		'likely'      => array( 'Administration', 'Service', 'Wisdom' ),
		'teams'       => array( 'administration', 'events' ),
		'langs'       => array( 'Malayalam', 'Hindi', 'English' ),
		'due'         => '+2 days',
		'status'      => Schema::STATUS_CONVERSATION_BOOKED,
		'roles'       => array( 'ORGANIZE', 'OPERATE/MAINTAIN' ),
		'group'       => array( 'Older Adults 60+' ),
		'causes'      => array( 'Financial Management' ),
		'abilities'   => array( 'Counting ability', 'Classifying ability', 'Planning ability', 'Evaluating ability' ),
		'personality' => array( 'Be Introverted', 'Be Self-controlled', 'Prefer Routine', 'Be Cooperative' ),
		'spiritual'   => array( 'Regularly Give Back to God' ),
		'educational' => array( 'Masters Degree' ),
		'work'        => array( 'Business: Financial Services' ),
		'priority'    => 'Behind the scenes. I would rather the work showed than I did.',
		'hours'       => '1-2 hours',
		'timing'      => array( 'Weekday daytime' ),
		'next_step'   => 'A conversation about what the books actually need.',
	),

	/*
	 * Two teams, each corroborated by a different ability — musical for Worship,
	 * audio for Production — so the two suggestions are distinguishable rather
	 * than both resting on Creativity.
	 */
	array(
		'name'        => 'Marcus Vance',
		'email'       => 'marcus.demo@example.com',
		'tenure'      => 12,
		'likely'      => array( 'Faith', 'Encouragement', 'Discernment' ),
		'teams'       => array( 'worship', 'production' ),
		'langs'       => array( 'English' ),
		'due'         => '+9 days',
		'status'      => Schema::STATUS_TRIAL_SERVE,
		'roles'       => array( 'PERFORM', 'DESIGN/DEVELOP' ),
		'group'       => array( 'College/Career' ),
		'causes'      => array( 'Worship' ),
		'abilities'   => array( 'Musical ability', 'Artistic ability', 'Technical ability: Audio/Technical Support' ),
		'personality' => array( 'Be Introverted', 'Be Self-expressive', 'Prefer Variety', 'Be Cooperative' ),
		'spiritual'   => array( 'Baptized', 'Regularly Pray' ),
		'work'        => array( 'Creative Arts: Graphic Design' ),
		'priority'    => 'Anywhere I can play. I do not need to be at the front.',
		'hours'       => '3-5 hours',
		'timing'      => array( 'Weekend' ),
		'next_step'   => 'Two Sundays on the desk before anyone decides anything.',
	),

	/*
	 * Her painful experience is the one sensitive field in this data, so it also
	 * demonstrates the redaction: a ministry leader without
	 * serve_view_sensitive sees the Experience section withheld rather than
	 * empty. Her passion for women surfaces Women Connect, which the assessment
	 * never suggested.
	 */
	array(
		'name'        => 'Sara Haddad',
		'email'       => 'sara.demo@example.com',
		'tenure'      => 36,
		'likely'      => array( 'Healing', 'Discernment', 'Mercy' ),
		'teams'       => array( 'prayer' ),
		'langs'       => array( 'Arabic', 'French', 'English' ),
		'due'         => '-11 days',
		'status'      => Schema::STATUS_SUBMITTED,
		'roles'       => array( 'PERSEVERE', 'SERVE/HELP' ),
		'group'       => array( 'Women' ),
		'causes'      => array( 'Illness and/or Injury', 'Disabilities and/or Support' ),
		'abilities'   => array( 'Counseling/Encouraging ability', 'Interview ability' ),
		'personality' => array( 'Be Introverted', 'Be Self-controlled', 'Prefer Routine', 'Be Cooperative' ),
		'spiritual'   => array( 'Regularly Pray', 'Cross Cultural Ministry' ),
		'painful'     => array( 'Extended Physical Illness/Injury' ),
		'priority'    => 'Praying with people who are in the middle of something hard.',
		'hours'       => '1-2 hours',
		'timing'      => array( 'Weekday daytime' ),
		'next_step'   => 'Ask whether she would rather pray with people or for them.',
	),
	/*
	 * The end of the pipeline, which nobody demonstrated.
	 *
	 * Somebody actually serving. Without a placed person the dashboard can show
	 * every stage except the one the whole thing is for, and "Serving now" reads
	 * as zero on every screenshot taken of it.
	 */
	array(
		'name'        => 'Ruth Adeyemi',
		'email'       => 'ruth.demo@example.com',
		'tenure'      => 84,
		'likely'      => array( 'Administration', 'Service', 'Giving' ),
		'teams'       => array( 'administration' ),
		'langs'       => array( 'English' ),
		'due'         => '+26 days',
		'status'      => Schema::STATUS_PLACED,
		'roles'       => array( 'ORGANIZE', 'OPERATE/MAINTAIN' ),
		'group'       => array( 'Older Adults 60+' ),
		'causes'      => array( 'Financial Management' ),
		'abilities'   => array( 'Counting ability', 'Classifying ability', 'Planning ability' ),
		'personality' => array( 'Be Introverted', 'Be Self-controlled', 'Prefer Routine', 'Be Cooperative' ),
		'spiritual'   => array( 'Serving in a Ministry', 'Regularly Give Back to God' ),
		'work'        => array( 'Business: Accounting' ),
		'priority'    => 'I have done the books for two other churches and would rather keep doing that.',
		'hours'       => '3-5 hours',
		'timing'      => array( 'Weekday daytime' ),
		'next_step'   => 'Settling-in check comes round in a few weeks.',
	),

	/*
	 * A pause with a return date.
	 *
	 * The one stage that quietly rots a queue if it is handled badly, so it is
	 * worth being able to see what a well-handled one looks like: a date, and a
	 * reason somebody wrote down.
	 */
	array(
		'name'        => 'Tomás Ferreira',
		'email'       => 'tomas.demo@example.com',
		'tenure'      => 8,
		'likely'      => array( 'Teaching', 'Encouragement', 'Leadership' ),
		'teams'       => array( 'grow-compass' ),
		'langs'       => array( 'Portuguese', 'English' ),
		'due'         => '+45 days',
		'status'      => Schema::STATUS_PAUSED,
		'roles'       => array( 'IMPROVE', 'INFLUENCE' ),
		'group'       => array( 'Young Marrieds', 'Men' ),
		'causes'      => array( 'Education', 'Families/Marriage' ),
		'abilities'   => array( 'Teaching ability', 'Writing ability', 'Researching ability' ),
		'personality' => array( 'Be Extroverted', 'Be Self-expressive', 'Prefer Variety', 'Be Cooperative' ),
		'spiritual'   => array( 'Member of a Small Group', 'Regularly Read the Bible' ),
		'work'        => array( 'Education: Teacher' ),
		'priority'    => 'Yes, but our second child is due in March.',
		'hours'       => '1-2 hours',
		'timing'      => array( 'Weeknight' ),
		'next_step'   => 'Paused until after the baby. Ask again in the spring.',
	),

	/*
	 * Nothing reached a strong or suggested match.
	 *
	 * The state the software is most easily accused of getting wrong, and the
	 * one no demo person showed: real, complete answers that simply do not
	 * concentrate on any one team. Her row carries the "no team matched" flag,
	 * her Teams column is empty on purpose, and the catch-all team owns the
	 * first conversation instead of a pastor carrying her alone.
	 *
	 * Worth keeping exactly as it is. A demo where everybody matches something
	 * is a demo of a matcher that always says yes.
	 */
	array(
		'name'        => 'Hannah Whitfield',
		'email'       => 'hannah.demo@example.com',
		'tenure'      => 3,
		'likely'      => array( 'Giving', 'Miracles' ),
		'teams'       => array(),
		'langs'       => array( 'English' ),
		'due'         => '-1 days',
		'status'      => Schema::STATUS_SUBMITTED,
		'roles'       => array( 'PERSEVERE' ),
		'group'       => array( 'Singles' ),
		'causes'      => array( 'Sanctity of Life' ),
		'abilities'   => array( 'Athletic ability' ),
		'personality' => array( 'Be Introverted', 'Be Self-controlled', 'Prefer Variety', 'Be Competitive' ),
		'spiritual'   => array( 'Baptized' ),
		'priority'    => 'I am not sure where I would fit, which is why I filled this in.',
		'hours'       => '1-2 hours',
		'timing'      => array( 'Weekend' ),
		'next_step'   => 'No team matched. Worth a conversation before suggesting anything.',
	),
);


/*
 * Keys as the assessment writes them, so the profile a leader opens has the
 * same section headings a real submission would.
 */
$experience_prompts = array(
	'spiritual'   => 'Select Your Spiritual Experiences',
	'painful'     => 'Painful Experiences (I can relate to someone who is/or has gone through…)',
	'educational' => 'Select Your Educational Experiences',
	'work'        => 'Select Your Work Experiences',
);

global $wpdb;
$created   = 0;
$refreshed = 0;

foreach ( $people as $person ) {
	$experiences = array();
	foreach ( $experience_prompts as $key => $prompt ) {
		if ( ! empty( $person[ $key ] ) ) {
			$experiences[ $prompt ] = $person[ $key ];
		}
	}

	// Languages are an ability in the workbook, and the only one the dashboard
	// derives rather than reads, so they are appended rather than typed twice.
	$abilities = array_merge(
		$person['abilities'],
		array_map(
			static fn( $lang ) => 'Linguistic ability: ' . $lang,
			$person['langs']
		)
	);

	/*
	 * A complete gift partition, like a real one.
	 *
	 * The assessment requires an answer to every one of the eighteen gifts, so
	 * a profile naming three "likely" and two "possible" and leaving the rest
	 * absent is a shape no participant can produce. Matching validates the
	 * partition now and caps an incomplete one at "explore with an advisor" —
	 * correctly — so seeding flat profiles would make every demo person look
	 * like a broken result. Whatever is not named is unlikely, which is what
	 * the workbook means by the third option.
	 */
	$likely_labels = $person['likely'];

	/*
	 * The stock "possible" pair, minus anything this person already marked
	 * likely. One gift cannot be two answers, and the assessment has no way to
	 * express it — so leaving the overlap in produced an invalid partition,
	 * which the matcher correctly refuses to draw a strong result from. Caught
	 * by giving somebody Service as a likely gift and watching her drop to
	 * "explore with an advisor" for no visible reason.
	 */
	$likely_ids      = array_filter( array_map( array( Gift_Taxonomy::class, 'id_for_label' ), $likely_labels ) );
	$possible_labels = array_values(
		array_filter(
			array( 'Service', 'Giving' ),
			static fn( $label ) => ! in_array( Gift_Taxonomy::id_for_label( $label ), $likely_ids, true )
		)
	);

	/*
	 * Resolved to ids before the remainder is worked out.
	 *
	 * Comparing labels was not enough: "Organization" is the assessment's
	 * alternate name for Administration, so naming it as likely while
	 * "Administration" fell into the unlikely remainder put one gift in two
	 * buckets — an invalid partition, which the matcher correctly refuses to
	 * produce a strong result from. Gift_Ratings reported it; this is the
	 * seeder's side of the same fix.
	 */
	$named = array();
	foreach ( array_merge( $likely_labels, $possible_labels ) as $label ) {
		$id = Gift_Taxonomy::id_for_label( (string) $label );
		if ( '' === $id ) {
			\WP_CLI::warning( "Not one of the 18 assessed gifts, so no participant could answer it: {$label}" );
			continue;
		}
		$named[ $id ] = true;
	}

	$rest = array();
	foreach ( Gift_Taxonomy::gifts() as $id => $gift ) {
		if ( ! isset( $named[ $id ] ) ) {
			$rest[] = $gift['label'];
		}
	}

	$profile = array(
		'spiritualGifts' => array(
			'likely'   => $likely_labels,
			'possible' => $possible_labels,
			'unlikely' => $rest,
		),
		'heart'          => array(
			'roles'  => $person['roles'],
			'people' => $person['group'],
			'causes' => $person['causes'],
		),
		'abilities'      => $abilities,
		'personality'    => $person['personality'],
		'experiences'    => $experiences,
		'availability'   => array(
			'priority' => $person['priority'],
			'hours'    => $person['hours'],
			'timing'   => $person['timing'],
		),
		'recommendedNextStep' => $person['next_step'],

		/*
		 * `recommendedMinistries` is gone.
		 *
		 * The browser stopped producing it when ranking moved to the server,
		 * and sanitize_profile() has never stored it — so seeding it wrote a
		 * field nothing reads, into demo rows whose whole purpose is to look
		 * like real ones. What the person was shown lives in the match
		 * snapshot now, written by Submissions::create() from these answers.
		 */
	);

	$now = current_time( 'mysql', true );

	/*
	 * Re-runnable, and it refreshes rather than skips.
	 *
	 * Skipping was the first version and left demo rows frozen at whatever the
	 * seeder said the day they were created — which is exactly the state that
	 * made the flat profiles survive into 1.17.0 unnoticed. An existing person
	 * has their *answers* rewritten and nothing else: status, follow-up date,
	 * placements and notes are left alone, because a developer part-way through
	 * working the pipeline should not have it reset underneath them.
	 */
	$existing = (int) $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
			'SELECT id FROM ' . Schema::table( 'submissions' ) . ' WHERE email = %s',
			$person['email']
		)
	);

	if ( $existing ) {
		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'gifts_likely'   => wp_json_encode( $person['likely'] ),
				'languages'      => wp_json_encode( $person['langs'] ),
				'profile_json'   => wp_json_encode( $profile ),
				// Rewritten with the answers, or a re-run leaves the dashboard
				// showing a snapshot the profile below it no longer supports.
				'match_snapshot' => wp_json_encode( Matching::participant_match_snapshot( $profile ) ),
				'match_version'  => Matching_Contract::VERSION . '/' . Gift_Crosswalk::VERSION,
				'updated_at'     => $now,
			),
			array( 'id' => $existing ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		\WP_CLI::log( "Refreshed answers for: {$person['name']}" );
		++$refreshed;
		continue;
	}

	$wpdb->insert(
		Schema::table( 'submissions' ),
		array(
			'uuid'                => wp_generate_uuid4(),
			'display_name'        => $person['name'],
			'email'               => $person['email'],
			'phone'               => '+971 50 000 0000',
			'status'              => $person['status'],
			'tenure_months'       => $person['tenure'],
			'gifts_likely'        => wp_json_encode( $person['likely'] ),
			'languages'           => wp_json_encode( $person['langs'] ),
			'suggested_teams'     => wp_json_encode( $person['teams'] ),
			'profile_json'        => wp_json_encode( $profile ),
			/*
			 * The same snapshot Submissions::create() writes, from the same
			 * answers. Demo rows exist to make the dashboard look like a real
			 * one, and a row with no snapshot renders as "predates the record"
			 * — a state a freshly seeded person cannot be in.
			 */
			'match_snapshot'      => wp_json_encode( Matching::participant_match_snapshot( $profile ) ),
			'match_version'       => Matching_Contract::VERSION . '/' . Gift_Crosswalk::VERSION,
			'safeguarding_status' => Safeguarding::initial_status( $person['teams'] ),
			'next_action_at'      => gmdate( 'Y-m-d', strtotime( $person['due'] ) ),
			'submitted_at'        => $now,
			'updated_at'          => $now,
			'verified_at'         => $now,
		)
	);

	$submission_id = (int) $wpdb->insert_id;
	Consent::record( $submission_id );

	/*
	 * Placement rows stand for a decision a coordinator took, not for a match.
	 *
	 * The seeder wrote them with no source at all, which defaulted to `match` —
	 * so seeded data demonstrated exactly the behaviour this release removed: a
	 * ranking handing a ministry leader somebody's profile. A developer looking
	 * at the dashboard to understand the model would have been shown the old
	 * one.
	 *
	 * These people are further along the pipeline than a fresh submission, so
	 * they have been triaged; that is what the rows say.
	 */
	foreach ( $person['teams'] as $slug ) {
		$team = Teams::get_by_slug( $slug );
		if ( ! $team ) {
			\WP_CLI::warning( "Unknown team slug: {$slug}" );
			continue;
		}

		$wpdb->insert(
			Schema::table( 'placements' ),
			array(
				'submission_id' => $submission_id,
				'team_id'       => (int) $team->id,
				'status'        => $person['status'],
				'source'        => Placements::SOURCE_INTAKE_TRIAGE,
				'notes'         => '',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
	}

	/*
	 * The owner a real unmatched submission gets.
	 *
	 * This seeder inserts its rows directly rather than going through
	 * Submissions::create(), so it skipped the one thing create() does for
	 * somebody nothing matched: hand them to the catch-all team. A demo person
	 * with no teams was therefore visible to pastors and nobody else, which is
	 * the behaviour from before the catch-all existed, and the screen showed the
	 * old answer while the code did something else.
	 */
	Placements::assign_catchall( $submission_id, $person['teams'] );

	++$created;
}

// Give a few teams real capacity numbers so the gap panel has something to say.
$capacity = array(
	'welcome'         => array( 12, 8, 6 ),
	'fellowship-kids' => array( 20, 14, 9 ),
	'production'      => array( 8, 6, 7 ),
	'worship'         => array( 14, 10, 13 ),
	'prayer'          => array( 10, 6, 4 ),
);

foreach ( $capacity as $slug => list( $target, $min, $current ) ) {
	$team = Teams::get_by_slug( $slug );
	if ( ! $team ) {
		continue;
	}

	$wpdb->update(
		Schema::table( 'teams' ),
		array(
			'target_headcount'  => $target,
			'min_headcount'     => $min,
			'current_headcount' => $current,
		),
		array( 'id' => (int) $team->id )
	);
}

\WP_CLI::success(
	"Seeded {$created} demo submissions, refreshed {$refreshed}, and set capacity for "
	. count( $capacity ) . ' teams.'
);
