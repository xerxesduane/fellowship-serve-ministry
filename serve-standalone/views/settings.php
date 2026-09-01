<?php
/**
 * Settings and audit.
 *
 * Four things a pastor can change, a checklist they cannot, and the record of
 * what everybody has done. They share a screen because between them they answer
 * one question: is this installation behaving the way we told people it would.
 *
 * The grouping into sections is a judgement call rather than something a rule
 * dictated. These settings are unrelated to each other, and a flat list of five
 * fields gives no clue which of them govern a volunteer's data and which govern
 * a link in an email, so each section says what it is for before asking for
 * anything.
 *
 * The retention period is the one to be careful with. It is a promise published
 * in the privacy notice, and that notice is generated from this value -- so
 * shortening it shortens what the notice claims, and lengthening it makes a new
 * claim about answers already collected under a shorter one. The field says so.
 *
 * Field markup follows views/teams.php so there is one pattern rather than two.
 * The wrapper is a div rather than a wrapping label, because these fields carry
 * help text and error text and a <label> may only contain phrasing content.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App as Platform;
use Serve_Dashboard\Assessment;
use Serve_Dashboard\Audit;
use Serve_Dashboard\Digest;
use Serve_Dashboard\Placements;
use Serve_Dashboard\Planning_Center;
use Serve_Dashboard\Privacy;
use Serve_Dashboard\Security_Status;
use Serve_Dashboard\Teams;

require SERVE_ROOT . '/views/app/chrome.php';

/** @var string $serve_notice Set by the front controller after a save. */
$serve_notice = $serve_notice ?? '';

/** @var array<string,string> $serve_errors Per-field messages, empty on success. */
$serve_errors = $serve_errors ?? array();

/*
 * Stored values, unless this render is a rejected submission.
 *
 * A rejected submission shows what was typed, not what is in the database.
 * Re-reading the database would silently discard the four fields somebody had
 * just filled in correctly because the fifth was wrong -- which is the whole
 * reason this path renders instead of redirecting.
 */
$serve_rejected = array() !== $serve_errors;

/** Either what was posted, or what is stored. */
$serve_value = static function ( string $field, $stored ) use ( $serve_rejected ): string {
	return $serve_rejected ? (string) ( $_POST[ $field ] ?? '' ) : (string) $stored;
};

/**
 * Everything tying a control to the text that describes it.
 *
 * One attribute string rather than two, because a field needs its help text and
 * its error announced together. A second aria-describedby would be invalid
 * markup and only the first would be read, so on a rejected submission the
 * error would be the half silently dropped.
 */
$serve_describe = static function ( string $field, string $help ) use ( $serve_errors ): string {
	$ids = array( $help );

	if ( isset( $serve_errors[ $field ] ) ) {
		$ids[] = 'serve-err-' . $field;
	}

	return ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"'
		. ( isset( $serve_errors[ $field ] ) ? ' aria-invalid="true"' : '' );
};

/** The error itself, under the field it belongs to. */
$serve_error_for = static function ( string $field ) use ( $serve_errors ): void {
	if ( ! isset( $serve_errors[ $field ] ) ) {
		return;
	}

	printf(
		'<p class="serve-field__error" id="serve-err-%s">%s<span>%s</span></p>',
		esc_attr( $field ),
		'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
			. ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
			. '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>'
			. '<path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
		esc_html( $serve_errors[ $field ] )
	);
};

$serve_teams     = Teams::all( false );
$serve_retention = $serve_value( 'retention_months', Privacy::retention_months() );
$serve_contact   = $serve_value( 'contact_email', Privacy::contact_email() );
$serve_catchall  = $serve_value( 'catchall_team', get_option( Placements::OPTION_CATCHALL_TEAM, Placements::DEFAULT_CATCHALL_TEAM ) );
$serve_form_url  = $serve_value( 'serving_form_url', get_option( Assessment::OPTION_SERVING_FORM, '' ) );
$serve_pco       = $serve_value( 'pco_subdomain', get_option( Planning_Center::OPTION_SUBDOMAIN, '' ) );
$serve_checks    = Security_Status::checks();
$serve_audit     = Audit::recent( 50 );
$serve_sweep     = Privacy::last_sweep();
$serve_digest    = Digest::last_run();
$serve_date      = (string) get_option( 'date_format' ) . ' H:i';

serve_chrome_open(
	'settings',
	__( 'Settings and audit' ),
	__( 'What this installation promises, and a record of what has been done in it.' )
);
?>

<?php if ( '' !== $serve_notice ) : ?>
	<?php
	/*
	 * Three outcomes, said differently.
	 *
	 * A first version had two branches, so a rejected submission was announced
	 * as "nothing changed" -- technically true and actively misleading, since
	 * something was wrong and the page was waiting to be corrected. role="alert"
	 * for that case, role="status" for the other two, and focus either way so
	 * the outcome is not something only a sighted user notices.
	 */
	$serve_notices = array(
		'saved'     => array( 'ok', 'status', __( 'Saved.' ) ),
		'unchanged' => array( 'ok', 'status', __( 'Nothing changed — the values were already what you entered.' ) ),
		'invalid'   => array( 'warn', 'alert', __( 'Nothing was saved. Check the fields marked below.' ) ),
	);

	list( $serve_tone, $serve_role, $serve_message ) = $serve_notices[ $serve_notice ] ?? $serve_notices['unchanged'];
	?>
	<p class="serve-note serve-note--<?php echo esc_attr( $serve_tone ); ?>"
		role="<?php echo esc_attr( $serve_role ); ?>" tabindex="-1" autofocus>
		<?php echo esc_html( $serve_message ); ?>
	</p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( Platform::url( 'settings' ) ); ?>" class="serve-settings">
	<?php wp_nonce_field( 'serve_save_settings' ); ?>

	<!-- Retention ---------------------------------------------------------- -->
	<section class="serve-card serve-setting" aria-labelledby="serve-set-retention">
		<h2 class="serve-setting__title" id="serve-set-retention"><?php esc_html_e( 'Keeping people’s answers' ); ?></h2>
		<p class="serve-card__hint">
			<?php esc_html_e( 'A profile is deleted automatically once this long has passed since it was submitted. The privacy notice is generated from this number, so changing it changes what that notice promises.' ); ?>
		</p>

		<div class="serve-setting__grid">
			<div class="serve-field">
				<label for="serve-retention"><?php esc_html_e( 'Keep profiles for' ); ?></label>

				<span class="serve-setting__inline">
					<input id="serve-retention" name="retention_months" type="number"
						min="1" max="120" step="1" inputmode="numeric"
						value="<?php echo esc_attr( $serve_retention ); ?>"
						<?php echo $serve_describe( 'retention_months', 'serve-retention-help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>>
					<span class="serve-setting__unit"><?php esc_html_e( 'months' ); ?></span>
				</span>

				<p class="serve-field__help" id="serve-retention-help">
					<?php esc_html_e( 'Between 1 and 120. Shortening this deletes profiles sooner, including ones already collected.' ); ?>
				</p>

				<?php $serve_error_for( 'retention_months' ); ?>
			</div>

			<div class="serve-field">
				<label for="serve-contact"><?php esc_html_e( 'Removal requests go to' ); ?></label>

				<input id="serve-contact" name="contact_email" type="email"
					autocomplete="off" spellcheck="false"
					value="<?php echo esc_attr( $serve_contact ); ?>"
					<?php echo $serve_describe( 'contact_email', 'serve-contact-help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>>

				<p class="serve-field__help" id="serve-contact-help">
					<?php esc_html_e( 'Published in the privacy notice as the address to write to, so it has to be one somebody reads.' ); ?>
				</p>

				<?php $serve_error_for( 'contact_email' ); ?>
			</div>
		</div>

		<details class="serve-setting__details">
			<summary><?php esc_html_e( 'The consent text people are agreeing to' ); ?></summary>
			<p class="serve-consent-text"><?php echo esc_html( Privacy::purpose_text() ); ?></p>
		</details>

		<p class="serve-setting__fact">
			<?php
			printf(
				/* translators: %s: when the retention sweep last ran. */
				esc_html__( 'Retention sweep last ran: %s' ),
				esc_html(
					null === $serve_sweep
						? __( 'never — check that the scheduled job is running' )
						: (string) mysql2date( $serve_date, (string) $serve_sweep )
				)
			);
			?>
		</p>
	</section>

	<!-- Who picks up a new profile ------------------------------------------ -->
	<section class="serve-card serve-setting" aria-labelledby="serve-set-catchall">
		<h2 class="serve-setting__title" id="serve-set-catchall"><?php esc_html_e( 'Who picks up a new profile' ); ?></h2>
		<p class="serve-card__hint">
			<?php esc_html_e( 'Every completed profile is given one owner, so it lands in somebody’s queue rather than nobody’s. This is not a judgement about fit and it does not place anyone on a team.' ); ?>
		</p>

		<div class="serve-field">
			<label for="serve-catchall"><?php esc_html_e( 'Central intake team' ); ?></label>

			<select id="serve-catchall" name="catchall_team"
				<?php echo $serve_describe( 'catchall_team', 'serve-catchall-help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>>
				<option value=""<?php echo '' === $serve_catchall ? ' selected' : ''; ?>>
					<?php esc_html_e( 'Nobody — pastors pick these up' ); ?>
				</option>

				<?php foreach ( $serve_teams as $serve_team ) : ?>
					<?php if ( empty( $serve_team->is_active ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>

					<option value="<?php echo esc_attr( (string) $serve_team->slug ); ?>"
						<?php echo (string) $serve_team->slug === $serve_catchall ? ' selected' : ''; ?>>
						<?php echo esc_html( (string) $serve_team->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<p class="serve-field__help" id="serve-catchall-help">
				<?php esc_html_e( 'Naming a team gives its leaders access to every new profile. Choosing nobody means only pastors see them until somebody is assigned.' ); ?>
			</p>

			<?php $serve_error_for( 'catchall_team' ); ?>
		</div>
	</section>

	<!-- The share step ------------------------------------------------------ -->
	<section class="serve-card serve-setting" aria-labelledby="serve-set-form">
		<h2 class="serve-setting__title" id="serve-set-form"><?php esc_html_e( 'The form offered at the end' ); ?></h2>
		<p class="serve-card__hint">
			<?php esc_html_e( 'Shown to somebody who has just finished the journey. Left empty, the step is not offered at all — which is how the journey behaved before this dashboard existed.' ); ?>
		</p>

		<div class="serve-field">
			<label for="serve-form-url"><?php esc_html_e( 'Serving opportunities form' ); ?></label>

			<input id="serve-form-url" name="serving_form_url" type="url"
				inputmode="url" spellcheck="false" placeholder="https://"
				value="<?php echo esc_attr( $serve_form_url ); ?>"
				<?php echo $serve_describe( 'serving_form_url', 'serve-form-help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>>

			<p class="serve-field__help" id="serve-form-help">
				<?php esc_html_e( 'A full https:// address. Anything else is refused rather than saved and quietly broken.' ); ?>
			</p>

			<?php $serve_error_for( 'serving_form_url' ); ?>
		</div>
	</section>

	<!-- Planning Center ----------------------------------------------------- -->
	<section class="serve-card serve-setting" aria-labelledby="serve-set-pco">
		<h2 class="serve-setting__title" id="serve-set-pco"><?php esc_html_e( 'Planning Center' ); ?></h2>
		<p class="serve-card__hint">
			<?php esc_html_e( 'Used only to build a link to somebody’s Church Center record. Nothing is sent to Planning Center and nothing is read from it.' ); ?>
		</p>

		<div class="serve-field">
			<label for="serve-pco"><?php esc_html_e( 'Church Center address' ); ?></label>

			<span class="serve-setting__inline">
				<span class="serve-setting__unit"><?php esc_html_e( 'https://' ); ?></span>
				<input id="serve-pco" name="pco_subdomain" type="text"
					autocomplete="off" spellcheck="false" placeholder="yourchurch"
					value="<?php echo esc_attr( $serve_pco ); ?>"
					<?php echo $serve_describe( 'pco_subdomain', 'serve-pco-help' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>>
				<span class="serve-setting__unit"><?php esc_html_e( '.churchcenter.com' ); ?></span>
			</span>

			<p class="serve-field__help" id="serve-pco-help">
				<?php esc_html_e( 'Just the subdomain — the part before .churchcenter.com. Paste the whole address if it is easier; the rest is trimmed off. Leave it empty to show no links.' ); ?>
			</p>

			<?php $serve_error_for( 'pco_subdomain' ); ?>
		</div>
	</section>

	<div class="serve-settings__actions">
		<button type="submit" class="serve-btn serve-btn--primary"><?php esc_html_e( 'Save settings' ); ?></button>
	</div>
</form>

<!-- Security checklist ------------------------------------------------------ -->
<section class="serve-card serve-setting" aria-labelledby="serve-set-checks">
	<h2 class="serve-setting__title" id="serve-set-checks"><?php esc_html_e( 'Security checklist' ); ?></h2>
	<p class="serve-card__hint">
		<?php esc_html_e( 'Checked on every load. Nothing here is editable: each line is a fact about how this installation is running.' ); ?>
	</p>

	<?php
	$serve_failing = array_filter(
		$serve_checks,
		static fn( $check ): bool => 'fail' === ( ( (array) $check )['state'] ?? '' )
	);
	?>

	<?php if ( array() !== $serve_failing ) : ?>
		<p class="serve-note serve-note--warn">
			<?php
			printf(
				/* translators: %d: how many checks are failing. */
				esc_html( _n( '%d check needs attention.', '%d checks need attention.', count( $serve_failing ), 'serve' ) ),
				count( $serve_failing )
			);
			?>
		</p>
	<?php endif; ?>

	<?php
	/*
	 * A table, because this is genuinely tabular: three columns of the same
	 * shape for every row. It scrolls inside its own container rather than
	 * widening the page, and every state is a word as well as a colour.
	 */
	?>
	<div class="serve-table-scroll">
		<table class="serve-table serve-checks">
			<caption class="screen-reader-text"><?php esc_html_e( 'Security checks and their current state' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'State' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Check' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $serve_checks as $serve_check ) : ?>
					<?php
					$serve_check = (array) $serve_check;
					$serve_state = (string) ( $serve_check['state'] ?? 'warn' );
					$serve_words = array(
						'pass' => __( 'Pass' ),
						'fail' => __( 'Fail' ),
					);
					?>
					<tr>
						<td>
							<span class="serve-state serve-state--<?php echo esc_attr( $serve_state ); ?>">
								<?php echo esc_html( $serve_words[ $serve_state ] ?? __( 'Check' ) ); ?>
							</span>
						</td>
						<th scope="row"><?php echo esc_html( (string) ( $serve_check['label'] ?? '' ) ); ?></th>
						<td class="serve-checks__detail"><?php echo esc_html( (string) ( $serve_check['detail'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="serve-setting__fact">
		<?php
		printf(
			/* translators: %s: when the weekly digest last went out. */
			esc_html__( 'Weekly digest last sent: %s' ),
			esc_html(
				null === $serve_digest || empty( $serve_digest['at'] )
					? __( 'never' )
					: (string) mysql2date( $serve_date, (string) $serve_digest['at'] )
			)
		);
		?>
	</p>
</section>

<!-- The audit trail --------------------------------------------------------- -->
<section class="serve-card serve-setting" aria-labelledby="serve-set-audit">
	<h2 class="serve-setting__title" id="serve-set-audit"><?php esc_html_e( 'Audit trail' ); ?></h2>
	<p class="serve-card__hint">
		<?php esc_html_e( 'Who looked at whose answers, and who changed what. Append-only: nothing here can be edited or removed, including by a pastor.' ); ?>
	</p>

	<?php if ( array() === $serve_audit ) : ?>
		<div class="serve-empty">
			<p><?php esc_html_e( 'Nothing recorded yet.' ); ?></p>
		</div>
	<?php else : ?>
		<div class="serve-table-scroll">
			<table class="serve-table serve-audit">
				<caption class="screen-reader-text"><?php esc_html_e( 'The fifty most recent recorded actions' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Who' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Did what' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $serve_audit as $serve_row ) : ?>
						<?php
						$serve_who  = (int) ( $serve_row->user_id ?? 0 );
						$serve_user = $serve_who > 0 ? get_userdata( $serve_who ) : false;
						$serve_type = (string) ( $serve_row->object_type ?? '' );
						$serve_oid  = (int) ( $serve_row->object_id ?? 0 );
						?>
						<tr>
							<td class="serve-audit__when">
								<?php echo esc_html( (string) mysql2date( $serve_date, (string) $serve_row->created_at ) ); ?>
							</td>
							<td>
								<?php
								/*
								 * A deleted account still has to be attributable.
								 * The trail outlives somebody's login, and "user 7
								 * (removed)" is more use than an empty cell.
								 */
								echo esc_html(
									false !== $serve_user
										? (string) $serve_user->display_name
										: ( $serve_who > 0
											/* translators: %d: the id of an account that no longer exists. */
											? sprintf( __( 'user %d (removed)' ), $serve_who )
											: __( 'the system' ) )
								);
								?>
							</td>
							<th scope="row" class="serve-audit__action">
								<code><?php echo esc_html( (string) $serve_row->action ); ?></code>
							</th>
							<td class="serve-audit__object">
								<?php
								echo esc_html(
									'' === $serve_type
										? '—'
										: ( $serve_oid > 0 ? $serve_type . ' ' . $serve_oid : $serve_type )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<p class="serve-setting__fact">
			<?php esc_html_e( 'The fifty most recent entries. The full trail is in the database and is never trimmed.' ); ?>
		</p>
	<?php endif; ?>
</section>

<?php serve_chrome_close(); ?>
