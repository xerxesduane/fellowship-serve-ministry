<?php
/**
 * Route registration and dispatch.
 *
 * Accepts the same registration shape WordPress's register_rest_route() does,
 * including the '(?P<id>\d+)' patterns, so nineteen route registrations and
 * every test that calls them carry over unchanged.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Router {

	/**
	 * @var array<int,array{
	 *   methods:array<int,string>,
	 *   pattern:string,
	 *   route:string,
	 *   callback:callable,
	 *   permission:callable|null,
	 *   args:array<string,mixed>
	 * }>
	 */
	private static array $routes = array();

	/**
	 * @param array<string,mixed> $args
	 */
	public static function register( string $namespace, string $route, array $args ): void {
		// WordPress accepts either one definition or a list of them.
		$definitions = isset( $args['methods'] ) || isset( $args['callback'] ) ? array( $args ) : $args;

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) || ! isset( $definition['callback'] ) ) {
				continue;
			}

			$methods = $definition['methods'] ?? 'GET';
			$methods = array_map(
				'strtoupper',
				array_map( 'trim', explode( ',', is_array( $methods ) ? implode( ',', $methods ) : (string) $methods ) )
			);

			$full = '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' );

			self::$routes[] = array(
				'methods'    => $methods,
				'route'      => $full,
				'pattern'    => self::pattern( $full ),
				'callback'   => $definition['callback'],
				'permission' => $definition['permission_callback'] ?? null,
				'args'       => $definition['args'] ?? array(),
			);
		}
	}

	/**
	 * Turn a route into a regex.
	 *
	 * WordPress's '(?P<id>\d+)' syntax is already a named group, so it passes
	 * through untouched; everything around it is escaped so a literal dot in a
	 * path cannot match any character.
	 */
	private static function pattern( string $route ): string {
		$out    = '';
		$offset = 0;

		// Named groups are kept verbatim; the gaps between them are quoted.
		if ( preg_match_all( '/\(\?P<[a-z_]+>[^)]*\)/i', $route, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$out   .= preg_quote( substr( $route, $offset, (int) $match[1] - $offset ), '#' );
				$out   .= (string) $match[0];
				$offset = (int) $match[1] + strlen( (string) $match[0] );
			}
		}

		$out .= preg_quote( substr( $route, $offset ), '#' );

		return '#^' . $out . '/?$#';
	}

	/** Used by the tests, which register routes per case. */
	public static function reset(): void {
		self::$routes = array();
	}

	/**
	 * The registered routes, keyed by path.
	 *
	 * Shaped like WordPress's get_routes() only as far as callers need: a map
	 * from route string to its definitions.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function routes(): array {
		$out = array();

		foreach ( self::$routes as $route ) {
			$out[ $route['route'] ][] = array(
				'methods'  => array_fill_keys( $route['methods'], true ),
				'callback' => $route['callback'],
			);
		}

		return $out;
	}

	public static function count(): int {
		return count( self::$routes );
	}

	/**
	 * Run a request through the matching route.
	 *
	 * Returns a response in every case, including refusals, so the caller never
	 * has to distinguish "no route" from "route that failed".
	 */
	public static function dispatch( \WP_REST_Request $request ): \WP_REST_Response {
		$path   = '/' . trim( $request->get_route(), '/' );
		$method = $request->get_method();

		$matched_path = false;

		foreach ( self::$routes as $route ) {
			if ( ! preg_match( $route['pattern'], $path, $found ) ) {
				continue;
			}

			$matched_path = true;

			if ( ! in_array( $method, $route['methods'], true ) ) {
				continue;
			}

			// Named captures become URL parameters.
			$params = array();

			foreach ( $found as $key => $value ) {
				if ( is_string( $key ) ) {
					$params[ $key ] = $value;
				}
			}

			$request->set_url_params( $params );

			/*
			 * The declared arguments, enforced before the callback runs.
			 *
			 * WordPress validated the 'args' schema itself and answered a missing
			 * required parameter with rest_missing_callback_param and a 400, so
			 * the handlers were written assuming their required parameters are
			 * present. Skipping this does not merely change an error code: it
			 * hands the handler a null it never expected.
			 */
			$invalid = self::check_args( $request, $route['args'] );

			if ( null !== $invalid ) {
				return self::error_response( $invalid );
			}

			if ( null !== $route['permission'] ) {
				$allowed = ( $route['permission'] )( $request );

				if ( $allowed instanceof \WP_Error ) {
					return self::error_response( $allowed );
				}

				if ( true !== $allowed ) {
					return self::error_response(
						new \WP_Error(
							'rest_forbidden',
							__( 'You are not allowed to do that.' ),
							array( 'status' => rest_authorization_required_code() )
						)
					);
				}
			}

			$result = ( $route['callback'] )( $request );

			if ( $result instanceof \WP_Error ) {
				return self::error_response( $result );
			}

			return rest_ensure_response( $result );
		}

		if ( $matched_path ) {
			return self::error_response(
				new \WP_Error(
					'rest_no_route',
					__( 'That method is not allowed on this route.' ),
					array( 'status' => 405 )
				)
			);
		}

		return self::error_response(
			new \WP_Error( 'rest_no_route', __( 'No route matched.' ), array( 'status' => 404 ) )
		);
	}

	/**
	 * Check a request against a route's declared arguments.
	 *
	 * Deliberately narrow: required-ness and the scalar types the routes
	 * actually declare. A fuller schema validator would be more code and less
	 * trustworthy, and the handlers sanitise their own inputs regardless.
	 *
	 * @param array<string,mixed> $args
	 */
	private static function check_args( \WP_REST_Request $request, array $args ): ?\WP_Error {
		foreach ( $args as $name => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}

			$value = $request->get_param( (string) $name );

			if ( null === $value ) {
				if ( ! empty( $spec['required'] ) ) {
					return new \WP_Error(
						'rest_missing_callback_param',
						sprintf( __( 'Missing parameter: %s' ), (string) $name ),
						array(
							'status' => 400,
							'params' => array( (string) $name ),
						)
					);
				}

				continue;
			}

			$type = (string) ( $spec['type'] ?? '' );

			if ( 'integer' === $type && ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {
				return self::invalid( (string) $name, __( 'is not a whole number' ) );
			}

			if ( 'boolean' === $type && ! is_bool( $value ) && ! in_array( $value, array( '0', '1', 0, 1, 'true', 'false' ), true ) ) {
				return self::invalid( (string) $name, __( 'is not true or false' ) );
			}

			if ( 'string' === $type && ! is_string( $value ) && ! is_numeric( $value ) ) {
				return self::invalid( (string) $name, __( 'is not text' ) );
			}

			if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
				return self::invalid( (string) $name, __( 'is not one of the accepted values' ) );
			}
		}

		return null;
	}

	private static function invalid( string $name, string $why ): \WP_Error {
		return new \WP_Error(
			'rest_invalid_param',
			sprintf( __( '%1$s %2$s.' ), $name, $why ),
			array(
				'status' => 400,
				'params' => array( $name ),
			)
		);
	}

	/**
	 * An error in the shape WordPress sends it.
	 *
	 * The admin JavaScript reads data.message on failure and the tests read
	 * data['code'], so both keys are present and named the same way.
	 */
	public static function error_response( \WP_Error $error ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			),
			$error->get_status()
		);
	}

	/**
	 * Build a request from the live PHP superglobals.
	 */
	public static function from_globals( string $path ): \WP_REST_Request {
		$request = new \WP_REST_Request(
			(string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ),
			$path
		);

		$body = (string) file_get_contents( 'php://input' );

		$request->set_body( $body );
		$request->set_header( 'content-type', (string) ( $_SERVER['CONTENT_TYPE'] ?? '' ) );

		/*
		 * X-WP-Nonce, because that is the header admin/js/app.js sends.
		 *
		 * Renaming it would mean editing the one line in the dashboard module
		 * that sets it, and then the module would no longer be the same file the
		 * plugin ships. Keeping the wire format identical is worth more than a
		 * tidier header name: it means the front-end is genuinely unmodified,
		 * and a bug in it is a bug in both builds rather than in the port.
		 */
		$request->set_header( 'x-wp-nonce', (string) ( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ) );

		/** @var array<string,mixed> $query */
		$query = $_GET;
		$request->set_query_params( $query );

		return $request;
	}

	/**
	 * Send a response to the browser.
	 */
	public static function send( \WP_REST_Response $response ): void {
		if ( ! headers_sent() ) {
			http_response_code( $response->get_status() );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );

			foreach ( $response->get_headers() as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		echo (string) wp_json_encode( $response->get_data() );
	}
}
