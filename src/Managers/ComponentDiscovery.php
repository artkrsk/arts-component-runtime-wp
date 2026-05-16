<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Auto-discovers components by buffering rendered HTML and splicing emissions
 * at two anchors:
 *
 *   - EARLY (after `<meta charset>`): component CSS + `<link rel="modulepreload">`.
 *     First-paint dependencies start early; FOUC window stays minimal.
 *   - LATE (before `</head>`): coverage blob + manifest blob + bootstrap
 *     `<script type="module">`. Keeps byte-zero region lean.
 *
 * **Consumer contract**: any script reading `window.__artsManifest__` MUST be
 * `defer`, `async`, `type="module"`, or `in_footer: true`. Buffer rewrite runs
 * after `wp_head`, so non-deferred head consumers would race the late anchor.
 *
 * `$emitted` flag short-circuits subsequent `process()` calls so when LiteSpeed
 * Cache re-passes the response, the second call returns the buffer untouched.
 *
 * Filter `arts_runtime/auto_discover` (default `true`) opts the pipeline out.
 */
class ComponentDiscovery {
	/** Guards against a second `process()` re-emitting payload. */
	private static bool $emitted = false;

	private function __construct() {}

	public static function register(): void {
		// Bail for request types whose entry point never includes
		// `wp-blog-header.php` (template_redirect never fires).
		if (
			is_admin()
			|| ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) )
			|| wp_doing_cron()
		) {
			return;
		}
		if ( ! (bool) apply_filters( 'arts_runtime/auto_discover', true ) ) {
			return;
		}

		add_action(
			'template_redirect',
			static function (): void {
				// `do_feed()` / `do_robots()` echo XML/plain-text the scanner
				// would process for no gain. `do_favicon()` exits before the
				// callback ever runs.
				if ( is_feed() || is_robots() ) {
					return;
				}
				ob_start( array( self::class, 'process' ) );
			},
			0
		);
	}

	/**
	 * Used as `ob_start` callback AND `litespeed_buffer_after` filter —
	 * `$emitted` makes second pass a no-op. `PHP_OUTPUT_HANDLER_CLEAN` phases
	 * skip because a discarded buffer must not trigger side effects.
	 */
	public static function process( string $buffer, int $phase = PHP_OUTPUT_HANDLER_END ): string {
		if ( $buffer === '' || self::$emitted ) {
			return $buffer;
		}
		if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) {
			return $buffer;
		}

		ComponentScanner::scan( $buffer );

		$early = ComponentCssEmitter::generate_styles()
			. PreloadEmitter::generate();
		$late  = ComponentCssEmitter::generate_coverage_blob()
			. BootstrapEmitter::generate();

		if ( $early === '' && $late === '' ) {
			return $buffer;
		}

		self::$emitted = true;

		$out = $buffer;
		if ( $early !== '' ) {
			$out = self::inject_after_charset( $out, $early );
		}
		if ( $late !== '' ) {
			$out = self::inject_before_head_close( $out, $late );
		}
		return $out;
	}

	/** Falls back to `</head>` when no charset meta found. */
	private static function inject_after_charset( string $html, string $payload ): string {
		$insert_at = self::find_charset_meta_end( $html );
		if ( $insert_at !== null ) {
			return substr( $html, 0, $insert_at ) . $payload . substr( $html, $insert_at );
		}
		return self::inject_before_head_close( $html, $payload );
	}

	private static function inject_before_head_close( string $html, string $payload ): string {
		$head_end = strpos( $html, '</head>' );
		if ( $head_end !== false ) {
			return substr( $html, 0, $head_end ) . $payload . substr( $html, $head_end );
		}
		return $html;
	}

	/**
	 * Searches only the first 2 KB (charset meta sits near the top). Skips
	 * matches inside HTML comments to avoid wrong splice points.
	 */
	private static function find_charset_meta_end( string $html ): ?int {
		$window = substr( $html, 0, 2048 );
		$offset = 0;
		while ( preg_match( '/<meta\b[^>]*\bcharset\b[^>]*>/i', $window, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$match_pos   = $m[0][1];
			$before      = substr( $window, 0, $match_pos );
			$comment_pos = strrpos( $before, '<!--' );
			if ( $comment_pos !== false && strpos( $before, '-->', $comment_pos ) === false ) {
				$end    = strpos( $window, '-->', $match_pos );
				$offset = $end !== false ? $end + 3 : strlen( $window );
				continue;
			}
			return $match_pos + strlen( $m[0][0] );
		}
		return null;
	}
}
