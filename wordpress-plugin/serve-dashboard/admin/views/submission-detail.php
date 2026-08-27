<?php
/**
 * One person's profile, plus the actions a leader can take on it.
 *
 * @package ServeDashboard
 *
 * @var object $submission
 * @var array<string,mixed> $profile
 * @var object|null $consent
 * @var array<int,object> $placements
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels     = Schema::status_labels();
$safeguard_labels  = Safeguarding::status_labels();
$back              = add_query_arg( array( 'page' => Admin::PAGE_DASHBOARD ), admin_url( 'admin.php' ) );
$redacted          = ! empty( $profile['_redacted'] );
?>
<div class="wrap serve-wrap serve-detail">
	<a class="serve-back" href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to the list', 'serve-dashboard' ); ?></a>

	<h1><?php echo esc_html( $submission->display_name ); ?></h1>

	<p class="serve-contact">
		<a href="<?php echo esc_url( 'mailto:' . $submission->email ); ?>"><?php echo esc_html( $submission->email ); ?></a>
		<?php if ( $submission->phone ) : ?>
			&middot; <?php echo esc_html( $submission->phone ); ?>
		<?php endif; ?>
		&middot;
		<?php
		printf(
			/* translators: %s: submission date. */
			esc_html__( 'Submitted %s', 'serve-dashboard' ),
			esc_html( mysql2date( get_option( 'date_format' ), $submission->submitted_at ) )
		);
		?>
	</p>

	<?php if ( Submissions::is_stale( $submission ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				printf(
					/* translators: %d: number of months. */
					esc_html__( 'This profile is more than %d months old. Worth confirming the answers still hold before making an invitation.', 'serve-dashboard' ),
					(int) Privacy::staleness_months()
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="serve-columns">
		<div class="serve-col-main">
			<h2><?php esc_html_e( 'Suggested teams', 'serve-dashboard' ); ?></h2>
			<?php if ( ! $placements ) : ?>
				<p class="serve-muted"><?php esc_html_e( 'No suggested teams were recorded for this profile.', 'serve-dashboard' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Team', 'serve-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Stage', 'serve-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Move to', 'serve-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $placements as $placement ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $placement->team_name ); ?></strong>
								<?php if ( (int) $placement->requires_safeguarding ) : ?>
									<span class="serve-flag serve-flag-safeguard"><?php esc_html_e( 'safeguarded', 'serve-dashboard' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $status_labels[ $placement->status ] ?? $placement->status ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="serve-move">
									<?php wp_nonce_field( 'serve_update_status' ); ?>
									<input type="hidden" name="action" value="serve_update_status">
									<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $submission->id ); ?>">
									<input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $placement->team_id ); ?>">

									<select name="status" class="serve-status-select">
										<?php foreach ( $status_labels as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $placement->status, $value ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>

									<label class="serve-inline-field">
										<span><?php esc_html_e( 'Next action', 'serve-dashboard' ); ?></span>
										<input type="date" name="next_action_at" value="<?php echo esc_attr( (string) $submission->next_action_at ); ?>">
									</label>

									<label class="serve-inline-field serve-snooze-field" hidden>
										<span><?php esc_html_e( 'Bring back on', 'serve-dashboard' ); ?></span>
										<input type="date" name="snooze_until" value="<?php echo esc_attr( (string) $submission->snooze_until ); ?>">
									</label>

									<label class="serve-inline-field serve-decline-field" hidden>
										<span><?php esc_html_e( 'Reason', 'serve-dashboard' ); ?></span>
										<input type="text" name="decline_reason" maxlength="190">
									</label>

									<?php submit_button( __( 'Save', 'serve-dashboard' ), 'secondary small', '', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Spiritual gifts', 'serve-dashboard' ); ?></h2>
			<?php foreach ( array( 'likely', 'possible', 'unlikely' ) as $bucket ) : ?>
				<?php $list = (array) ( $profile['spiritualGifts'][ $bucket ] ?? array() ); ?>
				<p>
					<strong><?php echo esc_html( ucfirst( $bucket ) ); ?>:</strong>
					<?php echo $list ? esc_html( implode( ', ', $list ) ) : '<span class="serve-muted">' . esc_html__( 'None', 'serve-dashboard' ) . '</span>'; ?>
				</p>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Heart', 'serve-dashboard' ); ?></h2>
			<?php foreach ( array( 'roles' => __( 'Roles', 'serve-dashboard' ), 'people' => __( 'People', 'serve-dashboard' ), 'causes' => __( 'Causes', 'serve-dashboard' ) ) as $key => $label ) : ?>
				<?php $list = (array) ( $profile['heart'][ $key ] ?? array() ); ?>
				<p>
					<strong><?php echo esc_html( $label ); ?>:</strong>
					<?php echo $list ? esc_html( implode( ', ', $list ) ) : '<span class="serve-muted">&mdash;</span>'; ?>
				</p>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Abilities', 'serve-dashboard' ); ?></h2>
			<p>
				<?php
				$abilities = (array) ( $profile['abilities'] ?? array() );
				echo $abilities ? esc_html( implode( ', ', $abilities ) ) : '<span class="serve-muted">&mdash;</span>';
				?>
			</p>

			<h2><?php esc_html_e( 'Personality', 'serve-dashboard' ); ?></h2>
			<p>
				<?php
				$personality = (array) ( $profile['personality'] ?? array() );
				echo $personality ? esc_html( implode( ' / ', $personality ) ) : '<span class="serve-muted">&mdash;</span>';
				?>
			</p>

			<h2><?php esc_html_e( 'Experiences', 'serve-dashboard' ); ?></h2>
			<?php if ( $redacted ) : ?>
				<p class="serve-redacted">
					<?php esc_html_e( 'Hidden. This section can include painful experiences, so it is only visible to pastoral staff.', 'serve-dashboard' ); ?>
				</p>
			<?php else : ?>
				<?php foreach ( (array) ( $profile['experiences'] ?? array() ) as $label => $values ) : ?>
					<p>
						<strong><?php echo esc_html( $label ); ?>:</strong>
						<?php echo $values ? esc_html( implode( ', ', (array) $values ) ) : '<span class="serve-muted">&mdash;</span>'; ?>
					</p>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="serve-col-side">
			<div class="serve-card">
				<h3><?php esc_html_e( 'Availability', 'serve-dashboard' ); ?></h3>
				<p><strong><?php esc_html_e( 'Hours:', 'serve-dashboard' ); ?></strong> <?php echo esc_html( (string) ( $profile['availability']['hours'] ?? '—' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Best times:', 'serve-dashboard' ); ?></strong>
					<?php
					$timing = (array) ( $profile['availability']['timing'] ?? array() );
					echo $timing ? esc_html( implode( ', ', $timing ) ) : '—';
					?>
				</p>
				<?php if ( null !== $submission->tenure_months ) : ?>
					<p>
						<strong><?php esc_html_e( 'Expected time in the UAE:', 'serve-dashboard' ); ?></strong>
						<?php
						printf(
							/* translators: %d: months. */
							esc_html__( '%d months', 'serve-dashboard' ),
							(int) $submission->tenure_months
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="serve-card">
				<h3><?php esc_html_e( 'Background check', 'serve-dashboard' ); ?></h3>
				<p class="serve-safeguard-current">
					<?php echo esc_html( $safeguard_labels[ $submission->safeguarding_status ] ?? $submission->safeguarding_status ); ?>
				</p>

				<?php if ( current_user_can( Roles::CAP_VERIFY_SAFEGUARD ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'serve_update_status' ); ?>
						<input type="hidden" name="action" value="serve_update_status">
						<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $submission->id ); ?>">
						<input type="hidden" name="status" value="<?php echo esc_attr( $submission->status ); ?>">

						<select name="safeguarding_status">
							<?php foreach ( $safeguard_labels as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $submission->safeguarding_status, $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Update', 'serve-dashboard' ), 'secondary small', '', false ); ?>
					</form>
				<?php else : ?>
					<p class="serve-muted"><?php esc_html_e( 'Only pastoral staff can record a check outcome.', 'serve-dashboard' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="serve-card">
				<h3><?php esc_html_e( 'Consent', 'serve-dashboard' ); ?></h3>
				<?php if ( $consent ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: date, 2: policy version. */
							esc_html__( 'Given %1$s (version %2$s)', 'serve-dashboard' ),
							esc_html( mysql2date( get_option( 'date_format' ), $consent->consented_at ) ),
							esc_html( $consent->policy_version )
						);
						?>
					</p>
					<p class="serve-consent-text"><?php echo esc_html( $consent->purpose_text ); ?></p>
					<p>
						<?php
						printf(
							/* translators: %d: retention period in months. */
							esc_html__( 'Deleted automatically after %d months.', 'serve-dashboard' ),
							(int) $consent->retention_months
						);
						?>
					</p>
				<?php else : ?>
					<p class="serve-muted"><?php esc_html_e( 'No consent record found for this profile.', 'serve-dashboard' ); ?></p>
				<?php endif; ?>

				<?php if ( current_user_can( Roles::CAP_MANAGE_SETTINGS ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'Delete this profile and everything attached to it? This cannot be undone.', 'serve-dashboard' ) ); ?>');">
						<?php wp_nonce_field( 'serve_erase' ); ?>
						<input type="hidden" name="action" value="serve_erase">
						<input type="hidden" name="submission_id" value="<?php echo esc_attr( (string) $submission->id ); ?>">
						<button type="submit" class="button-link delete"><?php esc_html_e( 'Delete this profile', 'serve-dashboard' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
