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

	/** Page template that drops the theme's header and footer. */
	public const TEMPLATE = 'serve-consent-page';

	public static function register(): void {
		add_shortcode( 'serve_shape_consent', array( __CLASS__, 'render' ) );

		add_filter( 'theme_page_templates', array( __CLASS__, 'register_template' ) );
		add_filter( 'template_include', array( __CLASS__, 'use_template' ), 11 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Load the stylesheet in the head, before anything is painted.
	 *
	 * A shortcode runs during `the_content()`, long after `wp_head()`, so
	 * enqueueing there leaves WordPress to print the stylesheet near the bottom
	 * of the body. On a themed page the theme's own CSS hides most of that; on
	 * this page's own bare template there is nothing to hide behind, and the
	 * form appears unstyled and then snaps into place.
	 *
	 * The enqueue inside `render()` stays, for a shortcode dropped on some
	 * other page. Enqueueing the same handle twice is a no-op.
	 */
	public static function maybe_enqueue(): void {
		if ( ! is_page() ) {
			return;
		}

		$page_id = get_queried_object_id();
		$post    = get_post( $page_id );

		// The privacy notice uses the same template, so it needs the same styles.
		$is_ours = in_array(
			$page_id,
			array(
				(int) get_option( Assessment::OPTION_CONSENT_PAGE, 0 ),
				(int) get_option( 'wp_page_for_privacy_policy', 0 ),
			),
			true
		) || ( $post && has_shortcode( (string) $post->post_content, 'serve_shape_consent' ) );

		if ( $is_ours ) {
			self::enqueue();
		}
	}

	/**
	 * Offer the template in the page editor's Template dropdown.
	 *
	 * @param array<string,string> $templates
	 * @return array<string,string>
	 */
	public static function register_template( array $templates ): array {
		$templates[ self::TEMPLATE ] = __( 'SERVE — Share your profile', 'serve-dashboard' );

		return $templates;
	}

	/**
	 * Use the plugin's document for the consent page.
	 *
	 * Matched on the stored page id as well as on the chosen template, so a site
	 * that was set up before this template existed gets it without anyone having
	 * to know to go and pick it. Somebody who deliberately switches the page to
	 * a different template still wins — the check is only a fallback for pages
	 * that never chose one.
	 */
	public static function use_template( string $template ): string {
		if ( ! is_page() ) {
			return $template;
		}

		$page_id = get_queried_object_id();
		$chosen  = get_page_template_slug( $page_id );

		if ( self::TEMPLATE === $chosen ) {
			return SERVE_DASHBOARD_DIR . 'public/templates/consent.php';
		}

		/*
		 * The privacy notice too. It is linked from the consent page's footer,
		 * and following that link should not drop somebody back into the
		 * theme's navigation — which is the thing this template exists to avoid.
		 */
		$ours = array(
			(int) get_option( Assessment::OPTION_CONSENT_PAGE, 0 ),
			(int) get_option( 'wp_page_for_privacy_policy', 0 ),
		);

		if ( '' === $chosen && $page_id > 0 && in_array( $page_id, $ours, true ) ) {
			return SERVE_DASHBOARD_DIR . 'public/templates/consent.php';
		}

		return $template;
	}

	/** Register the page's own assets. Safe to call twice. */
	public static function enqueue(): void {
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
				'inviteEndpoint' => rest_url( Rest::NAMESPACE . '/invite-response' ),
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
	}

	/**
	 * @param array<string,mixed> $atts
	 */
	public static function render( $atts = array() ): string {
		// Already done in the head on the consent page; a no-op there.
		self::enqueue();

		/*
		 * Arriving from an invitation: the person answers for themselves.
		 *
		 * The deck's model is system suggests, leader confirms, person chooses,
		 * and the third had no surface anywhere in the product. Until now
		 * "Declined" was something a leader typed on somebody else's behalf.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an emailed one-time token is the credential.
		$invite = isset( $_GET[ Invitation::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ Invitation::QUERY_VAR ] ) ) : '';

		if ( '' !== $invite ) {
			return self::render_invitation( $invite );
		}

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
			<div class="serve-consent-page">
				<div class="serve-consent serve-consent--result is-<?php echo esc_attr( $message['tone'] ); ?>">
					<h1><?php echo esc_html( $message['title'] ); ?></h1>
					<p class="serve-consent__lede"><?php echo esc_html( $message['body'] ); ?></p>
					<?php if ( Assessment::assessment_url() ) : ?>
						<p>
							<a class="serve-consent__submit" href="<?php echo esc_url( Assessment::assessment_url() ); ?>">
								<?php esc_html_e( 'Back to my profile', 'serve-dashboard' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<?php

			return (string) ob_get_clean();
		}

		ob_start();
		?>
		<div class="serve-consent-page">

		<form class="serve-consent" id="serve-consent-form" novalidate>
			<h1><?php esc_html_e( 'Share your profile with the SERVE team', 'serve-dashboard' ); ?></h1>

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
				<h2><?php esc_html_e( 'What you are about to share', 'serve-dashboard' ); ?></h2>
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
			<h1><?php esc_html_e( 'Thank you — that is on its way', 'serve-dashboard' ); ?></h1>
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

	/**
	 * Answering an invitation.
	 *
	 * Three honest options and a box, rather than a single "accept" button.
	 * "Not right now" is a real answer and has to be as easy to give as yes, or
	 * the only people who reply are the ones saying what we hoped to hear.
	 */
	private static function render_invitation( string $token ): string {
		$submission = Invitation::find( $token );

		ob_start();
		?>
		<div class="serve-consent-page">
		<?php if ( ! $submission ) : ?>
			<div class="serve-consent serve-consent--result is-warn">
				<h1><?php esc_html_e( 'That link is no longer active', 'serve-dashboard' ); ?></h1>
				<p class="serve-consent__lede">
					<?php esc_html_e( 'It may already have been used, or it may have been sitting in an inbox for a while. Reply to the email a leader sent you and they will pick it up from there.', 'serve-dashboard' ); ?>
				</p>
			</div>
		<?php else : ?>
			<form class="serve-consent" id="serve-invite-form" data-invite-token="<?php echo esc_attr( $token ); ?>" novalidate>
				<h1><?php esc_html_e( 'About serving at Fellowship Dubai', 'serve-dashboard' ); ?></h1>
				<p class="serve-consent__lede">
					<?php
					printf(
						/* translators: %s: the person's first name. */
						esc_html__( 'Hello %s. Whatever you say here is fine — there is no right answer, and nobody is keeping score.', 'serve-dashboard' ),
						esc_html( (string) strtok( (string) $submission->display_name, ' ' ) )
					);
					?>
				</p>

				<div class="serve-invite__choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Your answer', 'serve-dashboard' ); ?>">
					<?php foreach ( Invitation::responses() as $value => $label ) : ?>
						<label class="serve-invite__choice">
							<input type="radio" name="serve_invite_response" value="<?php echo esc_attr( $value ); ?>"
								<?php checked( Invitation::RESPONSE_YES, $value ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="serve-consent__field">
					<label for="serve-invite-note"><?php esc_html_e( 'Anything you would like them to know (optional)', 'serve-dashboard' ); ?></label>
					<textarea id="serve-invite-note" rows="3" maxlength="500" data-invite-note
						placeholder="<?php esc_attr_e( 'e.g. I am travelling until October, or — what would it actually involve?', 'serve-dashboard' ); ?>"></textarea>
				</div>

				<button type="submit" class="serve-consent__submit"><?php esc_html_e( 'Send my answer', 'serve-dashboard' ); ?></button>
				<p class="serve-consent__status" role="status" aria-live="polite"></p>
			</form>

			<div class="serve-consent serve-consent--done" data-invite-done hidden>
				<span class="serve-consent__tick" aria-hidden="true">&#10003;</span>
				<h1><?php esc_html_e( 'Thank you', 'serve-dashboard' ); ?></h1>
				<p class="serve-consent__lede" data-invite-done-message></p>
			</div>
		<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The mark carried over from the journey.
	 *
	 * The nineteen steps before this are full-bleed and branded; arriving at
	 * something that looks like a different website is not the moment to ask
	 * for somebody's personal details.
	 */
	public static function brand_header( string $eyebrow ): void {
		?>
		<header class="serve-consent__brand">
			<img src="<?php echo esc_url( SERVE_DASHBOARD_URL . 'public/assessment/fellowship-logo.jpeg' ); ?>"
				alt="Fellowship Dubai" width="284" height="221" loading="lazy">
			<div>
				<p class="serve-consent__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<p class="serve-consent__purpose"><?php esc_html_e( 'KNOW · GROW · GO', 'serve-dashboard' ); ?></p>
			</div>
		</header>
		<?php
	}

	/**
	 * The footer this page needs, rather than the one a theme supplies.
	 *
	 * Somebody is being asked for their spiritual gifts and, in the Experiences
	 * section, their pastoral history. What belongs at the bottom of that page
	 * is who is asking, how long it is kept and how to have it removed — not
	 * Blog, Events and Shop.
	 */
	public static function page_footer( bool $link_privacy = true ): void {
		$privacy = get_privacy_policy_url();
		$email   = antispambot( Privacy::contact_email() );
		?>
		<footer class="serve-consent__foot">
			<p class="serve-consent__foot-lede">
				<?php
				printf(
					/* translators: %d: retention period in months. */
					esc_html__( 'Your profile is seen only by authorised Fellowship Dubai ministry leaders. It is kept for %d months and then deleted automatically.', 'serve-dashboard' ),
					absint( Privacy::retention_months() )
				);
				?>
			</p>

			<p class="serve-consent__foot-lede">
				<?php
				printf(
					/* translators: %s: mailto link to the SERVE team. */
					esc_html__( 'Change your mind at any time and we will remove it — just write to %s.', 'serve-dashboard' ),
					'<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>'
				);
				?>
			</p>

			<nav class="serve-consent__foot-links" aria-label="<?php esc_attr_e( 'Page links', 'serve-dashboard' ); ?>">
				<?php if ( Assessment::assessment_url() ) : ?>
					<a href="<?php echo esc_url( Assessment::assessment_url() ); ?>">
						<?php esc_html_e( 'Back to my S.H.A.P.E. profile', 'serve-dashboard' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $privacy && $link_privacy ) : ?>
					<a href="<?php echo esc_url( $privacy ); ?>"><?php esc_html_e( 'Privacy', 'serve-dashboard' ); ?></a>
				<?php endif; ?>
			</nav>

			<p class="serve-consent__foot-mark"><?php esc_html_e( 'Fellowship Dubai · KNOW · GROW · GO', 'serve-dashboard' ); ?></p>
		</footer>
		<?php
	}
}
