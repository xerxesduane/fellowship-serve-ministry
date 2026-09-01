<?php
/**
 * Stylesheets, script modules, and the data they read.
 *
 * WordPress had a dependency-resolving asset queue; this is a list. Two
 * stylesheets and two modules, neither depending on the other, so a list is the
 * honest size of the problem.
 *
 * It exists at all -- rather than the views hardcoding <link> and <script> tags
 * -- because wp_localize_script() was never really about scripts. It is how the
 * journey receives its configuration: the logo URL, the draft endpoint, the
 * consent page, the estimate shown before somebody starts. Hardcoding the tags
 * silently dropped all of that, and the journey then rendered with no way to
 * save a draft or share a profile.
 *
 * @package Serve
 */

declare(strict_types=1);

namespace Serve\Platform;

final class Assets {

	/** @var array<string,string> handle => url */
	private static array $styles = array();

	/** @var array<string,string> handle => url */
	private static array $modules = array();

	/** @var array<string,array{object:string,data:array<string,mixed>}> */
	private static array $data = array();

	public static function style( string $handle, string $url ): void {
		self::$styles[ $handle ] = self::versioned( $url );
	}

	public static function module( string $handle, string $url ): void {
		self::$modules[ $handle ] = self::versioned( $url );
	}

	/**
	 * Attach a cache key to an enqueued URL.
	 *
	 * Enqueued assets were the one set of URLs going out unversioned. The views
	 * build theirs through App::asset(), which appends the release and the
	 * file's mtime; anything arriving here from wp_enqueue_script() kept the
	 * bare path it was registered with.
	 *
	 * That is not a tidiness point. The asset route sends max-age=300, so an
	 * edited file keeps being served from cache -- and the failure it produces
	 * is genuinely misleading: serve-form.js was patched, curl returned the new
	 * bytes, and the browser went on running the old copy, which threw on a
	 * function that did not exist in it yet. The share form silently did not
	 * prefill, and every visible cause pointed somewhere else.
	 *
	 * Same key as App::asset(), so the two agree.
	 */
	private static function versioned( string $url ): string {
		if ( str_contains( $url, '?' ) ) {
			return $url;
		}

		return App::asset( self::relative( $url ) );
	}

	/** The path App::asset() wants, recovered from an absolute URL. */
	private static function relative( string $url ): string {
		$base = rtrim( (string) parse_url( App::url(), PHP_URL_PATH ), '/' );
		$path = (string) parse_url( $url, PHP_URL_PATH );

		if ( '' !== $base && str_starts_with( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}

		return ltrim( $path, '/' );
	}

	/** @param array<string,mixed> $data */
	public static function localize( string $handle, string $object, array $data ): void {
		self::$data[ $handle ] = array(
			'object' => $object,
			'data'   => $data,
		);
	}

	public static function reset(): void {
		self::$styles  = array();
		self::$modules = array();
		self::$data    = array();
	}

	/**
	 * The <link> tags, for the document head.
	 */
	public static function render_styles(): string {
		$out = '';

		foreach ( self::$styles as $url ) {
			$out .= '<link rel="stylesheet" href="' . esc_url( $url ) . '">' . "\n";
		}

		return $out;
	}

	/**
	 * The data blocks and the module tags, for the end of the body.
	 *
	 * Data is delivered as <script type="application/json"> and read by the
	 * module, not as an inline assignment. That is what lets the content
	 * security policy forbid inline script entirely -- with an inline
	 * assignment the policy would need 'unsafe-inline', which would undo most of
	 * what the policy is for.
	 */
	public static function render_scripts(): string {
		$out = '';

		foreach ( self::$data as $handle => $block ) {
			$out .= sprintf(
				'<script type="application/json" id="%s-data">%s</script>' . "\n",
				esc_attr( $handle ),
				(string) wp_json_encode( $block['data'] )
			);
		}

		foreach ( self::$modules as $handle => $url ) {
			$out .= sprintf(
				'<script type="module" id="%s-js" src="%s"></script>' . "\n",
				esc_attr( $handle ),
				esc_url( $url )
			);
		}

		return $out;
	}

	/**
	 * The localised data for one handle, for a view that wants to place the
	 * block itself.
	 *
	 * @return array<string,mixed>
	 */
	public static function data_for( string $handle ): array {
		return self::$data[ $handle ]['data'] ?? array();
	}

	public static function object_for( string $handle ): string {
		return self::$data[ $handle ]['object'] ?? '';
	}
}
