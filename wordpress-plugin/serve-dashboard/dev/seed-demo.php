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
 * @package ServeDashboard
 */

// No declare(strict_types=1) here: `wp eval-file` eval()s this file, and a
// strict_types declaration must be the first statement in a script.

namespace Serve_Dashboard;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "This script is for WP-CLI only.\n" );
}

$people = array(
	array(
		'name'     => 'Aiza Ramos',
		'email'    => 'aiza.demo@example.com',
		'tenure'   => 24,
		'likely'   => array( 'Hospitality', 'Encouragement', 'Mercy' ),
		'teams'    => array( 'welcome', 'fellowship-kids' ),
		'langs'    => array( 'Tagalog', 'English' ),
		'due'      => '-4 days',
		'status'   => Schema::STATUS_SUBMITTED,
	),
	array(
		'name'     => 'Daniel Okonkwo',
		'email'    => 'daniel.demo@example.com',
		'tenure'   => 60,
		'likely'   => array( 'Leadership', 'Teaching', 'Vision' ),
		'teams'    => array( 'youth-ministry', 'grow-young-adults' ),
		'langs'    => array( 'English' ),
		'due'      => '-1 day',
		'status'   => Schema::STATUS_CONTACTED,
	),
	array(
		'name'     => 'Priya Nair',
		'email'    => 'priya.demo@example.com',
		'tenure'   => 6,
		'likely'   => array( 'Organization', 'Assisting' ),
		'teams'    => array( 'administration', 'events' ),
		'langs'    => array( 'Malayalam', 'Hindi', 'English' ),
		'due'      => '+2 days',
		'status'   => Schema::STATUS_CONVERSATION_BOOKED,
	),
	array(
		'name'     => 'Marcus Vance',
		'email'    => 'marcus.demo@example.com',
		'tenure'   => 12,
		'likely'   => array( 'Creativity', 'Faith' ),
		'teams'    => array( 'worship', 'production' ),
		'langs'    => array( 'English' ),
		'due'      => '+9 days',
		'status'   => Schema::STATUS_TRIAL_SERVE,
	),
	array(
		'name'     => 'Sara Haddad',
		'email'    => 'sara.demo@example.com',
		'tenure'   => 36,
		'likely'   => array( 'Prayer', 'Discernment', 'Mercy' ),
		'teams'    => array( 'prayer' ),
		'langs'    => array( 'Arabic', 'French', 'English' ),
		'due'      => '-11 days',
		'status'   => Schema::STATUS_SUBMITTED,
	),
);

global $wpdb;
$created = 0;

foreach ( $people as $person ) {
	$profile = array(
		'spiritualGifts' => array(
			'likely'   => $person['likely'],
			'possible' => array( 'Service', 'Giving' ),
			'unlikely' => array( 'Healing' ),
		),
		'heart'          => array(
			'roles'  => array( 'Encourager', 'Helper' ),
			'people' => array( 'Newcomers', 'Young families' ),
			'causes' => array( 'Loneliness in a transient city' ),
		),
		'abilities'      => array_map(
			static fn( $lang ) => 'Languages: ' . $lang,
			$person['langs']
		),
		'personality'    => array( 'Relational', 'Structured' ),
		'experiences'    => array(
			'Spiritual experiences' => array( 'Came to faith as an adult' ),
			'Work experiences'      => array( 'Business: operations' ),
		),
		'availability'   => array(
			'priority' => 'Somewhere I can build relationships.',
			'hours'    => '3-5 hours',
			'timing'   => array( 'Weekend', 'Weeknight' ),
		),
		'recommendedNextStep'   => 'Explore a team where hospitality can be tested.',
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
	'production'       => array( 8, 6, 7 ),
	'worship'          => array( 14, 10, 13 ),
	'prayer'           => array( 10, 6, 4 ),
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

\WP_CLI::success( "Seeded {$created} demo submissions and capacity for " . count( $capacity ) . ' teams.' );
