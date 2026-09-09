<?php
/**
 * Consent records.
 *
 * Written once per submission, never updated. The purpose text is stored
 * verbatim rather than by reference, so that if the wording changes later the
 * record still shows what this person actually agreed to.
 *
 * @package ServeDashboard
 */

declare(strict_types=1);

namespace Serve_Dashboard;


final class Consent {

	/**
	 * Write the consent record for a submission.
	 *
	 * Returns whether it was actually stored. This was `void`: the insert
	 * result went unread and the audit entry was written whether or not there
	 * was anything to audit, so a failed insert produced a profile the church
	 * was holding with no record of the basis for holding it — and an audit log
	 * asserting the opposite.
	 *
	 * @return bool
	 */
	public static function record( int $submission_id ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			Schema::table( 'consents' ),
			array(
				'submission_id'    => $submission_id,
				'policy_version'   => Privacy::POLICY_VERSION,
				'purpose_text'     => Privacy::purpose_text(),
				'retention_months' => Privacy::retention_months(),
				'ip_hash'          => Privacy::hash_ip(),
				'user_agent_hash'  => Privacy::hash_user_agent(),
				'consented_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		Audit::log(
			Audit::ACTION_CONSENT_RECORDED,
			'submission',
			$submission_id,
			array( 'policy_version' => Privacy::POLICY_VERSION )
		);

		return true;
	}

	public static function for_submission( int $submission_id ): ?object {
		global $wpdb;
		$table = Schema::table( 'consents' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE submission_id = %d ORDER BY id DESC LIMIT 1",
				$submission_id
			)
		);

		return $row ?: null;
	}
}
