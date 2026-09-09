<?php
/**
 * Accounts.
 *
 * The screen that stops onboarding a ministry leader from requiring shell
 * access. Before this, creating an account meant `bin/serve user:add` -- so a
 * church could not add a leader, or help anybody who had forgotten a password,
 * without a developer available.
 *
 * Every guard is enforced in Platform\Auth, not here. What this page does is
 * avoid offering choices that would then be refused: the role chooser lists
 * only roles this person may actually assign, and their own row carries no
 * controls at all. A screen that offers an action and then refuses it teaches
 * people to distrust the screen.
 *
 * No password is ever chosen on somebody's behalf. A new account gets an
 * emailed link and sets its own, so no password is known to two people.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App as Platform;
use Serve\Platform\Auth;

require SERVE_ROOT . '/views/app/chrome.php';

/** @var string $serve_notice Set by the front controller after a write. */
$serve_notice = $serve_notice ?? '';

/** @var array<string,string> $serve_errors Per-field messages, empty on success. */
$serve_errors = $serve_errors ?? array();

$serve_me       = Platform::auth()->current_id();
$serve_roles    = Platform::auth()->manageable_roles( $serve_me );
$serve_accounts = Platform::db()->get_results(
	'SELECT id, email, display_name, role, is_active, last_login_at FROM '
	. Platform::db()->table( 'users' ) . ' ORDER BY display_name, email'
);
$serve_date = (string) get_option( 'date_format' );

serve_chrome_open(
	'users',
	__( 'Accounts' ),
	__( 'Who can sign in, and what each of them may do.' )
);
?>

<?php if ( '' !== $serve_notice ) : ?>
	<?php
	$serve_messages = array(
		'created' => array( 'ok', 'status', __( 'Account created, and a link to set a password has been sent.' ) ),
		'saved'   => array( 'ok', 'status', __( 'Saved.' ) ),
		'reset'   => array( 'ok', 'status', __( 'A link to set a new password has been sent.' ) ),
		'invalid' => array( 'warn', 'alert', __( 'Nothing was saved. Check the fields marked below.' ) ),
		'refused' => array( 'warn', 'alert', __( 'That change was refused.' ) ),
	);

	list( $serve_tone, $serve_live, $serve_message ) = $serve_messages[ $serve_notice ] ?? $serve_messages['saved'];
	?>
	<p class="serve-note serve-note--<?php echo esc_attr( $serve_tone ); ?>"
		role="<?php echo esc_attr( $serve_live ); ?>" tabindex="-1" autofocus>
		<?php echo esc_html( $serve_message ); ?>
	</p>
<?php endif; ?>

<!-- Add somebody ----------------------------------------------------------- -->
<section class="serve-card serve-setting" aria-labelledby="serve-add-title">
	<h2 class="serve-setting__title" id="serve-add-title"><?php esc_html_e( 'Add an account' ); ?></h2>
	<p class="serve-card__hint">
		<?php esc_html_e( 'They receive an email with a link to set their own password. None is chosen for them, so no password is ever known to two people.' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( Platform::url( 'users' ) ); ?>">
		<?php wp_nonce_field( 'serve_add_user' ); ?>
		<input type="hidden" name="action" value="add">

		<div class="serve-setting__grid">
			<div class="serve-field">
				<label for="serve-new-name"><?php esc_html_e( 'Their name' ); ?></label>
				<input id="serve-new-name" name="display_name" type="text" autocomplete="off"
					value="<?php echo esc_attr( (string) ( $_POST['display_name'] ?? '' ) ); ?>"
					<?php echo isset( $serve_errors['display_name'] ) ? 'aria-invalid="true" aria-describedby="serve-e-name"' : ''; ?>>

				<?php if ( isset( $serve_errors['display_name'] ) ) : ?>
					<p class="serve-field__error" id="serve-e-name"><span><?php echo esc_html( $serve_errors['display_name'] ); ?></span></p>
				<?php endif; ?>
			</div>

			<div class="serve-field">
				<label for="serve-new-email"><?php esc_html_e( 'Email address' ); ?></label>
				<input id="serve-new-email" name="email" type="email" autocomplete="off" spellcheck="false"
					value="<?php echo esc_attr( (string) ( $_POST['email'] ?? '' ) ); ?>"
					aria-describedby="serve-new-email-help<?php echo isset( $serve_errors['email'] ) ? ' serve-e-email' : ''; ?>"
					<?php echo isset( $serve_errors['email'] ) ? 'aria-invalid="true"' : ''; ?>>

				<p class="serve-field__help" id="serve-new-email-help">
					<?php esc_html_e( 'Both their sign-in name and where the link goes.' ); ?>
				</p>

				<?php if ( isset( $serve_errors['email'] ) ) : ?>
					<p class="serve-field__error" id="serve-e-email"><span><?php echo esc_html( $serve_errors['email'] ); ?></span></p>
				<?php endif; ?>
			</div>

			<div class="serve-field">
				<label for="serve-new-role"><?php esc_html_e( 'What they may do' ); ?></label>
				<select id="serve-new-role" name="role" aria-describedby="serve-new-role-help">
					<?php foreach ( $serve_roles as $serve_option ) : ?>
						<option value="<?php echo esc_attr( $serve_option ); ?>"
							<?php echo Auth::ROLE_LEADER === $serve_option ? ' selected' : ''; ?>>
							<?php echo esc_html( Auth::role_label( $serve_option ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<p class="serve-field__help" id="serve-new-role-help">
					<?php esc_html_e( 'A Ministry Leader sees only people placed on teams they lead, with Experiences hidden from them. A Pastor sees everyone.' ); ?>
				</p>
			</div>
		</div>

		<div class="serve-settings__actions">
			<button type="submit" class="serve-btn serve-btn--primary"><?php esc_html_e( 'Add account' ); ?></button>
		</div>
	</form>
</section>

<!-- Existing accounts ------------------------------------------------------ -->
<section class="serve-card serve-setting" aria-labelledby="serve-accounts-title">
	<h2 class="serve-setting__title" id="serve-accounts-title"><?php esc_html_e( 'Who has an account' ); ?></h2>
	<p class="serve-card__hint">
		<?php esc_html_e( 'Switching an account off ends its sessions immediately. Nothing it did is removed: the audit trail goes on naming it.' ); ?>
	</p>

	<div class="serve-table-scroll">
		<table class="serve-table serve-accounts">
			<caption class="screen-reader-text"><?php esc_html_e( 'Accounts, what each may do, and when they last signed in' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Who' ); ?></th>
					<th scope="col"><?php esc_html_e( 'May do' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last signed in' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Change' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $serve_accounts as $serve_account ) : ?>
					<?php
					$serve_id     = (int) $serve_account->id;
					$serve_is_me  = $serve_id === $serve_me;
					$serve_can    = Platform::auth()->can_manage_user( $serve_me, $serve_id );
					$serve_active = (bool) (int) $serve_account->is_active;
					?>
					<tr class="<?php echo $serve_active ? '' : 'is-off'; ?>">
						<th scope="row">
							<span class="serve-accounts__name"><?php echo esc_html( (string) $serve_account->display_name ); ?></span>
							<span class="serve-accounts__email"><?php echo esc_html( (string) $serve_account->email ); ?></span>

							<?php if ( $serve_is_me ) : ?>
								<span class="serve-state serve-state--warn"><?php esc_html_e( 'You' ); ?></span>
							<?php endif; ?>

							<?php if ( ! $serve_active ) : ?>
								<span class="serve-state serve-state--fail"><?php esc_html_e( 'Switched off' ); ?></span>
							<?php endif; ?>
						</th>

						<td><?php echo esc_html( Auth::role_label( (string) $serve_account->role ) ); ?></td>

						<td class="serve-audit__when">
							<?php
							echo esc_html(
								null === $serve_account->last_login_at
									? __( 'never' )
									: (string) mysql2date( $serve_date, (string) $serve_account->last_login_at )
							);
							?>
						</td>

						<td>
							<?php if ( ! $serve_can ) : ?>
								<?php
								/*
								 * Nothing offered, rather than controls that would
								 * be refused -- and the reason differs, so it says
								 * which. Nobody edits their own account, because
								 * that is how a person removes their own access and
								 * leaves the command line as the only way back.
								 */
								?>
								<span class="serve-muted">
									<?php
									echo esc_html(
										$serve_is_me
											? __( 'Ask another pastor to change your own account' )
											: __( 'Not yours to change' )
									);
									?>
								</span>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( Platform::url( 'users' ) ); ?>" class="serve-accounts__form">
									<?php wp_nonce_field( 'serve_save_user' ); ?>
									<input type="hidden" name="action" value="save">
									<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $serve_id ); ?>">

									<label class="screen-reader-text" for="serve-role-<?php echo esc_attr( (string) $serve_id ); ?>">
										<?php
										printf(
											/* translators: %s: the person's name. */
											esc_html__( 'What %s may do' ),
											esc_html( (string) $serve_account->display_name )
										);
										?>
									</label>

									<select id="serve-role-<?php echo esc_attr( (string) $serve_id ); ?>" name="role">
										<?php foreach ( $serve_roles as $serve_option ) : ?>
											<option value="<?php echo esc_attr( $serve_option ); ?>"
												<?php echo (string) $serve_account->role === $serve_option ? ' selected' : ''; ?>>
												<?php echo esc_html( Auth::role_label( $serve_option ) ); ?>
											</option>
										<?php endforeach; ?>
									</select>

									<label class="serve-check">
										<input type="checkbox" name="is_active" value="1" <?php echo $serve_active ? 'checked' : ''; ?>>
										<span><?php esc_html_e( 'Can sign in' ); ?></span>
									</label>

									<button type="submit" class="serve-btn serve-btn--secondary serve-btn--sm">
										<?php esc_html_e( 'Save' ); ?>
									</button>
								</form>

								<form method="post" action="<?php echo esc_url( Platform::url( 'users' ) ); ?>" class="serve-accounts__form">
									<?php wp_nonce_field( 'serve_reset_user' ); ?>
									<input type="hidden" name="action" value="reset">
									<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $serve_id ); ?>">

									<button type="submit" class="serve-btn serve-btn--sm serve-accounts__reset">
										<?php esc_html_e( 'Send a reset link' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<?php serve_chrome_close(); ?>
