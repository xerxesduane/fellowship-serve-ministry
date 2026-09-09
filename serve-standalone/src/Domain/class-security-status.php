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
			self::check_config_permissions(),
			self::check_two_factor(),
			self::check_mail(),
			self::check_cron(),
			self::check_unsent_confirmations(),
			self::check_digest(),
			self::check_demo_data(),
			self::check_unverified_backlog(),
			self::check_debug_display(),
		);
	}

	private static function row( string $label, string $status, string $detail, string $action = '' ): array {
		return compact( 'label', 'status', 'detail', 'action' );
	}

	/**
	 * Whether last week's digest actually reached anybody.
	 *
	 * wp_mail()'s result was counted and thrown away, so a mailer refusing every
	 * message looked identical to a quiet week with nothing to report. The only
	 * symptom was leaders not mentioning an email they had never been promised
	 * loudly enough to miss, and the checklist stayed green throughout.
	 */
	private static function check_digest(): array {
		$label = __( 'Weekly leader digest', 'serve-dashboard' );

		if ( ! Digest::is_enabled() ) {
			return self::row( $label, self::PASS, __( 'Turned off, so nothing is expected to be sent.', 'serve-dashboard' ) );
		}

		$run = Digest::last_run();

		if ( null === $run ) {
			return self::row(
				$label,
				self::WARN,
				__( 'It has not run yet. The first one goes out on Monday morning; if a Monday has passed and this still says the same thing, nothing is triggering WordPress cron.', 'serve-dashboard' )
			);
		}

		$failed = (int) ( $run['failed'] ?? 0 );
		$sent   = (int) ( $run['sent'] ?? 0 );
		$due    = (int) ( $run['due'] ?? 0 );

		if ( $failed > 0 && 0 === $sent ) {
			return self::row(
				$label,
				self::FAIL,
				sprintf(
					/* translators: %d: number of leaders the mailer refused. */
					__( 'The last run reached nobody: the mailer refused all %d messages. Leaders are getting no digest at all, which looks from their side exactly like a week with nothing to do.', 'serve-dashboard' ),
					$failed
				)
			);
		}

		if ( $failed > 0 ) {
			return self::row(
				$label,
				self::WARN,
				sprintf(
					/* translators: 1: messages sent, 2: messages refused. */
					__( 'The last run sent %1$d and the mailer refused %2$d. Check the mail log for the addresses that failed.', 'serve-dashboard' ),
					$sent,
					$failed
				)
			);
		}

		if ( 0 === $due ) {
			return self::row(
				$label,
				self::PASS,
				__( 'Ran, and nobody had anything waiting, so nothing was sent. That is a quiet week rather than a fault.', 'serve-dashboard' )
			);
		}

		return self::row(
			$label,
			self::PASS,
			sprintf(
				/* translators: %d: number of leaders emailed. */
				__( 'The last run reached all %d leaders who had something waiting.', 'serve-dashboard' ),
				$sent
			)
		);
	}

	/**
	 * Whether the scheduled jobs exist, and whether anything is running them.
	 *
	 * Two different questions, and the second is the one that bites. The
	 * runbook has the operator set DISABLE_WP_CRON and add a system crontab
	 * entry, which means WordPress will happily report a job as scheduled
	 * forever while nothing ever fires it. Everything on this checklist stayed
	 * green in that state, and the privacy notice's promise that profiles are
	 * "deleted automatically by a daily job" would have been false from day one
	 * with nothing anywhere saying so.
	 *
	 * Reported as one row because it is one operational fact: are the promises
	 * that depend on cron actually being kept.
	 */
	private static function check_cron(): array {
		$label = __( 'Scheduled jobs', 'serve-dashboard' );

		$missing = array();

		if ( ! wp_next_scheduled( 'serve_dashboard_retention_sweep' ) ) {
			$missing[] = __( 'the daily retention sweep', 'serve-dashboard' );
		}

		if ( Digest::is_enabled() && ! wp_next_scheduled( Digest::CRON_HOOK ) ) {
			$missing[] = __( 'the weekly leader digest', 'serve-dashboard' );
		}

		if ( $missing ) {
			return self::row(
				$label,
				self::FAIL,
				sprintf(
					/* translators: %s: list of missing scheduled jobs. */
					__( 'Not scheduled: %s. Retention deletion, the unverified purge and the digest all run from these, so anything they promise is not happening. Deactivating and reactivating the plugin re-registers them.', 'serve-dashboard' ),
					implode( __( ', and ', 'serve-dashboard' ), $missing )
				)
			);
		}

		$last = Privacy::last_sweep();

		if ( '' === $last ) {
			return self::row(
				$label,
				self::WARN,
				__( 'Scheduled, but the retention sweep has not run yet. On a new install that is expected within a day. If it is still saying this tomorrow, nothing is triggering WordPress cron -- check the system crontab from step 9 of the runbook.', 'serve-dashboard' )
			);
		}

		$age_hours = ( time() - (int) strtotime( $last . ' UTC' ) ) / HOUR_IN_SECONDS;

		/*
		 * 26 rather than 24. A daily job whose trigger runs every fifteen
		 * minutes drifts by design, and a checklist that cries wolf every
		 * afternoon gets ignored on the day it is right.
		 */
		if ( $age_hours > 26 ) {
			return self::row(
				$label,
				self::FAIL,
				sprintf(
					/* translators: %s: how long ago the sweep last ran, e.g. "3 days". */
					__( 'The daily retention sweep last ran %s ago. Something has stopped triggering WordPress cron, so profiles past their retention window are not being deleted and nobody is being told. Check the system crontab from step 9 of the runbook.', 'serve-dashboard' ),
					human_time_diff( (int) strtotime( $last . ' UTC' ) )
				)
			);
		}

		return self::row(
			$label,
			self::PASS,
			sprintf(
				/* translators: %s: how long ago the sweep last ran. */
				__( 'Scheduled and running. The retention sweep last ran %s ago.', 'serve-dashboard' ),
				human_time_diff( (int) strtotime( $last . ' UTC' ) )
			)
		);
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

	/**
	 * Who can read the config file.
	 *
	 * Replaces a check for DISALLOW_FILE_EDIT, which asked whether an
	 * administrator could edit plugin code from the browser. There is no plugin
	 * editor here and no administrator account that could reach one, so the
	 * check had nothing to measure and reported a WARN about a risk that does
	 * not exist -- which is worse than silence, because a checklist nobody
	 * believes is a checklist nobody reads.
	 *
	 * What replaces it is the equivalent risk in this build. config/config.php
	 * holds the database password and the secret that signs session cookies and
	 * email confirmation tokens. On shared hosting, group- or world-readable is
	 * enough for another account on the same machine to take both.
	 */
	private static function check_config_permissions(): array {
		$path = SERVE_ROOT . '/config/config.php';

		if ( ! is_file( $path ) ) {
			return self::row(
				__( 'Configuration file' ),
				self::WARN,
				__( 'No config file was found, which should not be possible while this page is rendering.' ),
				__( 'Check that config/config.php exists and is readable by the web server.' )
			);
		}

		/*
		 * Windows does not report meaningful POSIX permissions, so saying
		 * anything definite there would be inventing a result.
		 */
		if ( '/' !== DIRECTORY_SEPARATOR ) {
			return self::row(
				__( 'Configuration file' ),
				self::WARN,
				__( 'Permissions cannot be read reliably on Windows. This is a development machine concern only.' ),
				__( 'On the server, make config/config.php readable by the web server user and nobody else (chmod 600).' )
			);
		}

		$mode  = fileperms( $path ) & 0777;
		$loose = (bool) ( $mode & 0077 );

		return self::row(
			__( 'Configuration file' ),
			$loose ? self::FAIL : self::PASS,
			$loose
				/* translators: %s: octal file permissions, for example 644. */
				? sprintf( __( 'config/config.php is mode %s, so other accounts on this server can read the database password and the session secret.' ), decoct( $mode ) )
				/* translators: %s: octal file permissions. */
				: sprintf( __( 'config/config.php is mode %s: readable by its owner only.' ), decoct( $mode ) ),
			$loose ? __( 'Run: chmod 600 config/config.php' ) : ''
		);
	}

	/**
	 * A second factor, which this build does not have.
	 *
	 * The old version searched the installed plugins for a two-factor plugin.
	 * There are no plugins here, so it always reported "none detected" -- true
	 * by accident and for the wrong reason.
	 *
	 * The risk it was pointing at is unchanged and worth reporting plainly: an
	 * account reaching this dashboard reaches other people's spiritual gifts,
	 * phone numbers and pastoral history with a password alone. So this states
	 * that as a fact about the build rather than dressing it as a missing
	 * plugin, and names the two ways to fix it that actually apply.
	 */
	private static function check_two_factor(): array {
		$leaders = count( get_users( array( 'capability' => Roles::CAP_VIEW_DASHBOARD ) ) );
		$leaders = max( 1, $leaders );

		return self::row(
			__( 'Two-factor authentication' ),
			self::WARN,
			sprintf(
				/* translators: %d: number of accounts that can open the dashboard. */
				_n(
					'This application authenticates with a password only. %d account can reach personal profiles with one.',
					'This application authenticates with a password only. %d accounts can reach personal profiles with one.',
					$leaders,
					'serve'
				),
				$leaders
			),
			__( 'Put the dashboard behind single sign-on or a reverse proxy that enforces a second factor. Failing that, treat these passwords as you would the church bank account.' )
		);
	}

	/**
	 * Where outgoing email actually goes.
	 *
	 * The old version searched for an SMTP plugin, which in this build meant it
	 * could only ever report "default PHP mailer" -- and that was not merely
	 * imprecise, it was the wrong warning. The default here is a log transport
	 * that writes messages to a file and sends nothing, so on a fresh install
	 * the truth is stronger than the old text: no confirmation email is
	 * reaching anybody at all, which means no profile can ever be confirmed and
	 * nothing reaches a leader.
	 *
	 * A configuration this application can read is better than a plugin list it
	 * has to guess from, so it reads the configuration.
	 */
	private static function check_mail(): array {
		$transport = (string) \Serve\Platform\App::config( 'mail.transport', 'log' );

		if ( 'smtp' !== $transport ) {
			return self::row(
				__( 'Outgoing email' ),
				self::FAIL,
				__( 'Mail is written to var/mail.log and not sent. Nobody receives a confirmation link, so no profile can be confirmed and nothing reaches a leader.' ),
				__( "Set mail.transport to 'smtp' in config/config.php with an authenticated account, and set SPF, DKIM and DMARC for the domain." )
			);
		}

		$host = (string) \Serve\Platform\App::config( 'mail.host', '' );
		$from = (string) \Serve\Platform\App::config( 'mail.from', '' );

		if ( '' === $host || '' === $from ) {
			return self::row(
				__( 'Outgoing email' ),
				self::FAIL,
				__( 'SMTP is selected but the host or the from address is missing, so every send fails.' ),
				__( 'Fill in mail.host and mail.from in config/config.php.' )
			);
		}

		return self::row(
			__( 'Outgoing email' ),
			self::PASS,
			sprintf(
				/* translators: %s: SMTP host name. */
				__( 'Sending through %s over an authenticated connection.' ),
				$host
			),
			__( 'Check that SPF, DKIM and DMARC are set for the sending domain, which this cannot verify from here.' )
		);
	}

	/**
	 * Confirmations the mailer refused outright.
	 *
	 * The backlog check below infers trouble from a large number of people not
	 * having confirmed, which it cannot distinguish from a quiet week. This one
	 * is not an inference: `wp_mail()` returned false, so we know the message
	 * never left. Those people are waiting on an email that does not exist.
	 */
	private static function check_unsent_confirmations(): array {
		$unsent = Verification::unsent_count();

		return self::row(
			__( 'Unsent confirmations', 'serve-dashboard' ),
			0 === $unsent ? self::PASS : self::FAIL,
			0 === $unsent
				? __( 'Every confirmation email has been accepted by the mailer.', 'serve-dashboard' )
				: sprintf(
					/* translators: %d: number of submissions whose confirmation email failed. */
					_n(
						'%d person completed the journey but their confirmation email could not be sent, so they are waiting on a message that never went out. Their profile is invisible to leaders until it does.',
						'%d people completed the journey but their confirmation emails could not be sent, so they are waiting on messages that never went out. Their profiles are invisible to leaders until they do.',
						$unsent,
						'serve-dashboard'
					),
					$unsent
				),
			0 === $unsent ? '' : __( 'Fix outgoing email above, then use "Send unsent confirmations" below. These profiles are held back from the seven-day purge until the email actually goes.', 'serve-dashboard' )
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
