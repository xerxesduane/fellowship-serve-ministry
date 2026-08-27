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
				'logoUrl'    => SERVE_DASHBOARD_URL . 'public/assessment/fellowship-logo.jpeg',
				'strings'    => array(
					'noProfile' => __( 'We could not find a completed profile in this browser. Please finish the S.H.A.P.E. journey first.', 'serve-dashboard' ),
					'sending'   => __( 'Sending…', 'serve-dashboard' ),
					'failed'    => __( 'That did not send. Please check your connection and try again.', 'serve-dashboard' ),
					'consent'   => __( 'Please tick the box so we know you agree.', 'serve-dashboard' ),
					// Labels for the "what you are about to share" summary.
					'summaryGifts' => __( 'Your likely gifts', 'serve-dashboard' ),
					'summaryTeams' => __( 'Teams this points to', 'serve-dashboard' ),
					'summaryTime'  => __( 'Time you can give', 'serve-dashboard' ),
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
		<div class="serve-consent-page">

		<?php /* Carried over from the journey, so the last step does not look like a different website. */ ?>
		<header class="serve-consent__brand">
			<img src="<?php echo esc_url( SERVE_DASHBOARD_URL . 'public/assessment/fellowship-logo.jpeg' ); ?>"
				alt="Fellowship Dubai" width="284" height="221" loading="lazy">
			<div>
				<p class="serve-consent__eyebrow"><?php esc_html_e( 'Final step', 'serve-dashboard' ); ?></p>
				<p class="serve-consent__purpose"><?php esc_html_e( 'KNOW · GROW · GO', 'serve-dashboard' ); ?></p>
			</div>
		</header>

		<form class="serve-consent" id="serve-consent-form" novalidate>
			<h2><?php esc_html_e( 'Share your profile with the SERVE team', 'serve-dashboard' ); ?></h2>

			<p class="serve-consent__lede">
				<?php esc_html_e( 'Your answers are still only on this device. Sending them lets a ministry leader start the conversation about where you might serve.', 'serve-dashboard' ); ?>
			</p>

			<?php
			/*
			 * A summary of what is about to be sent, filled in by the script from
			 * the profile in this browser.
			 *
			 * Agreeing to share something you cannot see is not really agreeing.
			 * It also answers the question people actually have at this point,
			 * which is "what did all that add up to?"
			 */
			?>
			<section class="serve-summary" data-serve-summary hidden>
				<h3><?php esc_html_e( 'What you are about to share', 'serve-dashboard' ); ?></h3>
				<dl data-serve-summary-body></dl>
				<p class="serve-summary__note">
					<?php esc_html_e( 'Your full answers go with it, including anything you wrote in your own words.', 'serve-dashboard' ); ?>
				</p>
			</section>

			<fieldset class="serve-consent__group">
				<legend><?php esc_html_e( 'How a leader reaches you', 'serve-dashboard' ); ?></legend>

				<p class="serve-consent__prefilled" data-serve-prefill hidden>
					<?php esc_html_e( 'Filled in from your answers — please check they are right.', 'serve-dashboard' ); ?>
				</p>

				<div class="serve-consent__field">
					<label for="serve-name"><?php esc_html_e( 'Your name', 'serve-dashboard' ); ?></label>
					<input type="text" id="serve-name" name="display_name" required autocomplete="name">
				</div>

				<div class="serve-consent__row">
					<div class="serve-consent__field">
						<label for="serve-email"><?php esc_html_e( 'Email', 'serve-dashboard' ); ?></label>
						<input type="email" id="serve-email" name="email" required autocomplete="email">
					</div>

					<div class="serve-consent__field">
						<label for="serve-phone"><?php esc_html_e( 'Phone', 'serve-dashboard' ); ?></label>
						<input type="tel" id="serve-phone" name="phone" required autocomplete="tel">
					</div>
				</div>
			</fieldset>

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

			<?php // The decision. Given its own weight, because it is the point of the page. ?>
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

			<p class="serve-consent__reassure">
				<?php esc_html_e( 'Nothing is shared until you press this, and you can ask us to delete it at any time.', 'serve-dashboard' ); ?>
			</p>

			<p class="serve-consent__status" role="status" aria-live="polite"></p>
		</form>

		<?php
		/*
		 * Shown in place of the form once it has sent. The old version hid the
		 * fields and left a single line of status text, which read as though
		 * something had gone missing at the exact moment a person most wants to
		 * know they were heard.
		 */
		?>
		<div class="serve-consent serve-consent--done" data-serve-done hidden>
			<span class="serve-consent__tick" aria-hidden="true">&#10003;</span>
			<h2><?php esc_html_e( 'Thank you — that is on its way', 'serve-dashboard' ); ?></h2>
			<p class="serve-consent__lede" data-serve-done-message></p>
			<ol class="serve-consent__next">
				<li><?php esc_html_e( 'Open the confirmation email we have just sent, so we know the address is yours.', 'serve-dashboard' ); ?></li>
				<li><?php esc_html_e( 'A ministry leader reads your profile and gets in touch.', 'serve-dashboard' ); ?></li>
				<li><?php esc_html_e( 'You talk it over together, and you decide what happens next.', 'serve-dashboard' ); ?></li>
			</ol>
			<p class="serve-consent__smallprint">
				<?php esc_html_e( 'No email after a few minutes? Check your spam folder before sending again.', 'serve-dashboard' ); ?>
			</p>
		</div>

		</div>
		<?php

		return (string) ob_get_clean();
	}
}
