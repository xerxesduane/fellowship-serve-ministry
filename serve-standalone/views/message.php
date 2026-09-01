<?php
/**
 * A plain outcome page: no access, or no such page.
 *
 * "Not allowed" and "does not exist" are told apart on purpose. Telling somebody
 * already signed in that a screen exists and they may not use it is useful; the
 * things worth hiding are the records, and those are scoped by the queries that
 * read them rather than by the shape of a URL.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App;
?>
<div class="serve-empty">
	<h1><?php echo esc_html( (string) $heading ); ?></h1>
	<?php if ( '' !== (string) $detail ) : ?>
		<p><?php echo esc_html( (string) $detail ); ?></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( App::url( 'dashboard' ) ); ?>"><?php esc_html_e( 'Back to the dashboard' ); ?></a></p>
</div>
