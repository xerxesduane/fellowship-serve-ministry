<?php
/**
 * Completion funnel.
 *
 * Nineteen steps, and no idea where people give up. "40% stop at Work
 * Experiences" is a fixable fact; a hunch is not.
 *
 * Deliberately the crudest thing that answers the question: one integer per
 * step. No identifiers, no IP, no session, no per-person path — so there is
 * nothing here to leak and nothing to reconcile against a profile. It counts
 * how many people reached each step and stops.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Funnel {

	private const OPTION = 'serve_dashboard_funnel';

	/** The journey has 19 steps; anything outside that is not ours. */
	private const MAX_STEP = 30;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/step',
			array(
				'methods'  => \WP_REST_Server::CREATABLE,
				'callback' => array( __CLASS__, 'record' ),
				// Public: the person is not a WordPress user, and the payload is
				// a single integer that identifies nobody.
				'permission_callback' => '__return_true',
				'args'                => array(
					'step' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 */
	public static function record( \WP_REST_Request $request ): \WP_REST_Response {
		$step = (int) $request->get_param( 'step' );

		if ( $step >= 0 && $step <= self::MAX_STEP ) {
			self::increment( $step );
		}

		// Always 204 regardless: a counter must never make the journey feel
		// like it failed at something.
		return new \WP_REST_Response( null, 204 );
	}

	private static function increment( int $step ): void {
		// One count per browser per step, so a back-and-forth through the
		// journey does not inflate the numbers.
		$guard = 'serve_fn_' . ( Privacy::hash_ip() ?: 'anon' ) . '_' . $step;

		if ( get_transient( $guard ) ) {
			return;
		}

		set_transient( $guard, 1, DAY_IN_SECONDS );

		$counts          = self::counts();
		$counts[ $step ] = ( $counts[ $step ] ?? 0 ) + 1;

		update_option( self::OPTION, $counts, false );
	}

	/**
	 * @return array<int,int>
	 */
	public static function counts(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_map( 'intval', $stored ) : array();
	}

	/**
	 * Funnel rows for the Settings screen, with drop-off between steps.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function report(): array {
		$counts = self::counts();

		if ( ! $counts ) {
			return array();
		}

		ksort( $counts );
		$first = (int) reset( $counts );
		$out   = array();
		$prev  = null;

		foreach ( $counts as $step => $count ) {
			$out[] = array(
				'step'      => (int) $step + 1,
				'reached'   => $count,
				// Share of the people who started who got this far.
				'share'     => $first > 0 ? (int) round( $count / $first * 100 ) : 0,
				// Lost since the previous recorded step.
				'dropped'   => null === $prev ? 0 : max( 0, $prev - $count ),
			);

			$prev = $count;
		}

		return $out;
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
