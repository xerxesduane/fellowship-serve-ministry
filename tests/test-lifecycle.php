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

test(
	'both scheduled jobs are booked',
	function ( Assert $a, Fixtures $f ) {
		// Retention is a legal commitment, not a nicety; if the sweep is not
		// scheduled, nothing is ever deleted and nobody finds out.
		$a->ok( false !== wp_next_scheduled( 'serve_dashboard_retention_sweep' ), 'retention sweep scheduled' );
		$a->ok( false !== wp_next_scheduled( Digest::CRON_HOOK ), 'weekly digest scheduled' );
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
