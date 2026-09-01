<?php
/**
 * The privacy notice.
 *
 * Rendered from Privacy_Page on every request, so the retention period it states
 * is always the one the system enforces. See tests/test-privacy-notice.php.
 *
 * @package Serve
 */

declare(strict_types=1);
?>
<article class="serve-prose-wrap">
	<?php
	/*
	 * Echoed unescaped, and that is correct: this is markup this application
	 * generated, and every value interpolated into it was escaped by
	 * Privacy_Page::content() at the point of interpolation. Escaping it again
	 * here would print the tags.
	 */
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</article>
