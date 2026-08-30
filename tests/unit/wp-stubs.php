<?php
/**
 * The WordPress functions the matching stack actually calls.
 *
 * Each is the real behaviour for the inputs used here, not a mock that records
 * calls: translation is identity, apply_filters returns its default because no
 * filters are registered, and current_time gives a UTC MySQL timestamp. Nothing
 * in this file makes a test pass that would fail in WordPress.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

function __( string $text, string $domain = '' ): string {
	unset( $domain );

	return $text;
}

function esc_html__( string $text, string $domain = '' ): string {
	unset( $domain );

	return $text;
}

/**
 * @param mixed $value
 * @return mixed
 */
function apply_filters( string $hook, $value ) {
	$extra = func_get_args();
	unset( $hook, $extra );

	// No filters are registered in this runner, so WordPress would return the
	// default unchanged. Tests that need a filtered value set the global below.
	global $serve_unit_filters;

	$name = func_get_arg( 0 );

	if ( isset( $serve_unit_filters[ $name ] ) ) {
		return ( $serve_unit_filters[ $name ] )( $value );
	}

	return $value;
}

function current_time( string $type = 'mysql', $gmt = 0 ): string {
	unset( $type, $gmt );

	return gmdate( 'Y-m-d H:i:s' );
}
