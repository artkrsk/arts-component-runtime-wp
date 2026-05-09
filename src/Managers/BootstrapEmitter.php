<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds the always-full `arts-cr-manifest` JSON blob and the bootstrap
 * `<script type="module">` tag.
 *
 * REQUIRED for the runtime to work. Without it the page has no bootstrap
 * entry point and no manifest blob to resolve component names against.
 *
 * The blob is always-full (every component, every request) because it is the
 * global registry for the session — AJAX nav, editor drops, and static
 * templates all resolve from this single emission without a second pass.
 * Shared chunks are excluded; the browser follows transitive ESM imports
 * natively once it has the entry URL. Cost is ~7 KB JSON (~1.5 KB gzipped),
 * rounding-error against any real content payload.
 *
 * Per-entry asset URLs are derived from the `_arts_base_url` field that
 * `ManifestRegistry` stamps onto every merged entry — this lets components
 * from one build coexist with bootstrap/subsystem chunks from another build
 * without the host having to know which addon owns which entry.
 *
 * Dev URL precedence: products supply per-component dev URLs via
 * `arts_runtime/dev_manifest`; this emitter folds them into the manifest
 * slice server-side by overwriting `entry.file` with the dev URL (and
 * synthesizing entries for dev-only components the prod manifest doesn't
 * know about yet). One blob, one resolver path on the JS side.
 *
 * The bootstrap tag is emitted via direct string concat rather than
 * `wp_register_script_module` / `wp_enqueue_script_module` because the WP
 * Script Modules API shifts emission to `wp_footer` on classic themes (its
 * `print_enqueued_script_modules` only registers on `wp_footer` /
 * `wp_head:10` for block themes); calling enqueue from a late-stage
 * buffer-rewrite hook misses both passes.
 *
 * Filters owned by this emitter:
 *   - `arts_runtime/bootstrap_entry` — override the bootstrap manifest key
 *                                      (default `src/bootstrap.ts`).
 *   - `arts_runtime/dev_manifest`    — return `{ ComponentName => devUrl }`
 *                                      so the manifest entry's `file` is
 *                                      replaced with the Vite dev URL when
 *                                      a dev server is live (default `[]`).
 */
class BootstrapEmitter {
	/**
	 * Default bootstrap entry key in the Vite manifest. Overridable via the
	 * `arts_runtime/bootstrap_entry` filter — products that ship their own
	 * `src/<name>.ts` entry rename here without subclassing.
	 */
	private const DEFAULT_BOOTSTRAP_ENTRY = 'src/bootstrap.ts';

	private function __construct() {}

	/**
	 * Returns the `arts-cr-manifest` blob and the bootstrap
	 * `<script type="module">` tag concatenated into a single string ready
	 * to splice into the document head.
	 */
	public static function generate(): string {
		$out = '';

		$merged         = ManifestRegistry::get_merged();
		$manifest_slice = self::build_manifest_slice( $merged );
		$manifest_slice = self::apply_dev_overrides( $manifest_slice, self::resolve_dev_manifest() );

		if ( ! empty( $manifest_slice ) ) {
			$out .= JsonBlobEmitter::emit( 'arts-cr-manifest', $manifest_slice );
		}

		$bootstrap_entry = self::resolve_bootstrap_entry();
		$entry           = ManifestRegistry::lookup( $bootstrap_entry );
		if ( is_array( $entry ) && ! empty( $entry['file'] ) && is_string( $entry['file'] ) ) {
			$bootstrap_url = ManifestRegistry::entry_asset_url( $entry, $entry['file'] );
			// `wp_get_script_tag` esc_attr's `src` instead of esc_url'ing it,
			// so pre-esc_url here for URL-semantic protection (% encoding,
			// dangerous-protocol stripping) on top of the helper's attribute
			// encoding. Trailing newline is part of the helper's return.
			$out .= wp_get_script_tag(
				array(
					'type' => 'module',
					'src'  => esc_url( $bootstrap_url ),
				)
			);
		}

		return $out;
	}

	/**
	 * Resolves the dev-manifest map via the `arts_runtime/dev_manifest`
	 * filter. Default empty; products with their own Vite builds populate
	 * `{ ComponentName => devUrl }` so this emitter can fold dev URLs into
	 * the prod manifest slice per name.
	 *
	 * @return array<string, string>
	 */
	private static function resolve_dev_manifest(): array {
		/**
		 * Filter the dev-manifest map.
		 *
		 * @param array<string, string> $dev_manifest
		 */
		$dev_manifest = apply_filters( 'arts_runtime/dev_manifest', array() );
		if ( ! is_array( $dev_manifest ) ) {
			return array();
		}
		$result = array();
		foreach ( $dev_manifest as $name => $url ) {
			if ( is_string( $name ) && is_string( $url ) ) {
				$result[ $name ] = $url;
			}
		}
		return $result;
	}

	/**
	 * Resolves the bootstrap manifest key. Default `src/bootstrap.ts`,
	 * overridable via the `arts_runtime/bootstrap_entry` filter.
	 */
	private static function resolve_bootstrap_entry(): string {
		/**
		 * Filter the bootstrap manifest key.
		 *
		 * @param string $entry
		 */
		$entry = apply_filters( 'arts_runtime/bootstrap_entry', self::DEFAULT_BOOTSTRAP_ENTRY );
		return is_string( $entry ) && $entry !== '' ? $entry : self::DEFAULT_BOOTSTRAP_ENTRY;
	}

	/**
	 * Builds the manifest slice — every component the merged manifest knows
	 * about, keyed by its component name (derived from the Vite manifest key).
	 * Mirrors `ManifestRegistry::resolve_component_key`'s accepted layouts
	 * (subdirectory + flat, `.ts` + `.tsx`).
	 *
	 * @param array<string, array<string, mixed>> $merged
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_manifest_slice( array $merged ): array {
		$slice = array();
		foreach ( $merged as $key => $entry ) {
			if ( ! is_string( $key ) || ! is_array( $entry ) ) {
				continue;
			}
			$name = ComponentLayoutResolver::extract_name( $key );
			if ( $name === null ) {
				continue;
			}
			$slice[ $name ] = self::shape_entry( $merged, $key );
		}
		return $slice;
	}

	/**
	 * Folds dev URLs into the prod manifest slice. For each `name => devUrl`
	 * in the map: overwrites the existing entry's `file` field with the dev
	 * URL when the name already exists, or synthesises a fresh entry
	 * (`file` only — dev server resolves `.sass` imports via its own HMR
	 * pipeline, so no `css[]` here) when the name is dev-only.
	 *
	 * @param array<string, array<string, mixed>> $slice
	 * @param array<string, string>                $dev_manifest
	 * @return array<string, array<string, mixed>>
	 */
	private static function apply_dev_overrides( array $slice, array $dev_manifest ): array {
		foreach ( $dev_manifest as $name => $dev_url ) {
			if ( isset( $slice[ $name ] ) && is_array( $slice[ $name ] ) ) {
				$slice[ $name ]['file'] = $dev_url;
			} else {
				$slice[ $name ] = array( 'file' => $dev_url );
			}
		}
		return $slice;
	}

	/**
	 * Reduces a manifest entry to the pair bootstrap consumes (`file`, `css`).
	 * Drops Vite-internal fields (`isEntry`, `name`, `src`, `imports`) to keep
	 * the blob compact.
	 *
	 * `file` and `css` are pre-resolved to absolute URLs so the JS resolver
	 * can pass `entry.file` straight to `import()` without having to know
	 * the dist base path.
	 *
	 * `css` is FLATTENED to include transitive CSS from `imports[]` (via
	 * `ManifestRegistry::collect_entry_css_urls`) — Vite's manifest format
	 * leaves transitive CSS attached to imported chunks, NOT the consuming
	 * entry. Flattening here lets downstream consumers (browser-side
	 * `ComponentCssPlugin`, AJAX `updateStyles`) read a single flat list.
	 *
	 * @param array<string, array<string, mixed>> $merged
	 * @param string                              $entry_key
	 * @return array<string, mixed>
	 */
	private static function shape_entry( array $merged, string $entry_key ): array {
		$entry = $merged[ $entry_key ] ?? array();
		$shape = array();
		if ( isset( $entry['file'] ) && is_string( $entry['file'] ) ) {
			$shape['file'] = ManifestRegistry::entry_asset_url( $entry, $entry['file'] );
		}
		$css_urls = ManifestRegistry::collect_entry_css_urls( $merged, $entry_key );
		if ( ! empty( $css_urls ) ) {
			$shape['css'] = $css_urls;
		}
		return $shape;
	}
}
