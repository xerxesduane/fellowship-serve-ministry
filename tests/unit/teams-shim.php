<?php
/**
 * A Teams class backed by an array instead of the database.
 *
 * Defined before the real includes/class-teams.php is loaded, so the version
 * that needs $wpdb never enters this process. Only the four static methods the
 * matcher calls are provided; anything else is deliberately absent so a test
 * reaching for database behaviour fails loudly rather than against a stub that
 * quietly answers.
 *
 * The rows themselves come from Serve_Unit\Fixtures::teams(), which is asserted
 * elsewhere to match the church's Ministry-Gift Table exactly.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

final class Teams {

	/** @var array<int,object>|null */
	public static ?array $rows = null;

	/** @return array<int,object> */
	public static function all( bool $active_only = true ): array {
		$rows = self::$rows ?? array();

		if ( ! $active_only ) {
			return $rows;
		}

		return array_values( array_filter( $rows, static fn( $t ) => ! empty( $t->is_active ) ) );
	}

	public static function get( int $id ): ?object {
		foreach ( self::$rows ?? array() as $team ) {
			if ( (int) $team->id === $id ) {
				return $team;
			}
		}

		return null;
	}

	/** @return string[] */
	public static function gift_list( object $team ): array {
		$decoded = json_decode( (string) $team->gifts, true );

		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}

	/** @return string[] */
	public static function keyword_list( object $team ): array {
		$decoded = json_decode( (string) ( $team->keywords ?? '' ), true );

		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}
}
