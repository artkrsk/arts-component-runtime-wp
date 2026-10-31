<?php

declare(strict_types=1);

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
 * Auto-discovers components by buffering rendered HTML and scanning for
 * `data-arts-component-name`; CSS and modulepreloads are injected after
 * `<meta charset>`, the coverage and manifest blobs and the bootstrap module
 * before `</head>`. Opt-out via `arts_runtime/auto_discover`.
 *
 * @extends BasePlugin<ManagersContainer>
 */
class Plugin extends BasePlugin {
	protected function get_default_config(): array {
		return array();
	}

	protected function get_default_strings(): array {
		return array();
	}

	protected function get_default_run_action(): string {
		return 'plugins_loaded';
	}

	protected function get_managers_classes(): array {
		return array();
	}

	protected function do_after_init_managers(): void {
		GSAPLoaderPlugin::instance();
		TextSplitterPlugin::instance();
		ComponentDiscovery::register();
		CachePluginCompat::register();
	}
}
