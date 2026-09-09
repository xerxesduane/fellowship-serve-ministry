<?php
/**
 * Language-level compatibility: errors, escaping, sanitising, time, hooks.
 *
 * These are the WordPress functions the domain classes call for reasons that
 * have nothing to do with WordPress -- escaping a string, trimming a field,
 * formatting a timestamp. Reimplementing them is cheaper and safer than
 * rewriting six thousand lines of tested logic to a different idiom, and each
 * one below is a real implementation rather than a passthrough stub.
 *
 * Where WordPress's behaviour is looser than it should be, the note says so
 * and the stricter behaviour is chosen.
 *
 * @package Serve
 */

declare(strict_types=1);

/* ── Errors ──────────────────────────────────────────────────────────────── */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * A returned error, rather than a thrown one.
	 *
	 * The domain code returns errors instead of throwing, and the REST layer
	 * turns them into status codes. That is a deliberate shape -- "this leader
	 * may not do that" is an expected outcome, not an exception -- so it is
	 * kept.
	 */
	class WP_Error {

		/** @var array<string,array<int,string>> */
		private array $messages = array();

		/** @var array<string,mixed> */
		private array $data = array();

		/** @param mixed $data */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->messages[ $code ][] = $message;

				if ( '' !== $data && array() !== $data ) {
					$this->data[ $code ] = $data;
				}
			}
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->messages );

			return $codes[0] ?? '';
		}

		/** @return array<int,string> */
		public function get_error_codes(): array {
			return array_keys( $this->messages );
		}

		public function get_error_message( string $code = '' ): string {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->messages[ $code ][0] ?? '';
		}

		/** @return mixed */
		public function get_error_data( string $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;

			return $this->data[ $code ] ?? null;
		}

		public function has_errors(): bool {
			return array() !== $this->messages;
		}

		/** @param mixed $data */
		public function add( string $code, string $message, $data = '' ): void {
			$this->messages[ $code ][] = $message;

			if ( '' !== $data && array() !== $data ) {
				$this->data[ $code ] = $data;
			}
		}

		/**
		 * The HTTP status this error carries, if any.
		 *
		 * The domain code passes array( 'status' => 403 ) as the data, and the
		 * router needs it back out.
		 */
		public function get_status( int $fallback = 500 ): int {
			$data = $this->get_error_data();

			return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : $fallback;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/** @param mixed $thing */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

/* ── Translation ─────────────────────────────────────────────────────────── */

/*
 * A passthrough, honestly labelled.
 *
 * This application ships in one language. Keeping the __() calls means the
 * 291 translatable strings stay marked, so adding gettext later is a change to
 * these four functions and nothing else -- but pretending there is a catalogue
 * here would be a lie.
 */

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'serve' ): string {
		return $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( string $text, string $domain = 'serve' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'serve' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( string $text, string $context, string $domain = 'serve' ): string {
		return $text;
	}
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( string $domain, bool $deprecated = false, string $path = '' ): bool {
		return false;
	}
}

/*
 * Escaping, and one detail that is not a detail: entities are not re-encoded.
 *
 * WordPress's esc_html() calls _wp_specialchars() with $double_encode = false,
 * so text that already contains an entity passes through unchanged. PHP's
 * htmlspecialchars() defaults to the opposite.
 *
 * That difference is visible. The privacy notice publishes the church's contact
 * address through antispambot(), which returns "&#118;" style entities; escaped
 * with double encoding on, the "&" became "&amp;" and the page displayed
 * "ser&#118;e&#64;..." as literal text where an email address belonged.
 *
 * Not double-encoding is not less safe: htmlspecialchars still escapes <, >, "
 * and ' and any & that is not already part of an entity.
 */

/* ── Escaping ────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'esc_html' ) ) {
	/** @param mixed $text */
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/** @param mixed $text */
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	/** @param mixed $text */
	function esc_textarea( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'serve' ): string {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'serve' ): void {
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, string $domain = 'serve' ): void {
		echo esc_attr( $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Only http, https and mailto survive.
	 *
	 * The point of this function is that a javascript: or data: URL reaching an
	 * href is an XSS, so an unrecognised scheme returns an empty string rather
	 * than something that merely looks escaped.
	 *
	 * @param mixed $url
	 */
	function esc_url( $url ): string {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		// Strip control characters, which are how a scheme gets smuggled past a
		// naive check (java\tscript:).
		$url = preg_replace( '/[\x00-\x20\x7f]/', '', $url );
		$url = (string) $url;

		if ( '' === $url ) {
			return '';
		}

		// Relative URLs and fragments are fine and common here.
		if ( preg_match( '#^(/|\#|\?)#', $url ) ) {
			return esc_attr( $url );
		}

		if ( ! preg_match( '#^(https?:|mailto:)#i', $url ) ) {
			return '';
		}

		return esc_attr( $url );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/** @param mixed $url */
	function esc_url_raw( $url ): string {
		$url = trim( (string) $url );
		$url = (string) preg_replace( '/[\x00-\x20\x7f]/', '', $url );

		if ( '' === $url || preg_match( '#^(/|\#|\?)#', $url ) ) {
			return $url;
		}

		return preg_match( '#^(https?:|mailto:)#i', $url ) ? $url : '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/** @param mixed $text */
	function wp_strip_all_tags( $text, bool $remove_breaks = false ): string {
		$text = (string) $text;
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = strip_tags( $text );

		if ( $remove_breaks ) {
			$text = (string) preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * A small allowlist, not a sanitiser that tries to be clever.
	 *
	 * Only used for leader-authored note text. Everything outside the list goes,
	 * because an allowlist that is short is one that can be reasoned about.
	 *
	 * @param mixed $html
	 */
	function wp_kses_post( $html ): string {
		$allowed = '<p><br><strong><em><ul><ol><li><a>';
		$html    = strip_tags( (string) $html, $allowed );

		// An <a> may keep only a safe href.
		return (string) preg_replace_callback(
			'#<a\b[^>]*>#i',
			static function ( array $m ): string {
				if ( preg_match( '/href\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[0], $h ) ) {
					$url = esc_url( $h[2] ?? $h[3] ?? '' );

					if ( '' !== $url ) {
						return '<a href="' . $url . '" rel="noopener noreferrer">';
					}
				}

				return '<a>';
			},
			$html
		);
	}
}

/* ── Sanitising ──────────────────────────────────────────────────────────── */

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/** @param mixed $str */
	function sanitize_text_field( $str ): string {
		$str = (string) $str;
		$str = wp_strip_all_tags( $str );

		// Invalid UTF-8 out, then control characters, then collapse whitespace.
		$str = (string) mb_convert_encoding( $str, 'UTF-8', 'UTF-8' );
		$str = (string) preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $str );
		$str = (string) preg_replace( '/[\r\n\t ]+/u', ' ', $str );

		return trim( $str );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * As above, but newlines survive.
	 *
	 * Note text is written in paragraphs, and collapsing them would quietly
	 * destroy what a leader typed.
	 *
	 * @param mixed $str
	 */
	function sanitize_textarea_field( $str ): string {
		$str = wp_strip_all_tags( (string) $str );
		$str = (string) mb_convert_encoding( $str, 'UTF-8', 'UTF-8' );
		$str = (string) preg_replace( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $str );
		$str = str_replace( "\r\n", "\n", $str );

		return trim( $str );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/** @param mixed $email */
	function sanitize_email( $email ): string {
		$email = trim( (string) $email );
		$clean = filter_var( $email, FILTER_SANITIZE_EMAIL );

		return is_string( $clean ) && filter_var( $clean, FILTER_VALIDATE_EMAIL ) ? strtolower( $clean ) : '';
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/** @param mixed $email @return string|false */
	function is_email( $email ) {
		$email = trim( (string) $email );

		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/** @param mixed $key */
	function sanitize_key( $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/** @param mixed $title */
	function sanitize_title( $title ): string {
		$title = strtolower( wp_strip_all_tags( (string) $title ) );
		$title = (string) preg_replace( '/[^a-z0-9\s\-_]/u', '', $title );
		$title = (string) preg_replace( '/[\s_]+/', '-', $title );

		return trim( (string) preg_replace( '/-+/', '-', $title ), '-' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/** @param mixed $n */
	function absint( $n ): int {
		return abs( (int) $n );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * A no-op, and that is the correct implementation.
	 *
	 * WordPress adds slashes to request data on the way in and this undoes it.
	 * Nothing here adds them, so there is nothing to undo -- but the calls stay
	 * so the ported code reads the same as the code it was ported from.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

/* ── Time and identifiers ────────────────────────────────────────────────── */

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type 'mysql', 'timestamp' or a date() format.
	 * @param bool   $gmt  UTC when true.
	 * @return string|int
	 */
	function current_time( string $type = 'mysql', bool $gmt = false ) {
		$tz  = $gmt ? new DateTimeZone( 'UTC' ) : new DateTimeZone( \Serve\Platform\App::timezone() );
		$now = new DateTimeImmutable( 'now', $tz );

		if ( 'timestamp' === $type || 'U' === $type ) {
			return $now->getTimestamp();
		}

		return $now->format( 'mysql' === $type ? 'Y-m-d H:i:s' : $type );
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( int $from, int $to = 0 ): string {
		$to   = 0 === $to ? time() : $to;
		$diff = abs( $to - $from );

		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = max( 1, (int) round( $diff / MINUTE_IN_SECONDS ) );
			return sprintf( _n( '%s min', '%s mins', $mins ), (string) $mins );
		}

		if ( $diff < DAY_IN_SECONDS ) {
			$hours = max( 1, (int) round( $diff / HOUR_IN_SECONDS ) );
			return sprintf( _n( '%s hour', '%s hours', $hours ), (string) $hours );
		}

		$days = max( 1, (int) round( $diff / DAY_IN_SECONDS ) );

		return sprintf( _n( '%s day', '%s days', $days ), (string) $days );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
		return json_encode( $data, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, $depth );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		$b = random_bytes( 16 );

		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Always from a CSPRNG.
	 *
	 * WordPress's version can be filtered and historically fell back to a
	 * weaker source. This one cannot: the tokens it produces guard email
	 * confirmation links and invitations.
	 */
	function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

		if ( $special ) {
			$chars .= '!@#$%^&*()';
		}

		$out = '';
		$max = strlen( $chars ) - 1;

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, $max ) ];
		}

		return $out;
	}
}

/* ── Small helpers the ported code leans on ──────────────────────────────── */

/*
 * The __return_* family.
 *
 * Real functions rather than strings special-cased in add_filter, because the
 * ported code passes them by name and PHP's callable check needs something that
 * actually exists.
 */

if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

if ( ! function_exists( '__return_null' ) ) {
	function __return_null() {
		return null;
	}
}

if ( ! function_exists( '__return_empty_array' ) ) {
	/** @return array<int,mixed> */
	function __return_empty_array(): array {
		return array();
	}
}

if ( ! function_exists( '__return_empty_string' ) ) {
	function __return_empty_string(): string {
		return '';
	}
}

if ( ! function_exists( '__return_zero' ) ) {
	function __return_zero(): int {
		return 0;
	}
}

if ( ! function_exists( 'antispambot' ) ) {
	/**
	 * Obfuscate an email address as HTML entities.
	 *
	 * Worth almost nothing against a modern harvester, and kept anyway: the
	 * privacy notice publishes the church's contact address, the cost is one
	 * pass over forty characters, and removing it would be a change to what that
	 * page emits for no gain.
	 */
	function antispambot( string $email, int $hex_encoding = 0 ): string {
		$out = '';

		for ( $i = 0, $len = strlen( $email ); $i < $len; $i++ ) {
			$char = $email[ $i ];
			$j    = random_int( 0, 1 + $hex_encoding );

			if ( 0 === $j ) {
				$out .= '&#' . ord( $char ) . ';';
			} elseif ( 1 === $j ) {
				$out .= $char;
			} else {
				$out .= '%' . bin2hex( $char );
			}
		}

		return str_replace( '@', '&#64;', $out );
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	/**
	 * One field out of a list of rows or arrays.
	 *
	 * @param array<int,mixed> $list
	 * @param string|int       $field
	 * @return array<int,mixed>
	 */
	function wp_list_pluck( array $list, $field ): array {
		$out = array();

		foreach ( $list as $key => $item ) {
			if ( is_object( $item ) ) {
				$out[ $key ] = $item->{$field} ?? null;
			} elseif ( is_array( $item ) ) {
				$out[ $key ] = $item[ $field ] ?? null;
			}
		}

		return $out;
	}
}

if ( ! function_exists( 'mysql2date' ) ) {
	/**
	 * Format a MySQL datetime.
	 *
	 * Stored values are UTC, which is why the source timezone is stated rather
	 * than left to the server's default -- getting that wrong shifts every date
	 * a leader reads by several hours without anything looking broken.
	 *
	 * @return string|int|false
	 */
	function mysql2date( string $format, string $date, bool $translate = true ) {
		if ( '' === trim( $date ) || '0000-00-00 00:00:00' === $date ) {
			return false;
		}

		try {
			$when = new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return false;
		}

		if ( 'U' === $format || 'G' === $format ) {
			return $when->getTimestamp();
		}

		// Displayed dates are in the church's timezone; the stored value is not.
		return $when
			->setTimezone( new DateTimeZone( \Serve\Platform\App::timezone() ) )
			->format( $format );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/** @param float|int $number */
	function number_format_i18n( $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals );
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	/** @return string */
	function date_i18n( string $format, ?int $timestamp = null, bool $gmt = false ): string {
		$timestamp = $timestamp ?? time();
		$tz        = new DateTimeZone( $gmt ? 'UTC' : \Serve\Platform\App::timezone() );

		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz )->format( $format );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * There are no posts.
	 *
	 * WordPress pages were how the privacy policy and the journey page were
	 * found. Here those are configuration and routes, so this returns an empty
	 * list -- which is the truth, and lets the callers take their "no page
	 * configured" branch rather than crashing.
	 *
	 * @param array<string,mixed> $args
	 * @return array<int,object>
	 */
	function get_posts( array $args = array() ): array {
		return array();
	}
}

/* ── Hooks ───────────────────────────────────────────────────────────────── */

/*
 * A real, small hook system.
 *
 * The ported code uses actions and filters for genuine extension points -- the
 * cron entry points, the matching contract's filter, the digest's recipient
 * list -- so these do what they say rather than swallowing the call.
 */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $fn, int $priority = 10, int $args = 1 ): void {
		\Serve\Platform\Hooks::add( $hook, $fn, $priority );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $fn, int $priority = 10, int $args = 1 ): void {
		\Serve\Platform\Hooks::add( $hook, $fn, $priority );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/** @param mixed ...$args */
	function do_action( string $hook, ...$args ): void {
		\Serve\Platform\Hooks::run( $hook, $args );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param mixed $value
	 * @param mixed ...$args
	 * @return mixed
	 */
	function apply_filters( string $hook, $value, ...$args ) {
		return \Serve\Platform\Hooks::filter( $hook, $value, $args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook, callable $fn, int $priority = 10 ): bool {
		return \Serve\Platform\Hooks::remove( $hook, $fn, $priority );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $hook, callable $fn, int $priority = 10 ): bool {
		return \Serve\Platform\Hooks::remove( $hook, $fn, $priority );
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook ): bool {
		return \Serve\Platform\Hooks::has( $hook );
	}
}

if ( ! function_exists( 'checked' ) ) {
	/** @param mixed $a @param mixed $b */
	function checked( $a, $b = true, bool $echo = true ): string {
		$out = (string) $a === (string) $b ? ' checked="checked"' : '';

		if ( $echo ) {
			echo $out;
		}

		return $out;
	}
}

if ( ! function_exists( 'selected' ) ) {
	/** @param mixed $a @param mixed $b */
	function selected( $a, $b = true, bool $echo = true ): string {
		$out = (string) $a === (string) $b ? ' selected="selected"' : '';

		if ( $echo ) {
			echo $out;
		}

		return $out;
	}
}
