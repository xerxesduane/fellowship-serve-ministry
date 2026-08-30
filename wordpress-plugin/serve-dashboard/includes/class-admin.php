<?php
/**
 * Admin screens.
 *
 * The dashboard is a prioritisation view, not an analytics one: what it shows
 * is who to talk to next, not how many profiles exist. Counts appear only
 * where they change a decision.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	public const PAGE_DASHBOARD = 'serve-dashboard';
	public const PAGE_TEAMS     = 'serve-dashboard-teams';
	public const PAGE_SETTINGS  = 'serve-dashboard-settings';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_serve_save_team', array( __CLASS__, 'handle_team' ) );
		add_action( 'admin_post_serve_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_serve_resend_confirmations', array( __CLASS__, 'handle_resend_confirmations' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'SERVE Dashboard', 'serve-dashboard' ),
			__( 'SERVE', 'serve-dashboard' ),
			Roles::CAP_VIEW_DASHBOARD,
			self::PAGE_DASHBOARD,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-groups',
			26
		);

		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Follow-up', 'serve-dashboard' ),
			__( 'Follow-up', 'serve-dashboard' ),
			Roles::CAP_VIEW_DASHBOARD,
			self::PAGE_DASHBOARD,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Teams and gaps', 'serve-dashboard' ),
			__( 'Teams and gaps', 'serve-dashboard' ),
			Roles::CAP_VIEW_DASHBOARD,
			self::PAGE_TEAMS,
			array( __CLASS__, 'render_teams' )
		);

		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Settings and audit', 'serve-dashboard' ),
			__( 'Settings and audit', 'serve-dashboard' ),
			Roles::CAP_MANAGE_SETTINGS,
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'serve-dashboard' ) && false === strpos( $hook, 'serve_page' ) ) {
			return;
		}

		// The dashboard app ships its own stylesheet; loading the admin-table
		// styles there as well only invites specificity fights.
		if ( isset( $_GET['page'] ) && self::PAGE_DASHBOARD === $_GET['page'] ) {
			return;
		}

		wp_enqueue_style(
			'serve-dashboard-admin',
			SERVE_DASHBOARD_URL . 'admin/css/admin.css',
			array(),
			SERVE_DASHBOARD_VERSION
		);

		wp_enqueue_script(
			'serve-dashboard-admin',
			SERVE_DASHBOARD_URL . 'admin/js/admin.js',
			array(),
			SERVE_DASHBOARD_VERSION,
			true
		);
	}

	/* ---------------------------------------------------------------- Views */

	/**
	 * The dashboard is the purpose-built app shell rather than a WordPress
	 * list table. Teams and Settings below stay native admin screens on
	 * purpose: they are desktop-only configuration, and WordPress' own
	 * conventions serve that better than a bespoke UI would.
	 */
	public static function render_dashboard(): void {
		App::render();
	}

	public static function render_teams(): void {
		if ( ! current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have access to the SERVE dashboard.', 'serve-dashboard' ) );
		}

		$teams    = Teams::all( false );
		$can_edit = current_user_can( Roles::CAP_MANAGE_TEAMS );

		require SERVE_DASHBOARD_DIR . 'admin/views/teams.php';
	}

	public static function render_settings(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have access to these settings.', 'serve-dashboard' ) );
		}

		$retention = Privacy::retention_months();
		$audit     = Audit::recent( 50 );

		require SERVE_DASHBOARD_DIR . 'admin/views/settings.php';
	}

	/**
	 * @return array<int,object>
	 */
	/**
	 * Placement rows for one person, scoped to what the caller may act on.
	 *
	 * This returned every row for the person regardless of who asked, and the
	 * drawer builds its team chooser from exactly this list — so a leader who
	 * could see somebody through their own team was offered every other team
	 * that person sits on as a destination. set_status() did not check team
	 * ownership either, so the offer worked: an ordinary leader could mark
	 * somebody Placed on a team they have nothing to do with, moving another
	 * ministry's record and its headcount.
	 *
	 * Scoped by the same rule as everything else. A pastor holds CAP_VIEW_ALL
	 * and still sees the whole picture, which is the point of the role; a
	 * ministry leader sees their own teams' rows and is offered nothing else.
	 *
	 * @param bool $all_scopes Internal callers that have already decided who
	 *                         may see what — the reconciliation report, the
	 *                         retention sweep — pass true. Nothing reachable
	 *                         from a request should.
	 * @return array<int,object>
	 */
	public static function placements_for( int $submission_id, bool $all_scopes = false ): array {
		global $wpdb;

		$placements = Schema::table( 'placements' );
		$teams      = Schema::table( 'teams' );

		$where  = array( 'p.submission_id = %d' );
		$params = array( $submission_id );

		if ( ! $all_scopes ) {
			$visible = Roles::visible_team_ids();

			// Null means unrestricted, so no clause at all. An empty array is
			// the opposite and must not be allowed to fall through as one.
			if ( null !== $visible ) {
				if ( empty( $visible ) ) {
					return array();
				}

				$in      = implode( ',', array_fill( 0, count( $visible ), '%d' ) );
				$where[] = "p.team_id IN ({$in})";
				$params  = array_merge( $params, $visible );
			}
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are not user input; placeholders built from a counted int array.
				"SELECT p.*, t.name AS team_name, t.requires_safeguarding
				 FROM {$placements} p
				 INNER JOIN {$teams} t ON t.id = p.team_id
				 WHERE " . implode( ' AND ', $where ) . '
				 ORDER BY t.name ASC',
				$params
			)
		);
	}

	/* ------------------------------------------------------------- Handlers */

	public static function handle_team(): void {
		check_admin_referer( 'serve_save_team' );

		$id = isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;

		$saved = Teams::save(
			$id,
			array(
				// Unslashed here; Teams::parse_keywords sanitises each word.
				'keywords'              => wp_unslash( (string) ( $_POST['keywords'] ?? '' ) ),
				'target_headcount'      => $_POST['target_headcount'] ?? 0,
				'min_headcount'         => $_POST['min_headcount'] ?? 0,
				'current_headcount'     => $_POST['current_headcount'] ?? 0,
				'requires_safeguarding' => $_POST['requires_safeguarding'] ?? 0,
				'is_active'             => $_POST['is_active'] ?? 0,
				'leader_user_id'        => $_POST['leader_user_id'] ?? 0,
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_TEAMS,
					'notice' => $saved ? 'saved' : 'error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_settings(): void {
		check_admin_referer( 'serve_save_settings' );

		if ( ! current_user_can( Roles::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have access to these settings.', 'serve-dashboard' ) );
		}

		$months = isset( $_POST['retention_months'] ) ? absint( $_POST['retention_months'] ) : Privacy::DEFAULT_RETENTION_MONTHS;
		update_option( Privacy::OPTION_RETENTION_MONTHS, max( 1, min( 120, $months ) ) );

		// Blank is meaningful: it falls back to the administrator's address.
		$contact = isset( $_POST['contact_email'] )
			? sanitize_email( wp_unslash( $_POST['contact_email'] ) )
			: '';
		update_option( Privacy::OPTION_CONTACT_EMAIL, is_email( $contact ) ? $contact : '' );

		// Explicit, reversible, and never done behind the site owner's back.
		if ( ! empty( $_POST['use_as_front_page'] ) ) {
			Assessment::set_as_front_page();
		}

		/*
		 * Where the journey sends somebody who wants to browse opportunities
		 * themselves. esc_url_raw rather than esc_url because this is storage,
		 * and it is what refuses a javascript: URL — the value ends up as an
		 * iframe source on a public page.
		 */
		$serving_form = isset( $_POST['serving_form_url'] )
			? esc_url_raw( trim( wp_unslash( $_POST['serving_form_url'] ) ) )
			: '';
		update_option( Assessment::OPTION_SERVING_FORM, $serving_form );

		/*
		 * Who picks up the people no team matched. A slug, checked against the
		 * teams that actually exist, so a typo cannot silently mean "nobody".
		 */
		$catchall = isset( $_POST['catchall_team'] )
			? sanitize_key( wp_unslash( $_POST['catchall_team'] ) )
			: '';
		update_option(
			Placements::OPTION_CATCHALL_TEAM,
			( '' !== $catchall && Teams::get_by_slug( $catchall ) ) ? $catchall : ''
		);

		// Enables deep links only. Nothing is read from or written to Planning
		// Center, so no credentials are involved.
		$subdomain = isset( $_POST['pco_subdomain'] )
			? sanitize_key( wp_unslash( $_POST['pco_subdomain'] ) )
			: '';
		update_option( Planning_Center::OPTION_SUBDOMAIN, $subdomain );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SETTINGS,
					'notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Retry the confirmations the mailer previously refused.
	 *
	 * Deliberately manual. Retrying on a schedule against a mailer that is still
	 * broken just burns the sending reputation of a domain the church needs, and
	 * whoever fixed the mail is the person who knows it is fixed.
	 */
	public static function handle_resend_confirmations(): void {
		check_admin_referer( 'serve_resend_confirmations' );

		if ( ! current_user_can( Roles::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have access to these settings.', 'serve-dashboard' ) );
		}

		$result = Verification::resend_unsent();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SETTINGS,
					'notice' => 'resent',
					'sent'   => (int) $result['sent'],
					'failed' => (int) $result['failed'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private static function redirect_back( array $args ): void {
		$args = array_filter(
			array_merge( array( 'page' => self::PAGE_DASHBOARD ), $args ),
			static fn( $value ) => '' !== $value && null !== $value
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
