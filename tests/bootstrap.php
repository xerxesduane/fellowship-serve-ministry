<?php
/**
 * Assertions, fixtures, and the runner loop.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Privacy;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Schema;
use Serve_Dashboard\Submissions;

/** @var array<int,array{name:string,fn:callable}> */
$GLOBALS['serve_tests'] = array();

function test( string $name, callable $fn ): void {
	$GLOBALS['serve_tests'][] = array(
		'name' => $name,
		'fn'   => $fn,
	);
}

final class Failure extends \Exception {}

/**
 * Assertions.
 *
 * Each one says what it expected and what it got, because a failing test that
 * only says "false is not true" costs more time than it saves.
 */
final class Assert {

	public int $count = 0;

	public function ok( bool $value, string $what ): void {
		++$this->count;
		if ( ! $value ) {
			throw new Failure( "expected true: $what" );
		}
	}

	public function not( bool $value, string $what ): void {
		++$this->count;
		if ( $value ) {
			throw new Failure( "expected false: $what" );
		}
	}

	/** @param mixed $expected @param mixed $actual */
	public function same( $expected, $actual, string $what ): void {
		++$this->count;
		if ( $expected !== $actual ) {
			throw new Failure(
				sprintf( "%s\n      expected: %s\n      actual:   %s", $what, self::show( $expected ), self::show( $actual ) )
			);
		}
	}

	public function contains( string $needle, string $haystack, string $what ): void {
		++$this->count;
		if ( ! str_contains( $haystack, $needle ) ) {
			throw new Failure( "$what\n      \"$needle\" not found in: " . self::show( $haystack ) );
		}
	}

	public function lacks( string $needle, string $haystack, string $what ): void {
		++$this->count;
		if ( str_contains( $haystack, $needle ) ) {
			throw new Failure( "$what\n      \"$needle\" WAS found in: " . self::show( $haystack ) );
		}
	}

	/** @param mixed $value */
	private static function show( $value ): string {
		$out = is_scalar( $value ) || null === $value ? var_export( $value, true ) : wp_json_encode( $value );

		return strlen( (string) $out ) > 300 ? substr( (string) $out, 0, 300 ) . '…' : (string) $out;
	}
}

/**
 * Rows created by a test, and the means to remove them.
 *
 * Tests run against a database somebody may also be using by hand, so nothing
 * is truncated and nothing is deleted by pattern. Only ids this object handed
 * out are removed.
 */
final class Fixtures {

	/** @var int[] */
	private array $submissions = array();

	/** @var int[] */
	private array $users = array();

	/** @var array<int,int> team id => the leader it had before a test changed it. */
	private array $team_leaders = array();

	/**
	 * Highest feedback id when this test started.
	 *
	 * Friction is recorded through a static call that returns no id, so rather
	 * than thread one back, anything added during the test is anything newer
	 * than this. Without it every run left its examples behind in the pilot
	 * report — which is exactly the sort of invented data that report exists to
	 * avoid.
	 */
	private int $feedback_high_water;

	public function __construct() {
		global $wpdb;

		$table = Schema::table( 'feedback' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input.
		$this->feedback_high_water = (int) $wpdb->get_var( "SELECT COALESCE( MAX( id ), 0 ) FROM {$table}" );
	}

	/**
	 * A production-shaped profile, built through the real taxonomy.
	 *
	 * Every one of the eighteen gifts is placed in exactly one bucket, which is
	 * what the assessment guarantees: each question is required and offers three
	 * answers. Anything not named is unlikely.
	 *
	 * Values are the display labels the browser writes, not ids, so a fixture
	 * exercises the same normalisation a real submission does.
	 *
	 * This exists because the old fixtures put "Organization" in
	 * spiritualGifts.likely. The assessment cannot emit that — it emits
	 * "Administration" — and matching compared display strings, so an impossible
	 * fixture was the only thing making the gift comparison look like it worked.
	 * Anything hand-authored here can hide the same class of bug again, so build
	 * profiles with this.
	 *
	 * @param string[]            $likely   Canonical gift ids.
	 * @param string[]            $possible Canonical gift ids.
	 * @param array<string,mixed> $extra    Other profile sections.
	 * @return array<string,mixed>
	 */
	public static function gift_profile( array $likely, array $possible = array(), array $extra = array() ): array {
		foreach ( array_merge( $likely, $possible ) as $id ) {
			if ( ! \Serve_Dashboard\Gift_Taxonomy::is_gift( $id ) ) {
				throw new Failure( "fixture asked for '$id', which is not one of the 18 assessed gifts" );
			}
		}

		$unlikely = array_values( array_diff( \Serve_Dashboard\Gift_Taxonomy::ids(), $likely, $possible ) );

		return array_merge(
			array(
				'spiritualGifts' => array(
					'likely'   => \Serve_Dashboard\Gift_Taxonomy::labels( $likely ),
					'possible' => \Serve_Dashboard\Gift_Taxonomy::labels( $possible ),
					'unlikely' => \Serve_Dashboard\Gift_Taxonomy::labels( $unlikely ),
				),
			),
			$extra
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	public function submission( array $overrides = array() ): int {
		$payload = array_merge(
			array(
				'display_name'    => 'Test Person',
				'email'           => 'test-' . wp_generate_password( 8, false ) . '@serve.test',
				'phone'           => '',
				'tenure_months'   => 12,
				'gifts_likely'    => array( 'Mercy' ),
				'languages'       => array( 'English' ),
				'suggested_teams' => array( 'welcome' ),
				'profile'         => array(
					'spiritualGifts' => array( 'likely' => array( 'Mercy' ) ),
					'experiences'    => array( 'painful' => array( 'A bereavement' ) ),
				),
			),
			$overrides
		);

		$result = Submissions::create( $payload );
		if ( is_wp_error( $result ) ) {
			throw new Failure( 'could not create fixture: ' . $result->get_error_message() );
		}

		// create() returns the id alongside whether the confirmation email
		// actually went, so the endpoint can stop claiming one was sent when
		// the mailer refused.
		$id = (int) $result['submission_id'];

		$this->submissions[] = $id;

		return $id;
	}

	/**
	 * Take responsibility for a row this object did not create.
	 *
	 * A test that posts at the REST endpoint gets a real submission back that
	 * nothing is tracking. Handing the id over here means it is erased with
	 * everything else rather than left in the leaders' queue.
	 */
	public function adopt( int $submission_id ): int {
		if ( $submission_id > 0 && ! in_array( $submission_id, $this->submissions, true ) ) {
			$this->submissions[] = $submission_id;
		}

		return $submission_id;
	}

	/** A submission that has confirmed its address. */
	public function verified_submission( array $overrides = array() ): int {
		global $wpdb;

		$id = $this->submission( $overrides );
		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'verified_at'  => current_time( 'mysql', true ),
				'verify_token' => null,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $id;
	}

	public function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'serve-test-' . wp_generate_password( 8, false ),
				'user_email' => 'user-' . wp_generate_password( 8, false ) . '@serve.test',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => $role,
			)
		);

		if ( is_wp_error( $id ) ) {
			throw new Failure( 'could not create user: ' . $id->get_error_message() );
		}

		$this->users[] = (int) $id;

		return (int) $id;
	}

	/**
	 * Put a user in charge of one of the seeded teams.
	 *
	 * The sixteen teams are real rows created at activation, not fixtures, so
	 * the previous leader is remembered and restored — a test must not leave
	 * somebody's team pointing at a user that no longer exists.
	 *
	 * @return int The team id.
	 */
	public function lead_team( int $user_id, string $slug ): int {
		global $wpdb;

		$team = \Serve_Dashboard\Teams::get_by_slug( $slug );
		if ( ! $team ) {
			throw new Failure( "no such team: $slug" );
		}

		$id = (int) $team->id;
		if ( ! array_key_exists( $id, $this->team_leaders ) ) {
			$this->team_leaders[ $id ] = (int) $team->leader_user_id;
		}

		$wpdb->update(
			Schema::table( 'teams' ),
			array( 'leader_user_id' => $user_id ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return $id;
	}

	/** @var array<int,array{target:int,current:int,min:int,checked:?string}> capacity as it was before a test changed it. */
	private array $team_capacity = array();

	/**
	 * Give a seeded team a headcount, restoring whatever it had afterwards.
	 *
	 * A test must not depend on whether anyone has configured this install:
	 * on a fresh one no team has a target at all, which is the difference that
	 * made the first version of the gap test assert nothing.
	 */
	public function set_team_capacity( string $slug, int $target, int $current, int $minimum = 0 ): int {
		global $wpdb;

		$team = \Serve_Dashboard\Teams::get_by_slug( $slug );
		if ( ! $team ) {
			throw new Failure( "no such team: $slug" );
		}

		$id = (int) $team->id;
		if ( ! array_key_exists( $id, $this->team_capacity ) ) {
			$this->team_capacity[ $id ] = array(
				'target'  => (int) $team->target_headcount,
				'current' => (int) $team->current_headcount,
				'min'     => (int) $team->min_headcount,
				// Restored too, or a test that moves it leaves every later
				// drift figure measured from the wrong moment.
				'checked' => $team->headcount_checked_at,
			);
		}

		$wpdb->update(
			Schema::table( 'teams' ),
			array(
				'target_headcount'  => $target,
				'current_headcount' => $current,
				'min_headcount'     => $minimum,
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%d' ),
			array( '%d' )
		);

		return $id;
	}

	public function cleanup(): void {
		global $wpdb;

		$feedback = Schema::table( 'feedback' );
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"DELETE FROM {$feedback} WHERE id > %d",
				$this->feedback_high_water
			)
		);

		foreach ( $this->team_capacity as $team_id => $was ) {
			$wpdb->update(
				Schema::table( 'teams' ),
				array(
					'target_headcount'     => $was['target'],
					'current_headcount'    => $was['current'],
					'min_headcount'        => $was['min'],
					'headcount_checked_at' => $was['checked'],
				),
				array( 'id' => $team_id ),
				array( '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);
		}
		$this->team_capacity = array();

		foreach ( $this->team_leaders as $team_id => $previous ) {
			$wpdb->update(
				Schema::table( 'teams' ),
				array( 'leader_user_id' => $previous ),
				array( 'id' => $team_id ),
				array( '%d' ),
				array( '%d' )
			);
		}
		$this->team_leaders = array();

		foreach ( $this->submissions as $id ) {
			Privacy::erase_submission( $id );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->users as $id ) {
			wp_delete_user( $id );
		}

		$this->submissions = array();
		$this->users       = array();
	}
}

/**
 * Run every registered test.
 *
 * @return int Process exit code.
 */
function run( string $filter = '' ): int {
	global $wpdb;

	$tests = $GLOBALS['serve_tests'];
	if ( '' !== $filter ) {
		$tests = array_values(
			array_filter( $tests, static fn( $t ) => str_contains( strtolower( $t['name'] ), strtolower( $filter ) ) )
		);
	}

	$submissions_table = Schema::table( 'submissions' );
	$feedback_table    = Schema::table( 'feedback' );

	$rows_before     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$submissions_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$feedback_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$feedback_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	$passed     = 0;
	$failed     = 0;
	$assertions = 0;

	echo "\n";

	foreach ( $tests as $t ) {
		$fixtures = new Fixtures();
		$assert   = new Assert();

		// Each test starts logged out; forgetting to reset this is the easiest
		// way to write a capability test that passes for the wrong reason.
		wp_set_current_user( 0 );

		try {
			( $t['fn'] )( $assert, $fixtures );

			/*
			 * A test that asserted nothing passes for free, and reads in the
			 * output exactly like one that checked something. That is worse
			 * than no test, because it occupies the space where a real one
			 * would go. This caught a metrics test whose loop body never ran.
			 */
			if ( 0 === $assert->count ) {
				throw new Failure( 'this test made no assertions' );
			}

			printf( "  \u{2713} %s\n", $t['name'] );
			++$passed;
		} catch ( Failure $e ) {
			printf( "  \u{2717} %s\n      %s\n", $t['name'], $e->getMessage() );
			++$failed;
		} catch ( \Throwable $e ) {
			printf( "  \u{2717} %s\n      %s: %s\n      %s:%d\n", $t['name'], get_class( $e ), $e->getMessage(), $e->getFile(), $e->getLine() );
			++$failed;
		} finally {
			$fixtures->cleanup();
			wp_set_current_user( 0 );
			$assertions += $assert->count;
		}
	}

	$rows_after     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$submissions_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$feedback_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$feedback_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	printf( "\n  %d passed, %d failed, %d assertions\n", $passed, $failed, $assertions );

	// Say so rather than leave someone to find it later in the dashboard.
	if ( $feedback_after !== $feedback_before ) {
		printf(
			"  WARNING: feedback count went %d -> %d. A test leaked friction reports.
",
			$feedback_before,
			$feedback_after
		);

		return 1;
	}

	if ( $rows_after !== $rows_before ) {
		printf(
			"  WARNING: submission count went %d -> %d. A test leaked fixtures.\n",
			$rows_before,
			$rows_after
		);

		return 1;
	}

	echo "\n";

	return $failed > 0 ? 1 : 0;
}
