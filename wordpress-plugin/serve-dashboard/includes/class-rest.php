<?php
/**
 * Public REST endpoint that receives a completed SHAPE profile.
 *
 * This is the one anonymous write surface in the plugin, so it is deliberately
 * narrow: consent is mandatory, the payload is whitelisted field by field, and
 * repeat posts from one address are throttled.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest {

	public const NAMESPACE = 'serve/v1';

	/** Submissions allowed per IP per window. */
	private const RATE_LIMIT  = 5;
	private const RATE_WINDOW = HOUR_IN_SECONDS;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/submissions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create' ),
				// Public by design: the person filling in the form is not a
				// WordPress user. Abuse control is in the handler.
				'permission_callback' => '__return_true',
				'args'                => self::args(),
			)
		);

		// Saving a place and resuming it. Public, because the person is not a
		// WordPress user; the token is the credential on the way back.
		register_rest_route(
			self::NAMESPACE,
			'/draft',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_draft' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'email'   => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_email',
						),
						'answers' => array(
							'required' => true,
							'type'     => 'object',
						),
						'step'    => array( 'type' => 'integer' ),
						'consent' => array(
							'required' => true,
							'type'     => 'boolean',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'resume_draft' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/consent-text',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response(
						array(
							'version' => Privacy::POLICY_VERSION,
							'text'    => Privacy::purpose_text(),
							'months'  => Privacy::retention_months(),
						)
					);
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function args(): array {
		return array(
			'consent'       => array(
				'required' => true,
				'type'     => 'boolean',
			),
			'display_name'  => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'email'         => array(
				'required'          => true,
				'type'              => 'string',
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
			),
			'phone'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'tenure_months' => array(
				'type' => 'integer',
			),
			'profile'       => array(
				'required' => true,
				'type'     => 'object',
			),
			'honeypot'      => array(
				'type' => 'string',
			),
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create( \WP_REST_Request $request ) {
		// Bots fill in every field they find, including the hidden one.
		if ( '' !== trim( (string) $request->get_param( 'honeypot' ) ) ) {
			return new \WP_Error( 'serve_rejected', __( 'Submission rejected.', 'serve-dashboard' ), array( 'status' => 400 ) );
		}

		if ( ! $request->get_param( 'consent' ) ) {
			return new \WP_Error(
				'serve_consent_required',
				__( 'We can only pass your profile to a ministry leader if you agree to it being stored.', 'serve-dashboard' ),
				array( 'status' => 422 )
			);
		}

		$throttle = self::check_rate_limit();
		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		$email = (string) $request->get_param( 'email' );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'serve_bad_email', __( 'That email address does not look right.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		$name = trim( (string) $request->get_param( 'display_name' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'serve_name_required', __( 'Please tell us your name.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		$profile = self::sanitize_profile( (array) $request->get_param( 'profile' ) );

		$tenure = $request->get_param( 'tenure_months' );
		$tenure = null === $tenure ? null : max( 0, min( 600, (int) $tenure ) );

		$result = Submissions::create(
			array(
				'display_name'    => $name,
				'email'           => $email,
				'phone'           => (string) $request->get_param( 'phone' ),
				'tenure_months'   => $tenure,
				'gifts_likely'    => $profile['spiritualGifts']['likely'] ?? array(),
				'languages'       => self::extract_languages( $profile ),
				'suggested_teams' => self::extract_team_slugs( $profile ),
				'profile'         => $profile,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::bump_rate_limit();

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Almost done — please check your email and open the confirmation link. Your profile is not shared with anyone until you do.', 'serve-dashboard' ),
			),
			201
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save_draft( \WP_REST_Request $request ) {
		if ( ! $request->get_param( 'consent' ) ) {
			return new \WP_Error(
				'serve_consent_required',
				__( 'We can only email you a link if you agree to your answers being held for that purpose.', 'serve-dashboard' ),
				array( 'status' => 422 )
			);
		}

		$result = Draft::save(
			(string) $request->get_param( 'email' ),
			(array) $request->get_param( 'answers' ),
			(int) $request->get_param( 'step' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Check your email for a link to carry on. Your answers stay on this device too.', 'serve-dashboard' ),
			),
			201
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resume_draft( \WP_REST_Request $request ) {
		$result = Draft::resume( (string) $request->get_param( 'token' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( $result );
	}

	/**
	 * Whitelist the profile shape rather than trusting whatever arrives.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private static function sanitize_profile( array $raw ): array {
		$clean = array();

		foreach ( array( 'likely', 'possible', 'unlikely' ) as $bucket ) {
			$clean['spiritualGifts'][ $bucket ] = self::string_list( $raw['spiritualGifts'][ $bucket ] ?? array() );
		}

		foreach ( array( 'roles', 'people', 'causes' ) as $key ) {
			$clean['heart'][ $key ] = self::string_list( $raw['heart'][ $key ] ?? array() );
		}

		$clean['abilities']   = self::string_list( $raw['abilities'] ?? array() );
		$clean['personality'] = self::string_list( $raw['personality'] ?? array() );

		$clean['experiences'] = array();
		if ( isset( $raw['experiences'] ) && is_array( $raw['experiences'] ) ) {
			foreach ( $raw['experiences'] as $label => $values ) {
				$clean['experiences'][ sanitize_text_field( (string) $label ) ] = self::string_list( $values );
			}
		}

		$clean['availability'] = array(
			'priority' => sanitize_text_field( (string) ( $raw['availability']['priority'] ?? '' ) ),
			'hours'    => sanitize_text_field( (string) ( $raw['availability']['hours'] ?? '' ) ),
			'timing'   => self::string_list( $raw['availability']['timing'] ?? array() ),
		);

		$clean['recommendedNextStep'] = sanitize_textarea_field( (string) ( $raw['recommendedNextStep'] ?? '' ) );

		$clean['recommendedMinistries'] = array();
		if ( isset( $raw['recommendedMinistries'] ) && is_array( $raw['recommendedMinistries'] ) ) {
			foreach ( $raw['recommendedMinistries'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$clean['recommendedMinistries'][] = array(
					'ministry'     => sanitize_text_field( (string) ( $entry['ministry'] ?? '' ) ),
					'matchedGifts' => self::string_list( $entry['matchedGifts'] ?? array() ),
				);
			}
		}

		return $clean;
	}

	/**
	 * @param mixed $values
	 * @return string[]
	 */
	private static function string_list( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$clean = array();
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$text = sanitize_text_field( (string) $value );
				if ( '' !== $text ) {
					$clean[] = $text;
				}
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Pull language abilities out of the abilities list.
	 *
	 * The assessment already collects these; nothing has ever been able to
	 * search on them. In a church where the congregation spans dozens of first
	 * languages, "who can help in Tagalog" is a real weekly question.
	 *
	 * @param array<string,mixed> $profile
	 * @return string[]
	 */
	private static function extract_languages( array $profile ): array {
		$languages = array();

		foreach ( (array) ( $profile['abilities'] ?? array() ) as $ability ) {
			$ability = (string) $ability;

			// Nested options arrive as "Parent: Child", e.g. "Languages: Urdu".
			if ( stripos( $ability, 'language' ) !== false && strpos( $ability, ':' ) !== false ) {
				$languages[] = trim( substr( $ability, strpos( $ability, ':' ) + 1 ) );
			}
		}

		return array_values( array_unique( array_filter( $languages ) ) );
	}

	/**
	 * @param array<string,mixed> $profile
	 * @return string[]
	 */
	private static function extract_team_slugs( array $profile ): array {
		$slugs = array();

		foreach ( (array) ( $profile['recommendedMinistries'] ?? array() ) as $entry ) {
			$name = (string) ( $entry['ministry'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			// The assessment writes "GROW – Small Group" with an en dash; the
			// seeded slugs use a hyphen. Normalise before slugifying.
			$name    = str_replace( array( "\u{2013}", "\u{2014}" ), '-', $name );
			$slugs[] = sanitize_title( $name );
		}

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	private static function rate_key(): string {
		return 'serve_rl_' . ( Privacy::hash_ip() ?: 'unknown' );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function check_rate_limit() {
		$count = (int) get_transient( self::rate_key() );

		if ( $count >= self::RATE_LIMIT ) {
			return new \WP_Error(
				'serve_rate_limited',
				__( 'That is a lot of submissions from one place. Please try again later.', 'serve-dashboard' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	private static function bump_rate_limit(): void {
		$key   = self::rate_key();
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}
}
