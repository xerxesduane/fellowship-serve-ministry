<?php
/**
 * What activation is supposed to have left behind.
 *
 * Activation code runs once, on a database nobody has looked at yet, and then
 * never again on that install — so it is the least-exercised code in the plugin
 * and the easiest place for a mistake to sit unnoticed. The unassigned-team
 * defect was exactly this: correct on every install that had been used, wrong
 * on every install that had not.
 *
 * These read state rather than re-running activation. Calling activate() inside
 * a test would drop and rebuild tables under whatever else is using them.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Assessment;
use Serve_Dashboard\Digest;
use Serve_Dashboard\Metrics;
use Serve_Dashboard\Privacy;
use Serve_Dashboard\Privacy_Page;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Submissions;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Teams;

test(
	'every table exists',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		foreach ( Schema::table_names() as $name ) {
			$table = Schema::table( $name );
			$a->same(
				$table,
				(string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
				"$name exists"
			);
		}
	}
);

test(
	'the stored schema version matches the code',
	function ( Assert $a, Fixtures $f ) {
		// If these drift, maybe_upgrade() re-runs install() on every page load.
		$a->same(
			Schema::DB_VERSION,
			(string) get_option( Schema::OPTION_DB_VERSION, '' ),
			'db version option is current'
		);
	}
);

/*
 * Reads the roles as stored, not as declared in role_caps().
 *
 * Those are the same thing only on an install that activated with the current
 * code — which CI is, every run, so editing role_caps() to over-privilege a
 * leader does fail there. On a long-lived install it will not, because add_role()
 * does not revisit a role it already wrote; that is WordPress's behaviour and
 * the stored value is the one that actually decides what a leader can see, so
 * it is the one worth asserting.
 */
test(
	'both roles exist with the capabilities they are documented to have',
	function ( Assert $a, Fixtures $f ) {
		$leader = get_role( Roles::ROLE_LEADER );
		$pastor = get_role( Roles::ROLE_PASTOR );

		$a->ok( null !== $leader, 'the leader role exists' );
		$a->ok( null !== $pastor, 'the pastor role exists' );

		foreach ( array( Roles::CAP_VIEW_DASHBOARD, Roles::CAP_VIEW_TEAM, Roles::CAP_MANAGE_PLACE ) as $cap ) {
			$a->ok( $leader->has_cap( $cap ), "leader has $cap" );
		}

		// The whole point of two roles: a leader must not hold these.
		foreach ( array( Roles::CAP_VIEW_ALL, Roles::CAP_VIEW_SENSITIVE, Roles::CAP_EXPORT, Roles::CAP_MANAGE_SETTINGS, Roles::CAP_VERIFY_SAFEGUARD ) as $cap ) {
			$a->not( $leader->has_cap( $cap ), "leader does NOT have $cap" );
			$a->ok( $pastor->has_cap( $cap ), "pastor has $cap" );
		}
	}
);

test(
	'an administrator is not locked out of the plugin they installed',
	function ( Assert $a, Fixtures $f ) {
		$admin = $f->user( 'administrator' );
		wp_set_current_user( $admin );

		// Churches commonly run the pastor as a WordPress administrator.
		foreach ( array( Roles::CAP_VIEW_DASHBOARD, Roles::CAP_VIEW_ALL, Roles::CAP_VIEW_SENSITIVE, Roles::CAP_MANAGE_SETTINGS ) as $cap ) {
			$a->ok( current_user_can( $cap ), "administrator has $cap" );
		}
	}
);

test(
	'the sixteen ministry teams are seeded, with safeguarding where it belongs',
	function ( Assert $a, Fixtures $f ) {
		$teams = Teams::all( false );
		$a->same( 16, count( $teams ), 'sixteen teams' );

		$safeguarded = array();
		foreach ( $teams as $team ) {
			if ( (int) $team->requires_safeguarding ) {
				$safeguarded[] = $team->slug;
			}
		}
		sort( $safeguarded );

		$a->same( array( 'fellowship-kids', 'youth-ministry' ), $safeguarded, 'exactly the two that work with under-18s' );
	}
);

test(
	'a team with no target is left out of the gap panel rather than reported as full',
	function ( Assert $a, Fixtures $f ) {
		// Set the condition rather than hoping the install already has it. On a
		// fresh one no team has a target at all, and an earlier version of this
		// test simply iterated an empty list and asserted nothing.
		$short   = $f->set_team_capacity( 'events', 10, 4 );
		$staffed = $f->set_team_capacity( 'prayer', 5, 5 );
		$unset   = $f->set_team_capacity( 'administration', 0, 0 );

		$listed = wp_list_pluck( Teams::gaps(), 'id' );

		$a->ok( in_array( $short, $listed, false ), 'a team six people short is listed' );
		$a->not( in_array( $staffed, $listed, false ), 'a fully staffed team is not' );

		// The distinction the panel exists to make: "nobody has told us what
		// this team needs" is not the same as "this team needs nobody".
		$a->not( in_array( $unset, $listed, false ), 'a team with no target is left out, not reported as full' );

		foreach ( Teams::gaps() as $team ) {
			$a->ok( (int) $team->target_headcount > 0, "{$team->slug} has a real target" );
			$a->ok( (int) $team->gap > 0, "{$team->slug} has a real shortfall" );
		}
	}
);

/*
 * Headcount drift.
 *
 * `current_headcount` is typed in by hand and counts everyone serving on a
 * team, most of whom never did a S.H.A.P.E. assessment. Until the pipeline
 * could be worked at all nobody could be placed, so it never went stale. Now
 * that they can, an untouched figure drifts a little further with each
 * placement — and the gap panel is one of the four things the deck promises.
 *
 * The fix reports the drift rather than adjusting for it. Adding placements to
 * a hand-typed number double-counts the moment somebody corrects it themselves.
 */
test(
	'placements after a headcount check are reported, not silently absorbed',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$team_id = $f->set_team_capacity( 'events', 10, 4 );
		$team    = Schema::table( 'teams' );

		// As though somebody confirmed the figure yesterday.
		$wpdb->query( $wpdb->prepare( "UPDATE {$team} SET headcount_checked_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 DAY ) WHERE id = %d", $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$a->same( 0, Teams::placed_since_check()[ $team_id ] ?? 0, 'nothing placed since' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id = $f->verified_submission( array( 'suggested_teams' => array( 'events' ) ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team_id );

		$a->same( 1, Teams::placed_since_check()[ $team_id ] ?? 0, 'the placement is counted' );

		$gap = null;
		foreach ( Teams::gaps() as $row ) {
			if ( (int) $row->id === $team_id ) {
				$gap = $row;
			}
		}

		$a->ok( null !== $gap, 'the team is in the gap panel' );
		$a->same( 6, (int) $gap->gap, 'the shortfall is untouched — reporting, not adjusting' );
		$a->same( 1, (int) $gap->placed_since, 'and the drift travels with it' );
	}
);

test(
	'confirming the headcount clears the drift',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$team_id = $f->set_team_capacity( 'prayer', 8, 3 );
		$team    = Schema::table( 'teams' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$team} SET headcount_checked_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 DAY ) WHERE id = %d", $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id = $f->verified_submission( array( 'suggested_teams' => array( 'prayer' ) ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team_id );
		$a->same( 1, Teams::placed_since_check()[ $team_id ] ?? 0, 'drifted by one' );

		// Saving the row counts as looking at the number, whether or not it moved.
		Teams::save(
			$team_id,
			array( 'target_headcount' => 8, 'current_headcount' => 4, 'min_headcount' => 0, 'requires_safeguarding' => 0, 'is_active' => 1, 'leader_user_id' => 0 )
		);

		$a->same( 0, Teams::placed_since_check()[ $team_id ] ?? 0, 'and back to nothing outstanding' );
	}
);

test(
	'only real, confirmed, actually-placed people count as drift',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$team_id = $f->set_team_capacity( 'welcome', 12, 6 );
		$team    = Schema::table( 'teams' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$team} SET headcount_checked_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 DAY ) WHERE id = %d", $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );

		// Someone only part-way along is not on the team yet.
		$trial = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		Submissions::set_status( $trial, Schema::STATUS_TRIAL_SERVE, $team_id );
		$a->same( 0, Teams::placed_since_check()[ $team_id ] ?? 0, 'a trial serve is not a placement' );

		$placed = $f->verified_submission( array( 'suggested_teams' => array( 'welcome' ) ) );
		Submissions::set_status( $placed, Schema::STATUS_PLACED, $team_id );
		$a->same( 1, Teams::placed_since_check()[ $team_id ] ?? 0, 'a placement is' );

		// And somebody erased is no longer on the team either.
		Privacy::erase_submission( $placed );
		$a->same( 0, Teams::placed_since_check()[ $team_id ] ?? 0, 'an erased profile stops counting' );
	}
);

/*
 * Reporting the drift was only half of it.
 *
 * `placed_since_check()` states that a headcount is out of date and the gap
 * panel carries the figure, but nothing asked anybody to correct it. A note on
 * a panel has to be visited to be read, and it looks the same in week ten as
 * in week one — so over a pilot it becomes furniture while the number behind
 * the "team gaps" the deck promises drifts further from the truth. These cover
 * the nudge that closes that loop.
 */
test(
	'a team whose headcount has been overtaken is put forward to be confirmed',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$team_id = $f->set_team_capacity( 'production', 9, 5 );
		$team    = Schema::table( 'teams' );

		/*
		 * A fortnight ago, nothing placed since. Set with an hour of slack:
		 * an exact multiple of 24 hours floors to a day less whenever PHP's
		 * clock trails the database's by a fraction of a second, which is a
		 * test that fails once a fortnight for no reason.
		 */
		$wpdb->query( $wpdb->prepare( "UPDATE {$team} SET headcount_checked_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 337 HOUR ) WHERE id = %d", $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$listed = static function ( array $rows ) use ( $team_id ) {
			foreach ( $rows as $row ) {
				if ( (int) $row->id === $team_id ) {
					return $row;
				}
			}

			return null;
		};

		$a->same( null, $listed( Teams::needs_headcount_check() ), 'an old figure with no placements is not stale, it is just old' );

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id = $f->verified_submission( array( 'suggested_teams' => array( 'production' ) ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team_id );

		$row = $listed( Teams::needs_headcount_check() );

		$a->ok( null !== $row, 'one placement is enough to ask for a re-check' );
		$a->same( 1, (int) $row->placed_since, 'and it says how far out the number is' );
		$a->same( 14, (int) $row->days_since_check, 'and how long since anybody looked' );

		// Confirming it takes the team straight back off the list.
		Teams::save(
			$team_id,
			array( 'target_headcount' => 9, 'current_headcount' => 6, 'min_headcount' => 0, 'requires_safeguarding' => 0, 'is_active' => 1, 'leader_user_id' => 0 )
		);

		$a->same( null, $listed( Teams::needs_headcount_check() ), 'confirming clears the ask' );
	}
);

test(
	'a headcount nobody has ever confirmed is reported as never, not as today',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$team_id = $f->set_team_capacity( 'administration', 6, 2 );
		$team    = Schema::table( 'teams' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$team} SET headcount_checked_at = NULL WHERE id = %d", $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_set_current_user( $f->user( Roles::ROLE_PASTOR ) );
		$id = $f->verified_submission( array( 'suggested_teams' => array( 'administration' ) ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team_id );

		$row = null;
		foreach ( Teams::needs_headcount_check() as $candidate ) {
			if ( (int) $candidate->id === $team_id ) {
				$row = $candidate;
			}
		}

		$a->ok( null !== $row, 'it is on the list' );
		// Zero days would read as "checked today", which is the opposite of true.
		$a->same( null, $row->days_since_check, 'never confirmed is null, not zero' );

		// And the scoping argument is honoured: a list that does not include
		// this team excludes it, which is what keeps a leader from being asked
		// about teams they do not lead.
		$a->same( 0, count( Teams::needs_headcount_check( array() ) ), 'an empty scope asks about nothing' );
		$a->same( 1, count( Teams::needs_headcount_check( array( $team_id ) ) ), 'and a scope of one asks about one' );
	}
);

test(
	'the weekly digest asks whoever can fix a headcount, and nobody else',
	function ( Assert $a, Fixtures $f ) {
		global $wpdb;

		$team_id = $f->set_team_capacity( 'livestream-team', 7, 3 );
		$team    = Schema::table( 'teams' );
		// 30 days plus an hour, for the reason given in the test above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$team} SET headcount_checked_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 721 HOUR ) WHERE id = %d", $team_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$pastor = $f->user( Roles::ROLE_PASTOR );
		wp_set_current_user( $pastor );

		$id = $f->verified_submission( array( 'suggested_teams' => array( 'livestream-team' ) ) );
		Submissions::set_status( $id, Schema::STATUS_PLACED, $team_id );

		$digest = Digest::build();

		$a->contains( 'Livestream Team', $digest['body'], 'the drifted team is named' );
		$a->contains( 'out of date', $digest['body'], 'and described as out of date' );
		$a->contains( '3 recorded, 1 placed since', $digest['body'], 'with the numbers that make it actionable' );
		$a->contains( 'confirmed 30 days ago', $digest['body'], 'and how long it has been' );
		$a->ok( $digest['total'] >= 1, 'and it is enough on its own to send an email' );

		/*
		 * A ministry leader can place people, and so cause this drift, but
		 * cannot edit team capacity. Asking them to confirm a number they have
		 * no permission to change would be an instruction to do nothing.
		 *
		 * The leader is made leader *of the drifted team* on purpose. Without
		 * that they lead nothing, `visible_team_ids()` returns an empty scope,
		 * and the digest comes back clean whether the capability is checked or
		 * not — a test that passes for the wrong reason and would sit here
		 * green through the removal of the very gate it claims to cover.
		 */
		$leader = $f->user( Roles::ROLE_LEADER );
		$f->lead_team( $leader, 'livestream-team' );
		wp_set_current_user( $leader );

		$a->not( current_user_can( Roles::CAP_MANAGE_TEAMS ), 'a leader cannot edit team capacity' );
		$a->same( array( $team_id ), Roles::visible_team_ids(), 'but does lead the team that drifted' );

		$leader_digest = Digest::build();

		$a->lacks( 'out of date', $leader_digest['body'], 'and is still not asked to confirm its headcount' );
	}
);

/*
 * The stage badges are explained to the leader from `status_descriptions()`.
 * A status with no description renders an empty definition under its name —
 * visible only to whoever happens to open the legend, and only for the one
 * stage that was missed. Adding a status without describing it is exactly the
 * kind of omission that ships.
 */
test(
	'every pipeline stage is explained in plain words',
	function ( Assert $a, Fixtures $f ) {
		$labels       = Schema::status_labels();
		$descriptions = Schema::status_descriptions();

		$a->ok( count( $labels ) > 0, 'there are stages to describe' );
		$a->same( array_keys( $labels ), array_keys( $descriptions ), 'described in the same order, with nothing missing or extra' );

		foreach ( $descriptions as $status => $text ) {
			$a->ok( strlen( trim( $text ) ) > 10, "the {$status} stage says something useful" );
		}
	}
);

test(
	'both scheduled jobs are booked',
	function ( Assert $a, Fixtures $f ) {
		// Retention is a legal commitment, not a nicety; if the sweep is not
		// scheduled, nothing is ever deleted and nobody finds out.
		$a->ok( false !== wp_next_scheduled( 'serve_dashboard_retention_sweep' ), 'retention sweep scheduled' );
		$a->ok( false !== wp_next_scheduled( Digest::CRON_HOOK ), 'weekly digest scheduled' );
	}
);

/*
 * The serving form at the end of the journey.
 *
 * It used to be a URL written into app.js, form id and all. Two things were
 * wrong with that and both are tested here: a church could not correct it
 * without editing JavaScript, and every person finishing the journey was
 * offered five routes into it against one route into this dashboard — with the
 * embedded copy completable in place, so somebody could volunteer and leave no
 * record at all.
 */

test(
	'the serving form address is a setting, not a URL in the JavaScript',
	function ( Assert $a, Fixtures $f ) {
		$before = get_option( Assessment::OPTION_SERVING_FORM, false );

		try {
			update_option( Assessment::OPTION_SERVING_FORM, 'https://example.churchcenter.com/people/forms/12345' );

			$config = Assessment::config();

			$a->same(
				'https://example.churchcenter.com/people/forms/12345',
				$config['servingFormUrl'],
				'the journey is handed whatever the church configured'
			);

			/*
			 * And nothing else supplies one. A second copy in the JavaScript
			 * would win silently on the page while this test went on passing,
			 * which is exactly how the first version of this went wrong.
			 */
			$journey = '';
			foreach ( (array) glob( SERVE_DASHBOARD_DIR . 'public/assessment/*.js' ) as $module ) {
				$journey .= (string) file_get_contents( (string) $module );
			}

			$a->lacks( 'churchcenter.com', $journey, 'no serving form address is hardcoded anywhere in the journey' );
			$a->contains( 'SERVE_CONFIG.servingFormUrl', $journey, 'it reads the configured one instead' );
		} finally {
			if ( false === $before ) {
				delete_option( Assessment::OPTION_SERVING_FORM );
			} else {
				update_option( Assessment::OPTION_SERVING_FORM, $before );
			}
		}
	}
);

test(
	'no configured form means no external route is offered',
	function ( Assert $a, Fixtures $f ) {
		$before = get_option( Assessment::OPTION_SERVING_FORM, false );

		try {
			update_option( Assessment::OPTION_SERVING_FORM, '' );

			$a->same( '', Assessment::serving_form_url(), 'blank stays blank' );
			$a->same( '', Assessment::config()['servingFormUrl'], 'and reaches the page as blank' );

			/*
			 * What the journey then renders is asserted in tests/js, against the
			 * functions themselves. Checking the source for a guard here was
			 * tried and was worthless: it went on passing against a version that
			 * loaded the form for everybody regardless.
			 */
		} finally {
			if ( false === $before ) {
				delete_option( Assessment::OPTION_SERVING_FORM );
			} else {
				update_option( Assessment::OPTION_SERVING_FORM, $before );
			}
		}
	}
);

test(
	'a javascript: address cannot reach the results page',
	function ( Assert $a, Fixtures $f ) {
		$before = get_option( Assessment::OPTION_SERVING_FORM, false );

		try {
			/*
			 * This value becomes both a link href and an iframe source on a
			 * public page, so the sanitising happens on the way out as well as
			 * on the way in — an option can be written by something other than
			 * the settings form.
			 */
			update_option( Assessment::OPTION_SERVING_FORM, 'javascript:alert(document.cookie)' );

			$a->lacks( 'javascript:', Assessment::serving_form_url(), 'the scheme is refused' );
			$a->lacks( 'javascript:', (string) Assessment::config()['servingFormUrl'], 'and never reaches the config' );
		} finally {
			if ( false === $before ) {
				delete_option( Assessment::OPTION_SERVING_FORM );
			} else {
				update_option( Assessment::OPTION_SERVING_FORM, $before );
			}
		}
	}
);

test(
	'both public pages exist and carry their shortcode',
	function ( Assert $a, Fixtures $f ) {
		$assessment = (int) get_option( Assessment::OPTION_ASSESSMENT_PAGE, 0 );
		$consent    = (int) get_option( Assessment::OPTION_CONSENT_PAGE, 0 );

		$a->ok( $assessment > 0, 'the assessment page id is recorded' );
		$a->ok( $consent > 0, 'the consent page id is recorded' );

		$a->same( 'publish', get_post_status( $assessment ), 'assessment page is published' );
		$a->same( 'publish', get_post_status( $consent ), 'consent page is published' );

		$a->contains( '[serve_shape_assessment]', (string) get_post_field( 'post_content', $assessment ), 'assessment shortcode' );
		$a->contains( '[serve_shape_consent]', (string) get_post_field( 'post_content', $consent ), 'consent shortcode' );

		// Without the canvas template the theme wraps the journey in its own
		// header and footer, which is what the full-bleed design exists to avoid.
		$a->same(
			Assessment::TEMPLATE,
			(string) get_post_meta( $assessment, '_wp_page_template', true ),
			'assessment page uses the full-canvas template'
		);
	}
);

test(
	'activation does not seize the front page',
	function ( Assert $a, Fixtures $f ) {
		// Repointing the home page of somebody's existing site without asking
		// would be rude; Settings offers it as an explicit choice instead. This
		// only asserts the two settings agree with each other.
		if ( Assessment::is_front_page() ) {
			$a->same( 'page', (string) get_option( 'show_on_front' ), 'front page setting is coherent' );
			$a->same(
				(int) get_option( Assessment::OPTION_ASSESSMENT_PAGE ),
				(int) get_option( 'page_on_front' ),
				'and points at the assessment'
			);
		} else {
			$a->not(
				(int) get_option( 'page_on_front' ) === (int) get_option( Assessment::OPTION_ASSESSMENT_PAGE )
					&& 'page' === get_option( 'show_on_front' ),
				'not the front page unless is_front_page() says so'
			);
		}
	}
);

/*
 * The same defect class as the unassigned-team one, one layer up: headline
 * figures are scoped by the same helper, so a caller who leads nothing must see
 * zeroes rather than the whole church.
 */
test(
	'headline metrics are empty for a caller who leads nothing',
	function ( Assert $a, Fixtures $f ) {
		$f->verified_submission();

		$nobody = $f->user( Roles::ROLE_LEADER );

		foreach ( array( 0 => 'anonymous', $nobody => 'a leader with no team' ) as $user_id => $who ) {
			wp_set_current_user( (int) $user_id );

			$tiles = Metrics::headline();

			// headline() returns a tile per figure, each with its own value.
			// Iterating the top level and testing is_int() matched nothing and
			// asserted nothing at all, which is how this test first passed
			// against a deliberately broken scope clause.
			$a->ok( count( $tiles ) > 0, 'there are tiles to check' );

			foreach ( $tiles as $key => $tile ) {
				$a->same( 0, (int) ( $tile['value'] ?? -1 ), "$who sees zero for $key" );
			}
		}
	}
);

/*
 * The privacy notice replaces WordPress' boilerplate, which describes comment
 * forms and Gravatar and would contradict the consent wording it sits beside.
 * The rule that matters is the one protecting whatever a lawyer writes next.
 */
test(
	'the privacy notice never overwrites text somebody has edited',
	function ( Assert $a, Fixtures $f ) {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$a->ok( $page_id > 0, 'a privacy page exists' );

		$original = (string) get_post_field( 'post_content', $page_id );

		try {
			$reviewed = '<p>Reviewed by counsel. Every word here is deliberate.</p>';
			wp_update_post( array( 'ID' => $page_id, 'post_content' => $reviewed ) );

			$a->same( 0, Privacy_Page::install(), 'install() declines to touch an edited page' );
			$a->same( $reviewed, (string) get_post_field( 'post_content', $page_id ), 'and the wording survives' );

			// Boilerplate, by contrast, is exactly what it is there to replace.
			wp_update_post( array( 'ID' => $page_id, 'post_content' => '<p class="privacy-policy-tutorial">Suggested text: ...</p>' ) );
			$a->same( $page_id, Privacy_Page::install(), 'but it does replace the WordPress draft' );
			$a->contains( 'serve-privacy-notice', (string) get_post_field( 'post_content', $page_id ), 'with our own' );
		} finally {
			wp_update_post( array( 'ID' => $page_id, 'post_content' => $original ) );
		}
	}
);

/*
 * The path production actually takes, and the one an install that already has a
 * privacy page never exercises. Publishing text about religious belief the
 * moment a plugin is switched on is not a decision software should make.
 */
test(
	'a privacy notice created from nothing is left as a draft',
	function ( Assert $a, Fixtures $f ) {
		$existing = (int) get_option( 'wp_page_for_privacy_policy', 0 );

		try {
			update_option( 'wp_page_for_privacy_policy', 0 );

			$created = Privacy_Page::install();
			$a->ok( $created > 0, 'a page is created when there is none' );
			$a->same( 'draft', get_post_status( $created ), 'and it is not published' );
			$a->contains( 'serve-privacy-notice', (string) get_post_field( 'post_content', $created ), 'with the notice in it' );

			wp_delete_post( $created, true );
		} finally {
			update_option( 'wp_page_for_privacy_policy', $existing );
		}
	}
);

test(
	'the notice states the retention period the system actually uses',
	function ( Assert $a, Fixtures $f ) {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$content = (string) get_post_field( 'post_content', $page_id );

		// A privacy notice promising a different number from the one the sweep
		// enforces is worse than none, and nothing else would catch the drift.
		$a->contains(
			(string) Privacy::retention_months() . ' months',
			$content,
			'the published figure matches the configured one'
		);
		$a->contains( Privacy::contact_email(), html_entity_decode( $content ), 'and names the real contact address' );
	}
);

/*
 * A structural check rather than a behavioural one. Deleting the plugin is
 * meant to leave nothing behind, and the way that promise breaks is somebody
 * adding an option and not thinking about uninstall.php — which no amount of
 * manual testing catches, because nobody deletes the plugin.
 */
test(
	'uninstall.php names every option the plugin defines',
	function ( Assert $a, Fixtures $f ) {
		$plugin_dir = dirname( __DIR__ ) . '/wordpress-plugin/serve-dashboard';

		$uninstall = (string) file_get_contents( $plugin_dir . '/uninstall.php' );

		$defined = array();
		foreach ( (array) glob( $plugin_dir . '/includes/*.php' ) as $file ) {
			if ( preg_match_all( "/const OPTION[A-Z_]* *= *'([a-z_]+)'/", (string) file_get_contents( $file ), $m ) ) {
				$defined = array_merge( $defined, $m[1] );
			}
		}

		$a->ok( count( $defined ) >= 5, 'found the option constants to check against' );

		foreach ( array_unique( $defined ) as $option ) {
			$a->contains( "'$option'", $uninstall, "uninstall.php deletes $option" );
		}
	}
);

/*
 * The stage vocabulary has three lists over it now — the ordered path, the two
 * outcomes beside it, and the deck's three phases — and every one of them is a
 * separate place a new status can be forgotten. Forgetting is silent: a stage
 * missing from the path draws as an off-path chip, and one missing from the
 * phases disappears from the legend entirely. Neither throws.
 */
test(
	'every pipeline stage is on the path or explicitly beside it',
	function ( Assert $a, Fixtures $f ) {
		$all  = array_keys( Schema::status_labels() );
		$path = Schema::stage_path();
		$off  = Schema::stage_off_path();

		$a->same( 5, count( $path ), 'five stages are positions on the journey' );
		$a->same( 2, count( $off ), 'and two sit beside it' );

		$a->same(
			array(),
			array_values( array_diff( $all, array_merge( $path, $off ) ) ),
			'no stage is missing from both lists'
		);
		$a->same(
			array(),
			array_values( array_intersect( $path, $off ) ),
			'and none is claimed by both'
		);

		// The order is the journey, not alphabetical or insertion order.
		$a->same(
			array( 'submitted', 'contacted', 'conversation_booked', 'trial_serve', 'placed' ),
			$path,
			'the path runs in the order a person actually travels it'
		);
	}
);

test(
	'every stage is explained under one of the three deck phases',
	function ( Assert $a, Fixtures $f ) {
		$phases = Schema::stage_phases();

		$a->ok( isset( $phases['discover'], $phases['connect'], $phases['serve'] ), 'Discover, Connect and Serve are all named' );
		$a->same( array(), $phases['discover']['stages'], 'Discover holds no stage: it happens before the dashboard sees anybody' );

		$grouped = array();
		foreach ( $phases as $phase ) {
			$a->ok( '' !== trim( $phase['label'] ), 'each phase is named' );
			$a->ok( strlen( trim( $phase['note'] ) ) > 10, 'and says what it is for' );
			$grouped = array_merge( $grouped, $phase['stages'] );
		}

		$all = array_keys( Schema::status_labels() );

		$a->same(
			array(),
			array_values( array_diff( $all, $grouped ) ),
			'no stage is left out of the legend'
		);
		$a->same( count( $all ), count( $grouped ), 'and none appears in two phases' );

		// The lookup the drawer uses agrees with the grouping.
		$a->same( 'Connect', Schema::phase_of( Schema::STATUS_CONTACTED ), 'Contacted is Connect' );
		$a->same( 'Serve', Schema::phase_of( Schema::STATUS_PLACED ), 'Placed is Serve' );
		$a->same( '', Schema::phase_of( 'not-a-status' ), 'and an unknown status claims no phase' );
	}
);

/*
 * The three promises that depend on something outside WordPress actually
 * running, and the one that depends on reading the right number.
 */

test(
	'the checklist says so when nothing is running the scheduled jobs',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * The runbook has the operator set DISABLE_WP_CRON and add a system
		 * crontab entry. WordPress will then report a job as scheduled for ever
		 * while nothing fires it, and every row on this checklist stayed green
		 * in that state — including on an install whose privacy notice promises
		 * profiles are "deleted automatically by a daily job".
		 */
		$row = static function () {
			foreach ( \Serve_Dashboard\Security_Status::checks() as $check ) {
				if ( __( 'Scheduled jobs', 'serve-dashboard' ) === $check['label'] ) {
					return $check;
				}
			}

			return null;
		};

		$a->ok( null !== $row(), 'the checklist has a row for the scheduled jobs at all' );

		$before = get_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP, false );

		try {
			// Ran a moment ago: healthy.
			update_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP, current_time( 'mysql', true ) );
			$a->same( \Serve_Dashboard\Security_Status::PASS, $row()['status'], 'a sweep that just ran passes' );

			// Scheduled, but nothing has fired it for days.
			update_option(
				\Serve_Dashboard\Privacy::OPTION_LAST_SWEEP,
				gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS )
			);
			$stale = $row();
			$a->same( \Serve_Dashboard\Security_Status::FAIL, $stale['status'], 'a dead trigger fails' );
			$a->contains( 'crontab', $stale['detail'], 'and names where to look' );

			// Never run at all.
			delete_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP );
			$a->same( \Serve_Dashboard\Security_Status::WARN, $row()['status'], 'never having run is a warning, not a failure' );

			/*
			 * And the job itself gone, which is a different fault with a
			 * different remedy: nothing is scheduled to run rather than
			 * something failing to trigger what is. A mutation proved this
			 * branch was never reached, because every case above controls the
			 * timestamp and leaves the schedule alone.
			 */
			$hook = 'serve_dashboard_retention_sweep';
			$when = wp_next_scheduled( $hook );

			try {
				if ( $when ) {
					wp_unschedule_event( $when, $hook );
				}

				$unscheduled = $row();

				$a->same( \Serve_Dashboard\Security_Status::FAIL, $unscheduled['status'], 'an unscheduled job fails' );
				$a->contains( 'Not scheduled', $unscheduled['detail'], 'and says that is what is wrong' );
				$a->contains( 'retention sweep', $unscheduled['detail'], 'naming which job is missing' );
			} finally {
				if ( ! wp_next_scheduled( $hook ) ) {
					wp_schedule_event( $when ?: ( time() + DAY_IN_SECONDS ), 'daily', $hook );
				}
			}

			$a->ok( false !== wp_next_scheduled( $hook ), 'and the schedule is put back afterwards' );

			/*
			 * The digest is the other half, and it is the one that actually went
			 * missing in the field: it was added after this plugin had already
			 * been activated, and scheduling only ever happened on activation,
			 * so an install that upgraded in place never registered it. Its
			 * leaders simply never received the Monday email they were told to
			 * expect, and nothing anywhere said so.
			 */
			$digest_hook = \Serve_Dashboard\Digest::CRON_HOOK;
			$digest_when = wp_next_scheduled( $digest_hook );

			try {
				if ( $digest_when ) {
					wp_unschedule_event( $digest_when, $digest_hook );
				}

				$no_digest = $row();

				$a->same( \Serve_Dashboard\Security_Status::FAIL, $no_digest['status'], 'a missing digest job fails too' );
				$a->contains( 'digest', $no_digest['detail'], 'and is named, so the remedy is obvious' );
			} finally {
				if ( ! wp_next_scheduled( $digest_hook ) ) {
					\Serve_Dashboard\Digest::schedule();
				}
			}

			$a->ok( false !== wp_next_scheduled( $digest_hook ), 'and the digest is rescheduled afterwards' );
		} finally {
			if ( false === $before ) {
				delete_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP );
			} else {
				update_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP, $before );
			}
		}
	}
);

test(
	'the sweep records that it ran even when nothing was due',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * Without this, "nothing expired today" and "the job has not run in
		 * three weeks" are the same observation, and the check above has
		 * nothing to read.
		 */
		$before = get_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP, false );

		try {
			delete_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP );
			$a->same( '', \Serve_Dashboard\Privacy::last_sweep(), 'nothing recorded to begin with' );

			\Serve_Dashboard\Privacy::run_retention_sweep();

			$a->not( '' === \Serve_Dashboard\Privacy::last_sweep(), 'the sweep leaves a timestamp behind' );
		} finally {
			if ( false === $before ) {
				delete_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP );
			} else {
				update_option( \Serve_Dashboard\Privacy::OPTION_LAST_SWEEP, $before );
			}
		}
	}
);

test(
	'deletion honours what each person was promised, not the current setting',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * consents.retention_months is frozen when somebody agrees, the consent
		 * text quotes that number back to them, and the drawer shows it to their
		 * leader. Deletion read the live option instead, so lowering the setting
		 * from 24 months to 6 made the next sweep delete people who had been
		 * promised 24 — with the drawer still saying 24 until the row vanished.
		 */
		global $wpdb;

		$make = static function ( int $promised, int $age_months ) use ( $f, $wpdb ) {
			$id = $f->submission();

			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					'UPDATE ' . Schema::table( 'submissions' )
					. ' SET submitted_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d MONTH ) WHERE id = %d',
					$age_months,
					$id
				)
			);
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					'UPDATE ' . Schema::table( 'consents' )
					. ' SET retention_months = %d WHERE submission_id = %d',
					$promised,
					$id
				)
			);

			return $id;
		};

		$before = get_option( \Serve_Dashboard\Privacy::OPTION_RETENTION_MONTHS, false );

		try {
			// A pastor lowers the setting to six months.
			update_option( \Serve_Dashboard\Privacy::OPTION_RETENTION_MONTHS, 6 );

			$promised_24 = $make( 24, 12 );
			$promised_6  = $make( 6, 12 );

			$expired = \Serve_Dashboard\Privacy::expired_submission_ids();

			$a->not(
				in_array( $promised_24, $expired, true ),
				'somebody promised 24 months is not deleted at 12'
			);
			$a->ok(
				in_array( $promised_6, $expired, true ),
				'and somebody promised 6 months is'
			);

			// Raising it must not extend a promise either.
			update_option( \Serve_Dashboard\Privacy::OPTION_RETENTION_MONTHS, 60 );
			$a->ok(
				in_array( $promised_6, \Serve_Dashboard\Privacy::expired_submission_ids(), true ),
				'raising the setting does not keep somebody past what they agreed to'
			);
		} finally {
			if ( false === $before ) {
				delete_option( \Serve_Dashboard\Privacy::OPTION_RETENTION_MONTHS );
			} else {
				update_option( \Serve_Dashboard\Privacy::OPTION_RETENTION_MONTHS, $before );
			}
		}
	}
);

test(
	'searching the whole intake pool for candidates is written to the audit trail',
	function ( Assert $a, Fixtures $f ) {
		/*
		 * Candidate discovery decodes every verified profile in the church to
		 * answer one question about one team, and passes $log_view = false so
		 * the per-profile sensitive-read row does not fire two thousand times.
		 * That was right, and it left this screen logging nothing at all: the
		 * drawer recorded every single read of somebody's Experiences while the
		 * view that reads the whole congregation's at once recorded none.
		 */
		global $wpdb;

		$id = $f->submission(
			array(
				'suggested_teams' => array( 'fellowship-kids' ),
				'profile'         => array(
					'spiritualGifts' => array( 'likely' => array( 'Mercy', 'Teaching', 'Hospitality' ) ),
					'experiences'    => array( 'Painful experiences' => array( 'Death/Grief' ) ),
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

		$audit = Schema::table( 'audit' );
		$count = static function () use ( $wpdb, $audit ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
					"SELECT COUNT(*) FROM {$audit} WHERE action = %s",
					\Serve_Dashboard\Audit::ACTION_POOL_SEARCHED
				)
			);
		};

		$before = $count();

		$team = \Serve_Dashboard\Teams::get_by_slug( 'fellowship-kids' );
		$a->ok( null !== $team, 'the team exists to search' );

		\Serve_Dashboard\Teams::candidates( (int) $team->id );

		$a->same( $before + 1, $count(), 'one row per search, not one per profile' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT object_id, meta_json FROM {$audit} WHERE action = %s ORDER BY id DESC LIMIT 1",
				\Serve_Dashboard\Audit::ACTION_POOL_SEARCHED
			)
		);

		$a->same( (int) $team->id, (int) $row->object_id, 'naming which team was being searched for' );

		$meta = json_decode( (string) $row->meta_json, true );

		$a->ok( ( $meta['profiles_read'] ?? 0 ) > 0, 'and how many profiles were read' );
		$a->ok(
			( $meta['experiences_read'] ?? 0 ) > 0,
			'and how many of them had a readable Experiences section, which is the part worth being able to ask about later'
		);
	}
);
