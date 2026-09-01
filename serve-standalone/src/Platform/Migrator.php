<?php
/**
 * Schema migrations.
 *
 * Replaces dbDelta, which inferred what to change by parsing a CREATE TABLE
 * statement and comparing it to the live table. That inference is why the
 * plugin's schema definitions had to obey formatting rules -- two spaces before
 * PRIMARY KEY, lowercase types, one definition per line -- and why getting them
 * wrong failed silently.
 *
 * Here a migration is a file of SQL that runs once. What has run is recorded in
 * a table, so the answer to "is this database up to date" is a row rather than a
 * guess.
 *
 * Each file is applied inside a transaction where the statements allow it. DDL
 * in MySQL is not transactional, so a file that creates tables cannot be rolled
 * back -- which is why the applied-marker is written only after every statement
 * in that file has succeeded. A half-applied file is therefore retried rather
 * than skipped, and every migration must be safe to run twice.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Migrator {

	private Db $db;

	private string $dir;

	public function __construct( Db $db, string $dir = '' ) {
		$this->db  = $db;
		$this->dir = '' === $dir ? dirname( __DIR__, 2 ) . '/migrations' : $dir;
	}

	private function table(): string {
		return $this->db->table( 'migrations' );
	}

	public function ensure_table(): void {
		$this->db->query(
			'CREATE TABLE IF NOT EXISTS `' . $this->table() . '` ('
			. ' name varchar(190) NOT NULL,'
			. ' applied_at datetime NOT NULL,'
			. ' PRIMARY KEY (name)'
			. ') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'
		);
	}

	/** @return array<int,string> */
	public function applied(): array {
		$this->ensure_table();

		return $this->db->get_col( 'SELECT name FROM `' . $this->table() . '` ORDER BY name' );
	}

	/** @return array<int,string> */
	public function available(): array {
		$files = glob( $this->dir . '/*.sql' );

		if ( false === $files ) {
			return array();
		}

		sort( $files );

		return array_map( 'basename', $files );
	}

	/** @return array<int,string> */
	public function pending(): array {
		return array_values( array_diff( $this->available(), $this->applied() ) );
	}

	/**
	 * Apply everything outstanding.
	 *
	 * @return array{applied:array<int,string>,error:string}
	 */
	public function migrate(): array {
		$this->ensure_table();

		$done = array();

		foreach ( $this->pending() as $name ) {
			$sql = file_get_contents( $this->dir . '/' . $name );

			if ( false === $sql ) {
				return array(
					'applied' => $done,
					'error'   => "cannot read {$name}",
				);
			}

			// {prefix} in a migration means the full prefix, "serve_" included.
			$sql = str_replace( '{prefix}', $this->db->prefix . 'serve_', $sql );

			foreach ( self::statements( $sql ) as $statement ) {
				if ( false === $this->db->query( $statement ) ) {
					return array(
						'applied' => $done,
						'error'   => "{$name}: " . $this->db->last_error,
					);
				}
			}

			$this->db->insert(
				$this->table(),
				array(
					'name'       => $name,
					'applied_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);

			$done[] = $name;
		}

		return array(
			'applied' => $done,
			'error'   => '',
		);
	}

	/**
	 * Split a file into statements.
	 *
	 * Naive splitting on ';' would break on a semicolon inside a string or a
	 * comment, so comments are stripped and quoted regions are skipped.
	 *
	 * @return array<int,string>
	 */
	public static function statements( string $sql ): array {
		$out     = array();
		$current = '';
		$length  = strlen( $sql );
		$quote   = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];

			if ( '' !== $quote ) {
				$current .= $char;

				if ( '\\' === $char && $i + 1 < $length ) {
					$current .= $sql[ ++$i ];
					continue;
				}

				if ( $char === $quote ) {
					$quote = '';
				}

				continue;
			}

			// A line comment runs to the newline.
			if ( '-' === $char && $i + 1 < $length && '-' === $sql[ $i + 1 ] ) {
				$newline = strpos( $sql, "\n", $i );
				$i       = false === $newline ? $length : $newline;
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $sql[ $i + 1 ] ) {
				$end = strpos( $sql, '*/', $i );
				$i   = false === $end ? $length : $end + 1;
				continue;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote    = $char;
				$current .= $char;
				continue;
			}

			if ( ';' === $char ) {
				$statement = trim( $current );

				if ( '' !== $statement ) {
					$out[] = $statement;
				}

				$current = '';
				continue;
			}

			$current .= $char;
		}

		$statement = trim( $current );

		if ( '' !== $statement ) {
			$out[] = $statement;
		}

		return $out;
	}
}
