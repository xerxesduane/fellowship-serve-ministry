<?php
/**
 * Retention settings and the access trail.
 *
 * @package ServeDashboard
 *
 * @var int $retention
 * @var array<int,object> $audit
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap serve-wrap">
	<h1><?php esc_html_e( 'Settings and audit', 'serve-dashboard' ); ?></h1>

	<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice rendering after a redirect.
	$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
	$resent = isset( $_GET['sent'] ) ? absint( $_GET['sent'] ) : 0;
	$still  = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	?>

	<?php if ( 'resent' === $notice ) : ?>
		<div class="notice <?php echo $still > 0 ? 'notice-error' : 'notice-success'; ?> is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %d: number of confirmation emails sent. */
					esc_html( _n( '%d confirmation email sent.', '%d confirmation emails sent.', $resent, 'serve-dashboard' ) ),
					absint( $resent )
				);

				if ( $still > 0 ) {
					echo ' ';
					printf(
						/* translators: %d: number that failed again. */
						esc_html( _n( '%d still could not be sent — outgoing email is not working yet.', '%d still could not be sent — outgoing email is not working yet.', $still, 'serve-dashboard' ) ),
						absint( $still )
					);
				}
				?>
			</p>
		</div>
	<?php elseif ( '' !== $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'serve-dashboard' ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Security checklist', 'serve-dashboard' ); ?></h2>
	<p class="serve-lede">
		<?php esc_html_e( 'Some protections cannot be applied from plugin code — they belong to the server or to wp-config.php. Rather than assume they are in place, this checks them.', 'serve-dashboard' ); ?>
	</p>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col" style="width:60px"><?php esc_html_e( 'State', 'serve-dashboard' ); ?></th>
				<th scope="col" style="width:200px"><?php esc_html_e( 'Check', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Detail', 'serve-dashboard' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( Security_Status::checks() as $check ) : ?>
			<?php
			$glyph = array(
				Security_Status::PASS => '&#10003;',
				Security_Status::WARN => '!',
				Security_Status::FAIL => '&#10007;',
			)[ $check['status'] ];

			$colour = array(
				Security_Status::PASS => '#287657',
				Security_Status::WARN => '#8a6100',
				Security_Status::FAIL => '#b8401a',
			)[ $check['status'] ];
			?>
			<tr>
				<td>
					<strong style="color:<?php echo esc_attr( $colour ); ?>">
						<?php echo wp_kses_post( $glyph ); ?>
						<span class="screen-reader-text"><?php echo esc_html( $check['status'] ); ?></span>
					</strong>
				</td>
				<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
				<td>
					<?php echo esc_html( $check['detail'] ); ?>
					<?php if ( '' !== $check['action'] ) : ?>
						<div class="serve-row-meta"><em><?php echo esc_html( $check['action'] ); ?></em></div>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php $unsent = Verification::unsent_count(); ?>
	<?php if ( $unsent > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'serve_resend_confirmations' ); ?>
			<input type="hidden" name="action" value="serve_resend_confirmations">
			<p>
				<button type="submit" class="button button-primary">
					<?php
					printf(
						/* translators: %d: number of confirmation emails waiting to be sent. */
						esc_html( _n( 'Send %d unsent confirmation', 'Send %d unsent confirmations', $unsent, 'serve-dashboard' ) ),
						absint( $unsent )
					);
					?>
				</button>
				<span class="serve-row-meta">
					<em><?php esc_html_e( 'Fix outgoing email first — sending against a broken mailer will only fail again.', 'serve-dashboard' ); ?></em>
				</span>
			</p>
		</form>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Retention', 'serve-dashboard' ); ?></h2>
	<p class="serve-lede">
		<?php esc_html_e( 'A S.H.A.P.E. profile records religious belief and, in the Experiences section, pastoral history. Both UAE and DIFC data protection law treat that as sensitive, so it is kept for a fixed period and then deleted automatically.', 'serve-dashboard' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'serve_save_settings' ); ?>
		<input type="hidden" name="action" value="serve_save_settings">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="retention_months"><?php esc_html_e( 'Keep profiles for', 'serve-dashboard' ); ?></label>
				</th>
				<td>
					<input type="number" id="retention_months" name="retention_months" min="1" max="120"
						value="<?php echo esc_attr( (string) $retention ); ?>" class="small-text">
					<?php esc_html_e( 'months', 'serve-dashboard' ); ?>
					<p class="description">
						<?php esc_html_e( 'The daily sweep deletes profiles past this age, along with their consent and placement records.', 'serve-dashboard' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="contact_email"><?php esc_html_e( 'Removal requests go to', 'serve-dashboard' ); ?></label>
				</th>
				<td>
					<input type="email" id="contact_email" name="contact_email" class="regular-text"
						value="<?php echo esc_attr( (string) get_option( Privacy::OPTION_CONTACT_EMAIL, '' ) ); ?>"
						placeholder="<?php echo esc_attr( Privacy::DEFAULT_CONTACT_EMAIL ); ?>">
					<p class="description">
						<?php
						printf(
							/* translators: %s: the address published when this is left blank. */
							esc_html__( 'Published on the share page so somebody can ask for their profile to be deleted. Leave blank to use %s. Whatever is here should reach a person who would act on such a request.', 'serve-dashboard' ),
							'<code>' . esc_html( Privacy::DEFAULT_CONTACT_EMAIL ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent text in use', 'serve-dashboard' ); ?></th>
				<td>
					<p class="serve-consent-text"><?php echo esc_html( Privacy::purpose_text() ); ?></p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: policy version string. */
							esc_html__( 'Version %s. Changing the retention figure above changes this wording, so new submissions record the new version.', 'serve-dashboard' ),
							esc_html( Privacy::POLICY_VERSION )
						);
						?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'The public journey', 'serve-dashboard' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Assessment page', 'serve-dashboard' ); ?></th>
				<td>
					<?php $assessment_url = Assessment::assessment_url(); ?>
					<?php if ( $assessment_url ) : ?>
						<p>
							<a href="<?php echo esc_url( $assessment_url ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $assessment_url ); ?>
							</a>
						</p>
					<?php else : ?>
						<p class="serve-muted"><?php esc_html_e( 'No assessment page found. Create a page containing [serve_shape_assessment].', 'serve-dashboard' ); ?></p>
					<?php endif; ?>

					<?php if ( Assessment::is_front_page() ) : ?>
						<p class="description">
							<?php esc_html_e( 'This is currently the site\'s front page.', 'serve-dashboard' ); ?>
						</p>
					<?php elseif ( $assessment_url ) : ?>
						<label>
							<input type="checkbox" name="use_as_front_page" value="1">
							<?php esc_html_e( 'Make this the site\'s front page when I save', 'serve-dashboard' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Changes the WordPress reading settings so visitors land on the S.H.A.P.E. journey. Reversible from Settings → Reading.', 'serve-dashboard' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Share step', 'serve-dashboard' ); ?></th>
				<td>
					<?php $consent_url = Assessment::consent_url(); ?>
					<?php if ( $consent_url ) : ?>
						<p><a href="<?php echo esc_url( $consent_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $consent_url ); ?></a></p>
						<p class="description"><?php esc_html_e( 'The journey links here once someone reaches their completed profile. Without it, no share action is offered at all.', 'serve-dashboard' ); ?></p>
					<?php else : ?>
						<p class="serve-muted"><?php esc_html_e( 'No consent page found. Create a page containing [serve_shape_consent].', 'serve-dashboard' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Planning Center', 'serve-dashboard' ); ?></h2>
		<p class="serve-lede">
			<?php esc_html_e( 'There is no Planning Center integration. Nothing is read from or written to Planning Center, and nothing syncs. Adding your address below only enables a link that opens a person search in Planning Center — no credentials are stored and no data leaves this site.', 'serve-dashboard' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="pco_subdomain"><?php esc_html_e( 'Church Center address', 'serve-dashboard' ); ?></label>
				</th>
				<td>
					<input type="text" id="pco_subdomain" name="pco_subdomain" class="regular-text"
						value="<?php echo esc_attr( Planning_Center::subdomain() ); ?>"
						placeholder="fellowshipdubai">
					<p class="description">
						<?php esc_html_e( 'Just the first part of your Church Center web address. Leave blank to hide the Planning Center action entirely.', 'serve-dashboard' ); ?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Before a real integration is built', 'serve-dashboard' ); ?></strong>
						<?php esc_html_e( 'the team needs to agree which system owns each field, what SERVE reads and writes, sync direction and frequency, conflict handling, permissions, and API limits.', 'serve-dashboard' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<?php
	$pilot     = Pilot::report();
	$reach     = $pilot['reach'];
	$missed    = $pilot['overlooked'];
	$speed     = $pilot['speed'];
	$recorded  = $pilot['recorded'];
	?>

	<h2><?php esc_html_e( 'How the pilot is going', 'serve-dashboard' ); ?></h2>
	<p class="serve-lede">
		<?php
		if ( $pilot['period']['from'] ) {
			printf(
				/* translators: %d: number of days the pilot has been running. */
				esc_html( _n( 'Covering the %d day since the first profile was shared.', 'Covering the %d days since the first profile was shared.', $pilot['period']['days'], 'serve-dashboard' ) ),
				absint( $pilot['period']['days'] )
			);
			echo ' ';
		}
		esc_html_e( 'Every figure says how many people it was worked out from, because a median of three is not a median.', 'serve-dashboard' );
		?>
	</p>

	<?php if ( 0 === $reach['shared'] ) : ?>
		<p class="serve-muted"><?php esc_html_e( 'Nothing to report yet. These figures appear once people start sharing profiles.', 'serve-dashboard' ); ?></p>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped">
			<tbody>
				<tr>
					<td style="width:280px"><strong><?php esc_html_e( 'Profiles shared', 'serve-dashboard' ); ?></strong></td>
					<td>
						<?php echo esc_html( (string) $reach['shared'] ); ?>
						&mdash;
						<?php
						printf(
							/* translators: 1: number confirmed, 2: percentage. */
							esc_html__( '%1$d confirmed their email address (%2$d%%)', 'serve-dashboard' ),
							absint( $reach['confirmed'] ),
							absint( (int) $reach['rate'] )
						);
						?>
						<?php if ( $reach['waiting'] > 0 ) : ?>
							<div class="serve-row-meta">
								<em>
								<?php
								printf(
									/* translators: %d: number of people who have not confirmed. */
									esc_html( _n( '%d has not confirmed. A number that keeps growing is usually a mail problem rather than a people problem.', '%d have not confirmed. A number that keeps growing is usually a mail problem rather than a people problem.', $reach['waiting'], 'serve-dashboard' ) ),
									absint( $reach['waiting'] )
								);
								?>
								</em>
							</div>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<td>
						<strong><?php esc_html_e( 'Waiting on a first response', 'serve-dashboard' ); ?></strong>
						<div class="serve-row-meta"><em><?php esc_html_e( 'The number this is all meant to move.', 'serve-dashboard' ); ?></em></div>
					</td>
					<td>
						<?php if ( 0 === $missed['count'] ) : ?>
							<strong style="color:#287657"><?php esc_html_e( 'Nobody. Everyone who confirmed has been picked up.', 'serve-dashboard' ); ?></strong>
						<?php else : ?>
							<strong style="color:#b8401a"><?php echo esc_html( (string) $missed['count'] ); ?></strong>
							<?php esc_html_e( 'confirmed their address and have had nothing happen since.', 'serve-dashboard' ); ?>
							<div class="serve-row-meta">
								<em>
								<?php
								printf(
									/* translators: %d: days the longest-waiting person has waited. */
									esc_html( _n( 'The person waiting longest has been waiting %d day.', 'The person waiting longest has been waiting %d days.', (int) $missed['longest_days'], 'serve-dashboard' ) ),
									absint( (int) $missed['longest_days'] )
								);
								?>
								</em>
							</div>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<td><strong><?php esc_html_e( 'Days until someone acts', 'serve-dashboard' ); ?></strong></td>
					<td>
						<?php if ( 0 === $speed['sample'] ) : ?>
							<span class="serve-muted"><?php esc_html_e( 'Nobody has been moved along yet.', 'serve-dashboard' ); ?></span>
						<?php else : ?>
							<?php
							printf(
								/* translators: 1: median days, 2: number of people measured. */
								esc_html__( 'Median %1$s days, across %2$d people', 'serve-dashboard' ),
								esc_html( (string) $speed['median'] ),
								absint( $speed['sample'] )
							);
							?>
							<?php if ( null !== $speed['slowest'] ) : ?>
								<?php
								printf(
									/* translators: %s: the longest wait in days. */
									' &middot; ' . esc_html__( 'longest %s days', 'serve-dashboard' ),
									esc_html( (string) $speed['slowest'] )
								);
								?>
							<?php endif; ?>
							<?php if ( ! $speed['enough'] ) : ?>
								<div class="serve-row-meta">
									<em><?php esc_html_e( 'Too few people so far for this to mean much. Treat it as a sign of life, not a measurement.', 'serve-dashboard' ); ?></em>
								</div>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>

				<tr>
					<td><strong><?php esc_html_e( 'Conversations written down', 'serve-dashboard' ); ?></strong></td>
					<td>
						<?php
						printf(
							/* translators: 1: profiles with a note, 2: profiles acted on. */
							esc_html__( '%1$d of the %2$d people acted on have a note against them', 'serve-dashboard' ),
							absint( $recorded['with_notes'] ),
							absint( $recorded['acted_on'] )
						);
						?>
						<div class="serve-row-meta">
							<em><?php esc_html_e( 'Not a measure of how the conversations went — only of whether the next leader can find out what was said.', 'serve-dashboard' ); ?></em>
						</div>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'How far people get', 'serve-dashboard' ); ?></h3>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:220px"><?php esc_html_e( 'Stage', 'serve-dashboard' ); ?></th>
					<th scope="col" style="width:110px"><?php esc_html_e( 'Ever reached', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Share of confirmed profiles', 'serve-dashboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $pilot['progression'] as $stage ) : ?>
				<?php $share = $reach['confirmed'] > 0 ? (int) round( $stage['count'] / $reach['confirmed'] * 100 ) : 0; ?>
				<tr>
					<td><?php echo esc_html( $stage['label'] ); ?></td>
					<td><?php echo esc_html( (string) $stage['count'] ); ?></td>
					<td>
						<div style="background:#f5f1e8;border-radius:999px;height:8px;max-width:320px">
							<div style="width:<?php echo esc_attr( (string) $share ); ?>%;background:#167d83;height:8px;border-radius:999px"></div>
						</div>
						<small><?php echo esc_html( $share . '%' ); ?></small>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h3><?php esc_html_e( 'What this cannot tell you', 'serve-dashboard' ); ?></h3>
	<p class="serve-lede">
		<?php esc_html_e( 'Four of the five questions the pilot is meant to answer are about how something felt to a person. No database produces those. They have to be asked, and the answers matter at least as much as the figures above.', 'serve-dashboard' ); ?>
	</p>
	<ul class="ul-disc">
		<?php foreach ( Pilot::unanswerable() as $question ) : ?>
			<li><?php echo esc_html( $question ); ?></li>
		<?php endforeach; ?>
	</ul>

	<?php $friction = Friction::recent( 50 ); ?>

	<h3><?php esc_html_e( 'What leaders have said is not working', 'serve-dashboard' ); ?></h3>
	<p class="serve-lede">
		<?php esc_html_e( 'Left from the dashboard, while it was fresh. This is the half of the pilot the figures cannot reach — read it alongside them rather than after them.', 'serve-dashboard' ); ?>
	</p>

	<?php if ( ! $friction ) : ?>
		<p class="serve-muted">
			<?php esc_html_e( 'Nothing reported yet. Silence early in a pilot usually means nobody has been shown where to say it, rather than that everything is working.', 'serve-dashboard' ); ?>
		</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:230px"><?php esc_html_e( 'What happened', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'In their words', 'serve-dashboard' ); ?></th>
					<th scope="col" style="width:170px"><?php esc_html_e( 'Who and when', 'serve-dashboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $friction as $item ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $item['label'] ); ?></strong></td>
					<td><?php echo esc_html( $item['body'] ); ?></td>
					<td class="serve-muted">
						<?php echo esc_html( $item['who'] ); ?><br>
						<?php echo esc_html( $item['when'] ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Where people stop', 'serve-dashboard' ); ?></h2>
	<p class="serve-lede">
		<?php esc_html_e( 'Anonymous counts of how many people reached each step of the journey. No names, no addresses, nothing tied to a profile — just where the drop-off is, so it can be fixed.', 'serve-dashboard' ); ?>
	</p>

	<?php $funnel = Funnel::report(); ?>
	<?php if ( ! $funnel ) : ?>
		<p class="serve-muted"><?php esc_html_e( 'Nothing recorded yet. Counts appear once people start the journey.', 'serve-dashboard' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:90px"><?php esc_html_e( 'Step', 'serve-dashboard' ); ?></th>
					<th scope="col" style="width:110px"><?php esc_html_e( 'Reached', 'serve-dashboard' ); ?></th>
					<th scope="col" style="width:110px"><?php esc_html_e( 'Lost here', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Share of everyone who started', 'serve-dashboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $funnel as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row['step'] ); ?></td>
					<td><?php echo esc_html( (string) $row['reached'] ); ?></td>
					<td>
						<?php
						echo $row['dropped'] > 0
							? '<strong style="color:#b8401a">-' . esc_html( (string) $row['dropped'] ) . '</strong>'
							: '<span class="serve-muted">&mdash;</span>';
						?>
					</td>
					<td>
						<div style="background:#f5f1e8;border-radius:999px;height:8px;max-width:320px">
							<div style="width:<?php echo esc_attr( (string) $row['share'] ); ?>%;background:#167d83;height:8px;border-radius:999px"></div>
						</div>
						<small><?php echo esc_html( $row['share'] . '%' ); ?></small>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Recent access', 'serve-dashboard' ); ?></h2>
	<p class="serve-lede">
		<?php esc_html_e( 'Who opened which profile, and who changed what. Kept because a leader reading someone\'s spiritual gifts and pastoral history is itself worth recording.', 'serve-dashboard' ); ?>
	</p>

	<?php if ( ! $audit ) : ?>
		<p class="serve-muted"><?php esc_html_e( 'Nothing recorded yet.', 'serve-dashboard' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Record', 'serve-dashboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $audit as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $entry->created_at ) ); ?></td>
					<td>
						<?php
						$user = $entry->user_id ? get_userdata( (int) $entry->user_id ) : null;
						echo $user ? esc_html( $user->display_name ) : '<span class="serve-muted">' . esc_html__( 'Public form', 'serve-dashboard' ) . '</span>';
						?>
					</td>
					<td><code><?php echo esc_html( $entry->action ); ?></code></td>
					<td>
						<?php
						echo $entry->object_id
							? esc_html( $entry->object_type . ' #' . $entry->object_id )
							: '<span class="serve-muted">&mdash;</span>';
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
