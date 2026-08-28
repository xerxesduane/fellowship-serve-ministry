<?php
/**
 * Teams and their capacity.
 *
 * The target and current headcounts entered here are what make the "teams
 * short of people" panel on the dashboard possible. A team with no target is
 * reported as unset rather than as fully staffed.
 *
 * @package ServeDashboard
 *
 * @var array<int,object> $teams
 * @var bool $can_edit
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$leaders = get_users(
	array(
		'role__in' => array( Roles::ROLE_LEADER, Roles::ROLE_PASTOR, 'administrator' ),
		'orderby'  => 'display_name',
		'number'   => 200,
	)
);
?>
<div class="wrap serve-wrap">
	<h1><?php esc_html_e( 'Teams and gaps', 'serve-dashboard' ); ?></h1>

	<?php if ( isset( $_GET['notice'] ) ) : ?>
		<div class="notice <?php echo 'error' === sanitize_key( wp_unslash( $_GET['notice'] ) ) ? 'notice-error' : 'notice-success'; ?> is-dismissible">
			<p><?php esc_html_e( 'Saved.', 'serve-dashboard' ); ?></p>
		</div>
	<?php endif; ?>

	<p class="serve-lede">
		<?php esc_html_e( 'Set what each team actually needs. Until a target is entered, that team is left out of the gap panel rather than shown as fully staffed.', 'serve-dashboard' ); ?>
	</p>

	<p class="serve-lede" id="serve-kw-help">
		<?php esc_html_e( '“What it is about” is the everyday words a team’s work involves — the people it serves, the tasks it does — separated by commas. Matching reads a person’s passions, abilities and past experience against these, so a heart for Elementary Children can point to Fellowship Kids even when no spiritual gift lines up. Every team starts with a sensible list; edit it when a suggestion looks wrong.', 'serve-dashboard' ); ?>
	</p>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Team', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it is about', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Now', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Target', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Minimum', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Gap', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Leader', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Safeguarded', 'serve-dashboard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Active', 'serve-dashboard' ); ?></th>
				<?php if ( $can_edit ) : ?>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Save', 'serve-dashboard' ); ?></span></th>
				<?php endif; ?>
			</tr>
		</thead>
		<tbody>
		<?php $placed_since = Teams::placed_since_check(); ?>
		<?php foreach ( $teams as $team ) : ?>
			<?php
			$target  = (int) $team->target_headcount;
			$current = (int) $team->current_headcount;
			$gap     = $target > 0 ? max( 0, $target - $current ) : null;
			$since   = (int) ( $placed_since[ (int) $team->id ] ?? 0 );
			?>
			<tr>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'serve_save_team' ); ?>
					<input type="hidden" name="action" value="serve_save_team">
					<input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $team->id ); ?>">

					<td>
						<strong><?php echo esc_html( $team->name ); ?></strong>
						<div class="serve-row-meta"><?php echo esc_html( implode( ', ', Teams::gift_list( $team ) ) ); ?></div>
					</td>
					<td>
						<?php
						/*
						 * Matching used to compare a person's passions,
						 * abilities and experience against the team *name*
						 * alone, so a heart for "Elementary Children" said
						 * nothing about Fellowship Kids. These words are what
						 * makes those dimensions able to support a suggestion.
						 * Seeded, so this is tuning rather than data entry.
						 */
						?>
						<textarea name="keywords" rows="2" cols="24" <?php disabled( ! $can_edit ); ?>
							aria-describedby="serve-kw-help"
						><?php echo esc_textarea( implode( ', ', Teams::keyword_list( $team ) ) ); ?></textarea>
					</td>
					<td>
						<input type="number" min="0" name="current_headcount" value="<?php echo esc_attr( (string) $current ); ?>" <?php disabled( ! $can_edit ); ?> class="small-text">
						<?php
						/*
						 * This is the screen where the number gets corrected, so
						 * it is the screen that should say the number is stale.
						 * Saving the row counts as confirming it, which is why
						 * the count resets afterwards.
						 */
						if ( $since > 0 ) :
							?>
							<div class="serve-row-meta serve-stale">
								<?php
								printf(
									/* translators: %d: placements made since the headcount was last confirmed. */
									esc_html( _n( '%d placed since you last checked this', '%d placed since you last checked this', $since, 'serve-dashboard' ) ),
									absint( $since )
								);
								?>
							</div>
						<?php endif; ?>
					</td>
					<td><input type="number" min="0" name="target_headcount" value="<?php echo esc_attr( (string) $target ); ?>" <?php disabled( ! $can_edit ); ?> class="small-text"></td>
					<td><input type="number" min="0" name="min_headcount" value="<?php echo esc_attr( (string) $team->min_headcount ); ?>" <?php disabled( ! $can_edit ); ?> class="small-text"></td>
					<td>
						<?php if ( null === $gap ) : ?>
							<span class="serve-muted"><?php esc_html_e( 'target not set', 'serve-dashboard' ); ?></span>
						<?php elseif ( 0 === $gap ) : ?>
							<span class="serve-ok"><?php esc_html_e( 'staffed', 'serve-dashboard' ); ?></span>
						<?php else : ?>
							<strong class="<?php echo $current < (int) $team->min_headcount ? 'serve-overdue' : ''; ?>">
								<?php echo esc_html( '-' . $gap ); ?>
							</strong>
						<?php endif; ?>
					</td>
					<td>
						<select name="leader_user_id" <?php disabled( ! $can_edit ); ?>>
							<option value="0"><?php esc_html_e( 'Unassigned', 'serve-dashboard' ); ?></option>
							<?php foreach ( $leaders as $leader ) : ?>
								<option value="<?php echo esc_attr( (string) $leader->ID ); ?>" <?php selected( (int) $team->leader_user_id, (int) $leader->ID ); ?>>
									<?php echo esc_html( $leader->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<input type="checkbox" name="requires_safeguarding" value="1" <?php checked( (int) $team->requires_safeguarding, 1 ); ?> <?php disabled( ! $can_edit ); ?>>
					</td>
					<td>
						<input type="checkbox" name="is_active" value="1" <?php checked( (int) $team->is_active, 1 ); ?> <?php disabled( ! $can_edit ); ?>>
					</td>
					<?php if ( $can_edit ) : ?>
						<td><?php submit_button( __( 'Save', 'serve-dashboard' ), 'secondary small', '', false ); ?></td>
					<?php endif; ?>
				</form>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p class="serve-note">
		<?php esc_html_e( 'A team marked safeguarded cannot take anyone to trial serve or placement without a cleared background check. Fellowship Kids and Youth Ministry are marked by default.', 'serve-dashboard' ); ?>
	</p>
</div>
