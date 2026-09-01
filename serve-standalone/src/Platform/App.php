<?php
/**
 * The application's single wiring point.
 *
 * Everything the ported code reaches for through a global -- the database,
 * the settings store, the current user -- is held here and handed out by these
 * static accessors. It is a service locator rather than injected dependencies,
 * and that is a deliberate trade: the domain classes are static and were tested
 * that way, so threading a container through them would mean rewriting the code
 * whose behaviour is the thing worth keeping.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class App {

	private static ?Db $db = null;

	private static ?Options $options = null;

	private static ?Auth $auth = null;

	private static ?Mail $mail = null;

	/** @var array<string,mixed> */
	private static array $config = array();

	/**
	 * @param array<string,mixed> $config
	 */
	public static function boot( array $config ): void {
		self::$config = $config;

		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			(string) ( $config['db']['host'] ?? '127.0.0.1' ),
			(int) ( $config['db']['port'] ?? 3306 ),
			(string) ( $config['db']['name'] ?? 'serve' ),
			(string) ( $config['db']['charset'] ?? 'utf8mb4' )
		);

		$pdo = new \PDO(
			$dsn,
			(string) ( $config['db']['user'] ?? 'root' ),
			(string) ( $config['db']['pass'] ?? '' ),
			array(
				\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
				\PDO::ATTR_EMULATE_PREPARES   => false,
			)
		);

		self::$db = new Db(
			$pdo,
			(string) ( $config['db']['prefix'] ?? 'serve_' ),
			(string) ( $config['db']['charset'] ?? 'utf8mb4' ),
			(string) ( $config['db']['collate'] ?? 'utf8mb4_unicode_520_ci' )
		);

		self::$options = new Options( self::$db );
		self::$auth    = new Auth( self::$db, self::$options );
		self::$mail    = new Mail( $config['mail'] ?? array() );

		// $wpdb is what the ported code names it, so that is what it is called.
		$GLOBALS['wpdb'] = self::$db;
	}

	public static function db(): Db {
		if ( null === self::$db ) {
			throw new \RuntimeException( 'App::boot() has not run.' );
		}

		return self::$db;
	}

	public static function options(): Options {
		if ( null === self::$options ) {
			throw new \RuntimeException( 'App::boot() has not run.' );
		}

		return self::$options;
	}

	public static function auth(): Auth {
		if ( null === self::$auth ) {
			throw new \RuntimeException( 'App::boot() has not run.' );
		}

		return self::$auth;
	}

	public static function mail(): Mail {
		if ( null === self::$mail ) {
			throw new \RuntimeException( 'App::boot() has not run.' );
		}

		return self::$mail;
	}

	/** @return mixed */
	public static function config( string $key, $default = null ) {
		$value = self::$config;

		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $default;
			}

			$value = $value[ $part ];
		}

		return $value;
	}

	public static function timezone(): string {
		return (string) self::config( 'timezone', 'Asia/Dubai' );
	}

	public static function url( string $path = '' ): string {
		return rtrim( (string) self::config( 'url', 'http://localhost/serve-standalone' ), '/' )
			. '/' . ltrim( $path, '/' );
	}

	/**
	 * The secret behind tokens and session cookies.
	 *
	 * Fails loudly when it is missing or left at the example value, because a
	 * predictable key here means forgeable email-confirmation links, and an
	 * application that boots anyway is one that ships that way.
	 */
	public static function secret(): string {
		$secret = (string) self::config( 'secret', '' );

		if ( strlen( $secret ) < 32 || str_contains( $secret, 'change-me' ) ) {
			throw new \RuntimeException(
				'config secret must be set to at least 32 random characters. Run: bin/serve secret'
			);
		}

		return $secret;
	}
}
