<?php
/**
 * Actions and filters.
 *
 * Small on purpose. The ported code uses hooks for a handful of real extension
 * points -- the cron entry points, the matching contract's filter, the digest's
 * recipient list -- and nothing here needs the rest of WordPress's plugin API.
 *
 * The callbacks live in $GLOBALS['wp_filter'], which looks like a strange choice
 * for new code and is a deliberate one. WordPress kept them there, and callers
 * reach into it directly to detach everything on a hook at once -- the test
 * helpers that simulate broken SMTP do exactly that:
 *
 *     unset( $GLOBALS['wp_filter']['pre_wp_mail'] );
 *
 * With a private array behind these functions, that line silently does nothing.
 * A filter forcing mail to fail then stayed attached for the remainder of the
 * process, and a later test reported one unsent confirmation with no way to see
 * why. Using the same storage means those resets work, and there is exactly one
 * place callbacks live rather than two that disagree.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Hooks {

	/**
	 * The registry, created on first use.
	 *
	 * @return array<string,array<int,array<int,callable>>>
	 */
	private static function &registry(): array {
		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			$GLOBALS['wp_filter'] = array();
		}

		return $GLOBALS['wp_filter'];
	}

	public static function add( string $hook, callable $fn, int $priority = 10 ): void {
		$registry = &self::registry();

		$registry[ $hook ][ $priority ][] = $fn;
	}

	/** @param array<int,mixed> $args */
	public static function run( string $hook, array $args = array() ): void {
		foreach ( self::callbacks( $hook ) as $fn ) {
			$fn( ...$args );
		}
	}

	/**
	 * @param mixed            $value
	 * @param array<int,mixed> $args
	 * @return mixed
	 */
	public static function filter( string $hook, $value, array $args = array() ) {
		foreach ( self::callbacks( $hook ) as $fn ) {
			$value = $fn( $value, ...$args );
		}

		return $value;
	}

	/**
	 * Detach a callback.
	 *
	 * Needed because the tests add a filter, exercise the path it changes, and
	 * take it off again -- a filter left attached silently alters every later
	 * test in the run.
	 */
	public static function remove( string $hook, callable $fn, int $priority = 10 ): bool {
		$registry = &self::registry();

		if ( ! isset( $registry[ $hook ][ $priority ] ) ) {
			return false;
		}

		$found = false;

		foreach ( $registry[ $hook ][ $priority ] as $i => $existing ) {
			if ( $existing === $fn ) {
				unset( $registry[ $hook ][ $priority ][ $i ] );
				$found = true;
			}
		}

		return $found;
	}

	public static function has( string $hook ): bool {
		return array() !== self::callbacks( $hook );
	}

	/** Used by the tests, which need each case to start from a known state. */
	public static function reset(): void {
		$GLOBALS['wp_filter'] = array();
	}

	/** @return array<int,callable> */
	private static function callbacks( string $hook ): array {
		$registry = self::registry();

		if ( ! isset( $registry[ $hook ] ) || ! is_array( $registry[ $hook ] ) ) {
			return array();
		}

		$by_priority = $registry[ $hook ];

		ksort( $by_priority );

		$out = array();

		foreach ( $by_priority as $group ) {
			foreach ( (array) $group as $fn ) {
				if ( is_callable( $fn ) ) {
					$out[] = $fn;
				}
			}
		}

		return $out;
	}
}
