<?php
/**
 * Settings storage.
 *
 * Replaces WordPress's options API, which the domain code uses for the schema
 * version, the catch-all team, retention months, the digest's last run and so
 * on. Same three functions, same semantics: a missing option returns the
 * default, and `false` is a legitimate stored value that must survive a round
 * trip.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Options {

	/** @var array<string,mixed> */
	private array $cache = array();

	private bool $loaded = false;

	private Db $db;

	public function __construct( Db $db ) {
		$this->db = $db;
	}

	private function table(): string {
		return $this->db->table( 'options' );
	}

	/**
	 * Loaded in one query rather than one per option.
	 *
	 * The dashboard reads a dozen settings per request and they are all small,
	 * so the round trips cost more than the rows.
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}

		$this->loaded = true;

		foreach ( $this->db->get_results( 'SELECT option_name, option_value FROM ' . $this->table() ) as $row ) {
			$this->cache[ (string) $row->option_name ] = $this->decode( (string) $row->option_value );
		}
	}

	/**
	 * Presentation defaults that always have a value.
	 *
	 * WordPress created these rows at install, so get_option( 'date_format' )
	 * never returned false there and the ported code does not check. Here it did
	 * return false, and mysql2date() was handed a boolean where a format string
	 * belonged -- five tests failed on it.
	 *
	 * Declared rather than seeded into the table on purpose: a fresh database
	 * then renders dates correctly with no install step that can be skipped, and
	 * anything an operator actually changes is stored and takes precedence.
	 *
	 * @return array<string,mixed>
	 */
	private static function defaults(): array {
		return array(
			'date_format'     => 'j M Y',
			'time_format'     => 'H:i',
			'blogname'        => 'SERVE',
			'gmt_offset'      => 0,
			'timezone_string' => '',
		);
	}

	/**
	 * @param mixed $default
	 * @return mixed
	 */
	public function get( string $name, $default = false ) {
		$this->load();

		if ( array_key_exists( $name, $this->cache ) ) {
			return $this->cache[ $name ];
		}

		/*
		 * The application's own default wins over the caller's.
		 *
		 * In WordPress these rows existed, so a caller's fallback was never
		 * reached; honouring it here would change behaviour rather than preserve
		 * it.
		 */
		$defaults = self::defaults();

		return array_key_exists( $name, $defaults ) ? $defaults[ $name ] : $default;
	}

	/**
	 * @param mixed $value
	 * @return bool True when the stored value changed.
	 */
	public function set( string $name, $value ): bool {
		$this->load();

		if ( array_key_exists( $name, $this->cache ) && $this->cache[ $name ] === $value ) {
			// WordPress returns false here, and callers rely on it: the digest
			// treats "no change" differently from "written".
			return false;
		}

		$encoded = $this->encode( $value );

		$sql = $this->db->prepare(
			'INSERT INTO ' . $this->table() . ' (option_name, option_value) VALUES (%s, %s)'
			. ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
			$name,
			$encoded
		);

		if ( false === $this->db->query( $sql ) ) {
			return false;
		}

		$existed = array_key_exists( $name, $this->cache );

		$this->cache[ $name ] = $value;

		/*
		 * The same two hooks WordPress fired.
		 *
		 * Not cosmetic: one test proves that a data migration finishes before
		 * the schema version is stamped, and the only way to observe that
		 * ordering is to watch the moment the stamp is written. Without these
		 * the test cannot fail, and the ordering it protects is the difference
		 * between a failed upgrade being retried and being declared done.
		 */
		do_action( $existed ? 'update_option_' . $name : 'add_option_' . $name, $value );

		return true;
	}

	public function delete( string $name ): bool {
		$this->load();

		$done = $this->db->delete( $this->table(), array( 'option_name' => $name ) );

		unset( $this->cache[ $name ] );

		return false !== $done;
	}

	/**
	 * JSON, not serialize().
	 *
	 * PHP's serialize format executes __wakeup on unserialize and has been a
	 * reliable source of object-injection holes. Nothing stored here needs to
	 * be a PHP object, so nothing here can become one.
	 *
	 * @param mixed $value
	 */
	private function encode( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/** @return mixed */
	private function decode( string $raw ) {
		$value = json_decode( $raw, true );

		return null === $value && 'null' !== $raw ? $raw : $value;
	}
}
