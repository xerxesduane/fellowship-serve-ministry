<?php
/**
 * What a CSV is allowed to contain.
 *
 * A spreadsheet is the easiest thing in the world to forward to the wrong
 * person, so Experiences and conversation notes are excluded at every
 * capability level — including a pastor's, who can see both on screen.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Test;

use Serve_Dashboard\Export;
use Serve_Dashboard\Roles;
use Serve_Dashboard\Submissions;

test(
	'the export has no column for experiences or notes',
	function ( Assert $a, Fixtures $f ) {
		$columns = strtolower( implode( '|', Export::columns() ) );

		foreach ( array( 'experience', 'note', 'painful', 'profile', 'consent' ) as $forbidden ) {
			$a->lacks( $forbidden, $columns, "no \"$forbidden\" column" );
		}
	}
);

test(
	'an exported row carries nothing from the Experiences section',
	function ( Assert $a, Fixtures $f ) {
		$id  = $f->verified_submission();
		$row = Submissions::get( $id );

		// A pastor is the most privileged reader there is; even here it stays out.
		$pastor = $f->user( Roles::ROLE_PASTOR );
		wp_set_current_user( $pastor );

		$cells = strtolower( implode( '|', array_map( 'strval', Export::row( $row ) ) ) );

		$a->lacks( 'bereavement', $cells, 'the fixture\'s painful experience is absent' );
		$a->lacks( 'experiences', $cells, 'and so is the section itself' );
	}
);

test(
	'every row lines up with the header',
	function ( Assert $a, Fixtures $f ) {
		$row = Submissions::get( $f->verified_submission() );

		$a->same(
			count( Export::columns() ),
			count( Export::row( $row ) ),
			'a row that drifts from its header silently mislabels every column after the gap'
		);
	}
);

test(
	'the export is scoped by the same rules as the dashboard',
	function ( Assert $a, Fixtures $f ) {
		// Submissions::query() is what download() calls, so scoping is shared by
		// construction rather than by two things being kept in step by hand.
		$unverified = $f->submission();
		$verified   = $f->verified_submission();

		$pastor = $f->user( Roles::ROLE_PASTOR );
		wp_set_current_user( $pastor );

		$ids = wp_list_pluck( Submissions::query( array( 'include_snoozed' => true, 'limit' => 200 ) ), 'id' );

		$a->ok( in_array( $verified, $ids, false ), 'a confirmed profile is exportable' );
		$a->not( in_array( $unverified, $ids, false ), 'an unconfirmed one is not' );
	}
);
