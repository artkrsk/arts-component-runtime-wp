<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Containers;

use Arts\Base\Containers\ManagersContainer as BaseManagersContainer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Empty no-op — runtime uses static utility classes, not `BaseManager`
 * subclasses. Kept for `BasePlugin<TManagers>` typing parity.
 */
class ManagersContainer extends BaseManagersContainer {
}
