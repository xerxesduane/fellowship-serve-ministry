<?php
/**
 * Teams and gaps.
 *
 * A placeholder that is honest about being one. The plugin's Teams screen is a
 * WordPress list table with admin-post.php form handlers, and porting it is a
 * separate piece of work from porting the runtime; the capacity numbers it edits
 * are reachable meanwhile through the dashboard's inline headcount confirmation
 * and the REST routes, both of which are ported and tested.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
use Serve_Dashboard\Teams;

$serve_teams = Teams::all( false );
?>
<div class="serve-panel">
	<h1><?php esc_html_e( 'Teams and gaps' ); ?></h1>

	<p class="serve-note">
		<?php esc_html_e( 'Read-only for now. Editing capacity from this screen has not been ported yet; the dashboard can still confirm a headcount inline.' ); ?>
	</p>

	<table class="serve-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Team' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Now' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Target' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Minimum' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Checks' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Active' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $serve_teams as $serve_team ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( (string) $serve_team->name ); ?></th>
					<td><?php echo esc_html( (string) (int) $serve_team->current_headcount ); ?></td>
					<td><?php echo esc_html( (string) (int) $serve_team->target_headcount ); ?></td>
					<td><?php echo esc_html( (string) (int) $serve_team->min_headcount ); ?></td>
					<td>
						<?php
						echo empty( $serve_team->requires_safeguarding )
							? esc_html__( 'not required' )
							: esc_html__( 'background check' );
						?>
					</td>
					<td><?php echo empty( $serve_team->is_active ) ? esc_html__( 'no' ) : esc_html__( 'yes' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
