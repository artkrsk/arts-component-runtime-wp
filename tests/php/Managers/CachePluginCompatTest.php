<?php
/**
 * CachePluginCompat — LiteSpeed re-pass filter registration +
 * Autoptimize JS-exclusion shape handling.
 *
 * `LSCWP_V` is a PHP constant; once defined, it stays defined for the
 * process lifetime. CACHE-1 needs it defined, but other cells must NOT
 * see it (otherwise their `register()` calls add the litespeed filter
 * without a matching `expectAdded` declaration). Process isolation via
 * `@runInSeparateProcess` on CACHE-1 forks a fresh PHP process for that
 * one test, so the constant doesn't leak into the rest of the suite.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\CachePluginCompat;
use Arts\ComponentRuntime\Managers\ComponentDiscovery;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Filters;

final class CachePluginCompatTest extends AbstractTestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_CACHE_1_register_adds_litespeed_buffer_after_at_priority_999_when_LSCWP_V_defined(): void {
		if ( ! defined( 'LSCWP_V' ) ) {
			define( 'LSCWP_V', '6.0' );
		}
		Filters\expectAdded( 'litespeed_buffer_after' )
			->once()
			->with( array( ComponentDiscovery::class, 'process' ), 999 );
		Filters\expectAdded( 'autoptimize_filter_js_exclude' )->once();

		CachePluginCompat::register();
	}

	public function test_CACHE_2_register_adds_autoptimize_filter_unconditionally(): void {
		Filters\expectAdded( 'autoptimize_filter_js_exclude' )
			->once()
			->with( array( CachePluginCompat::class, 'autoptimize_js_exclude' ) );

		CachePluginCompat::register();
	}

	public function test_CACHE_3_autoptimize_js_exclude_array_input_appends_arts_runtime(): void {
		$out = CachePluginCompat::autoptimize_js_exclude( array( 'foo', 'bar' ) );
		$this->assertSame( array( 'foo', 'bar', 'arts-runtime' ), $out );
	}

	public function test_CACHE_3b_autoptimize_js_exclude_array_input_filters_non_strings(): void {
		$out = CachePluginCompat::autoptimize_js_exclude( array( 'foo', 42, null, 'bar' ) );
		$this->assertSame( array( 'foo', 'bar', 'arts-runtime' ), $out );
	}

	public function test_CACHE_4_autoptimize_js_exclude_string_input_appends_comma_separated(): void {
		$out = CachePluginCompat::autoptimize_js_exclude( 'foo, bar' );
		$this->assertSame( 'foo, bar, arts-runtime', $out );
	}

	public function test_CACHE_4b_autoptimize_js_exclude_empty_string_returns_arts_runtime(): void {
		$out = CachePluginCompat::autoptimize_js_exclude( '' );
		$this->assertSame( 'arts-runtime', $out );
	}

	public function test_CACHE_5_autoptimize_js_exclude_malformed_input_returns_array_with_arts_runtime(): void {
		$this->assertSame( array( 'arts-runtime' ), CachePluginCompat::autoptimize_js_exclude( 42 ) );
		$this->assertSame( array( 'arts-runtime' ), CachePluginCompat::autoptimize_js_exclude( null ) );
		$this->assertSame( array( 'arts-runtime' ), CachePluginCompat::autoptimize_js_exclude( true ) );
	}
}
