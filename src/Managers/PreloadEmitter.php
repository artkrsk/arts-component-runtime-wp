<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds one `<link rel="modulepreload" href="...">` tag per unique JS chunk
 * URL across every registered component plus its transitive shared-chunk
 * closure. Returned string is concatenated before the bootstrap script tag
 * so the browser sees preloads first and starts parallel fetches while
 * parsing the bootstrap module.
 *
 * Direct string concat for `<link rel="modulepreload">` — WordPress has no
 * native helper for it, and `wp_register_script_module` only emits preloads
 * for STATIC import dependencies; component chunks reached via dynamic
 * `import()` from the bootstrap won't get auto-preloaded by WP.
 *
 * Owns no filters — the data it walks comes from `ComponentScanner` and
 * `ManifestRegistry`.
 */
class PreloadEmitter {
	private function __construct() {}

	public static function generate(): string {
		$component_names = ComponentScanner::get_components();
		if ( empty( $component_names ) ) {
			return '';
		}

		$merged     = ManifestRegistry::get_merged();
		$preload_js = array();

		foreach ( $component_names as $name ) {
			$manifest_key = ManifestRegistry::resolve_component_key( $merged, $name );
			if ( $manifest_key === null ) {
				continue;
			}
			// Entry's own `file` + every transitive import's `file`,
			// resolved against each owning entry's `_arts_base_url`.
			foreach ( ManifestRegistry::collect_entry_js_urls( $merged, $manifest_key ) as $url ) {
				$preload_js[ $url ] = true;
			}
		}

		$out = '';
		foreach ( array_keys( $preload_js ) as $chunk_url ) {
			$out .= '<link rel="modulepreload" href="' . esc_url( $chunk_url ) . '">' . "\n";
		}
		return $out;
	}
}
