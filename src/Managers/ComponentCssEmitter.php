<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Emits per-component CSS for components the scanner saw on this PHP
 * render. Two modes, controlled by the `arts_runtime/component_css/mode`
 * filter:
 *
 *   - `'inline'` (default) — single `<style id="arts-cr-component-css"
 *                            data-noptimize="1">` blob containing every
 *                            scanned component's CSS, fenced by
 *                            `/* arts:component-css:start <basename> *\/`
 *                            markers. Best for FOUC prevention and small
 *                            payloads — no extra HTTP round-trip.
 *
 *   - `'link'`              — one `<link rel="stylesheet"
 *                            id="arts-cr-<basename>-css">` per CSS chunk.
 *                            Best when CSS is large and you want to leverage
 *                            HTTP/2 prioritisation, browser caching, or
 *                            stylesheet-level CSS Modules.
 *
 * Components first-seen at RUNTIME (Elementor editor drops, AJAX-discovered
 * components on the next page) are NOT covered here — that's the
 * `ComponentCssPlugin`'s job on the JS side. To prevent the JS plugin from
 * double-loading something this emitter already covered, both modes also
 * emit a `<script id="arts-cr-css-coverage" type="application/json">` blob
 * listing the component names already styled by this render. JS plugin
 * reads it once and dedups injection against it.
 *
 * Per-component CSS URLs come from the merged Vite manifest's
 * `entry['css'][]` array (populated when components import their `.sass`
 * via Vite-native preprocessing). Components without CSS imports
 * (e.g. a TS-only component) get an empty `entry.css[]`; this emitter
 * silently skips them.
 *
 * The emitter exposes the styles markup and the coverage blob via two
 * separate accessors so `ComponentDiscovery` can splice each at its own
 * anchor: the styles go to the EARLY anchor (right after `<meta charset>`)
 * for FOUC prevention and modulepreload-priority discovery; the coverage
 * blob goes to the LATE anchor (just before `</head>`) where it sits next
 * to the manifest blob and away from the byte-zero region. A single
 * internal walk feeds both.
 *
 * Filters owned by this emitter:
 *
 *   - `arts_runtime/component_css/mode`        — string filter, returns
 *                                                `'inline'` or `'link'`.
 *                                                Default `'inline'`. Unknown
 *                                                values fall back to inline.
 *
 *   - `arts_runtime/component_css/should_skip` — per-component override
 *                                                for the skip decision
 *                                                (3-arg: default boolean,
 *                                                entry array, component
 *                                                name). Default `false`.
 */
class ComponentCssEmitter {
	private const MODE_INLINE = 'inline';
	private const MODE_LINK   = 'link';

	private const STYLE_ID         = 'arts-cr-component-css';
	private const LINK_ID_PREFIX   = 'arts-cr';
	private const COVERAGE_BLOB_ID = 'arts-cr-css-coverage';

	/**
	 * Per-request cache of the rendered (`styles`, `coverage`) pair. The
	 * scanner walk + skip-filter + URL collection runs ONCE per request;
	 * `generate_styles()` and `generate_coverage_blob()` both consume
	 * this cache so callers can splice each piece at its own anchor.
	 *
	 * @var array{styles: string, coverage: string}|null
	 */
	private static ?array $cached_bundle = null;

	private function __construct() {}

	/**
	 * Returns the CSS markup (`<style>` blob in inline mode, stack of
	 * `<link>` tags in link mode) for every scanned component, with no
	 * coverage blob. Splice at the early anchor so render-blocking CSS
	 * starts as soon as possible.
	 */
	public static function generate_styles(): string {
		return self::compute_bundle()['styles'];
	}

	/**
	 * Returns the `<script id="arts-cr-css-coverage">` JSON blob that the
	 * JS-side `ComponentCssPlugin` reads to dedup runtime injection. Pure
	 * data — splice at the late anchor next to the manifest blob.
	 */
	public static function generate_coverage_blob(): string {
		return self::compute_bundle()['coverage'];
	}

	/**
	 * Walks every registered component once, builds and caches both the
	 * styles markup and the coverage blob. Subsequent calls return the
	 * cached pair — keeps the per-component `should_skip` filter and the
	 * `collect_entry_css_urls` walk single-shot per request even though
	 * the two payloads splice at different anchors.
	 *
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
	 * Underlying walk that produces the (`styles`, `coverage`) pair —
	 * extracted so `compute_bundle()` stays a thin "read-or-fill" cache.
	 *
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
	 * Link-mode collector — one chunk record per unique CSS URL across every
	 * registered component. Dedups by URL (no filesystem inversion needed),
	 * so CDN-rewritten content URLs still produce valid `<link>` tags.
	 *
	 * @param string[] $component_names
	 * @return array{chunks: array<int, array{url: string, basename: string}>, covered: string[]}
	 */
	private static function collect_link_chunks( array $component_names ): array {
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
				$has_css = true;
				if ( isset( $seen[ $url ] ) ) {
					continue;
				}
				$seen[ $url ] = true;
				$chunks[]     = array(
					'url'      => $url,
					'basename' => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
				);
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
	 * Inline-mode collector — one chunk record per unique on-disk CSS file
	 * across every registered component. Dedups by local path; URLs that
	 * don't invert (CDN-rewritten, missing on disk) are silently skipped.
	 *
	 * @param string[] $component_names
	 * @return array{chunks: array<int, array{url: string, local_path: string, basename: string}>, covered: string[]}
	 */
	private static function collect_inline_chunks( array $component_names ): array {
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
				$local_path = WpContentPathInverter::url_to_local_path( $url );
				if ( $local_path === null ) {
					continue;
				}
				$has_css = true;
				if ( isset( $seen[ $local_path ] ) ) {
					continue;
				}
				$seen[ $local_path ] = true;
				$chunks[]            = array(
					'url'        => $url,
					'local_path' => $local_path,
					'basename'   => basename( $local_path ),
				);
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
	 * Resolves a component name to its CSS URL list — manifest key lookup +
	 * `should_skip` filter + transitive CSS aggregation. Returns `null`
	 * when the component is unknown, skipped, or has no CSS to emit; both
	 * collectors treat the three cases identically.
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

		/**
		 * Filter the per-component CSS skip decision.
		 *
		 * Default `false` — products that run their own dev servers wire
		 * skip-during-dev logic here.
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
	 * Resolves the active CSS-emission mode. Reads the
	 * `arts_runtime/component_css/mode` filter and falls back to inline
	 * for any unrecognised value (defensive against typos in product
	 * filter callbacks).
	 */
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
	 * Emits a single concatenated `<style>` blob containing every chunk's
	 * CSS body. Each segment is fenced by `arts:component-css:start/end
	 * <basename>` markers so consumers can debug which file contributed
	 * which rules.
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

		// Body intentionally NOT esc_html'd — CSS must reach the parser
		// verbatim. The id/data-noptimize attrs are esc_attr'd; the CSS body
		// itself can't carry user input through `file_get_contents` of
		// build-time-hashed chunks.
		return '<style id="' . esc_attr( self::STYLE_ID ) . '" data-noptimize="1">' . "\n"
			. implode( "\n", $parts )
			. "\n" . '</style>' . "\n";
	}

	/**
	 * Emits one `<link rel="stylesheet">` per chunk. Stable per-chunk id
	 * (`arts-cr-<basename without extension>-css`) lets consumers / cache
	 * plugins target individual stylesheets. The id format matches the
	 * one `ComponentCssPlugin.injectLink` synthesises on the JS side, so
	 * link-mode emissions get id-deduped against any plugin-injected
	 * `<link>` for the same chunk without coverage-blob coordination.
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
	 * Emits the coverage blob the JS-side `ComponentCssPlugin` reads to
	 * dedup runtime CSS injection. Empty array → empty blob (still
	 * emitted so the plugin's lookup doesn't fall back to "uncovered"
	 * for everything).
	 *
	 * @param string[] $covered
	 */
	private static function emit_coverage_blob( array $covered ): string {
		return JsonBlobEmitter::emit( self::COVERAGE_BLOB_ID, array_values( $covered ) );
	}
}
