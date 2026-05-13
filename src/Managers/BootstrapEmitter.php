<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds the always-full `arts-cr-manifest` JSON blob and the bootstrap
 * `<script type="module">` tag. REQUIRED for the runtime to work.
 *
 * Blob is always-full so AJAX nav, editor drops, and static templates resolve
 * from a single emission. Shared chunks are excluded; the browser follows
 * transitive ESM imports natively. Cost ~7 KB / ~1.5 KB gzipped.
 *
 * Per-entry asset URLs use `ManifestRegistry`'s `_arts_base_url` so components
 * from one build coexist with bootstrap chunks from another build.
 *
 * Bootstrap tag uses direct string concat instead of
 * `wp_register_script_module` / `wp_enqueue_script_module` because the
 * Script Modules API shifts emission to `wp_footer` on classic themes
 * (`print_enqueued_script_modules` only registers on `wp_footer` /
 * `wp_head:10` for block themes); enqueue from a late buffer-rewrite hook
 * misses both passes.
 *
 * Filters:
 *   - `arts_runtime/bootstrap_entry` — override bootstrap key
 *                                      (default `src/bootstrap.ts`).
 *   - `arts_runtime/dev_manifest`    — `{ ComponentName => devUrl }` to
 *                                      override `entry.file` with a Vite
 *                                      dev URL (default `[]`).
 */
class BootstrapEmitter {
	/** Overridable via the `arts_runtime/bootstrap_entry` filter. */
	private const DEFAULT_BOOTSTRAP_ENTRY = 'src/bootstrap.ts';

	private function __construct() {}

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
			// so pre-esc_url for URL-semantic protection.
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
	 * Manifest slice keyed by component name. Mirrors layouts accepted by
	 * `ManifestRegistry::resolve_component_key` (subdirectory + flat, `.ts` + `.tsx`).
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
	 * Folds dev URLs into the prod manifest slice. Dev-served components get a
	 * `file`-only entry: the dev server resolves `.sass` imports via the
	 * framework's HMR snippet (`<style id="arts-cr-<name>-inline">`), so the
	 * prod-build `css[]` from the cached manifest MUST be dropped — otherwise
	 * `ComponentCssPlugin` keeps injecting `<link>` to the stale prod chunk,
	 * which is appended after the HMR `<style>` and wins the cascade for any
	 * same-specificity rule. Net effect before the unset: SASS edits compile
	 * and hot-swap correctly, but the stale prod link masks the swap.
	 *
	 * @param array<string, array<string, mixed>> $slice
	 * @param array<string, string>                $dev_manifest
	 * @return array<string, array<string, mixed>>
	 */
	private static function apply_dev_overrides( array $slice, array $dev_manifest ): array {
		foreach ( $dev_manifest as $name => $dev_url ) {
			if ( isset( $slice[ $name ] ) && is_array( $slice[ $name ] ) ) {
				$slice[ $name ]['file'] = $dev_url;
				unset( $slice[ $name ]['css'] );
			} else {
				$slice[ $name ] = array( 'file' => $dev_url );
			}
		}
		return $slice;
	}

	/**
	 * Reduces a manifest entry to `(file, css)`. `file`/`css` are pre-resolved
	 * to absolute URLs so the JS resolver can pass `entry.file` straight to
	 * `import()`.
	 *
	 * `css` is FLATTENED to include transitive CSS from `imports[]` — Vite's
	 * manifest leaves transitive CSS attached to imported chunks, not the
	 * consuming entry. Flattening lets downstream consumers (`ComponentCssPlugin`,
	 * AJAX `updateStyles`) read a single flat list.
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
