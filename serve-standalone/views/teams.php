<?php
/**
 * Teams and gaps.
 *
 * This screen owns the numbers every other "short of people" statement in the
 * product is worked out from. The dashboard's gap panel, the weekly digest and
 * the matching screen all read target, minimum and current headcount, and none
 * of them can be more accurate than what is typed here.
 *
 * It used to be a placeholder that said so and offered a read-only table. What
 * follows is the whole screen: capacity, the leader who owns a team, whether it
 * needs a background check, whether it is still running, and the everyday words
 * a team's work involves.
 *
 * Two decisions worth stating, because both differ from the plugin's version:
 *
 *   1. One form, one save. The plugin puts a Save button on every row, so a
 *      leader setting targets for the first time saves sixteen times. Here the
 *      whole board posts at once and the handler in public/index.php writes only
 *      the teams whose values actually changed.
 *
 *   2. Saving is what confirms a headcount, and that is exactly why untouched
 *      teams must not be written. Teams::save() stamps headcount_checked_at on
 *      every save; writing all sixteen rows would silently mark every headcount
 *      in the church as freshly confirmed because somebody corrected one of
 *      them. A team whose figure is right despite recent placements is confirmed
 *      deliberately, with the checkbox on its card.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App as Platform;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Teams;

$serve_can_edit = current_user_can( Roles::CAP_MANAGE_TEAMS );
$serve_teams    = Teams::all( false );
$serve_since    = Teams::placed_since_check();

/*
 * Everybody who can be given a team.
 *
 * The capability rather than a list of role names: the compatibility layer's
 * get_users() answers capability queries, and asking "who may see the dashboard"
 * is a more durable question than naming three roles that may be renamed.
 */
$serve_leaders = get_users( array( 'capability' => Roles::CAP_VIEW_DASHBOARD ) );

// The board's own headline figures, counted from the same rows shown below.
$serve_active   = 0;
$serve_short    = 0;
$serve_critical = 0;
$serve_unset    = 0;

foreach ( $serve_teams as $serve_team ) {
	if ( empty( $serve_team->is_active ) ) {
		continue;
	}

	++$serve_active;

	$serve_target  = (int) $serve_team->target_headcount;
	$serve_current = (int) $serve_team->current_headcount;

	if ( $serve_target <= 0 ) {
		++$serve_unset;
		continue;
	}

	if ( $serve_current < $serve_target ) {
		++$serve_short;
	}

	if ( $serve_current < (int) $serve_team->min_headcount ) {
		++$serve_critical;
	}
}

require_once SERVE_ROOT . '/views/app/chrome.php';

serve_chrome_open(
	'teams',
	__( 'Teams and gaps' ),
	__( 'What each team actually needs. Everything the rest of the dashboard says about a shortfall is worked out from these numbers.' )
);
?>

<?php if ( '' !== ( $serve_notice ?? '' ) ) : ?>
	<p class="serve-note serve-note--<?php echo 'saved' === $serve_notice ? 'ok' : 'warn'; ?>" role="status">
		<?php
		echo 'saved' === $serve_notice
			? esc_html__( 'Saved. The gaps on the dashboard now use these numbers.' )
			: esc_html__( 'Nothing had changed, so nothing was saved.' );
		?>
	</p>
<?php endif; ?>

<div class="serve-teams-summary">
	<div class="serve-card serve-metric serve-metric--neutral">
		<span class="serve-metric__icon"><span class="serve-icon" aria-hidden="true"><?php \Serve_Dashboard\App::icon( 'grid' ); ?></span></span>
		<span class="serve-metric__label"><?php esc_html_e( 'Teams running' ); ?></span>
		<span class="serve-metric__value"><?php echo esc_html( (string) $serve_active ); ?></span>
		<span class="serve-metric__note">
			<?php
			printf(
				/* translators: %d: teams that are not active. */
				esc_html( _n( '%d retired', '%d retired', count( $serve_teams ) - $serve_active, 'serve' ) ),
				(int) ( count( $serve_teams ) - $serve_active )
			);
			?>
		</span>
	</div>

	<div class="serve-card serve-metric serve-metric--<?php echo $serve_short > 0 ? 'attention' : 'positive'; ?>">
		<span class="serve-metric__icon"><span class="serve-icon" aria-hidden="true"><?php \Serve_Dashboard\App::icon( 'users' ); ?></span></span>
		<span class="serve-metric__label"><?php esc_html_e( 'Short of people' ); ?></span>
		<span class="serve-metric__value"><?php echo esc_html( (string) $serve_short ); ?></span>
		<span class="serve-metric__note"><?php esc_html_e( 'below their target' ); ?></span>
	</div>

	<div class="serve-card serve-metric serve-metric--<?php echo $serve_critical > 0 ? 'attention' : 'positive'; ?>">
		<span class="serve-metric__icon"><span class="serve-icon" aria-hidden="true"><?php \Serve_Dashboard\App::icon( 'alert' ); ?></span></span>
		<span class="serve-metric__label"><?php esc_html_e( 'Below minimum' ); ?></span>
		<span class="serve-metric__value"><?php echo esc_html( (string) $serve_critical ); ?></span>
		<span class="serve-metric__note"><?php esc_html_e( 'cannot run as they are' ); ?></span>
	</div>

	<?php
	/*
	 * Not a failure state, and deliberately not coloured like one. A team with
	 * no target is simply left out of every gap figure, which is the honest
	 * thing to do with a number nobody has entered -- but a leader should be
	 * able to see how much of the church that describes.
	 */
	?>
	<div class="serve-card serve-metric serve-metric--neutral">
		<span class="serve-metric__icon"><span class="serve-icon" aria-hidden="true"><?php \Serve_Dashboard\App::icon( 'target' ); ?></span></span>
		<span class="serve-metric__label"><?php esc_html_e( 'No target set' ); ?></span>
		<span class="serve-metric__value"><?php echo esc_html( (string) $serve_unset ); ?></span>
		<span class="serve-metric__note"><?php esc_html_e( 'left out of the gap figures' ); ?></span>
	</div>
</div>

<?php if ( ! $serve_can_edit ) : ?>
	<p class="serve-note serve-note--muted">
		<?php esc_html_e( 'You can see these numbers but not change them. Whoever manages teams for your church can.' ); ?>
	</p>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( Platform::url( 'teams' ) ); ?>">
	<?php wp_nonce_field( 'serve_save_teams' ); ?>

	<div class="serve-teams">
		<?php
		foreach ( $serve_teams as $serve_team ) :
			$serve_id      = (int) $serve_team->id;
			$serve_target  = (int) $serve_team->target_headcount;
			$serve_current = (int) $serve_team->current_headcount;
			$serve_min     = (int) $serve_team->min_headcount;
			$serve_gap     = $serve_target > 0 ? max( 0, $serve_target - $serve_current ) : null;
			$serve_drift   = (int) ( $serve_since[ $serve_id ] ?? 0 );

			/*
			 * How full the team is, as a proportion of its target. Capped at
			 * 100 so an over-staffed team does not draw a bar past its own
			 * track; the words beside it still say "staffed".
			 */
			$serve_filled = $serve_target > 0
				? min( 100, (int) round( ( $serve_current / $serve_target ) * 100 ) )
				: 0;

			$serve_below = $serve_target > 0 && $serve_current < $serve_min;

			$serve_field = static fn( string $name ): string => 'teams[' . $serve_id . '][' . $name . ']';
			?>
			<div class="serve-card serve-team <?php echo empty( $serve_team->is_active ) ? 'is-retired' : ''; ?>">
				<div>
					<div class="serve-team__head">
						<span class="serve-team__name"><?php echo esc_html( (string) $serve_team->name ); ?></span>

						<?php if ( null === $serve_gap ) : ?>
							<span class="serve-team__state serve-team__state--unset"><?php esc_html_e( 'no target' ); ?></span>
						<?php elseif ( 0 === $serve_gap ) : ?>
							<span class="serve-team__state serve-team__state--ok"><?php esc_html_e( 'staffed' ); ?></span>
						<?php else : ?>
							<span class="serve-team__state serve-team__state--short">
								<?php
								printf(
									/* translators: %d: how many more people the team needs. */
									esc_html( _n( '%d needed', '%d needed', $serve_gap, 'serve' ) ),
									(int) $serve_gap
								);

								echo $serve_below ? esc_html__( ' · below minimum' ) : '';
								?>
							</span>
						<?php endif; ?>
					</div>

					<p class="serve-team__gifts"><?php echo esc_html( implode( ', ', Teams::gift_list( $serve_team ) ) ); ?></p>
				</div>

				<?php
				/*
				 * The same bar the dashboard's gap panel draws, from the same
				 * arithmetic, so the two screens cannot disagree about how
				 * short a team looks. Decorative: every number in it is stated
				 * in words above.
				 */
				?>
				<?php if ( null !== $serve_gap ) : ?>
					<span class="serve-bar__track" aria-hidden="true">
						<span class="serve-bar__fill" style="width:<?php echo esc_attr( (string) $serve_filled ); ?>%;<?php echo $serve_below ? 'background:var(--serve-coral);' : ''; ?>"></span>
					</span>
				<?php endif; ?>

				<?php if ( $serve_can_edit ) : ?>
					<div class="serve-team__numbers">
						<label class="serve-field">
							<span><?php esc_html_e( 'Now' ); ?></span>
							<input type="number" min="0" inputmode="numeric"
								name="<?php echo esc_attr( $serve_field( 'current_headcount' ) ); ?>"
								value="<?php echo esc_attr( (string) $serve_current ); ?>">
						</label>
						<label class="serve-field">
							<span><?php esc_html_e( 'Target' ); ?></span>
							<input type="number" min="0" inputmode="numeric"
								name="<?php echo esc_attr( $serve_field( 'target_headcount' ) ); ?>"
								value="<?php echo esc_attr( (string) $serve_target ); ?>">
						</label>
						<label class="serve-field">
							<span><?php esc_html_e( 'Minimum' ); ?></span>
							<input type="number" min="0" inputmode="numeric"
								name="<?php echo esc_attr( $serve_field( 'min_headcount' ) ); ?>"
								value="<?php echo esc_attr( (string) $serve_min ); ?>">
						</label>
					</div>

					<label class="serve-field">
						<span><?php esc_html_e( 'Leader' ); ?></span>
						<select name="<?php echo esc_attr( $serve_field( 'leader_user_id' ) ); ?>">
							<option value="0"><?php esc_html_e( 'Unassigned' ); ?></option>
							<?php foreach ( $serve_leaders as $serve_leader ) : ?>
								<option value="<?php echo esc_attr( (string) $serve_leader->ID ); ?>"
									<?php selected( (int) $serve_team->leader_user_id, (int) $serve_leader->ID ); ?>>
									<?php echo esc_html( (string) $serve_leader->display_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<div class="serve-team__flags">
						<label class="serve-check">
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( $serve_field( 'requires_safeguarding' ) ); ?>"
								<?php checked( (int) $serve_team->requires_safeguarding, 1 ); ?>>
							<?php esc_html_e( 'Background check' ); ?>
						</label>
						<label class="serve-check">
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( $serve_field( 'is_active' ) ); ?>"
								<?php checked( (int) $serve_team->is_active, 1 ); ?>>
							<?php esc_html_e( 'Running' ); ?>
						</label>
					</div>

					<?php
					/*
					 * Placements the "Now" figure above cannot account for.
					 *
					 * The dashboard discloses this drift beside the gap bar and
					 * asks nobody to do anything about it. This is the screen
					 * where it gets fixed, so this is where the ask belongs --
					 * and it has to be answerable both ways. Correcting the
					 * number confirms it; the checkbox is how a leader says the
					 * number was right all along, which is otherwise an
					 * unanswerable warning that returns every week.
					 */
					?>
					<?php if ( $serve_drift > 0 ) : ?>
						<div class="serve-team__confirm">
							<p>
								<?php
								printf(
									/* translators: %d: placements made since the headcount was last confirmed. */
									esc_html( _n( '%d person has been placed here since this number was last checked.', '%d people have been placed here since this number was last checked.', $serve_drift, 'serve' ) ),
									(int) $serve_drift
								);
								?>
							</p>
							<label class="serve-check">
								<input type="checkbox" value="1" name="<?php echo esc_attr( 'confirm[' . $serve_id . ']' ); ?>">
								<?php esc_html_e( 'This number is right as it stands' ); ?>
							</label>
						</div>
					<?php endif; ?>

					<?php
					/*
					 * Folded away, because it is tuning rather than data entry
					 * and it is seeded. Leaving a four-line textarea open on
					 * sixteen cards would make the vocabulary the loudest thing
					 * on a screen about capacity.
					 */
					?>
					<details class="serve-team__about">
						<summary><?php esc_html_e( 'What this team is about' ); ?></summary>
						<label class="serve-field">
							<span class="screen-reader-text"><?php esc_html_e( 'Everyday words this team\'s work involves' ); ?></span>
							<textarea name="<?php echo esc_attr( $serve_field( 'keywords' ) ); ?>" rows="3"><?php echo esc_textarea( implode( ', ', Teams::keyword_list( $serve_team ) ) ); ?></textarea>
						</label>
						<p class="serve-card__hint">
							<?php esc_html_e( 'Everyday words for the people this team serves and the work it does, separated by commas. They are shown beside a person\'s passions and experience as context. They do not affect matching: recommendations come from assessed spiritual gifts alone.' ); ?>
						</p>
					</details>
				<?php else : ?>
					<p class="serve-readonly">
						<?php
						printf(
							/* translators: 1: people serving now, 2: target, 3: minimum. */
							esc_html__( '%1$d now · %2$d target · %3$d minimum' ),
							(int) $serve_current,
							(int) $serve_target,
							(int) $serve_min
						);
						?>
					</p>
					<p class="serve-team__gifts">
						<?php
						echo empty( $serve_team->requires_safeguarding )
							? esc_html__( 'No background check required.' )
							: esc_html__( 'Needs a cleared background check.' );

						echo empty( $serve_team->is_active )
							? ' ' . esc_html__( 'Not running.' )
							: '';
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $serve_can_edit ) : ?>
		<div class="serve-teams-actions">
			<p>
				<?php esc_html_e( 'Only the teams you have changed are written, and saving a team counts as confirming its headcount. A team with no target is left out of the gap figures rather than reported as fully staffed.' ); ?>
			</p>
			<button type="submit" class="serve-btn serve-btn--primary"><?php esc_html_e( 'Save changes' ); ?></button>
		</div>
	<?php endif; ?>
</form>

<p class="serve-note serve-note--muted">
	<?php esc_html_e( 'A team marked for a background check cannot take anybody to trial serve or placement without a cleared one. Fellowship Kids and Youth Ministry are marked by default.' ); ?>
</p>

<?php
serve_chrome_close();
