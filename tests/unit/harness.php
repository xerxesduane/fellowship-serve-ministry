<?php
/**
 * Assertions, fixtures and the loop, for the WordPress-free suite.
 *
 * Two rules carried over from the main runner because both were learned the
 * hard way there:
 *
 * - A test that finishes without asserting anything fails. The metrics test in
 *   the main suite iterated the wrong level of a nested array, so its loop body
 *   never ran and it sailed through a deliberately broken scope clause.
 * - Fixtures are built through the same canonical taxonomy the assessment uses,
 *   never hand-authored. The old matching tests put "Organization" in
 *   spiritualGifts.likely, which the assessment cannot produce — it emits
 *   "Administration" — and that impossible fixture is precisely what hid the
 *   taxonomy regression for a release.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Unit;

use Serve_Dashboard\Gift_Taxonomy;
use Serve_Dashboard\Teams;

/** @var array<int,array{name:string,fn:callable}> */
$GLOBALS['serve_unit_tests'] = array();

function test( string $name, callable $fn ): void {
	$GLOBALS['serve_unit_tests'][] = array(
		'name' => $name,
		'fn'   => $fn,
	);
}

final class Failure extends \Exception {}

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

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	public function same( $expected, $actual, string $what ): void {
		++$this->count;
		if ( $expected !== $actual ) {
			throw new Failure(
				sprintf(
					"%s\n      expected: %s\n      actual:   %s",
					$what,
					self::show( $expected ),
					self::show( $actual )
				)
			);
		}
	}

	/**
	 * @param mixed $needle
	 * @param mixed $haystack
	 */
	public function contains( $needle, $haystack, string $what ): void {
		++$this->count;

		$found = is_array( $haystack )
			? in_array( $needle, $haystack, true )
			: str_contains( (string) $haystack, (string) $needle );

		if ( ! $found ) {
			throw new Failure(
				sprintf( "%s\n      looking for: %s\n      in:          %s", $what, self::show( $needle ), self::show( $haystack ) )
			);
		}
	}

	/**
	 * @param mixed $needle
	 * @param mixed $haystack
	 */
	public function lacks( $needle, $haystack, string $what ): void {
		++$this->count;

		$found = is_array( $haystack )
			? in_array( $needle, $haystack, true )
			: str_contains( (string) $haystack, (string) $needle );

		if ( $found ) {
			throw new Failure(
				sprintf( "%s\n      should not contain: %s\n      in: %s", $what, self::show( $needle ), self::show( $haystack ) )
			);
		}
	}

	/** @param mixed $value */
	private static function show( $value ): string {
		if ( is_array( $value ) ) {
			return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '[unprintable]';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return (string) $value;
	}
}

final class Fixtures {

	/**
	 * The sixteen ministry rows, exactly as the church's table lists them.
	 *
	 * Read from the plugin's own seed rather than retyped, so the snapshot test
	 * compares the table against the shipped source of truth and this file
	 * cannot drift into agreeing with itself.
	 *
	 * Always a fresh set of objects. PHP hands objects out by handle, so
	 * returning the cache let a test that deactivates Prayer or rewrites a
	 * keyword list edit the fixture for every test after it — and the suite
	 * then passed or failed depending on the order glob() happened to return.
	 * The parse is cached; the rows handed out are not.
	 *
	 * @return array<int,object>
	 */
	public static function teams(): array {
		return array_map(
			static fn( object $team ): object => clone $team,
			self::prototypes()
		);
	}

	/**
	 * The parsed rows, built once.
	 *
	 * @return array<int,object>
	 */
	private static function prototypes(): array {
		static $rows = null;

		if ( null !== $rows ) {
			return $rows;
		}

		$rows = array();
		$id   = 1;

		foreach ( self::seed_source() as list( $name, $gifts, $safeguarded, $keywords ) ) {
			$rows[] = (object) array(
				'id'                    => $id++,
				'slug'                  => self::slug( $name ),
				'name'                  => $name,
				'gifts'                 => json_encode( $gifts ),
				'keywords'              => json_encode( $keywords ),
				'target_headcount'      => 0,
				'min_headcount'         => 0,
				'current_headcount'     => 0,
				'requires_safeguarding' => $safeguarded ? 1 : 0,
				'is_active'             => 1,
			);
		}

		return $rows;
	}

	/**
	 * Pull the seed array out of class-teams.php without loading the class.
	 *
	 * The real Teams needs $wpdb, and the shim has replaced it in this process,
	 * so the constant cannot simply be called. Parsing the one method keeps the
	 * fixture honest: edit the seed and these tests see the edit.
	 *
	 * @return array<int,array{0:string,1:string[],2:bool,3:string[]}>
	 */
	private static function seed_source(): array {
		$path   = dirname( __DIR__, 2 ) . '/wordpress-plugin/serve-dashboard/includes/class-teams.php';
		$source = (string) file_get_contents( $path );

		$start = strpos( $source, 'private static function seed(): array {' );
		$end   = strpos( $source, 'public static function seed_defaults', (int) $start );

		if ( false === $start || false === $end ) {
			throw new Failure( 'could not find Teams::seed() to read the ministry table from' );
		}

		$body = substr( $source, $start, $end - $start );

		// Each row is `array( 'Name', array( gifts ), bool, array( keywords ) ),`
		preg_match_all( '/array\(\s*\'((?:[^\'\\\\]|\\\\.)*)\',\s*array\(([^)]*)\),\s*(true|false),\s*array\(([^)]*)\)\s*\)/s', $body, $matches, PREG_SET_ORDER );

		$out = array();
		foreach ( $matches as $row ) {
			$out[] = array(
				stripslashes( $row[1] ),
				self::strings( $row[2] ),
				'true' === $row[3],
				self::strings( $row[4] ),
			);
		}

		if ( 16 !== count( $out ) ) {
			throw new Failure( 'expected 16 seeded ministry rows, parsed ' . count( $out ) );
		}

		return $out;
	}

	/** @return string[] */
	private static function strings( string $list ): array {
		preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $list, $found );

		return array_map( 'stripslashes', $found[1] );
	}

	/** WordPress sanitize_title, for the names this seed actually contains. */
	private static function slug( string $name ): string {
		$slug = strtolower( $name );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug ) ?? '';

		return trim( $slug, '-' );
	}

	/**
	/** Install fresh team rows for the class under test. */
	public static function install(): void {
		Teams::$rows = self::teams();
	}

	/**
	 * A production-shaped profile, built through the real taxonomy.
	 *
	 * Every one of the eighteen gifts is placed in exactly one bucket, which is
	 * what the assessment guarantees — each question is required and offers
	 * three answers. Anything not named is `unlikely`, because that is what a
	 * real participant's profile looks like and because building it any other
	 * way is how a partition that could never occur ends up under test.
	 *
	 * Values are the display labels the browser writes, not ids, so these
	 * fixtures exercise the same normalisation a real submission does.
	 *
	 * @param string[]            $likely   Canonical gift ids.
	 * @param string[]            $possible Canonical gift ids.
	 * @param array<string,mixed> $extra    Other profile sections.
	 * @return array<string,mixed>
	 */
	public static function profile( array $likely, array $possible = array(), array $extra = array() ): array {
		foreach ( array_merge( $likely, $possible ) as $id ) {
			if ( ! Gift_Taxonomy::is_gift( $id ) ) {
				throw new Failure( "fixture asked for '$id', which is not one of the 18 assessed gifts" );
			}
		}

		$unlikely = array_values( array_diff( Gift_Taxonomy::ids(), $likely, $possible ) );

		return array_merge(
			array(
				'spiritualGifts' => array(
					'likely'   => Gift_Taxonomy::labels( $likely ),
					'possible' => Gift_Taxonomy::labels( $possible ),
					'unlikely' => Gift_Taxonomy::labels( $unlikely ),
				),
			),
			$extra
		);
	}

	/** Find one team's entry in a ranked list, or null. */
	public static function find( array $ranked, string $team_name ): ?array {
		foreach ( $ranked as $match ) {
			if ( $match['team_name'] === $team_name ) {
				return $match;
			}
		}

		return null;
	}
}

function run( string $filter = '' ): int {
	$tests  = $GLOBALS['serve_unit_tests'];
	$passed = 0;
	$failed = 0;
	$total  = 0;

	echo "\n";

	foreach ( $tests as $test ) {
		if ( '' !== $filter && ! str_contains( $test['name'], $filter ) ) {
			continue;
		}

		Fixtures::install();
		$assert = new Assert();

		try {
			( $test['fn'] )( $assert );

			/*
			 * A test that asserted nothing has not been shown to test
			 * anything, and passing is the wrong answer.
			 */
			if ( 0 === $assert->count ) {
				throw new Failure( 'made no assertions' );
			}

			++$passed;
			$total += $assert->count;
			echo "  \u{2713} {$test['name']}\n";
		} catch ( \Throwable $e ) {
			++$failed;
			$total += $assert->count;
			echo "  \u{2717} {$test['name']}\n      {$e->getMessage()}\n";
		}
	}

	printf( "\n  %d passed, %d failed, %d assertions\n\n", $passed, $failed, $total );

	return $failed > 0 ? 1 : 0;
}
