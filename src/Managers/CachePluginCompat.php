<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Cache-plugin compatibility shims. Each integration is a thin filter
 * registration; the underlying buffer-pipeline work stays on
 * `ComponentDiscovery`. Adding support for another cache plugin
 * (WP Rocket, Hummingbird, W3TC, …) is a new method on this class
 * plus one line in `register()` — no edits to the discovery flow.
 *
 * Owns no public API beyond `register()` and the autoptimize callback.
 */
class CachePluginCompat {
	private function __construct() {}

	public static function register(): void {
		// LiteSpeed Cache: its outer buffer fires after the standard
		// `template_redirect:0` `ob_start` callback, so route through
		// `ComponentDiscovery::process` again. The `$emitted` flag
		// inside `process` short-circuits the second pass.
		if ( defined( 'LSCWP_V' ) ) {
			add_filter( 'litespeed_buffer_after', array( ComponentDiscovery::class, 'process' ), 999 );
		}

		// Autoptimize: defensive only. Its own `should_aggregate()` type
		// allowlist (text|application)/(javascript|ecmascript) already
		// rejects both our `type="module"` bootstrap and the
		// `type="application/json"` blobs before the exclude list is even
		// consulted, so this filter is belt-and-braces against that gate
		// changing — not what keeps the module out of a classic IIFE.
		add_filter( 'autoptimize_filter_js_exclude', array( self::class, 'autoptimize_js_exclude' ) ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.autoptimize_filter_js_exclude
	}

	/**
	 * @param mixed $exclude Array or comma-separated string.
	 * @return string|array<int, string>
	 */
	public static function autoptimize_js_exclude( mixed $exclude ): string|array {
		if ( is_string( $exclude ) ) {
			return $exclude === '' ? 'arts-runtime' : $exclude . ', arts-runtime';
		}
		if ( ! is_array( $exclude ) ) {
			return array( 'arts-runtime' );
		}
		$list   = array_values( array_filter( $exclude, 'is_string' ) );
		$list[] = 'arts-runtime';
		return $list;
	}
}
