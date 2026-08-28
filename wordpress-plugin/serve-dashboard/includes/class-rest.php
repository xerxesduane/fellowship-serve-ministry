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

	/*
	 * Suggestion previews allowed per IP per window.
	 *
	 * Higher than the submission limit and deliberately so: a preview writes
	 * nothing and reveals nothing about anybody else, and somebody re-reading
	 * their own results page must not be locked out of it. Still limited,
	 * because it is unauthenticated and does real work.
	 */
	private const PREVIEW_LIMIT = 60;

	/** Largest profile a preview will consider, in bytes of JSON. */
	private const PREVIEW_MAX_BYTES = 64 * 1024;

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

		/*
		 * The person answering their own invitation.
		 *
		 * Public, because they are not a WordPress user and never will be. The
		 * emailed token is the credential, exactly as it is for confirming an
		 * address or resuming a draft.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/invite-response',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'invite_response' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'response' => array(
						'required' => true,
						'type'     => 'string',
					),
					'note'     => array( 'type' => 'string' ),
				),
			)
		);

		/*
		 * What teams a finished profile points to, before it is shared.
		 *
		 * The assessment used to show a person its own top three, ranked on
		 * spiritual-gift name overlap in the browser, while their leader saw
		 * every team ranked across all five S.H.A.P.E. dimensions. Two
		 * different answers to the same question, and the person's was the one
		 * printed on the profile they downloaded — so a conversation could open
		 * with "it said Worship" about a suggestion the leader could not see.
		 *
		 * Public, because the person filling in the assessment is not a
		 * WordPress user. Safe to be public because it is a pure calculation:
		 * it stores nothing, writes nothing, logs nothing, reads no other
		 * person's data, and returns only team names with the person's own
		 * words quoted back as the reasons. It needs no name, email or phone
		 * and is given none.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/suggestions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'preview_suggestions' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'profile' => array(
						'required' => true,
						'type'     => 'object',
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
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function invite_response( \WP_REST_Request $request ) {
		$saved = Invitation::respond(
			(string) $request->get_param( 'token' ),
			(string) $request->get_param( 'response' ),
			(string) $request->get_param( 'note' )
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'Thank you — that is recorded, and a ministry leader will see it.', 'serve-dashboard' ),
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
				'required'          => true,
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

		/*
		 * Checked here as well as in the form, because `required` in the markup
		 * is a convenience for the person filling it in and no obstacle at all
		 * to anything posting straight at the endpoint.
		 *
		 * Deliberately forgiving about shape: this congregation's numbers come
		 * from a dozen countries and no format is common to all of them. Enough
		 * digits to dial, and no characters that mean it is not a number.
		 */
		$phone  = trim( (string) $request->get_param( 'phone' ) );
		$digits = preg_replace( '/\D/', '', $phone );

		if ( '' === $phone ) {
			return new \WP_Error( 'serve_phone_required', __( 'Please add a phone number so a leader can reach you.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		if ( strlen( (string) $digits ) < 7 || preg_match( '#[^\d\s+()./-]#', $phone ) ) {
			return new \WP_Error( 'serve_bad_phone', __( 'That phone number does not look complete.', 'serve-dashboard' ), array( 'status' => 422 ) );
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

		/*
		 * `contact` is deliberately absent from this whitelist.
		 *
		 * The journey now carries name, email and phone inside the profile so
		 * the consent page can prefill them rather than asking twice. Once the
		 * form is posted they belong in their own columns, which is what the
		 * dashboard, the search, and the export all read. Adding them here as
		 * well would put a second copy of the same personal data inside
		 * profile_json, free to drift from the columns and easy to miss when
		 * erasing someone. One copy, in one place.
		 */

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

	/**
	 * Rank a profile without storing it.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preview_suggestions( \WP_REST_Request $request ) {
		$limited = self::check_rate_limit( self::PREVIEW_LIMIT, 'preview' );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$profile = $request->get_param( 'profile' );

		if ( ! is_array( $profile ) ) {
			return new \WP_Error(
				'serve_bad_profile',
				__( 'That profile could not be read.', 'serve-dashboard' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Bounded before anything else looks at it. An unauthenticated endpoint
		 * that walks every value in a structure it was handed needs a size it
		 * refuses beyond, or a large enough payload becomes the attack.
		 */
		if ( strlen( (string) wp_json_encode( $profile ) ) > self::PREVIEW_MAX_BYTES ) {
			return new \WP_Error(
				'serve_profile_too_large',
				__( 'That profile is larger than this can consider.', 'serve-dashboard' ),
				array( 'status' => 413 )
			);
		}

		self::bump_rate_limit( 'preview' );

		/*
		 * Only the sections matching actually reads, flattened to strings.
		 *
		 * Anything else the browser included — a name, an email, a note to self
		 * — is dropped rather than walked, quoted back or reaching a log. The
		 * shapes are pinned as well as the keys: an array where a string belongs
		 * used to reach a string cast and emit a PHP warning for every value, so
		 * a 200-byte request could grow a log without limit on any site with
		 * WP_DEBUG_LOG enabled. string_list() keeps scalars and discards the
		 * rest, exactly as the submission path does.
		 */
		$considered = array(
			'spiritualGifts' => array(
				'likely' => self::string_list( $profile['spiritualGifts']['likely'] ?? array() ),
			),
			'heart'          => array(
				'roles'  => self::string_list( $profile['heart']['roles'] ?? array() ),
				'people' => self::string_list( $profile['heart']['people'] ?? array() ),
				'causes' => self::string_list( $profile['heart']['causes'] ?? array() ),
			),
			'abilities'      => self::string_list( $profile['abilities'] ?? array() ),
			'experiences'    => array_map(
				array( __CLASS__, 'string_list' ),
				array_filter( (array) ( $profile['experiences'] ?? array() ), 'is_array' )
			),
			'personality'    => self::string_list( $profile['personality'] ?? array() ),
		);

		$out = array();
		foreach ( Matching::rank_profile( $considered ) as $match ) {
			$out[] = array(
				'team'          => $match['team_name'],
				'strength'      => $match['strength'],
				'strengthLabel' => $match['strength_label'],
				'reasons'       => array_column( $match['reasons'], 'label' ),
				'caveat'        => $match['caveat'],
			);
		}

		return new \WP_REST_Response(
			array(
				'suggestions' => $out,
				'personality' => Matching::personality_notes( $considered ),
			)
		);
	}

	/**
	 * One counter per kind of request, not one shared by all of them.
	 *
	 * Previews and submissions counted against the same bucket in the first
	 * version of the preview endpoint, so somebody who re-read their own
	 * results page a handful of times spent their submission allowance and was
	 * then refused when they tried to share the profile. The cheap, repeatable
	 * request must not be able to lock somebody out of the important one.
	 */
	private static function rate_key( string $bucket = 'submit' ): string {
		return 'serve_rl_' . $bucket . '_' . ( Privacy::hash_ip() ?: 'unknown' );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function check_rate_limit( ?int $ceiling = null, string $bucket = 'submit' ) {
		$ceiling = $ceiling ?? self::RATE_LIMIT;
		$count   = (int) get_transient( self::rate_key( $bucket ) );

		if ( $count >= $ceiling ) {
			return new \WP_Error(
				'serve_rate_limited',
				__( 'That is a lot of submissions from one place. Please try again later.', 'serve-dashboard' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	private static function bump_rate_limit( string $bucket = 'submit' ): void {
		$key   = self::rate_key( $bucket );
		$count = (int) get_transient( $key );

		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}
}
