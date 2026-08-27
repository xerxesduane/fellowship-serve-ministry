<?php
/**
 * The published privacy notice.
 *
 * WordPress creates a draft privacy policy describing comment forms, Gravatar
 * and embedded media, and gives the site's address as whatever it was installed
 * at. None of that is true here, and publishing it would put a link labelled
 * Privacy — on a page collecting religious belief — to a document contradicting
 * the accurate consent wording beside it.
 *
 * So the plugin supplies the text. It describes what the software actually
 * does, which is the part nobody can write without reading the source.
 *
 * Two deliberate limits:
 *
 * - **It never overwrites edited text.** The content is written only into a page
 *   that still holds the untouched WordPress boilerplate, or nothing at all.
 *   Once anybody has changed a word — a lawyer, the church office — this stops
 *   touching it, for good.
 * - **It publishes nothing on its own.** The page is created as a draft. Text
 *   about sensitive personal data goes live when a person decides it should,
 *   not when a plugin is activated.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Privacy_Page {

	/** Marks content this class wrote, so an edited page is left alone. */
	private const STAMP = 'serve-privacy-notice';

	/** WordPress' own boilerplate carries this class on every suggested paragraph. */
	private const WP_BOILERPLATE = 'privacy-policy-tutorial';

	/**
	 * Create or refresh the notice. Called on activation.
	 *
	 * @return int Page id, or 0 if nothing was done.
	 */
	public static function install(): int {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		$page    = $page_id ? get_post( $page_id ) : null;

		if ( ! $page || 'page' !== $page->post_type ) {
			$page_id = self::create();

			return $page_id;
		}

		if ( ! self::is_untouched( (string) $page->post_content ) ) {
			// Somebody has written their own. Leave it entirely alone.
			return 0;
		}

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => self::content(),
			)
		);

		return $page_id;
	}

	/**
	 * Whether the page still holds boilerplate rather than anybody's words.
	 *
	 * True for WordPress' suggested draft, for a page this class wrote, and for
	 * an empty one. False the moment a human has been near it.
	 */
	private static function is_untouched( string $content ): bool {
		$trimmed = trim( wp_strip_all_tags( $content ) );

		return '' === $trimmed
			|| str_contains( $content, self::WP_BOILERPLATE )
			|| str_contains( $content, self::STAMP );
	}

	private static function create(): int {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => __( 'Privacy', 'serve-dashboard' ),
				'post_name'    => 'privacy',
				'post_content' => self::content(),
			)
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return 0;
		}

		update_option( 'wp_page_for_privacy_policy', $page_id );

		return (int) $page_id;
	}

	/**
	 * The notice itself.
	 *
	 * Plain HTML rather than block markup: it stays editable in the editor
	 * either way, and this is far easier to read in a diff when somebody wants
	 * to check what the page actually claims.
	 *
	 * Every factual statement here was checked against the code. The three
	 * judgement calls — the embedded Church Center form, the retention period,
	 * and which supervisory authority applies — are the ones to revisit.
	 */
	private static function content(): string {
		$months  = Privacy::retention_months();
		$contact = Privacy::contact_email();
		$stale   = Privacy::staleness_months();

		$mail = '<a href="mailto:' . esc_attr( antispambot( $contact ) ) . '">'
			. esc_html( antispambot( $contact ) ) . '</a>';

		$out = array();

		$out[] = '<div class="serve-prose ' . self::STAMP . '">';

		$out[] = '<p class="serve-prose__lede">' . esc_html__(
			'You answer a set of questions about how God has shaped you. Your answers stay in your own browser until you decide to share them. If you share them, a small number of ministry leaders can see them so they can talk to you about serving. We keep them for a fixed period and then delete them automatically, and you can ask us to delete them sooner at any time.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<p>' . esc_html__(
			'We do not use analytics, we set no cookies of our own, and nothing you write is sent anywhere except to Fellowship Dubai.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'Who is responsible', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . sprintf(
			/* translators: %s: contact email link. */
			esc_html__( 'Fellowship Dubai. For anything in this notice, including asking for your profile to be removed, write to %s.', 'serve-dashboard' ),
			$mail
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'While you are answering, nothing reaches us', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__(
			'The journey saves your progress in your own browser, on your own device. That is not a cookie and it is not sent to us. Close the tab and come back on the same device and your answers are still there; clear your browser data and they are gone, and we never had them.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'What we receive when you share your profile', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__( 'Only when you tick the consent box and press send does anything reach us. At that point we store:', 'serve-dashboard' ) . '</p>';
		$out[] = '<ul>'
			. '<li>' . esc_html__( 'Your name, email address and phone number.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'Roughly how long you expect to be in the UAE, if you told us. This is optional, and exists so a leader does not offer a role needing two years of continuity to somebody here for six months.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'Your full S.H.A.P.E. answers — spiritual gifts, heart, abilities, personality and experiences, including anything you wrote in your own words. The Experiences section can include painful or difficult history if you chose to record it.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'A record of the consent you gave: the exact wording you agreed to, saved word for word. If we change that wording later, your record still shows what you agreed to.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'A one-way scrambled form of your IP address and browser identifier, stored with that consent record. These cannot be turned back into your address.', 'serve-dashboard' ) . '</li>'
			. '</ul>';

		$out[] = '<h2>' . esc_html__( 'If you ask us to save your place', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__(
			'To continue on another device we store your unfinished answers, your email address and how far you had got, so we can send you a link back. That is a separate request with its own wording: finishing the journey is not the same as agreeing to share it. A saved draft is kept for 30 days, is deleted the moment you use the link, and no ministry leader can see it — drafts appear in no list, search, export or email.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'What we record about the journey itself', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__(
			'We count how many people reached each step: a single number per step, with no name, no address and no record of any individual path. It answers "where do people give up" and nothing else. To avoid counting one person twice that counter uses the scrambled form of your IP address, held for a day and then discarded. The form also accepts at most five submissions from one connection per hour, and carries a field hidden from people that automated bots fill in.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'Who can see your profile', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__(
			'Ministry leaders see only profiles suggested to a team they lead, and the Experiences section is hidden from them entirely — the page says it has been hidden rather than pretending it is not there. Pastors can see every confirmed profile including Experiences.',
			'serve-dashboard'
		) . '</p>';
		$out[] = '<p>' . esc_html__(
			'Until you open the confirmation link we email you, your profile is invisible to everyone, including pastors and site administrators. Every time somebody opens your profile it is recorded — who, what and when — and opening the Experiences section is recorded separately, so that access to sensitive information can be accounted for. If a leader writes notes of a conversation with you, those notes are deleted with your profile, and the content of a note is never copied into that access record.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'Emails', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__(
			'A confirmation email, so we know the address is yours — the link works once and expires after 48 hours, and only a scrambled form of it is stored, so a copy of our database would not let anyone confirm your address. A link back to your answers, only if you asked us to save your place. Ministry leaders get a weekly summary of who is waiting to be contacted, containing names and dates only: no gifts, no notes, no answers. We send no newsletters or marketing from this system.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'How long we keep it', 'serve-dashboard' ) . '</h2>';
		$out[] = '<ul>'
			. '<li>' . sprintf(
				/* translators: %d: retention period in months. */
				esc_html__( 'A shared profile: %d months, then deleted automatically by a daily job.', 'serve-dashboard' ),
				absint( $months )
			) . '</li>'
			. '<li>' . esc_html__( 'A profile whose email address was never confirmed: 7 days.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'A saved draft: 30 days.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'The step counter: indefinitely. It contains no personal data.', 'serve-dashboard' ) . '</li>'
			. '</ul>';
		$out[] = '<p>' . sprintf(
			/* translators: 1: staleness months, 2: retention months. */
			esc_html__( 'Profiles older than %1$d months are flagged to leaders as out of date, because a profile that old no longer describes the person who filled it in. Flagged is not deleted; deletion happens at %2$d months. The record of who viewed a profile outlives the profile itself, including the entry recording its deletion — it holds no names and no answers, only which account did what and when.', 'serve-dashboard' ),
			absint( $stale ),
			absint( $months )
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'Deleting your profile', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . sprintf(
			/* translators: %s: contact email link. */
			esc_html__( 'Write to %s and ask. A pastor can delete it, which removes the profile, the consent record, the team suggestions and every conversation note, permanently and with no undo. You do not have to give a reason, and it does not affect your welcome at Fellowship Dubai in any way.', 'serve-dashboard' ),
			$mail
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'Where your information goes', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__(
			'It is stored in Fellowship Dubai\'s own website database, on the church\'s hosting. It is not sold, rented or shared with any other organisation. Planning Center is not connected to this system: no profile is copied to it, nothing is read from it, and no credentials exist. Email is delivered by an external mail provider on the church\'s behalf, which handles the message in transit.',
			'serve-dashboard'
		) . '</p>';
		$out[] = '<p>' . esc_html__(
			'One exception is worth knowing. At the end of the journey the page shows Fellowship Dubai\'s serving-opportunities form, which is hosted by Church Center (Planning Center) and displayed inside the page. Because it loads from their servers, Planning Center receives your IP address and may set its own cookies when that page opens, whether or not you use the form. Anything you type into that form goes to Planning Center rather than into this system, and their privacy terms apply to it.',
			'serve-dashboard'
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'What this system does not do', 'serve-dashboard' ) . '</h2>';
		$out[] = '<ul>'
			. '<li>' . esc_html__( 'No cookies of our own.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'No analytics, tracking pixels, session recording or advertising tags.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'No external fonts, scripts or images. The pages load nothing from another company\'s servers, apart from the embedded form described above.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'No photographs.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'No automated decisions. The dashboard suggests teams and gives its reasons, but no software places anyone anywhere. A leader reads it, a conversation happens, and you decide.', 'serve-dashboard' ) . '</li>'
			. '<li>' . esc_html__( 'No exporting of sensitive detail. Leaders can export a spreadsheet of the profiles they can see, and Experiences and conversation notes are excluded from it at every level of access, including a pastor\'s.', 'serve-dashboard' ) . '</li>'
			. '</ul>';

		$out[] = '<h2>' . esc_html__( 'Your rights', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . sprintf(
			/* translators: %s: contact email link. */
			esc_html__( 'You can ask to see what we hold about you, to correct it, or to have it deleted. You can withdraw your consent at any time; withdrawing it means we delete the profile, since it exists only because you agreed to share it. Write to %s.', 'serve-dashboard' ),
			$mail
		) . '</p>';

		$out[] = '<h2>' . esc_html__( 'Changes to this notice', 'serve-dashboard' ) . '</h2>';
		$out[] = '<p>' . esc_html__(
			'If this notice changes, the wording you agreed to does not. Your consent record keeps the exact text you were shown on the day, and the version it belonged to.',
			'serve-dashboard'
		) . '</p>';

		return implode( "\n\n", $out ) . "\n";
	}
}
