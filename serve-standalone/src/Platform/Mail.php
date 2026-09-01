<?php
/**
 * Outbound email.
 *
 * Replaces wp_mail(). Two transports: SMTP with authentication, and a log
 * transport that writes the message to a file instead of sending it.
 *
 * The log transport is the default, and that is deliberate. This application
 * sends confirmation links and volunteer invitations to real people; a fresh
 * install that silently mails them from an unconfigured server would either
 * bounce or land in spam, and either way the failure is invisible. Writing to a
 * file until somebody configures SMTP makes the unconfigured state obvious
 * without losing anything.
 *
 * send() returns false on failure and never throws, because every caller in the
 * ported code records a send failure rather than assuming success -- there is a
 * test named for it.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Mail {

	/** @var array<string,mixed> */
	private array $config;

	private string $last_error = '';

	/** @param array<string,mixed> $config */
	public function __construct( array $config = array() ) {
		$this->config = $config;
	}

	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * @param string|array<int,string> $to
	 * @param string|array<int,string> $headers
	 */
	public function send( $to, string $subject, string $message, $headers = '' ): bool {
		$this->last_error = '';

		$recipients = array_values(
			array_filter(
				array_map( 'sanitize_email', is_array( $to ) ? $to : array( $to ) )
			)
		);

		if ( array() === $recipients ) {
			$this->last_error = 'no valid recipient';

			return false;
		}

		/*
		 * A subject cannot contain a newline.
		 *
		 * Header injection is the classic mail bug: a newline in the subject
		 * lets anything after it become a Bcc. The subject here is built from
		 * template text rather than request data, but the guard costs nothing
		 * and the assumption might not hold next year.
		 */
		$subject = trim( (string) preg_replace( '/[\r\n]+/', ' ', $subject ) );

		$transport = (string) ( $this->config['transport'] ?? 'log' );

		if ( 'smtp' === $transport ) {
			return $this->smtp( $recipients, $subject, $message, $this->header_lines( $headers ) );
		}

		return $this->log( $recipients, $subject, $message );
	}

	/**
	 * @param string|array<int,string> $headers
	 * @return array<int,string>
	 */
	private function header_lines( $headers ): array {
		$lines = is_array( $headers ) ? $headers : preg_split( '/\r?\n/', (string) $headers );
		$lines = is_array( $lines ) ? $lines : array();

		return array_values(
			array_filter(
				array_map( 'trim', $lines ),
				static fn( string $l ): bool => '' !== $l && str_contains( $l, ':' )
			)
		);
	}

	/**
	 * @param array<int,string> $recipients
	 */
	private function log( array $recipients, string $subject, string $message ): bool {
		$path = (string) ( $this->config['log_path'] ?? '' );

		if ( '' === $path ) {
			$path = dirname( __DIR__, 2 ) . '/var/mail.log';
		}

		$dir = dirname( $path );

		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			$this->last_error = "cannot create {$dir}";

			return false;
		}

		$entry = sprintf(
			"=== %s ===\nTo: %s\nSubject: %s\n\n%s\n\n",
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			implode( ', ', $recipients ),
			$subject,
			$message
		);

		$written = file_put_contents( $path, $entry, FILE_APPEND | LOCK_EX );

		if ( false === $written ) {
			$this->last_error = "cannot write {$path}";

			return false;
		}

		return true;
	}

	/**
	 * SMTP with STARTTLS or implicit TLS, and AUTH LOGIN or PLAIN.
	 *
	 * Written directly rather than pulling in a mail library, because the
	 * requirement is one authenticated submission to one configured server and
	 * a dependency here would be the only one in the project.
	 *
	 * @param array<int,string> $recipients
	 * @param array<int,string> $headers
	 */
	private function smtp( array $recipients, string $subject, string $message, array $headers ): bool {
		$host    = (string) ( $this->config['host'] ?? '' );
		$port    = (int) ( $this->config['port'] ?? 587 );
		$user    = (string) ( $this->config['username'] ?? '' );
		$pass    = (string) ( $this->config['password'] ?? '' );
		$from    = sanitize_email( (string) ( $this->config['from'] ?? '' ) );
		$name    = (string) ( $this->config['from_name'] ?? 'SERVE' );
		$secure  = (string) ( $this->config['encryption'] ?? 'tls' );
		$timeout = (int) ( $this->config['timeout'] ?? 15 );

		if ( '' === $host || '' === $from ) {
			$this->last_error = 'smtp transport is selected but host or from is not configured';

			return false;
		}

		$target = ( 'ssl' === $secure ? 'ssl://' : '' ) . $host . ':' . $port;

		$socket = @stream_socket_client( $target, $errno, $errstr, $timeout );

		if ( false === $socket ) {
			$this->last_error = "connect failed: {$errstr}";

			return false;
		}

		stream_set_timeout( $socket, $timeout );

		try {
			$this->expect( $socket, 220 );
			$this->command( $socket, 'EHLO ' . $this->helo(), 250 );

			if ( 'tls' === $secure ) {
				$this->command( $socket, 'STARTTLS', 220 );

				if ( ! stream_socket_enable_crypto( $socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) {
					throw new \RuntimeException( 'STARTTLS failed' );
				}

				// A new EHLO is required after the upgrade.
				$this->command( $socket, 'EHLO ' . $this->helo(), 250 );
			}

			if ( '' !== $user ) {
				$this->command( $socket, 'AUTH LOGIN', 334 );
				$this->command( $socket, base64_encode( $user ), 334 );
				$this->command( $socket, base64_encode( $pass ), 235 );
			}

			$this->command( $socket, 'MAIL FROM:<' . $from . '>', 250 );

			foreach ( $recipients as $recipient ) {
				$this->command( $socket, 'RCPT TO:<' . $recipient . '>', 250 );
			}

			$this->command( $socket, 'DATA', 354 );

			fwrite( $socket, $this->body( $recipients, $subject, $message, $headers, $from, $name ) );

			$this->command( $socket, '.', 250 );
			$this->command( $socket, 'QUIT', 221 );

			fclose( $socket );

			return true;
		} catch ( \RuntimeException $e ) {
			$this->last_error = $e->getMessage();

			if ( is_resource( $socket ) ) {
				fclose( $socket );
			}

			return false;
		}
	}

	private function helo(): string {
		$host = (string) parse_url( App::url(), PHP_URL_HOST );

		return '' === $host ? 'localhost' : $host;
	}

	/**
	 * @param resource $socket
	 */
	private function command( $socket, string $line, int $expected ): void {
		fwrite( $socket, $line . "\r\n" );

		$this->expect( $socket, $expected );
	}

	/**
	 * @param resource $socket
	 */
	private function expect( $socket, int $code ): void {
		$reply = '';

		while ( true ) {
			$line = fgets( $socket, 1024 );

			if ( false === $line ) {
				throw new \RuntimeException( 'connection closed waiting for ' . $code );
			}

			$reply .= $line;

			// Multi-line replies use a hyphen after the code on all but the last.
			if ( strlen( $line ) >= 4 && '-' !== $line[3] ) {
				break;
			}
		}

		if ( (int) substr( $reply, 0, 3 ) !== $code ) {
			throw new \RuntimeException( 'expected ' . $code . ', got: ' . trim( $reply ) );
		}
	}

	/**
	 * @param array<int,string> $recipients
	 * @param array<int,string> $headers
	 */
	private function body( array $recipients, string $subject, string $message, array $headers, string $from, string $name ): string {
		$has = static function ( array $headers, string $needle ): bool {
			foreach ( $headers as $line ) {
				if ( 0 === stripos( $line, $needle . ':' ) ) {
					return true;
				}
			}

			return false;
		};

		$lines = array(
			'Date: ' . gmdate( 'D, d M Y H:i:s O' ),
			'From: ' . $this->encode_name( $name ) . ' <' . $from . '>',
			'To: ' . implode( ', ', $recipients ),
			'Subject: ' . $this->encode_header( $subject ),
			'Message-ID: <' . bin2hex( random_bytes( 12 ) ) . '@' . $this->helo() . '>',
			'MIME-Version: 1.0',
		);

		if ( ! $has( $headers, 'content-type' ) ) {
			$lines[] = 'Content-Type: text/plain; charset=UTF-8';
		}

		$lines[] = 'Content-Transfer-Encoding: base64';

		foreach ( $headers as $line ) {
			if ( 0 === stripos( $line, 'content-transfer-encoding:' ) ) {
				continue;
			}

			$lines[] = $line;
		}

		/*
		 * base64 rather than raw 8-bit.
		 *
		 * SMTP's line limit is 1000 octets and a lone "." at the start of a line
		 * ends the message early. Encoding sidesteps both, which matters because
		 * these messages carry long confirmation URLs.
		 */
		return implode( "\r\n", $lines ) . "\r\n\r\n"
			. chunk_split( base64_encode( $message ), 76, "\r\n" );
	}

	private function encode_header( string $text ): string {
		return preg_match( '/[^\x20-\x7e]/', $text )
			? '=?UTF-8?B?' . base64_encode( $text ) . '?='
			: $text;
	}

	private function encode_name( string $name ): string {
		$encoded = $this->encode_header( $name );

		return $encoded === $name ? '"' . str_replace( '"', '', $name ) . '"' : $encoded;
	}
}
