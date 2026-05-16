<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads and merges Vite manifest files from filterable paths.
 *
 * Manifest entries are flat (keyed by source path); a shallow array_merge
 * is correct — later paths override earlier ones, no deep tree-merge.
 *
 * Each merged entry is stamped with `_arts_base_url` — the URL prefix
 * that resolves the entry's `file` / `css` paths to public URLs. Derived
 * from the manifest's filesystem path (parent of `.vite/`), translated
 * through `content_url` so multi-build setups emit correct URLs regardless
 * of which addon plugin's build a given entry came from.
 *
 * The framework ships zero default manifest paths — products MUST register
 * their build's manifest path via `arts_runtime/manifest_paths`.
 */
class ManifestRegistry {
	/**
	 * Per-request cache of merged manifest entries.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $cache = null;

	/**
	 * Per-request memo of `collect_imports` results, keyed by start key.
	 * Multiple callers hit this for the same keys; memoization keeps the
	 * BFS single-shot per `(request, key)`.
	 *
	 * @var array<string, string[]>
	 */
	private static array $imports_cache = array();

	/**
	 * Per-request memo of the `arts_runtime/dev_manifest` filter result.
	 * Single source of truth — `BootstrapEmitter::resolve_dev_manifest`
	 * and `ComponentCssEmitter::is_dev_served` both delegate here so the
	 * filter dispatches once per request even when both emitters run.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $dev_manifest_cache = null;

	/**
	 * Reads every path from `arts_runtime/manifest_paths`. Missing or invalid
	 * files are skipped (warning logged in debug mode).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_merged(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}

		/**
		 * Filter the list of Vite manifest paths to merge.
		 *
		 * Default empty — products MUST register their build's manifest path.
		 *
		 * @param string[] $paths
		 */
		$paths = apply_filters( 'arts_runtime/manifest_paths', array() );

		$merged = array();
		foreach ( (array) $paths as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			$decoded = self::load_manifest_decoded( $path );
			if ( $decoded === null ) {
				continue;
			}

			$base_url = self::derive_base_url( $path );
			if ( $base_url === '' ) {
				self::log_debug( 'manifest path outside wp-content or symlink unresolvable: ' . $path );
			}
			foreach ( $decoded as $key => $entry ) {
				if ( ! is_string( $key ) || ! is_array( $entry ) ) {
					continue;
				}
				$normalized = array();
				foreach ( $entry as $entry_key => $entry_value ) {
					if ( is_string( $entry_key ) ) {
						$normalized[ $entry_key ] = $entry_value;
					}
				}
				$normalized['_arts_base_url'] = $base_url;
				$merged[ $key ]               = $normalized;
			}
		}

		self::$cache = $merged;
		return self::$cache;
	}

	/**
	 * Reads + JSON-decodes a manifest. Returns `null` (with debug log) on any
	 * failure. Caller narrows keys.
	 *
	 * @return array<mixed, mixed>|null
	 */
	private static function load_manifest_decoded( string $path ): ?array {
		if ( ! file_exists( $path ) ) {
			self::log_debug( 'manifest path missing: ' . $path );
			return null;
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $contents === false ) {
			self::log_debug( 'manifest read failed: ' . $path );
			return null;
		}
		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			self::log_debug( 'manifest decode failed: ' . $path );
			return null;
		}
		return $decoded;
	}

	/**
	 * Single guard, single phpcs:ignore for `WP_DEBUG`-gated `error_log`.
	 */
	private static function log_debug( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[arts-runtime] ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Per-request resolution of the `arts_runtime/dev_manifest` filter.
	 * Map of `ComponentName => devUrl` for components currently served by
	 * the Vite dev server. Returns `[]` when no filter subscriber is
	 * registered or the filter returns a non-array value.
	 *
	 * Centralized here so both `BootstrapEmitter` (folds dev URLs into
	 * the manifest slice) and `ComponentCssEmitter` (skips prod CSS for
	 * dev-served components — see ComponentCssEmitter::is_dev_served)
	 * share a single filter dispatch + per-request memo.
	 *
	 * @return array<string, string>
	 */
	public static function get_dev_manifest(): array {
		if ( self::$dev_manifest_cache !== null ) {
			return self::$dev_manifest_cache;
		}
		/**
		 * Filter the dev-manifest map.
		 *
		 * @param array<string, string> $dev_manifest
		 */
		$dev_manifest = apply_filters( 'arts_runtime/dev_manifest', array() );
		$normalized   = array();
		if ( is_array( $dev_manifest ) ) {
			foreach ( $dev_manifest as $name => $url ) {
				if ( is_string( $name ) && is_string( $url ) && $url !== '' ) {
					$normalized[ $name ] = $url;
				}
			}
		}
		self::$dev_manifest_cache = $normalized;
		return self::$dev_manifest_cache;
	}

	/**
	 * Looks up a single entry by its source-path key (e.g. `src/bootstrap.ts`).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function lookup( string $entry_name ): ?array {
		$merged = self::get_merged();
		$entry  = $merged[ $entry_name ] ?? null;
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Resolves a chunk path under `dist/` to a public URL using the entry's
	 * `_arts_base_url`. Returns `''` when the base URL is missing — only
	 * triggers when a custom filter feeds raw entries without going through
	 * the registry.
	 *
	 * @param array<string, mixed> $entry
	 * @param string               $relative_path
	 * @return string
	 */
	public static function entry_asset_url( array $entry, string $relative_path ): string {
		$base = isset( $entry['_arts_base_url'] ) && is_string( $entry['_arts_base_url'] )
			? $entry['_arts_base_url']
			: '';
		return $base . ltrim( $relative_path, '/' );
	}

	/**
	 * @param array<string, array<string, mixed>> $merged
	 * @param string                              $name
	 * @return string|null
	 */
	public static function resolve_component_key( array $merged, string $name ): ?string {
		return ComponentLayoutResolver::resolve_key( $merged, $name );
	}

	/**
	 * Returns the transitive closure of `imports[]` keys reachable from
	 * `$start_key`. Excludes `$start_key` itself.
	 *
	 * @param array<string, array<string, mixed>> $merged
	 * @param string                              $start_key
	 * @return string[]
	 */
	private static function collect_imports( array $merged, string $start_key ): array {
		if ( isset( self::$imports_cache[ $start_key ] ) ) {
			return self::$imports_cache[ $start_key ];
		}
		$seen  = array();
		$queue = array( $start_key );
		while ( $queue ) {
			$key   = array_shift( $queue );
			$entry = $merged[ $key ] ?? null;
			if ( ! is_array( $entry ) || empty( $entry['imports'] ) || ! is_array( $entry['imports'] ) ) {
				continue;
			}
			foreach ( $entry['imports'] as $import_key ) {
				if ( ! is_string( $import_key ) || isset( $seen[ $import_key ] ) ) {
					continue;
				}
				$seen[ $import_key ] = true;
				$queue[]             = $import_key;
			}
		}
		self::$imports_cache[ $start_key ] = array_keys( $seen );
		return self::$imports_cache[ $start_key ];
	}

	/**
	 * Flat, deduped list of absolute JS chunk URLs for `$entry_key` — entry's
	 * own `file` plus every transitive import's `file`. Each URL resolves
	 * against the OWNING entry's `_arts_base_url` so cross-build merged
	 * manifests produce correct URLs when imported chunks live under a
	 * different base.
	 *
	 * @param array<string, array<string, mixed>> $merged
	 * @param string                              $entry_key
	 * @return string[]
	 */
	public static function collect_entry_js_urls( array $merged, string $entry_key ): array {
		$urls = array();
		$seen = array();

		$keys = array_merge( array( $entry_key ), self::collect_imports( $merged, $entry_key ) );
		foreach ( $keys as $key ) {
			$entry = $merged[ $key ] ?? null;
			if ( ! is_array( $entry ) || ! isset( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
				continue;
			}
			$url = self::entry_asset_url( $entry, $entry['file'] );
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$urls[]       = $url;
		}
		return $urls;
	}

	/**
	 * Flat, deduped list of absolute CSS URLs for `$entry_key` — entry's own
	 * `css[]` plus every transitive import's `css[]`. Each URL resolves against
	 * the OWNING entry's `_arts_base_url`.
	 *
	 * Vite/Rolldown's manifest format leaves transitive CSS attached to
	 * imported chunks' own entries, NOT flattened onto the consuming entry's
	 * `css[]`. Without aggregation, shared CSS chunks extracted across
	 * multiple components would never load at runtime.
	 *
	 * @param array<string, array<string, mixed>> $merged
	 * @param string                              $entry_key
	 * @return string[]
	 */
	public static function collect_entry_css_urls( array $merged, string $entry_key ): array {
		$urls = array();
		$seen = array();

		$keys = array_merge( array( $entry_key ), self::collect_imports( $merged, $entry_key ) );
		foreach ( $keys as $key ) {
			if ( ! isset( $merged[ $key ] ) ) {
				continue;
			}
			self::append_entry_css( $merged[ $key ], $urls, $seen );
		}
		return $urls;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param string[]             $urls
	 * @param array<string, true>  $seen
	 */
	private static function append_entry_css( array $entry, array &$urls, array &$seen ): void {
		if ( empty( $entry['css'] ) || ! is_array( $entry['css'] ) ) {
			return;
		}
		foreach ( $entry['css'] as $css_path ) {
			if ( ! is_string( $css_path ) || $css_path === '' ) {
				continue;
			}
			$url = self::entry_asset_url( $entry, $css_path );
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$urls[]       = $url;
		}
	}

	/**
	 * Derives the public URL prefix from the manifest filesystem path (parent
	 * of `.vite/manifest.json`), mapped through `content_url`. Returns `''`
	 * if the manifest lives outside `WP_CONTENT_DIR`.
	 */
	private static function derive_base_url( string $manifest_path ): string {
		$dist_dir      = dirname( $manifest_path, 2 ); // strip `.vite/manifest.json`
		$content_dir   = wp_normalize_path( WP_CONTENT_DIR );
		$dist_dir_norm = wp_normalize_path( $dist_dir );

		if ( strpos( $dist_dir_norm, $content_dir ) === 0 ) {
			return self::relative_to_content_url( $dist_dir_norm, $content_dir );
		}

		// Symlink fallback: PHP resolves `__FILE__` to its realpath on Linux/macOS
		// but `WP_CONTENT_DIR` is raw `ABSPATH` concatenation with no `realpath()`
		// call. The two diverge when the plugin directory is symlinked
		// (confirmed case: Hostinger).
		$real_dist_dir    = wp_normalize_path( (string) realpath( $dist_dir ) );
		$real_content_dir = wp_normalize_path( (string) realpath( WP_CONTENT_DIR ) );
		if ( $real_content_dir && $real_dist_dir && strpos( $real_dist_dir, $real_content_dir ) === 0 ) {
			return self::relative_to_content_url( $real_dist_dir, $real_content_dir );
		}

		return '';
	}

	/**
	 * Inputs MUST be normalized by `wp_normalize_path` and the prefix
	 * relationship MUST hold — caller's responsibility (no re-validation).
	 */
	private static function relative_to_content_url( string $abs_path, string $content_dir ): string {
		$relative = ltrim( substr( $abs_path, strlen( $content_dir ) ), '/' );
		return content_url( $relative ) . '/';
	}
}
