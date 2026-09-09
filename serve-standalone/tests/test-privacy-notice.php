<?php
/**
 * The privacy notice, as a route rather than a page.
 *
 * These replace four tests in the shared suite that cannot run here, and the
 * replacement is the point: those four asserted things about a wp_post -- that a
 * page exists, that it carries a shortcode, that it is created as a draft, that
 * editing it by hand is never overwritten. All of that was machinery for getting
 * the notice into WordPress, and none of it is a promise made to a participant.
 *
 * The promise is: there is a notice, it says how long we keep their answers, and
 * the period it states is the period the system actually enforces. That is what
 * is asserted below, and it is asserted more directly than before -- the old
 * version read the number out of stored post content, which could be stale the
 * moment somebody changed the retention setting.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Privacy;
use Serve_Dashboard\Privacy_Page;

test(
	'the notice states the retention period the system actually enforces',
	function ( Assert $a, Fixtures $f ): void {
		$months = Privacy::retention_months();
		$html   = Privacy_Page::render();

		$a->ok( '' !== trim( $html ), 'there is a notice' );

		/*
		 * The number, not a number.
		 *
		 * Read from Privacy::retention_months() rather than hardcoded, so this
		 * keeps holding when a church changes the setting -- which is exactly
		 * the case the stored-page version got wrong.
		 */
		$a->contains( (string) $months, $html, 'and it names the retention period in force' );

		$a->ok( $months > 0, 'which is a real period rather than "forever"' );
	}
);

test(
	'the notice is generated fresh, so it cannot drift from the setting',
	function ( Assert $a, Fixtures $f ): void {
		/*
		 * The WordPress version wrote the notice into a page once and then
		 * refused to touch it again if anybody had edited it. That protected the
		 * editor's work and, in exchange, allowed a published notice to state a
		 * retention period the system no longer used -- a legal document
		 * disagreeing with the code it describes.
		 *
		 * Rendering on request removes that possibility, and this proves it by
		 * changing the setting and reading the notice again.
		 */
		$original = get_option( Privacy::OPTION_RETENTION_MONTHS, false );

		try {
			update_option( Privacy::OPTION_RETENTION_MONTHS, 7 );

			$a->contains( '7', Privacy_Page::render(), 'the new period appears' );

			update_option( Privacy::OPTION_RETENTION_MONTHS, 19 );

			$html = Privacy_Page::render();

			$a->contains( '19', $html, 'and so does the next one' );
			$a->lacks( 'keep them for 7 months', $html, 'with no trace of the old one' );
		} finally {
			if ( false === $original ) {
				delete_option( Privacy::OPTION_RETENTION_MONTHS );
			} else {
				update_option( Privacy::OPTION_RETENTION_MONTHS, $original );
			}
		}
	}
);

test(
	'the notice never leaks the contact address as plain text in the markup',
	function ( Assert $a, Fixtures $f ): void {
		/*
		 * Not a serious defence and not claimed to be one. It is asserted
		 * because the notice publishes the church's address and the
		 * obfuscation is cheap; if it ever silently stopped happening, this
		 * says so rather than nobody noticing.
		 */
		$contact = Privacy::contact_email();
		$html    = Privacy_Page::render();

		$a->ok( '' !== $contact, 'there is a contact address configured' );
		$a->lacks( $contact, $html, 'and it is not sitting in the markup verbatim' );
		$a->contains( 'mailto:', $html, 'but the notice still offers a way to write in' );
	}
);
