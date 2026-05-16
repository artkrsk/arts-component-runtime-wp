<?php
/**
 * PHPUnit bootstrap for arts/component-runtime-wp.
 *
 * Defines the minimum WP constants the source files guard on
 * (`defined( 'ABSPATH' ) || exit;`), then loads the Composer
 * autoloader. The autoloader's `classmap` (configured in
 * composer.json's `autoload-dev`) covers WordPress's HTML API
 * classes shipped by `roots/wordpress` — so `new
 * \WP_HTML_Tag_Processor()` resolves to the real implementation
 * on demand. No WordPress core boot, no DB, no plugin loader.
 *
 * Brain Monkey lifecycle (setUp/tearDown) lives in
 * `AbstractTestCase` so each test gets fresh interception state.
 */

declare(strict_types=1);

// Per-process suffix keeps parallel runs (paratest, future CI matrix)
// from clobbering each other's scratch dirs mid-flight.
$arts_test_pid_suffix = (string) getmypid();
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-abspath-arts-cr-tests-' . $arts_test_pid_suffix . '/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-arts-cr-tests-' . $arts_test_pid_suffix );
}
unset( $arts_test_pid_suffix );

require_once __DIR__ . '/../../vendor/autoload.php';
