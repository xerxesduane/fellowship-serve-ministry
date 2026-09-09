<?php
/**
 * The dashboard application shell.
 *
 * The brief asks for something that feels like a purpose-built application
 * rather than a set of WordPress admin tables, but this is still a plugin and
 * throwing away WordPress' authentication would be the wrong trade. So the
 * dashboard runs as a full-bleed admin screen: WordPress still handles login,
 * capabilities and nonces, and a body class collapses the admin chrome so the
 * app owns the viewport. An explicit exit link means nobody gets trapped.
 *
 * Configuration screens (Teams, Settings) stay as ordinary WordPress admin
 * pages. They are desktop-only administration, and reusing the platform's own
 * conventions there is a feature, not a shortcut.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

final class App {

	/**
	 * Nothing to enqueue.
	 *
	 * register(), assets() and body_class() were WordPress's asset queue and
	 * admin-screen plumbing. The layout in public/index.php includes the two
	 * files directly, which is what a queue with two entries and no dependency
	 * graph amounts to.
	 */
	public static function register(): void {}

	/**
	 * Config the JS module needs. Rendered into the page as JSON rather than
	 * inlined into the module, so the module stays a static cacheable file.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		return array(
			'root'     => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'exitUrl'  => admin_url(),
			'exportUrl' => current_user_can( Roles::CAP_EXPORT ) ? Export::url() : '',
			'teamsUrl' => admin_url( 'admin.php?page=' . Admin::PAGE_TEAMS ),
			'settingsUrl' => admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ),
			'caps'     => array(
				'manage'    => current_user_can( Roles::CAP_MANAGE_PLACE ),
				'viewAll'   => current_user_can( Roles::CAP_VIEW_ALL ),
				'sensitive' => current_user_can( Roles::CAP_VIEW_SENSITIVE ),
				'teams'     => current_user_can( Roles::CAP_MANAGE_TEAMS ),
				'settings'  => current_user_can( Roles::CAP_MANAGE_SETTINGS ),
				'export'    => current_user_can( Roles::CAP_EXPORT ),
				'safeguard' => current_user_can( Roles::CAP_VERIFY_SAFEGUARD ),
			),
			// The drawer builds its own controls from these rather than
			// hardcoding a second copy of the pipeline in JavaScript.
			'statuses'   => Schema::status_labels(),
			'safeguardStatuses' => Safeguarding::status_labels(),
			'inviteResponses'   => Invitation::responses(),
			// The journey, in order, and the two outcomes that sit outside it.
			'stagePhases'       => array_map(
				static fn( $status ) => Schema::phase_of( (string) $status ),
				array_combine( array_keys( Schema::status_labels() ), array_keys( Schema::status_labels() ) )
			),
			'stagePath'         => Schema::stage_path(),
			'stageOffPath'      => Schema::stage_off_path(),
			'gatedStatuses'     => array_values(
				array_filter( array_keys( Schema::status_labels() ), array( Safeguarding::class, 'is_gated_status' ) )
			),
			'i18n'     => array(
				'loading'        => __( 'Loading', 'serve-dashboard' ),
				'errorTitle'     => __( 'We could not load the dashboard', 'serve-dashboard' ),
				'errorBody'      => __( 'Something went wrong reaching the server. Your data is safe.', 'serve-dashboard' ),
				'retry'          => __( 'Try again', 'serve-dashboard' ),
				'noResults'      => __( 'No one matches that search', 'serve-dashboard' ),
				'noResultsBody'  => __( 'Try a different name, gift, or team.', 'serve-dashboard' ),
				'noProfiles'     => __( 'No profiles yet', 'serve-dashboard' ),
				'noProfilesBody' => __( 'When someone completes the S.H.A.P.E. journey and agrees to share it, they will appear here with a suggested team.', 'serve-dashboard' ),
				'allClear'       => __( 'Nothing waiting on you', 'serve-dashboard' ),
				'allClearBody'   => __( 'Every follow-up is up to date. This is a good place to be.', 'serve-dashboard' ),
			),
		);
	}

	/**
	 * One icon set, one stroke weight.
	 *
	 * Lucide-style line icons drawn inline: no icon font, no sprite request,
	 * and no mixing of stroke weights. Every icon is decorative — the label
	 * beside it carries the meaning — so they are marked aria-hidden by the
	 * caller.
	 */
	public static function icon( string $name ): void {
		$paths = array(
			'home'     => '<path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z"/>',
			'users'    => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16 5.5a3.2 3.2 0 0 1 0 6.2"/><path d="M17.5 14.5a5.5 5.5 0 0 1 3 5.5"/>',
			'clock'    => '<circle cx="12" cy="12" r="8.2"/><path d="M12 7.5V12l3 2"/>',
			'grid'     => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.4"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.4"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.4"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.4"/>',
			'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2.2M12 18.3v2.2M4.9 7.9l1.9 1.1M17.2 15l1.9 1.1M4.9 16.1 6.8 15M17.2 9l1.9-1.1"/>',
			'search'   => '<circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4 4"/>',
			'gift'     => '<path d="M4.5 10.5h15V20H4.5z"/><path d="M3.5 7h17v3.5h-17zM12 7v13"/><path d="M12 7S10.5 4 8.6 4a2 2 0 0 0 0 3M12 7s1.5-3 3.4-3a2 2 0 0 1 0 3"/>',
			'heart'    => '<path d="M12 19.5s-7-4.3-7-9A3.8 3.8 0 0 1 12 8.2 3.8 3.8 0 0 1 19 10.5c0 4.7-7 9-7 9z"/>',
			'tool'     => '<path d="M14.5 6.5a3.5 3.5 0 0 0 4.6 4.6l-8 8a2.4 2.4 0 0 1-3.4-3.4z"/><path d="m5 5 3 3"/>',
			'person'   => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20a6.5 6.5 0 0 1 13 0"/>',
			'briefcase' => '<rect x="3.5" y="7.5" width="17" height="12" rx="1.6"/><path d="M9 7.5V6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 6v1.5"/>',
			'target'   => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.4"/>',
			'send'     => '<path d="m20 4-8.5 16-2-6.5L3 11.5z"/>',
			'external' => '<path d="M14 4h6v6"/><path d="m20 4-8.5 8.5"/><path d="M18 14v4.5A1.5 1.5 0 0 1 16.5 20h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>',
			'close'    => '<path d="m6 6 12 12M18 6 6 18"/>',
			'alert'    => '<path d="M12 4.5 20.5 19h-17z"/><path d="M12 10v4M12 16.6v.1"/>',
			'check'    => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			'shield'   => '<path d="M12 4l7 2.5v5c0 4.2-3 7.4-7 8.5-4-1.1-7-4.3-7-8.5v-5z"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return;
		}

		printf(
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" focusable="false">%s</svg>',
			// Path data is a fixed literal from the array above, not user input.
			$paths[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Placeholder rows shown while a region loads.
	 *
	 * Reserving the real row height keeps the layout from jumping when data
	 * arrives, which is the whole point of a skeleton over a spinner.
	 */
	public static function skeleton_rows( int $count ): void {
		echo '<div class="serve-sk-rows" aria-hidden="true">';
		for ( $i = 0; $i < $count; $i++ ) {
			echo '<span class="serve-sk serve-sk--row"></span>';
		}
		echo '</div>';
	}

	/**
	 * Render the shell. Content is drawn by the JS module; this provides the
	 * landmarks, the no-JS message, and the server-rendered brand block.
	 */
	/**
	 * Collapsed explanation of the stage badges.
	 *
	 * Closed by default so it costs a returning leader nothing, and a plain
	 * <details> rather than a scripted panel so it works before the app has
	 * booted and keeps its keyboard behaviour for free.
	 *
	 * Emitted in both list views from here rather than written out twice: the
	 * same question gets asked wherever the badges are, and two copies of the
	 * wording is two things to keep in step.
	 */
	public static function stage_legend(): void {
		$labels       = Schema::status_labels();
		$descriptions = Schema::status_descriptions();

		echo '<details class="serve-legend"><summary>'
			. esc_html__( 'What do these stages mean?', 'serve-dashboard' )
			. '</summary>';

		/*
		 * Grouped under Discover, Connect and Serve — the three words slide 3
		 * of the deck is built on, which the dashboard had never used. Grouping
		 * also does something a flat list of seven could not: it shows that
		 * Paused and Declined are a different kind of thing from the five that
		 * are positions on a path.
		 */
		foreach ( Schema::stage_phases() as $phase ) {
			echo '<div class="serve-phase">';
			echo '<p class="serve-phase__head"><span class="serve-phase__name">'
				. esc_html( $phase['label'] ) . '</span> '
				. esc_html( $phase['note'] ) . '</p>';

			if ( $phase['stages'] ) {
				echo '<dl class="serve-legend__list">';
				foreach ( $phase['stages'] as $key ) {
					echo '<div><dt>' . esc_html( $labels[ $key ] ?? $key ) . '</dt><dd>'
						. esc_html( $descriptions[ $key ] ?? '' )
						. '</dd></div>';
				}
				echo '</dl>';
			}

			echo '</div>';
		}

		echo '</details>';
	}

	/**
	 * The way out of the dashboard.
	 *
	 * The plugin sends a leader back to the WordPress admin here. There is no
	 * WordPress admin in this build, and the link that said so was pointing at
	 * the participant assessment -- an exit that both lied about where it went
	 * and left the session signed in.
	 *
	 * A form rather than a link, because logging out is a write: index.php only
	 * accepts a POST for it, so that an <img src=".../logout"> on any page
	 * cannot sign a leader out from under them.
	 */
	public static function exit_control(): void {
		printf(
			'<form class="serve-exitform" method="post" action="%s">%s<button type="submit" class="serve-exit">%s</button></form>',
			esc_url( \Serve\Platform\App::url( 'logout' ) ),
			wp_nonce_field( 'serve_logout', '_wpnonce', true, false ),
			esc_html__( 'Sign out', 'serve-dashboard' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Roles::CAP_VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have access to the SERVE dashboard.', 'serve-dashboard' ) );
		}

		/*
		 * The shell is required from inside this method, exactly as the plugin
		 * required it, because the markup uses `self::icon()` and
		 * `self::skeleton_rows()`. Rendering it from anywhere else means no class
		 * scope and a fatal error -- which is what happened when the front
		 * controller included it directly.
		 *
		 * Keeping the call site identical is also what keeps views/app/shell.php
		 * byte-for-byte the plugin's file. That is the whole mechanism behind
		 * "looks exactly the same": one set of markup, one stylesheet, no second
		 * copy to drift.
		 */
		require SERVE_ROOT . '/views/app/shell.php';
	}
}
