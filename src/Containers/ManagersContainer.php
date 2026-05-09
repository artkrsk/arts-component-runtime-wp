<?php

namespace Arts\ComponentRuntime\Containers;

use Arts\Base\Containers\ManagersContainer as BaseManagersContainer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Container for manager instances. Empty no-op subclass — the manifest-driven
 * runtime layer uses static utility classes (`ManifestRegistry`,
 * `ComponentScanner`, `PreloadEmitter`) rather than `BaseManager` subclasses.
 * Kept for `BasePlugin<TManagers>` typing parity with the rest of the Arts
 * framework packages.
 */
class ManagersContainer extends BaseManagersContainer {
}
