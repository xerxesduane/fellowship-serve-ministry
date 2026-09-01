<?php
/**
 * The database layer.
 *
 * Deliberately shaped like WordPress's $wpdb, because the domain classes this
 * application is built from were written against it and their behaviour is
 * covered by 168 tests. Reimplementing the API is a few hundred lines;
 * rewriting six thousand lines of tested matching, placement and safeguarding
 * logic to a new idiom is how that behaviour gets quietly lost.
 *
 * Underneath it is PDO with real prepared statements. prepare() is the one
 * place where values are interpolated into SQL, and it quotes through PDO
 * rather than sprintf, so the compatibility is in the signature only -- not in
 * WordPress's escaping.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Db {

	public string $prefix;

	/** Last error message, empty when the last statement succeeded. */
	public string $last_error = '';

	/** Insert id from the last insert(). */
	public int $insert_id = 0;

	/** Rows affected by the last write. */
	public int $rows_affected = 0;

	/**
	 * The options table's name, as WordPress exposed it.
	 *
	 * Ported code interpolates `{$wpdb->options}` into raw SQL. Undefined, it
	 * interpolated as an empty string and produced `DELETE FROM  WHERE ...` --
	 * a syntax error that Db::query() reports by returning false, which nobody
	 * was checking. Metrics::flush() therefore never cleared the
	 * gift-distribution cache.
	 */
	public string $options;

	private bool $suppressing = false;

	private \PDO $pdo;

	private string $charset;

	private string $collate;

	public function __construct( \PDO $pdo, string $prefix = '', string $charset = 'utf8mb4', string $collate = 'utf8mb4_unicode_520_ci' ) {
		$this->pdo     = $pdo;
		$this->prefix  = $prefix;
		$this->charset = $charset;
		$this->collate = $collate;

		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_OBJ );
		$this->options = $this->table( 'options' );

		$this->pdo->setAttribute( \PDO::ATTR_EMULATE_PREPARES, false );

		/*
		 * Strict mode, on purpose, and this is a deliberate divergence.
		 *
		 * WordPress lists STRICT_TRANS_TABLES in wpdb::$incompatible_modes and
		 * removes it on every connect. That is why the plugin's inserts succeed
		 * while omitting NOT NULL columns with no default: the server silently
		 * substitutes an empty string instead of refusing.
		 *
		 * Inheriting that would mean inheriting the masking. With strict mode on,
		 * a value too long for its column is an error rather than a silent
		 * truncation -- and silent truncation of a profile, a note, or an audit
		 * entry is the kind of data loss nobody discovers until they need the
		 * data. Turning it on immediately exposed one real schema defect, which
		 * migration 002 fixes.
		 *
		 * Set here rather than left to the server so both a developer machine and
		 * a host behave the same way. Attempted quietly: a server that refuses
		 * still works, it just offers weaker guarantees.
		 */
		try {
			$this->pdo->exec(
				"SET SESSION sql_mode = CONCAT( COALESCE( NULLIF( @@SESSION.sql_mode, '' ), 'NO_ENGINE_SUBSTITUTION' ), ',STRICT_TRANS_TABLES' )"
			);
		} catch ( \PDOException $e ) {
			// Not fatal. The application is correct without it; it is a net.
			unset( $e );
		}
	}

	public function pdo(): \PDO {
		return $this->pdo;
	}

	/**
	 * A table's real name.
	 *
	 * Deliberately identical to the ported Schema::table(): prefix, then
	 * "serve_", then the name. Getting this wrong is not a subtle failure --
	 * with the prefix itself set to "serve_", the two conventions produced
	 * serve_serve_teams and every insert failed against a table that did not
	 * exist.
	 *
	 * So the prefix defaults to empty and the "serve_" is added here. A site
	 * sharing a database with something else sets a prefix and gets
	 * theirs_serve_teams.
	 */
	public function table( string $name ): string {
		return $this->prefix . 'serve_' . $name;
	}

	/**
	 * Whether to keep quiet about failures.
	 *
	 * Recorded rather than acted on: this layer never prints anything, it puts
	 * the message in last_error and returns false. The method exists because the
	 * ported code and its tests call it -- and its absence was not a harmless
	 * gap. One test renames the placements table to force a write failure, calls
	 * this immediately afterwards, and renames it back. Without the method that
	 * middle call was fatal, so the rename-back never ran and the table stayed
	 * hidden, breaking eleven later tests in a way that pointed at placements
	 * rather than at the cause.
	 */
	public function suppress_errors( bool $suppress = true ): bool {
		$was = $this->suppressing;

		$this->suppressing = $suppress;

		return $was;
	}

	/**
	 * Escape the wildcards in a LIKE search term.
	 *
	 * `%` and `_` are wildcards to LIKE, so a search for "50%" without this
	 * matches everything beginning "50" and a search for "a_b" matches "axb".
	 * Its absence was not a subtle bug: Submissions::query() calls it for the
	 * dashboard's search box, so every search returned a 500.
	 *
	 * Separate from prepare(), and both are needed -- this escapes the pattern,
	 * prepare() quotes the value.
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, "_%" . chr( 92 ) );
	}

	public function get_charset_collate(): string {
		return "DEFAULT CHARACTER SET {$this->charset} COLLATE {$this->collate}";
	}

	/**
	 * Interpolate values into SQL, the $wpdb way.
	 *
	 * Accepts %s, %d, %f and %% with either a single array argument or a
	 * variadic list, matching how the ported code calls it. Values go through
	 * PDO::quote rather than being pasted in, and %d/%f are cast so a string
	 * cannot smuggle SQL through a numeric placeholder.
	 *
	 * A placeholder count that does not match the argument count is a
	 * programming error and throws, rather than producing SQL nobody intended.
	 * WordPress only warns; a silent half-built WHERE clause is worse than a
	 * stack trace.
	 *
	 * @param mixed ...$args
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$args = array_values( $args );

		// Protect %% before counting real placeholders.
		$sentinel = "\x00PCT\x00";
		$query    = str_replace( '%%', $sentinel, $query );

		$expected = preg_match_all( '/%[sdf]/', $query );

		if ( $expected !== count( $args ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'prepare() got %d values for %d placeholders in: %s',
					count( $args ),
					$expected,
					$query
				)
			);
		}

		$i   = 0;
		$out = preg_replace_callback(
			'/%([sdf])/',
			function ( array $m ) use ( &$i, $args ) {
				$value = $args[ $i++ ];

				if ( 'd' === $m[1] ) {
					return (string) (int) $value;
				}

				if ( 'f' === $m[1] ) {
					return (string) (float) $value;
				}

				if ( null === $value ) {
					return 'NULL';
				}

				return $this->pdo->quote( (string) $value );
			},
			$query
		);

		return str_replace( $sentinel, '%', (string) $out );
	}

	/** @return \PDOStatement|false */
	private function run( string $sql ) {
		$this->last_error = '';

		try {
			$stmt                 = $this->pdo->query( $sql );
			$this->rows_affected  = $stmt ? $stmt->rowCount() : 0;
			return $stmt;
		} catch ( \PDOException $e ) {
			$this->last_error    = $e->getMessage();
			$this->rows_affected = 0;
			return false;
		}
	}

	/** @return int|false Rows affected, or false on error. */
	public function query( string $sql ) {
		$stmt = $this->run( $sql );

		return false === $stmt ? false : $this->rows_affected;
	}

	/** @return string|null */
	public function get_var( string $sql ) {
		$stmt = $this->run( $sql );

		if ( false === $stmt ) {
			return null;
		}

		$row = $stmt->fetch( \PDO::FETCH_NUM );

		return ( false === $row || ! array_key_exists( 0, $row ) ) ? null : ( null === $row[0] ? null : (string) $row[0] );
	}

	/** @return object|null */
	public function get_row( string $sql ) {
		$stmt = $this->run( $sql );

		if ( false === $stmt ) {
			return null;
		}

		$row = $stmt->fetch( \PDO::FETCH_OBJ );

		return false === $row ? null : $row;
	}

	/** @return array<int,object> */
	public function get_results( string $sql ): array {
		$stmt = $this->run( $sql );

		if ( false === $stmt ) {
			return array();
		}

		return $stmt->fetchAll( \PDO::FETCH_OBJ );
	}

	/** @return array<int,string> */
	public function get_col( string $sql ): array {
		$stmt = $this->run( $sql );

		if ( false === $stmt ) {
			return array();
		}

		return array_map(
			static fn( $v ) => null === $v ? '' : (string) $v,
			$stmt->fetchAll( \PDO::FETCH_COLUMN, 0 )
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,string>   $formats Accepted and ignored: PDO binds by type.
	 * @return int|false Rows inserted, or false on error.
	 */
	public function insert( string $table, array $data, array $formats = array() ) {
		if ( array() === $data ) {
			$this->last_error = 'insert() called with no columns';
			return false;
		}

		$cols   = array_keys( $data );
		$marks  = implode( ', ', array_fill( 0, count( $cols ), '?' ) );
		$quoted = implode( ', ', array_map( array( $this, 'ident' ), $cols ) );

		return $this->write(
			"INSERT INTO {$this->ident( $table )} ({$quoted}) VALUES ({$marks})",
			array_values( $data ),
			true
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 * @param array<int,string>   $formats
	 * @param array<int,string>   $where_formats
	 * @return int|false Rows affected, or false on error.
	 */
	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ) {
		if ( array() === $data || array() === $where ) {
			$this->last_error = 'update() needs both columns and a where clause';
			return false;
		}

		$set    = implode( ', ', array_map( fn( $c ) => $this->ident( $c ) . ' = ?', array_keys( $data ) ) );
		$clause = implode( ' AND ', array_map( fn( $c ) => $this->ident( $c ) . ' = ?', array_keys( $where ) ) );

		return $this->write(
			"UPDATE {$this->ident( $table )} SET {$set} WHERE {$clause}",
			array_merge( array_values( $data ), array_values( $where ) )
		);
	}

	/**
	 * @param array<string,mixed> $where
	 * @param array<int,string>   $formats
	 * @return int|false Rows deleted, or false on error.
	 */
	public function delete( string $table, array $where, array $formats = array() ) {
		if ( array() === $where ) {
			// Refused rather than translated into "delete everything", which is
			// what an empty where clause would mean.
			$this->last_error = 'delete() needs a where clause';
			return false;
		}

		$clause = implode( ' AND ', array_map( fn( $c ) => $this->ident( $c ) . ' = ?', array_keys( $where ) ) );

		return $this->write(
			"DELETE FROM {$this->ident( $table )} WHERE {$clause}",
			array_values( $where )
		);
	}

	/**
	 * @param array<int,mixed> $values
	 * @return int|false
	 */
	private function write( string $sql, array $values, bool $capture_id = false ) {
		$this->last_error = '';

		try {
			$stmt = $this->pdo->prepare( $sql );

			foreach ( array_values( $values ) as $i => $value ) {
				$stmt->bindValue( $i + 1, $value, $this->type_of( $value ) );
			}

			$stmt->execute();

			$this->rows_affected = $stmt->rowCount();

			if ( $capture_id ) {
				$this->insert_id = (int) $this->pdo->lastInsertId();
			}

			return $this->rows_affected;
		} catch ( \PDOException $e ) {
			$this->last_error    = $e->getMessage();
			$this->rows_affected = 0;
			return false;
		}
	}

	/** @param mixed $value */
	private function type_of( $value ): int {
		if ( null === $value ) {
			return \PDO::PARAM_NULL;
		}

		if ( is_int( $value ) || is_bool( $value ) ) {
			return \PDO::PARAM_INT;
		}

		return \PDO::PARAM_STR;
	}

	/**
	 * Quote an identifier.
	 *
	 * Table and column names never come from request data in this application,
	 * but they are quoted anyway: the cost is a backtick and the alternative is
	 * trusting that nobody ever passes one through.
	 */
	private function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}
