<?php
/**
 * Authenticated read/write routes the dashboard app calls.
 *
 * Every route enforces capability and team scope server-side. The JavaScript
 * decides what to draw; it never decides what the user is allowed to see.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Dashboard {

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$ns = Rest::NAMESPACE;

		register_rest_route(
			$ns,
			'/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);

		register_rest_route(
			$ns,
			'/people',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'people' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'status'          => array( 'type' => 'string' ),
					'team_id'         => array( 'type' => 'integer' ),
					'language'        => array( 'type' => 'string' ),
					'search'          => array( 'type' => 'string' ),
					'due_only'        => array( 'type' => 'boolean' ),
					'include_snoozed' => array( 'type' => 'boolean' ),
					'orderby'         => array( 'type' => 'string' ),
					'page'            => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page'        => array(
						'type'    => 'integer',
						'default' => 25,
					),
				),
			)
		);

		/*
		 * Friction. Any leader who can open the dashboard can say what is not
		 * working — the people who hit the problems are the point.
		 */
		register_rest_route(
			$ns,
			'/friction',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'record_friction' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
				'args'                => array(
					'area' => array( 'type' => 'string' ),
					'body' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'person' ),
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);

		register_rest_route(
			$ns,
			'/teams/(?P<id>\d+)/candidates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => static function ( \WP_REST_Request $request ) {
					$team = Teams::get( (int) $request['id'] );

					if ( ! $team ) {
						return new \WP_Error( 'serve_not_found', __( 'Team not found.', 'serve-dashboard' ), array( 'status' => 404 ) );
					}

					return new \WP_REST_Response(
						array(
							'team'       => array(
								'id'      => (int) $team->id,
								'name'    => $team->name,
								'target'  => (int) $team->target_headcount,
								'current' => (int) $team->current_headcount,
								'gap'     => max( 0, (int) $team->target_headcount - (int) $team->current_headcount ),
								'safeguarded' => (bool) (int) $team->requires_safeguarding,
							),
							'candidates' => Teams::candidates( (int) $request['id'] ),
						)
					);
				},
				'permission_callback' => array( __CLASS__, 'can_view' ),
			)
		);

		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)/claim',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'claim' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'release' => array( 'type' => 'boolean' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)/notes',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_notes' ),
					'permission_callback' => array( __CLASS__, 'can_view' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'add_note' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => array(
						'body' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_status' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'status'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'team_id'        => array( 'type' => 'integer' ),
					'next_action_at' => array( 'type' => 'string' ),
					'snooze_until'   => array( 'type' => 'string' ),
					'decline_reason' => array( 'type' => 'string' ),
				),
			)
		);

		/*
		 * Recording a background check.
		 *
		 * Its own route, and its own capability. Only a pastor may do this, and
		 * it must not travel with a pipeline move — a leader who can move
		 * someone along must not be able to clear the check that lets them.
		 *
		 * Until this existed there was no reachable way to mark a check cleared
		 * at all, so the gate on Fellowship Kids and Youth Ministry was not
		 * merely strict, it was shut: nobody could ever be placed on either.
		 */
		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'erase' ),
				'permission_callback' => array( __CLASS__, 'can_erase' ),
			)
		);

		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)/invite',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'invite' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'method'  => array( 'type' => 'string' ),
					'subject' => array( 'type' => 'string' ),
					'body'    => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)/settled',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'mark_settled' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/people/(?P<id>\d+)/safeguarding',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_safeguarding' ),
				'permission_callback' => array( __CLASS__, 'can_verify_safeguarding' ),
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function can_verify_safeguarding(): bool {
		return current_user_can( Roles::CAP_VERIFY_SAFEGUARD );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function record_friction( \WP_REST_Request $request ) {
		$saved = Friction::record(
			(string) $request->get_param( 'area' ),
			(string) $request->get_param( 'body' )
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new \WP_REST_Response( array( 'recorded' => true ) );
	}

	/**
	 * Close a settling-in check.
	 *
	 * Clears the date rather than changing the status: they are still Placed,
	 * somebody has simply been to see how it is going.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mark_settled( \WP_REST_Request $request ) {
		global $wpdb;

		$id = (int) $request->get_param( 'id' );

		if ( ! Roles::can_view_submission( $id ) ) {
			return new \WP_Error( 'serve_forbidden', __( 'You cannot change this record.', 'serve-dashboard' ), array( 'status' => 403 ) );
		}

		$wpdb->update(
			Schema::table( 'submissions' ),
			array(
				'next_action_at' => null,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		Audit::log( 'submission.settled', 'submission', $id );

		return new \WP_REST_Response( array( 'settled' => true ) );
	}

	/**
	 * Invite somebody, or record that a leader will do it themselves.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function invite( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! Roles::can_view_submission( $id ) ) {
			return new \WP_Error( 'serve_forbidden', __( 'You cannot change this record.', 'serve-dashboard' ), array( 'status' => 403 ) );
		}

		$method = Invitation::METHOD_PERSONAL === $request->get_param( 'method' )
			? Invitation::METHOD_PERSONAL
			: Invitation::METHOD_EMAIL;

		$result = Invitation::METHOD_PERSONAL === $method
			? Invitation::record_personal( $id )
			: Invitation::send( $id, (string) $request->get_param( 'subject' ), (string) $request->get_param( 'body' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Either way somebody has taken this on, so the stage moves.
		Submissions::set_status( $id, Schema::STATUS_CONTACTED );

		return self::person( $request );
	}

	public static function can_erase(): bool {
		return current_user_can( Roles::CAP_MANAGE_SETTINGS );
	}

	/**
	 * Delete somebody's profile on request.
	 *
	 * A plugin that takes consent, states a retention period and records
	 * religious belief has to be able to honour "please remove my details".
	 * The only path to this was a form on a screen nothing rendered, so the
	 * answer to such a request was, in practice, a database query.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function erase( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! Roles::can_view_submission( $id ) ) {
			return new \WP_Error( 'serve_forbidden', __( 'You cannot change this record.', 'serve-dashboard' ), array( 'status' => 403 ) );
		}

		Privacy::erase_submission( $id );

		return new \WP_REST_Response( array( 'erased' => true ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_safeguarding( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! Roles::can_view_submission( $id ) ) {
			return new \WP_Error( 'serve_forbidden', __( 'You cannot change this record.', 'serve-dashboard' ), array( 'status' => 403 ) );
		}

		$status = (string) $request->get_param( 'status' );

		if ( ! in_array( $status, Safeguarding::statuses(), true ) ) {
			return new \WP_Error( 'serve_bad_safeguarding', __( 'Unknown background-check status.', 'serve-dashboard' ), array( 'status' => 422 ) );
		}

		if ( ! Safeguarding::set_status( $id, $status ) ) {
			return new \WP_Error( 'serve_safeguarding_failed', __( 'Could not record that check.', 'serve-dashboard' ), array( 'status' => 500 ) );
		}

		return self::person( $request );
	}

	public static function can_view(): bool {
		return current_user_can( Roles::CAP_VIEW_DASHBOARD );
	}

	public static function can_manage(): bool {
		return current_user_can( Roles::CAP_MANAGE_PLACE );
	}

	/**
	 * Everything the dashboard home screen needs, in one request.
	 *
	 * One round trip rather than five, because these widgets are useless
	 * individually and the payload is small.
	 */
	public static function dashboard(): \WP_REST_Response {
		$gaps = array();
		foreach ( Teams::gaps() as $team ) {
			$gaps[] = array(
				'id'            => (int) $team->id,
				'name'          => $team->name,
				'gap'           => (int) $team->gap,
				'target'        => (int) $team->target_headcount,
				'current'       => (int) $team->current_headcount,
				'below_minimum' => (bool) $team->below_minimum,
				// Placements the typed-in headcount cannot yet know about.
				'placedSince'   => (int) $team->placed_since,
			);
		}

		/*
		 * Teams whose hand-typed headcount has been overtaken by placements.
		 *
		 * Only sent to somebody who can actually correct it. A ministry leader
		 * can place people — and so cause the drift — but cannot edit team
		 * capacity, so a card asking them to confirm a number they have no
		 * permission to change would be an instruction to do nothing. They
		 * still see the drift note on the gap bars themselves.
		 */
		$headcount_checks = array();
		if ( current_user_can( Roles::CAP_MANAGE_TEAMS ) ) {
			foreach ( Teams::needs_headcount_check( Roles::visible_team_ids() ) as $team ) {
				$headcount_checks[] = array(
					'id'          => (int) $team->id,
					'name'        => $team->name,
					'current'     => (int) $team->current_headcount,
					'placedSince' => (int) $team->placed_since,
					// Null means never confirmed, which is not "today".
					'daysSince'   => $team->days_since_check,
				);
			}
		}

		$teams = array();
		foreach ( Teams::all() as $team ) {
			$teams[] = array(
				'id'   => (int) $team->id,
				'name' => $team->name,
			);
		}

		$user = wp_get_current_user();

		return new \WP_REST_Response(
			array(
				'greeting'       => array(
					'name'    => $user->first_name ?: $user->display_name,
					'canManage' => current_user_can( Roles::CAP_MANAGE_PLACE ),
					'canViewAll' => current_user_can( Roles::CAP_VIEW_ALL ),
				),
				'metrics'        => array_values(
					array_map(
						static function ( $key, $metric ) {
							$metric['key'] = $key;

							return $metric;
						},
						array_keys( Metrics::headline() ),
						Metrics::headline()
					)
				),
				// Never-contacted first, then by follow-up date. Six rather than
				// five so a busy week does not push the newest arrival off.
				'priority'       => self::rows( Submissions::query( array( 'limit' => 6, 'orderby' => 'waiting' ) ) ),
				'gaps'           => $gaps,

				/*
				 * How many people the ranking matched to nothing. A count rather
				 * than a list, and zero for a scoped leader, because it is a
				 * question about the whole congregation.
				 *
				 * Worth watching: if this climbs, the honest response is to look
				 * again at where the bar for a strong match sits, not to quietly
				 * start suggesting weaker ones.
				 */
				'unmatchedCount' => Placements::unmatched_count(),
				// Numbers somebody needs to re-confirm, not teams short of people.
				'headcountChecks' => $headcount_checks,
				'followups'      => Metrics::upcoming_followups(),
				// People placed a while ago that nobody has looked in on.
				'settling'       => Submissions::settling_in(),
				'gifts'          => Metrics::gift_distribution(),
				'teams'          => $teams,
				'planningCenter' => Planning_Center::status(),
				'statuses'       => Schema::status_labels(),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 */
	public static function people( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$rows = Submissions::query(
			array(
				'status'          => (string) $request->get_param( 'status' ),
				'team_id'         => (int) $request->get_param( 'team_id' ),
				'language'        => (string) $request->get_param( 'language' ),
				'search'          => (string) $request->get_param( 'search' ),
				'due_only'        => (bool) $request->get_param( 'due_only' ),
				'include_snoozed' => (bool) $request->get_param( 'include_snoozed' ),
				'orderby'         => (string) $request->get_param( 'orderby' ) ?: 'next_action_at',
				// Fetch one extra row to learn whether another page exists,
				// which is cheaper than a second COUNT query.
				'limit'           => $per_page + 1,
				'offset'          => ( $page - 1 ) * $per_page,
			)
		);

		$has_more = count( $rows ) > $per_page;

		return new \WP_REST_Response(
			array(
				'people'  => self::rows( array_slice( $rows, 0, $per_page ) ),
				'page'    => $page,
				'hasMore' => $has_more,
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function person( \WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! Roles::can_view_submission( $id ) ) {
			return new \WP_Error(
				'serve_forbidden',
				__( 'That profile belongs to a team you do not lead.', 'serve-dashboard' ),
				array( 'status' => 403 )
			);
		}

		$submission = Submissions::get( $id );
		if ( ! $submission ) {
			return new \WP_Error( 'serve_not_found', __( 'Profile not found.', 'serve-dashboard' ), array( 'status' => 404 ) );
		}

		Audit::log( Audit::ACTION_VIEWED, 'submission', $id );

		$profile = Submissions::profile( $submission );
		$consent = Consent::for_submission( $id );

		return new \WP_REST_Response(
			array(
				'id'             => $id,
				'name'           => $submission->display_name,
				'initials'       => self::initials( $submission->display_name ),
				'email'          => $submission->email,
				'phone'          => $submission->phone,
				'status'         => $submission->status,
				'statusLabel'    => Schema::status_labels()[ $submission->status ] ?? $submission->status,
				'submittedAt'    => mysql2date( get_option( 'date_format' ), $submission->submitted_at ),
				'updatedAt'      => mysql2date( get_option( 'date_format' ), $submission->updated_at ),
				'isStale'        => Submissions::is_stale( $submission ),
				'tenureMonths'   => null === $submission->tenure_months ? null : (int) $submission->tenure_months,
				'nextActionAt'   => $submission->next_action_at,
				'snoozeUntil'    => $submission->snooze_until,
				'safeguarding'   => array(
					'status' => $submission->safeguarding_status,
					'label'  => Safeguarding::status_labels()[ $submission->safeguarding_status ] ?? '',
					'cleared' => Safeguarding::STATUS_CLEARED === $submission->safeguarding_status,
					'relevant' => Safeguarding::STATUS_NOT_REQUIRED !== $submission->safeguarding_status,
				),
				'shape'          => self::shape_dimensions( $profile, $submission ),
				'redacted'       => ! empty( $profile['_redacted'] ),
				'matches'        => Matching::for_submission( $submission, $profile ),
				/*
				 * Sent once, not per match. Personality shapes how a person
				 * serves rather than which team they belong on, so it sits
				 * beside the suggestions instead of inside each one.
				 */
				'personalityNotes' => Matching::personality_notes( $profile ),

				/*
				 * Whether the ranking found anything at all, which is a
				 * different question from whether there are placement rows: an
				 * unmatched person has a catch-all row and still matched nothing.
				 */
				'unmatched'      => empty( Submissions::decode_list( $submission->suggested_teams ) ),

				/*
				 * Every active team, so somebody who matched nothing can still
				 * be recorded onto whichever team the conversation settled on.
				 * Sent only when it is needed: the ordinary case chooses from
				 * the placements below and this would be noise.
				 */
				'allTeams'       => empty( Submissions::decode_list( $submission->suggested_teams ) )
					? array_map(
						static fn( $t ) => array(
							'teamId'      => (int) $t->id,
							'teamName'    => $t->name,
							'safeguarded' => (bool) (int) $t->requires_safeguarding,
						),
						Teams::all()
					)
					: array(),
				'placements'     => array_map(
					static fn( $p ) => array(
						'teamId'   => (int) $p->team_id,
						'teamName' => $p->team_name,
						'status'   => $p->status,
						'safeguarded' => (bool) (int) $p->requires_safeguarding,
					),
					Admin::placements_for( $id )
				),
				'owner'          => Followup::owner( $submission ),
				'notes'          => Followup::notes( $id ),
				'consent'        => $consent ? array(
					'date'    => mysql2date( get_option( 'date_format' ), $consent->consented_at ),
					'version' => $consent->policy_version,
					'months'  => (int) $consent->retention_months,
				) : null,
				'planningCenterUrl' => Planning_Center::person_search_url( (string) $submission->email ),
				// Piece one: what the leader would send if they chose to.
				'inviteDraft'    => Invitation::draft( $submission ),
				'invitedAt'      => $submission->invited_at
					? mysql2date( get_option( 'date_format' ), $submission->invited_at )
					: null,
				'inviteMethod'   => $submission->invite_method,
				// Piece two: what the person said back, if anything.
				'inviteResponse' => Invitation::summary( $submission ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function update_status( \WP_REST_Request $request ) {
		$result = Submissions::set_status(
			(int) $request['id'],
			(string) $request->get_param( 'status' ),
			(int) $request->get_param( 'team_id' ),
			array(
				'next_action_at' => $request->get_param( 'next_action_at' ),
				'snooze_until'   => $request->get_param( 'snooze_until' ),
				'decline_reason' => $request->get_param( 'decline_reason' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Metrics::flush();

		return new \WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function claim( \WP_REST_Request $request ) {
		$id = (int) $request['id'];

		$result = $request->get_param( 'release' )
			? Followup::release( $id )
			: Followup::claim( $id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$submission = Submissions::get( $id );

		return new \WP_REST_Response(
			array(
				'ok'    => true,
				'owner' => $submission ? Followup::owner( $submission ) : null,
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function list_notes( \WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! Roles::can_view_submission( $id ) ) {
			return new \WP_Error( 'serve_forbidden', __( 'Not allowed.', 'serve-dashboard' ), array( 'status' => 403 ) );
		}

		return new \WP_REST_Response( array( 'notes' => Followup::notes( $id ) ) );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_note( \WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = Followup::add_note( $id, (string) $request->get_param( 'body' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response( array( 'notes' => Followup::notes( $id ) ), 201 );
	}

	/**
	 * Shape one submission row for a list.
	 *
	 * Lists deliberately carry less than the detail view: enough to prioritise,
	 * not a full profile dump in a table anyone can screenshot.
	 *
	 * @param array<int,object> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows( array $rows ): array {
		$labels   = Schema::status_labels();
		$today    = gmdate( 'Y-m-d' );

		return array_map(
			static function ( $row ) use ( $labels, $today ) {
				$suggested = array_map(
					static fn( $slug ) => ucwords( str_replace( '-', ' ', (string) $slug ) ),
					Submissions::decode_list( $row->suggested_teams )
				);

				return array(
					'id'             => (int) $row->id,
					'name'           => $row->display_name,
					'initials'       => self::initials( $row->display_name ),
					'gifts'          => array_slice( Submissions::decode_list( $row->gifts_likely ), 0, 3 ),
					'languages'      => Submissions::decode_list( $row->languages ),
					'suggestedTeams' => $suggested,

					/*
					 * No team matched. Distinct from "no teams shown", which a
					 * scoped leader also sees, and the reason the Teams column
					 * is empty for this row.
					 */
					'unmatched'      => empty( $suggested ),
					'status'         => $row->status,
					'statusLabel'    => $labels[ $row->status ] ?? $row->status,
					'nextActionAt'   => $row->next_action_at,
					'nextActionLabel' => $row->next_action_at
						? mysql2date( get_option( 'date_format' ), $row->next_action_at )
						: '',
					'isOverdue'      => (bool) ( $row->next_action_at && $row->next_action_at < $today ),
					'isDueToday'     => (bool) ( $row->next_action_at && $row->next_action_at === $today ),
					'isStale'        => ! empty( $row->is_stale ),
					'needsCheck'     => ! in_array(
						$row->safeguarding_status,
						array( Safeguarding::STATUS_NOT_REQUIRED, Safeguarding::STATUS_CLEARED ),
						true
					),
					'tenureMonths'   => null === $row->tenure_months ? null : (int) $row->tenure_months,
				);
			},
			$rows
		);
	}

	/**
	 * The five SHAPE dimensions in the order the assessment teaches them.
	 *
	 * @param array<string,mixed> $profile
	 * @return array<int,array<string,mixed>>
	 */
	private static function shape_dimensions( array $profile, object $submission ): array {
		$experiences = array();
		if ( isset( $profile['experiences'] ) ) {
			foreach ( (array) $profile['experiences'] as $values ) {
				$experiences = array_merge( $experiences, (array) $values );
			}
		}

		return array(
			array(
				'key'    => 'gifts',
				'label'  => __( 'Spiritual Gifts', 'serve-dashboard' ),
				'values' => (array) ( $profile['spiritualGifts']['likely'] ?? array() ),
			),
			array(
				'key'    => 'heart',
				'label'  => __( 'Heart / Passions', 'serve-dashboard' ),
				'values' => array_merge(
					(array) ( $profile['heart']['roles'] ?? array() ),
					(array) ( $profile['heart']['people'] ?? array() ),
					(array) ( $profile['heart']['causes'] ?? array() )
				),
			),
			array(
				'key'    => 'abilities',
				'label'  => __( 'Abilities', 'serve-dashboard' ),
				'values' => (array) ( $profile['abilities'] ?? array() ),
			),
			array(
				'key'    => 'personality',
				'label'  => __( 'Personality', 'serve-dashboard' ),
				'values' => (array) ( $profile['personality'] ?? array() ),
			),
			array(
				'key'      => 'experience',
				'label'    => __( 'Experience', 'serve-dashboard' ),
				'values'   => $experiences,
				'redacted' => ! empty( $profile['_redacted'] ),
			),
		);
	}

	private static function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) ) ?: array();
		$parts = array_filter( $parts );

		if ( ! $parts ) {
			return '?';
		}

		$first = mb_substr( (string) reset( $parts ), 0, 1 );
		$last  = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';

		return mb_strtoupper( $first . $last );
	}
}
