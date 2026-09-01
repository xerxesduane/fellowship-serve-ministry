<?php
/**
 * The dashboard shell.
 *
 * The JSON block and the module below are the same contract the plugin used:
 * App::config() rendered as JSON, read once by admin/js/app.js. Delivered as
 * data rather than as an inline script so the content security policy can
 * forbid inline execution outright.
 *
 * @package Serve
 */

declare(strict_types=1);

use Serve\Platform\App as Platform;
use Serve_Dashboard\App;
?>
<div class="serve-app" id="serve-app" data-view="dashboard">

	<script type="application/json" id="serve-app-config"><?php echo wp_json_encode( App::config() ); ?></script>

	<noscript>
		<div class="serve-noscript">
			<h1><?php esc_html_e( 'The SERVE dashboard needs JavaScript' ); ?></h1>
			<p><?php esc_html_e( 'Please enable JavaScript to see who is ready for a serving conversation.' ); ?></p>
		</div>
	</noscript>

	<div id="serve-root"></div>
</div>

<script type="module" src="<?php echo esc_url( Platform::url( 'admin/js/app.js' ) ); ?>"></script>
