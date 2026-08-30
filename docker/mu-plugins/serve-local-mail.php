<?php
/**
 * Plugin Name: SERVE local mail and dev notices
 * Description: Points wp_mail() at the Mailpit container and marks the install as local. Loaded only by the Docker development stack in docker-compose.yml.
 *
 * Not part of the plugin and not shipped with it. It lives in mu-plugins so it
 * cannot be deactivated by accident and so nothing in the product has to know
 * that a development stack exists.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send through Mailpit rather than the container's non-existent sendmail.
 *
 * Without this every confirmation email fails silently, and a submission whose
 * mail was never accepted stays unverified — which looks exactly like the
 * plugin refusing to show unverified profiles, and is not.
 *
 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer about to send.
 */
add_action(
	'phpmailer_init',
	static function ( $mailer ): void {
		$mailer->isSMTP();
		$mailer->Host       = 'mail';
		$mailer->Port       = 1025;
		$mailer->SMTPAuth   = false;
		$mailer->SMTPAutoTLS = false;
	}
);

// example.com is reserved for documentation, so a message that somehow escapes
// the container still cannot reach a real inbox.
add_filter( 'wp_mail_from', static fn (): string => 'serve-local@example.com' );
add_filter( 'wp_mail_from_name', static fn (): string => 'SERVE (local)' );

// Nothing here should ever reach a person, and a local install is not a place
// to be told about updates.
add_filter( 'automatic_updater_disabled', '__return_true' );
add_filter( 'auto_plugin_update_send_email', '__return_false' );
add_filter( 'auto_core_update_send_email', '__return_false' );

/**
 * Say where the mail went, on every admin screen.
 *
 * The one thing a developer needs to know about this stack that is not visible
 * from the site itself.
 */
add_action(
	'admin_notices',
	static function (): void {
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: %s: Mailpit URL. */
					__( 'Local development stack. Outgoing email is captured, not sent — read it at <a href="%s" target="_blank" rel="noreferrer">Mailpit</a>.', 'serve-dashboard' ),
					esc_url( 'http://localhost:' . ( getenv( 'SERVE_MAIL_PORT' ) ?: '8025' ) )
				),
				array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
			)
		);
	}
);
