<?php
/**
 * Go-live security checks.
 *
 * Some of what protects this data cannot be enforced from plugin code: TLS
 * termination, the database account's privileges, two-factor authentication,
 * whether demo rows are still in the table. Pretending otherwise would be the
 * dangerous move, so those are checked and reported honestly on the Settings
 * screen instead.
 *
 * Each check returns a status of pass, warn or fail, plus what to do about it.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Security_Status {

	public const PASS = 'pass';
	public const WARN = 'warn';
	public const FAIL = 'fail';

	/**
	 * @return array<int,array<string,string>>
	 */
	public static function checks(): array {
		return array(
			self::check_https(),
			self::check_db_user(),
			self::check_file_editing(),
			self::check_two_factor(),
			self::check_mail(),
			self::check_demo_data(),
			self::check_unverified_backlog(),
			self::check_debug_display(),
		);
	}

	private static function row( string $label, string $status, string $detail, string $action = '' ): array {
		return compact( 'label', 'status', 'detail', 'action' );
	}

	/**
	 * Whether any active plugin's path matches a pattern.
	 *
	 * Matched loosely on purpose: the point is to notice that *something*
	 * credible is installed, not to bless a specific vendor.
	 */
	private static function plugin_matching( string $pattern ): bool {
		foreach ( (array) get_option( 'active_plugins', array() ) as $slug ) {
			if ( preg_match( $pattern, (string) $slug ) ) {
				return true;
			}
		}

		return false;
	}

	private static function check_https(): array {
		$secure = is_ssl() && str_starts_with( (string) get_option( 'home' ), 'https://' );

		return self::row(
			__( 'HTTPS', 'serve-dashboard' ),
			$secure ? self::PASS : self::FAIL,
			$secure
				? __( 'The site is served over HTTPS.', 'serve-dashboard' )
				: __( 'This site is not using HTTPS. Profiles, contact details and leader passwords would travel in clear text.', 'serve-dashboard' ),
			$secure ? '' : __( 'Install a certificate on the subdomain, set the WordPress and site address to https://, then add an HSTS header.', 'serve-dashboard' )
		);
	}

	private static function check_db_user(): array {
		global $wpdb;

		$user  = defined( 'DB_USER' ) ? (string) DB_USER : '';
		$isroot = in_array( strtolower( $user ), array( 'root', 'admin', 'mysql' ), true );
		$blank  = defined( 'DB_PASSWORD' ) && '' === (string) DB_PASSWORD;

		if ( $isroot || $blank ) {
			return self::row(
				__( 'Database account', 'serve-dashboard' ),
				self::FAIL,
				$blank
					? __( 'The database password is empty.', 'serve-dashboard' )
					/* translators: %s: database username. */
					: sprintf( __( 'WordPress is connecting as "%s", which normally has full server privileges.', 'serve-dashboard' ), $user ),
				__( 'Create a dedicated database user with rights to this one database only, and a strong password.', 'serve-dashboard' )
			);
		}

		unset( $wpdb );

		return self::row(
			__( 'Database account', 'serve-dashboard' ),
			self::PASS,
			__( 'A dedicated database account is in use.', 'serve-dashboard' )
		);
	}

	private static function check_file_editing(): array {
		$disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;

		return self::row(
			__( 'Theme and plugin file editing', 'serve-dashboard' ),
			$disabled ? self::PASS : self::WARN,
			$disabled
				? __( 'In-browser file editing is disabled.', 'serve-dashboard' )
				: __( 'An administrator account could edit plugin code from the browser, which turns one stolen password into full server access.', 'serve-dashboard' ),
			$disabled ? '' : __( "Add define( 'DISALLOW_FILE_EDIT', true ); to wp-config.php.", 'serve-dashboard' )
		);
	}

	private static function check_two_factor(): array {
		$present = self::plugin_matching( '/two.?factor|2fa|authenticator|wordfence-login/i' );

		$leaders = count(
			get_users(
				array(
					'role__in' => array( Roles::ROLE_LEADER, Roles::ROLE_PASTOR ),
					'fields'   => 'ids',
				)
			)
		);

		return self::row(
			__( 'Two-factor authentication', 'serve-dashboard' ),
			$present ? self::PASS : self::WARN,
			$present
				? __( 'A two-factor plugin is active.', 'serve-dashboard' )
				: sprintf(
					/* translators: %d: number of leader accounts. */
					_n(
						'No two-factor plugin detected. %d leader account can reach personal profiles with a password alone.',
						'No two-factor plugin detected. %d leader accounts can reach personal profiles with a password alone.',
						max( 1, $leaders ),
						'serve-dashboard'
					),
					max( 1, $leaders )
				),
			$present ? '' : __( 'Install a two-factor plugin and require it for every SERVE Pastor and Ministry Leader account.', 'serve-dashboard' )
		);
	}

	private static function check_mail(): array {
		// Verification is useless if the email never arrives, and shared hosting
		// mail is routinely filtered as spam.
		$smtp = self::plugin_matching( '/smtp|postmark|sendgrid|mailgun|amazon-?ses|brevo|sparkpost|fluent-?smtp/i' );

		return self::row(
			__( 'Outgoing email', 'serve-dashboard' ),
			$smtp ? self::PASS : self::WARN,
			$smtp
				? __( 'A dedicated mail service is configured.', 'serve-dashboard' )
				: __( 'Confirmation emails are going through the default PHP mailer, which is often silently filtered as spam. If they do not arrive, nobody can confirm a profile and no submission ever reaches a leader.', 'serve-dashboard' ),
			$smtp ? '' : __( 'Send mail through an authenticated SMTP or transactional email service, and set SPF, DKIM and DMARC for the domain.', 'serve-dashboard' )
		);
	}

	private static function check_demo_data(): array {
		global $wpdb;
		$table = Schema::table( 'submissions' );

		$demo = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed literal pattern.
			"SELECT COUNT(*) FROM {$table} WHERE email LIKE '%@example.com' OR email LIKE '%@example.org'"
		);

		return self::row(
			__( 'Demonstration data', 'serve-dashboard' ),
			0 === $demo ? self::PASS : self::WARN,
			0 === $demo
				? __( 'No demonstration profiles are present.', 'serve-dashboard' )
				: sprintf(
					/* translators: %d: number of demo rows. */
					_n( '%d demonstration profile is still in the database.', '%d demonstration profiles are still in the database.', $demo, 'serve-dashboard' ),
					$demo
				),
			0 === $demo ? '' : __( 'Delete the seeded profiles before leaders start using this for real people.', 'serve-dashboard' )
		);
	}

	private static function check_unverified_backlog(): array {
		$pending = Verification::pending_count();

		return self::row(
			__( 'Unconfirmed submissions', 'serve-dashboard' ),
			$pending > 20 ? self::WARN : self::PASS,
			sprintf(
				/* translators: %d: number of unconfirmed submissions. */
				_n(
					'%d submission is waiting for its email to be confirmed.',
					'%d submissions are waiting for their email to be confirmed.',
					$pending,
					'serve-dashboard'
				),
				$pending
			),
			$pending > 20 ? __( 'A large backlog usually means confirmation emails are not being delivered. Check the outgoing email setting above.', 'serve-dashboard' ) : ''
		);
	}

	private static function check_debug_display(): array {
		$leaking = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY && defined( 'WP_DEBUG' ) && WP_DEBUG;

		return self::row(
			__( 'Error display', 'serve-dashboard' ),
			$leaking ? self::FAIL : self::PASS,
			$leaking
				? __( 'PHP errors are being printed to the page, which can expose file paths and query fragments to visitors.', 'serve-dashboard' )
				: __( 'Errors are not displayed to visitors.', 'serve-dashboard' ),
			$leaking ? __( "Set WP_DEBUG_DISPLAY to false and log to a file instead.", 'serve-dashboard' ) : ''
		);
	}
}
