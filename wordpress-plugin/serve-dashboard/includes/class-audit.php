<?php
/**
 * Append-only audit trail.
 *
 * Because the dashboard exposes religious belief, contact details, and
 * pastoral history, "who read this record" is as much a part of the record as
 * "who changed it". Both go here.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit {

	public const ACTION_SUBMITTED        = 'submission.created';
	public const ACTION_VIEWED           = 'submission.viewed';
	public const ACTION_STATUS_CHANGED   = 'submission.status_changed';
	public const ACTION_SENSITIVE_VIEWED = 'submission.sensitive_viewed';
	public const ACTION_EXPORTED         = 'submission.exported';
	public const ACTION_DELETED          = 'submission.deleted';
	public const ACTION_SAFEGUARD_SET    = 'safeguarding.updated';
	public const ACTION_SAFEGUARD_BLOCK  = 'safeguarding.blocked';
	public const ACTION_TEAM_SAVED       = 'team.saved';
	public const ACTION_CONSENT_RECORDED = 'consent.recorded';
	public const ACTION_VERIFY_MAIL_FAIL = 'verification.mail_failed';
	public const ACTION_VERIFY_RESENT    = 'verification.resent';

	/**
	 * Write one audit row.
	 *
	 * @param string               $action      One of the ACTION_* constants.
	 * @param string               $object_type Short type name, e.g. "submission".
	 * @param int|null             $object_id   Row id the action applied to.
	 * @param array<string,mixed>  $meta        Extra detail. Never put raw
	 *                                          personal data in here.
	 */
	public static function log( string $action, string $object_type = '', ?int $object_id = null, array $meta = array() ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'audit' ),
			array(
				'user_id'     => get_current_user_id() ?: null,
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'meta_json'   => wp_json_encode( $meta ) ?: '{}',
				'ip_hash'     => Privacy::hash_ip(),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Recent entries, newest first. Used by the settings screen so a pastor
	 * can see access history without opening phpMyAdmin.
	 *
	 * @return array<int,object>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = Schema::table( 'audit' );
		$limit = max( 1, min( 500, $limit ) );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d",
				$limit
			)
		);
	}
}
