<?php
/**
 * The leader list: who to talk to next.
 *
 * @package ServeDashboard
 *
 * @var array<int,object> $submissions
 * @var array<int,object> $gaps
 * @var array<int,object> $teams
 * @var array<string,mixed> $filters
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = Schema::status_labels();
$today         = gmdate( 'Y-m-d' );
?>
<div class="wrap serve-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Who to talk to next', 'serve-dashboard' ); ?></h1>

	<?php if ( isset( $_GET['notice'] ) ) : ?>
		<?php
		$notice  = sanitize_key( wp_unslash( $_GET['notice'] ) );
		$message = isset( $_GET['message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['message'] ) ) ) : '';
		$class   = 'error' === $notice ? 'notice-error' : 'notice-success';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p>
				<?php
				if ( $message ) {
					echo esc_html( $message );
				} elseif ( 'erased' === $notice ) {
					esc_html_e( 'Profile deleted.', 'serve-dashboard' );
				} else {
					esc_html_e( 'Saved.', 'serve-dashboard' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $gaps ) : ?>
		<div class="serve-gaps">
			<h2><?php esc_html_e( 'Teams short of people', 'serve-dashboard' ); ?></h2>
			<ul>
				<?php foreach ( array_slice( $gaps, 0, 6 ) as $gap ) : ?>
					<li class="<?php echo $gap->below_minimum ? 'is-critical' : ''; ?>">
						<strong><?php echo esc_html( $gap->name ); ?></strong>
						<span>
							<?php
							printf(
								/* translators: 1: number of people needed. */
								esc_html( _n( 'needs %d more', 'needs %d more', (int) $gap->gap, 'serve-dashboard' ) ),
								(int) $gap->gap
							);
							?>
						</span>
						<?php if ( $gap->below_minimum ) : ?>
							<em><?php esc_html_e( 'below minimum', 'serve-dashboard' ); ?></em>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="get" class="serve-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_DASHBOARD ); ?>">

		<label class="screen-reader-text" for="serve-search"><?php esc_html_e( 'Search', 'serve-dashboard' ); ?></label>
		<input type="search" id="serve-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
			placeholder="<?php esc_attr_e( 'Name or email', 'serve-dashboard' ); ?>">

		<label class="screen-reader-text" for="serve-status"><?php esc_html_e( 'Status', 'serve-dashboard' ); ?></label>
		<select id="serve-status" name="status">
			<option value=""><?php esc_html_e( 'Any status', 'serve-dashboard' ); ?></option>
			<?php foreach ( $status_labels as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="serve-team"><?php esc_html_e( 'Team', 'serve-dashboard' ); ?></label>
		<select id="serve-team" name="team_id">
			<option value="0"><?php esc_html_e( 'Any team', 'serve-dashboard' ); ?></option>
			<?php foreach ( $teams as $team ) : ?>
				<option value="<?php echo esc_attr( (string) $team->id ); ?>" <?php selected( (int) $filters['team_id'], (int) $team->id ); ?>>
					<?php echo esc_html( $team->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<label class="screen-reader-text" for="serve-language"><?php esc_html_e( 'Language', 'serve-dashboard' ); ?></label>
		<input type="text" id="serve-language" name="language" value="<?php echo esc_attr( $filters['language'] ); ?>"
			placeholder="<?php esc_attr_e( 'Speaks…', 'serve-dashboard' ); ?>">

		<label class="serve-checkbox">
			<input type="checkbox" name="due_only" value="1" <?php checked( ! empty( $filters['due_only'] ) ); ?>>
			<?php esc_html_e( 'Only due or overdue', 'serve-dashboard' ); ?>
		</label>

		<label class="serve-checkbox">
			<input type="checkbox" name="include_snoozed" value="1" <?php checked( ! empty( $filters['include_snoozed'] ) ); ?>>
			<?php esc_html_e( 'Include paused', 'serve-dashboard' ); ?>
		</label>

		<?php submit_button( __( 'Filter', 'serve-dashboard' ), 'secondary', '', false ); ?>
	</form>

	<?php if ( ! $submissions ) : ?>
		<div class="serve-empty">
			<h2><?php esc_html_e( 'Nothing waiting on you', 'serve-dashboard' ); ?></h2>
			<p>
				<?php
				if ( $filters['search'] || $filters['status'] || $filters['team_id'] || $filters['language'] ) {
					esc_html_e( 'No profiles match those filters. Try clearing one of them.', 'serve-dashboard' );
				} else {
					esc_html_e( 'When someone completes the S.H.A.P.E. journey and agrees to share it, they will appear here with a suggested team.', 'serve-dashboard' );
				}
				?>
			</p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped serve-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Person', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Suggested teams', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Likely gifts', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'serve-dashboard' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Next action', 'serve-dashboard' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $submissions as $row ) : ?>
				<?php
				$overdue  = $row->next_action_at && $row->next_action_at < $today;
				$due_soon = $row->next_action_at && $row->next_action_at === $today;
				$detail   = add_query_arg(
					array(
						'page'       => Admin::PAGE_DASHBOARD,
						'submission' => (int) $row->id,
					),
					admin_url( 'admin.php' )
				);
				?>
				<tr class="<?php echo $overdue ? 'is-overdue' : ''; ?>">
					<td>
						<a class="row-title" href="<?php echo esc_url( $detail ); ?>">
							<?php echo esc_html( $row->display_name ); ?>
						</a>
						<div class="serve-row-meta">
							<?php echo esc_html( $row->email ); ?>
							<?php if ( $row->is_stale ) : ?>
								<span class="serve-flag serve-flag-stale" title="<?php esc_attr_e( 'This profile is old enough that the answers may have changed.', 'serve-dashboard' ); ?>">
									<?php esc_html_e( 'stale', 'serve-dashboard' ); ?>
								</span>
							<?php endif; ?>
							<?php if ( Safeguarding::STATUS_CLEARED !== $row->safeguarding_status && Safeguarding::STATUS_NOT_REQUIRED !== $row->safeguarding_status ) : ?>
								<span class="serve-flag serve-flag-safeguard">
									<?php esc_html_e( 'check required', 'serve-dashboard' ); ?>
								</span>
							<?php endif; ?>
							<?php if ( null !== $row->tenure_months ) : ?>
								<span class="serve-tenure">
									<?php
									printf(
										/* translators: %d: months the person expects to remain in the UAE. */
										esc_html__( '~%d months in the UAE', 'serve-dashboard' ),
										(int) $row->tenure_months
									);
									?>
								</span>
							<?php endif; ?>
						</div>
					</td>
					<td>
						<?php
						$suggested = Submissions::decode_list( $row->suggested_teams );
						echo $suggested
							? esc_html( implode( ', ', array_map( 'ucwords', str_replace( '-', ' ', $suggested ) ) ) )
							: '<span class="serve-muted">' . esc_html__( 'None yet', 'serve-dashboard' ) . '</span>';
						?>
					</td>
					<td>
						<?php
						$gifts = Submissions::decode_list( $row->gifts_likely );
						echo $gifts
							? esc_html( implode( ', ', array_slice( $gifts, 0, 4 ) ) )
							: '<span class="serve-muted">' . esc_html__( 'Not recorded', 'serve-dashboard' ) . '</span>';
						?>
					</td>
					<td>
						<span class="serve-status serve-status-<?php echo esc_attr( $row->status ); ?>">
							<?php echo esc_html( $status_labels[ $row->status ] ?? $row->status ); ?>
						</span>
					</td>
					<td>
						<?php if ( $row->next_action_at ) : ?>
							<span class="<?php echo $overdue ? 'serve-overdue' : ( $due_soon ? 'serve-due' : '' ); ?>">
								<?php echo esc_html( mysql2date( get_option( 'date_format' ), $row->next_action_at ) ); ?>
							</span>
							<?php if ( $overdue ) : ?>
								<em><?php esc_html_e( 'overdue', 'serve-dashboard' ); ?></em>
							<?php endif; ?>
						<?php else : ?>
							<span class="serve-muted"><?php esc_html_e( 'Not set', 'serve-dashboard' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
