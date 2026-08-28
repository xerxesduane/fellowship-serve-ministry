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
		'likely'      => array( 'Leadership', 'Teaching', 'Vision' ),
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
		'likely'      => array( 'Organization', 'Assisting' ),
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
		'likely'      => array( 'Creativity', 'Faith' ),
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
		'likely'      => array( 'Prayer', 'Discernment', 'Mercy' ),
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
$created = 0;
$skipped  = 0;

foreach ( $people as $person ) {
	/*
	 * Re-runnable. Without this a second run silently doubles every demo
	 * person, and the duplicates are indistinguishable from the originals in
	 * the dashboard.
	 */
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
			'SELECT id FROM ' . Schema::table( 'submissions' ) . ' WHERE email = %s',
			$person['email']
		)
	);

	if ( $exists ) {
		\WP_CLI::log( "Already seeded, skipping: {$person['name']}" );
		++$skipped;
		continue;
	}

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

	$profile = array(
		'spiritualGifts' => array(
			'likely'   => $person['likely'],
			'possible' => array( 'Service', 'Giving' ),
			'unlikely' => array( 'Healing' ),
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
		 * What the assessment itself would have produced: its gift-only top
		 * picks. Left as the person's own teams on purpose — the dashboard
		 * ranks every team now, and the difference between these and what a
		 * leader sees is exactly what the "not on their profile" flag marks.
		 */
		'recommendedMinistries' => array_map(
			static function ( $slug ) {
				$team = Teams::get_by_slug( $slug );

				return array(
					'ministry'     => $team ? $team->name : $slug,
					'matchedGifts' => $team ? array_slice( Teams::gift_list( $team ), 0, 3 ) : array(),
				);
			},
			$person['teams']
		),
	);

	$now = current_time( 'mysql', true );

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
			'safeguarding_status' => Safeguarding::initial_status( $person['teams'] ),
			'next_action_at'      => gmdate( 'Y-m-d', strtotime( $person['due'] ) ),
			'submitted_at'        => $now,
			'updated_at'          => $now,
			'verified_at'         => $now,
		)
	);

	$submission_id = (int) $wpdb->insert_id;
	Consent::record( $submission_id );

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
				'notes'         => '',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
	}

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
	"Seeded {$created} demo submissions ({$skipped} already present) and capacity for "
	. count( $capacity ) . ' teams.'
);
