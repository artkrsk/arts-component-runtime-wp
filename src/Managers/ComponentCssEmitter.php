<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Emits per-component CSS for components the scanner saw this render. Two
 * modes via `arts_runtime/component_css/mode` filter:
 *
 *   - `'inline'` (default) — single `<style id="arts-cr-component-css">` blob.
 *                            Best for FOUC prevention; no extra round-trip.
 *   - `'link'`             — one `<link rel="stylesheet">` per CSS chunk.
 *                            Best for HTTP/2 prioritisation + browser caching.
 *
 * Components first-seen at RUNTIME (Elementor editor drops, AJAX-discovered)
 * are handled by `ComponentCssPlugin` JS-side. Both modes emit a coverage
 * blob (`<script id="arts-cr-css-coverage">`) listing component names already
 * styled, so the JS plugin dedups runtime injection.
 *
 * Styles splice at the EARLY anchor (after `<meta charset>`) for FOUC + modulepreload
 * priority; coverage blob splices at the LATE anchor (before `</head>`).
 *
 * Filters:
 *   - `arts_runtime/component_css/mode`        — `'inline'` (default) | `'link'`.
 *   - `arts_runtime/component_css/should_skip` — per-component skip override
 *                                                (3-arg: bool, entry, name).
 */
class ComponentCssEmitter {
	private const MODE_INLINE = 'inline';
	private const MODE_LINK   = 'link';

	// LINK_ID_PREFIX and COVERAGE_BLOB_ID are part of the PHP↔JS bridge
	// contract — the runtime kernel exports matching constants and both
	// sides MUST agree on the literal strings. STYLE_ID is PHP-only (the
	// inline-mode blob has no JS-side consumer).
	private const STYLE_ID         = 'arts-cr-component-css';
	private const LINK_ID_PREFIX   = 'arts-cr';
	private const COVERAGE_BLOB_ID = 'arts-cr-css-coverage';

	/**
	 * Per-request cache: scanner walk + skip-filter + URL collection runs once.
	 *
	 * @var array{styles: string, coverage: string}|null
	 */
	private static ?array $cached_bundle = null;

	private function __construct() {}

	public static function generate_styles(): string {
		return self::compute_bundle()['styles'];
	}

	public static function generate_coverage_blob(): string {
		return self::compute_bundle()['coverage'];
	}

	/**
	 * @return array{styles: string, coverage: string}
	 */
	private static function compute_bundle(): array {
		if ( self::$cached_bundle !== null ) {
			return self::$cached_bundle;
		}
		self::$cached_bundle = self::compute_bundle_uncached();
		return self::$cached_bundle;
	}

	/**
	 * @return array{styles: string, coverage: string}
	 */
	private static function compute_bundle_uncached(): array {
		$empty           = array(
			'styles'   => '',
			'coverage' => '',
		);
		$component_names = ComponentScanner::get_components();
		if ( empty( $component_names ) ) {
			return $empty;
		}

		if ( self::is_link_mode() ) {
			$result = self::collect_link_chunks( $component_names );
			if ( empty( $result['chunks'] ) ) {
				return $empty;
			}
			return array(
				'styles'   => self::emit_links( $result['chunks'] ),
				'coverage' => self::emit_coverage_blob( $result['covered'] ),
			);
		}

		$result = self::collect_inline_chunks( $component_names );
		if ( empty( $result['chunks'] ) ) {
			return $empty;
		}
		return array(
			'styles'   => self::emit_inline( $result['chunks'] ),
			'coverage' => self::emit_coverage_blob( $result['covered'] ),
		);
	}

	/**
	 * Dedups by URL (no filesystem inversion), so CDN-rewritten content URLs
	 * still produce valid `<link>` tags.
	 *
	 * @param string[] $component_names
	 * @return array{chunks: array<int, array{url: string, basename: string}>, covered: string[]}
	 */
	private static function collect_link_chunks( array $component_names ): array {
		return self::collect_chunks(
			$component_names,
			/**
			 * @return array{dedup_key: string, chunk: array{url: string, basename: string}}
			 */
			static function ( string $url ): array {
				return array(
					'dedup_key' => $url,
					'chunk'     => array(
						'url'      => $url,
						'basename' => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
					),
				);
			}
		);
	}

	/**
	 * Dedups by local path; URLs that don't invert (CDN-rewritten, missing
	 * on disk) are silently skipped.
	 *
	 * @param string[] $component_names
	 * @return array{chunks: array<int, array{url: string, local_path: string, basename: string}>, covered: string[]}
	 */
	private static function collect_inline_chunks( array $component_names ): array {
		return self::collect_chunks(
			$component_names,
			/**
			 * @return array{dedup_key: string, chunk: array{url: string, local_path: string, basename: string}}|null
			 */
			static function ( string $url ): ?array {
				$local_path = WpContentPathInverter::url_to_local_path( $url );
				if ( $local_path === null ) {
					return null;
				}
				return array(
					'dedup_key' => $local_path,
					'chunk'     => array(
						'url'        => $url,
						'local_path' => $local_path,
						'basename'   => basename( $local_path ),
					),
				);
			}
		);
	}

	/**
	 * Outer loop shared by `collect_link_chunks` / `collect_inline_chunks`.
	 * The per-URL `$transformer` callback returns either:
	 *  - `null` when the URL should be silently skipped (e.g. inline mode
	 *    can't invert a CDN-rewritten URL to a local path),
	 *  - `array{dedup_key: string, chunk: TChunk}` otherwise.
	 *
	 * Dedup is by `dedup_key` (mode-specific — URL vs local path). Coverage
	 * is recorded per component whenever ANY URL passes the transformer,
	 * even when the chunk turns out to be a duplicate of one already
	 * collected.
	 *
	 * @template TChunk of array<string, mixed>
	 * @param string[] $component_names
	 * @param callable(string): (array{dedup_key: string, chunk: TChunk}|null) $transformer
	 * @return array{chunks: array<int, TChunk>, covered: string[]}
	 */
	private static function collect_chunks( array $component_names, callable $transformer ): array {
		$chunks  = array();
		$covered = array();
		$seen    = array();
		$merged  = ManifestRegistry::get_merged();

		foreach ( $component_names as $name ) {
			$urls = self::resolve_component_css_urls( $merged, $name );
			if ( $urls === null ) {
				continue;
			}
			$has_css = false;
			foreach ( $urls as $url ) {
				$result = $transformer( $url );
				if ( $result === null ) {
					continue;
				}
				$has_css = true;
				if ( isset( $seen[ $result['dedup_key'] ] ) ) {
					continue;
				}
				$seen[ $result['dedup_key'] ] = true;
				$chunks[]                     = $result['chunk'];
			}
			if ( $has_css ) {
				$covered[] = $name;
			}
		}

		return array(
			'chunks'  => $chunks,
			'covered' => $covered,
		);
	}

	/**
	 * Returns `null` when the component is unknown, skipped, or has no CSS.
	 *
	 * Components present in the `arts_runtime/dev_manifest` filter are auto-
	 * skipped: their CSS is managed by the framework's HMR snippet
	 * (`<style id="arts-cr-<name>-inline">`) which updates on SASS save.
	 * Without the skip, the cached prod chunk would be inlined into
	 * `<style id="arts-cr-component-css">` at the EARLY head anchor — and
	 * CSS cascade leaves earlier-declared properties in force whenever a
	 * later rule omits them. Net effect: source edits that ADD or CHANGE
	 * a property hot-swap correctly via the late HMR style, but edits
	 * that REMOVE a property silently fail until the next `pnpm build`
	 * refreshes the blob.
	 *
	 * @param array<string, array<string, mixed>> $merged
	 * @return string[]|null
	 */
	private static function resolve_component_css_urls( array $merged, string $name ): ?array {
		$entry_key = ManifestRegistry::resolve_component_key( $merged, $name );
		if ( $entry_key === null ) {
			return null;
		}
		$entry = $merged[ $entry_key ];

		if ( self::is_dev_served( $name ) ) {
			return null;
		}

		/**
		 * Filter the per-component CSS skip decision.
		 *
		 * Default `false` — products that need additional skip logic beyond
		 * the built-in dev-manifest check wire it here.
		 *
		 * @param bool                 $should_skip Default skip decision.
		 * @param array<string, mixed> $entry       The merged manifest entry.
		 * @param string               $name        Component name.
		 */
		$should_skip = apply_filters(
			'arts_runtime/component_css/should_skip',
			false,
			$entry,
			$name
		);
		if ( $should_skip ) {
			return null;
		}

		$urls = ManifestRegistry::collect_entry_css_urls( $merged, $entry_key );
		return empty( $urls ) ? null : $urls;
	}

	/**
	 * Delegates to `ManifestRegistry::get_dev_manifest()` — single source of
	 * truth for the `arts_runtime/dev_manifest` filter result. Returns
	 * `true` when `$name` is currently being served by the Vite dev server
	 * (and therefore its prod-cached CSS should not be inlined here — the
	 * framework's HMR `<style>` handles styling at runtime).
	 */
	private static function is_dev_served( string $name ): bool {
		return isset( ManifestRegistry::get_dev_manifest()[ $name ] );
	}

	/** Falls back to inline for any unrecognised value (defensive). */
	private static function is_link_mode(): bool {
		/**
		 * Filter the component CSS emission mode.
		 *
		 * @param string $mode One of `'inline'` (default) or `'link'`.
		 */
		$mode = apply_filters( 'arts_runtime/component_css/mode', self::MODE_INLINE );
		return is_string( $mode ) && $mode === self::MODE_LINK;
	}

	/**
	 * Each segment is fenced by `arts:component-css:start/end <basename>`
	 * markers for debug.
	 *
	 * @param array<int, array{url: string, local_path: string, basename: string}> $chunks
	 */
	private static function emit_inline( array $chunks ): string {
		$parts = array();
		foreach ( $chunks as $chunk ) {
			$contents = file_get_contents( $chunk['local_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $contents === false ) {
				continue;
			}
			// Prevent </style> in CSS content from breaking out of the injected <style> tag.
			$contents = str_ireplace( '</style>', '<\\/style>', $contents );
			$parts[]  = "/* arts:component-css:start {$chunk['basename']} */\n"
				. $contents
				. "\n/* arts:component-css:end {$chunk['basename']} */";
		}

		if ( empty( $parts ) ) {
			return '';
		}

		// CSS body intentionally NOT esc_html'd — must reach the parser verbatim.
		return '<style id="' . esc_attr( self::STYLE_ID ) . '" data-noptimize="1">' . "\n"
			. implode( "\n", $parts )
			. "\n" . '</style>' . "\n";
	}

	/**
	 * Stable per-chunk id (`arts-cr-<basename>-css`) matches what
	 * `ComponentCssPlugin.injectLink` synthesises JS-side, so link-mode
	 * emissions id-dedup against plugin injection without coverage-blob
	 * coordination.
	 *
	 * @param array<int, array{url: string, basename: string}> $chunks
	 */
	private static function emit_links( array $chunks ): string {
		$out = '';
		foreach ( $chunks as $chunk ) {
			$stem = pathinfo( $chunk['basename'], PATHINFO_FILENAME );
			$id   = self::LINK_ID_PREFIX . '-' . $stem . '-css';
			$out .= '<link rel="stylesheet" id="' . esc_attr( $id ) . '" href="' . esc_url( $chunk['url'] ) . '">' . "\n";
		}
		return $out;
	}

	/**
	 * Empty array → empty blob (still emitted so the JS plugin's lookup
	 * doesn't fall back to "uncovered" for everything).
	 *
	 * @param string[] $covered
	 */
	private static function emit_coverage_blob( array $covered ): string {
		return JsonBlobEmitter::emit( self::COVERAGE_BLOB_ID, array_values( $covered ) );
	}
}
