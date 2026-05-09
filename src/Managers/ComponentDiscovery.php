<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Auto-discovers components by buffering the rendered HTML, scanning it for
 * `data-arts-component-name` attributes (via `ComponentScanner`), and
 * splicing emissions at TWO anchors based on what the browser benefits from
 * seeing early vs late:
 *
 *   - EARLY anchor (right after `<meta charset>`): render-blocking and
 *     HTTP-discovery-sensitive payload — component CSS (`<style>` in inline
 *     mode, `<link rel="stylesheet">` stack in link mode) and the
 *     `<link rel="modulepreload">` set for component JS chunks. First-paint
 *     dependencies start as soon as possible; FOUC window stays minimal.
 *
 *   - LATE anchor (just before `</head>`): pure data + bootstrap — the
 *     `<script id="arts-cr-css-coverage">` JSON blob, the
 *     `<script id="arts-cr-manifest">` JSON blob, and (when emitted) the
 *     bootstrap `<script type="module" src="…">` tag. Keeps the byte-zero
 *     region lean and puts data next to its consumer.
 *
 * **Consumer contract**: any script reading `window.__artsManifest__` MUST
 * be `defer`, `async`, `type="module"`, or enqueued `in_footer: true`.
 * The buffer rewrite runs after `wp_head`, so any `wp_enqueue_script` in
 * head lands BEFORE the late anchor in document order. Deferred consumers
 * read the blob after full DOM parse — fine; non-deferred head consumers
 * would race.
 *
 * Always opens an `ob_start` on `template_redirect:0`. A per-request
 * `$emitted` flag short-circuits subsequent calls so when other hooks
 * (e.g. LiteSpeed Cache via `CachePluginCompat`) re-pass the response
 * through `process`, the second call returns the buffer untouched.
 *
 * Filter `arts_runtime/auto_discover` (default `true`) opts the entire
 * pipeline out — when `false`, no hooks are registered and products wire
 * the emitters themselves.
 */
class ComponentDiscovery {
	/**
	 * Set on first successful injection in this request — guards against
	 * a second `process()` call (e.g. via `litespeed_buffer_after`) from
	 * re-emitting payload into the already-injected buffer.
	 */
	private static bool $emitted = false;

	private function __construct() {}

	public static function register(): void {
		// Bail at `plugins_loaded` for request types whose entry point
		// never includes `wp-blog-header.php` (so `template_redirect`
		// never fires; registering the action would be inert). Each
		// guard is constant-/header-based, safe to call before the main
		// query is built. `wp_doing_ajax` / `REST_REQUEST` /
		// `wp_is_json_request` / `is_favicon` are intentionally NOT
		// checked here — they're either redundant with `is_admin()`,
		// evaluated false at this phase, or semantically mismatched.
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
				// At `template_redirect:0`, `wp` has fired so the main
				// query is built and conditional tags are valid.
				// `do_feed()` / `do_robots()` echo XML/plain-text into
				// the active buffer, which the scanner would process for
				// no gain. `is_favicon()` is omitted — `do_favicon()`
				// calls `exit` before the buffer callback receives any
				// content.
				if ( is_feed() || is_robots() ) {
					return;
				}
				ob_start( array( self::class, 'process' ) );
			},
			0
		);
	}

	/**
	 * Scans `$buffer` for components and splices all emissions at their
	 * anchors. Used as both the `ob_start` callback and the LiteSpeed Cache
	 * `litespeed_buffer_after` filter — the `$emitted` flag ensures the
	 * second pass is a no-op. `PHP_OUTPUT_HANDLER_CLEAN` phases are skipped
	 * because a discarded buffer must not trigger side-effecting work.
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

	/**
	 * Splices `$payload` immediately after the document's `<meta charset>`
	 * tag. Falls back to `</head>` when the charset meta isn't found, and
	 * to a no-op when neither anchor exists (raw HTML fragment, edge case).
	 */
	private static function inject_after_charset( string $html, string $payload ): string {
		$insert_at = self::find_charset_meta_end( $html );
		if ( $insert_at !== null ) {
			return substr( $html, 0, $insert_at ) . $payload . substr( $html, $insert_at );
		}
		return self::inject_before_head_close( $html, $payload );
	}

	/**
	 * Splices `$payload` immediately before the document's first `</head>`.
	 * No-ops on a malformed document (no closing head tag).
	 */
	private static function inject_before_head_close( string $html, string $payload ): string {
		$head_end = strpos( $html, '</head>' );
		if ( $head_end !== false ) {
			return substr( $html, 0, $head_end ) . $payload . substr( $html, $head_end );
		}
		return $html;
	}

	/**
	 * Locates the byte offset immediately after the document's `<meta charset>`
	 * tag. Searches only the first 2 KB (charset meta is always near the top).
	 * Skips matches inside HTML comments so a charset declaration inside a comment
	 * doesn't produce a wrong splice point.
	 *
	 * Returns null when no charset meta is found outside a comment.
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
