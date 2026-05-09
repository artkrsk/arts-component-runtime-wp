<?php

namespace Arts\ComponentRuntime;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Arts\Base\Plugins\BasePlugin;
use Arts\ComponentRuntime\Containers\ManagersContainer;
use Arts\ComponentRuntime\Managers\CachePluginCompat;
use Arts\ComponentRuntime\Managers\ComponentDiscovery;
use Arts\GSAPLoader\Plugin as GSAPLoaderPlugin;
use Arts\TextSplitter\Plugin as TextSplitterPlugin;

/**
 * Manifest-driven runtime emission plugin for `@arts/component-runtime`.
 *
 * Auto-discovers components by buffering the rendered HTML and scanning it
 * for `data-arts-component-name` attributes; the inline CSS, modulepreload
 * links, and bootstrap module are injected after the document's
 * `<meta charset>`. See `ComponentDiscovery` for the buffering branches and
 * the `arts_runtime/auto_discover` opt-out filter.
 *
 * Also bootstraps the framework's first-party UMD-script dependencies
 * (`GSAPLoader` and `TextSplitter`) so their classic scripts reach the
 * parser before the bootstrap module evaluates. This keeps `window.gsap`,
 * `window.ScrollTrigger`, `window.SplitText`, and `window.TextReveal`
 * pointing at single class instances shared between the app bundle and
 * dev-served component modules — components externalize these via
 * `peerDependencyGlobals` and reach for the same window references.
 *
 * Components are dynamic-imported by the bootstrap module from a Vite
 * manifest emitted by the consumer's build (e.g.
 * `wp-content/uploads/arts-runtime-dist/.vite/manifest.json`); there is
 * no IIFE registry layer.
 *
 * @extends BasePlugin<ManagersContainer>
 * @package Arts\ComponentRuntime
 */
class Plugin extends BasePlugin {
	/**
	 * @return array<string, mixed>
	 */
	protected function get_default_config(): array {
		return array();
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_default_strings(): array {
		return array();
	}

	/**
	 * @return string
	 */
	protected function get_default_run_action(): string {
		return 'plugins_loaded';
	}

	/**
	 * @return array<string, class-string>
	 */
	protected function get_managers_classes(): array {
		// `ManifestRegistry`, `ComponentScanner`, the three emitters, and
		// `ComponentDiscovery` are static utility classes — they don't fit
		// the BaseManager pattern. Hooks are registered directly in
		// `do_after_init_managers`.
		return array();
	}

	/**
	 * @return void
	 */
	protected function do_after_init_managers(): void {
		GSAPLoaderPlugin::instance();
		TextSplitterPlugin::instance();
		ComponentDiscovery::register();
		CachePluginCompat::register();
	}
}
