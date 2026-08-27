<?php
/**
 * The consent-and-send step that bridges the existing assessment to the new store.
 *
 * The public S.H.A.P.E. journey already computes a finished profile and keeps
 * it in localStorage under "fellowship-dubai-shape-v2". Rather than rewrite
 * that journey, this shortcode reads the same key, shows the person exactly
 * what they are agreeing to, and posts it to the REST endpoint. Nothing is
 * sent unless they tick the box.
 *
 * Usage: [serve_shape_consent]
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Shortcode {

	public const STORAGE_KEY = 'fellowship-dubai-shape-v2';

	public static function register(): void {
		add_shortcode( 'serve_shape_consent', array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array<string,mixed> $atts
	 */
	public static function render( $atts = array() ): string {
		wp_enqueue_style(
			'serve-shape-form',
			SERVE_DASHBOARD_URL . 'public/css/serve-form.css',
			array(),
			SERVE_DASHBOARD_VERSION
		);

		wp_enqueue_script(
			'serve-shape-form',
			SERVE_DASHBOARD_URL . 'public/js/serve-form.js',
			array(),
			SERVE_DASHBOARD_VERSION,
			true
		);

		wp_localize_script(
			'serve-shape-form',
			'serveShapeConfig',
			array(
				'endpoint'   => rest_url( Rest::NAMESPACE . '/submissions' ),
				'storageKey' => self::STORAGE_KEY,
				'strings'    => array(
					'noProfile' => __( 'We could not find a completed profile in this browser. Please finish the S.H.A.P.E. journey first.', 'serve-dashboard' ),
					'sending'   => __( 'Sending…', 'serve-dashboard' ),
					'failed'    => __( 'That did not send. Please check your connection and try again.', 'serve-dashboard' ),
					'consent'   => __( 'Please tick the box so we know you agree.', 'serve-dashboard' ),
				),
			)
		);

		/*
		 * Arriving from a confirmation email: show the outcome instead of the
		 * form. The person has already submitted; re-offering the form here
		 * would only invite a duplicate.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a result code.
		$result = isset( $_GET['serve_verified'] ) ? sanitize_key( wp_unslash( $_GET['serve_verified'] ) ) : '';
		$messages = Verification::messages();

		if ( isset( $messages[ $result ] ) ) {
			$message = $messages[ $result ];

			ob_start();
			?>
			<div class="serve-consent serve-consent--result is-<?php echo esc_attr( $message['tone'] ); ?>">
				<h2><?php echo esc_html( $message['title'] ); ?></h2>
				<p class="serve-consent__lede"><?php echo esc_html( $message['body'] ); ?></p>
				<?php if ( Assessment::assessment_url() ) : ?>
					<p>
						<a class="serve-consent__submit" href="<?php echo esc_url( Assessment::assessment_url() ); ?>">
							<?php esc_html_e( 'Back to my profile', 'serve-dashboard' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<?php

			return (string) ob_get_clean();
		}

		ob_start();
		?>
		<form class="serve-consent" id="serve-consent-form" novalidate>
			<h2><?php esc_html_e( 'Share your profile with the SERVE team', 'serve-dashboard' ); ?></h2>

			<p class="serve-consent__lede">
				<?php esc_html_e( 'Your answers are still only on this device. Sending them lets a ministry leader start the conversation about where you might serve.', 'serve-dashboard' ); ?>
			</p>

			<div class="serve-consent__field">
				<label for="serve-name"><?php esc_html_e( 'Your name', 'serve-dashboard' ); ?></label>
				<input type="text" id="serve-name" name="display_name" required autocomplete="name">
			</div>

			<div class="serve-consent__field">
				<label for="serve-email"><?php esc_html_e( 'Email', 'serve-dashboard' ); ?></label>
				<input type="email" id="serve-email" name="email" required autocomplete="email">
			</div>

			<div class="serve-consent__field">
				<label for="serve-phone"><?php esc_html_e( 'Phone', 'serve-dashboard' ); ?></label>
				<input type="tel" id="serve-phone" name="phone" required autocomplete="tel">
			</div>

			<div class="serve-consent__field">
				<label for="serve-tenure"><?php esc_html_e( 'Roughly how long do you expect to be in the UAE?', 'serve-dashboard' ); ?></label>
				<select id="serve-tenure" name="tenure_months">
					<option value=""><?php esc_html_e( 'Prefer not to say', 'serve-dashboard' ); ?></option>
					<option value="6"><?php esc_html_e( 'Less than 6 months', 'serve-dashboard' ); ?></option>
					<option value="12"><?php esc_html_e( 'About a year', 'serve-dashboard' ); ?></option>
					<option value="24"><?php esc_html_e( 'One to two years', 'serve-dashboard' ); ?></option>
					<option value="60"><?php esc_html_e( 'Several years', 'serve-dashboard' ); ?></option>
					<option value="120"><?php esc_html_e( 'Settled here', 'serve-dashboard' ); ?></option>
				</select>
				<small><?php esc_html_e( 'This helps a leader suggest something that fits your season, rather than a role that needs more time than you have.', 'serve-dashboard' ); ?></small>
			</div>

			<div class="serve-consent__box">
				<label>
					<input type="checkbox" id="serve-consent-check" name="consent" value="1" required>
					<span><?php echo esc_html( Privacy::purpose_text() ); ?></span>
				</label>
			</div>

			<?php // Bots complete every field they can see. This one is hidden from people. ?>
			<div class="serve-consent__decoy" aria-hidden="true">
				<label for="serve-website"><?php esc_html_e( 'Website', 'serve-dashboard' ); ?></label>
				<input type="text" id="serve-website" name="honeypot" tabindex="-1" autocomplete="off">
			</div>

			<button type="submit" class="serve-consent__submit">
				<?php esc_html_e( 'Send my profile', 'serve-dashboard' ); ?>
			</button>

			<p class="serve-consent__status" role="status" aria-live="polite"></p>
		</form>
		<?php

		return (string) ob_get_clean();
	}
}
