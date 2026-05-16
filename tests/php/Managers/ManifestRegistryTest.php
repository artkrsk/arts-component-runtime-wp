<?php
/**
 * ManifestRegistry — BFS cycle protection, path-derivation +
 * symlink-fallback, filter memoization, manifest decode failures.
 *
 * Most cells exercise the private surface through the public methods
 * (collect_imports via collect_entry_js_urls; derive_base_url via the
 * `_arts_base_url` field stamped by get_merged). Static caches are reset
 * via reflection in setUp so cells don't pollute each other.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\ManifestRegistry;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionClass;

final class ManifestRegistryTest extends AbstractTestCase {
	private const URL_PREFIX = 'https://example.test/wp-content';

	protected function setUp(): void {
		parent::setUp();

		// Fresh ManifestRegistry per cell — all three private static caches.
		$reflection = new ReflectionClass( ManifestRegistry::class );
		foreach ( array( 'cache', 'imports_cache', 'dev_manifest_cache' ) as $prop_name ) {
			$prop = $reflection->getProperty( $prop_name );
			$prop->setAccessible( true );
			$prop->setValue( null, $prop_name === 'imports_cache' ? array() : null );
		}

		// WP_CONTENT_DIR scratch dir.
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0777, true );
		}

		Functions\when( 'wp_normalize_path' )->alias(
			static fn( string $path ): string => str_replace( '\\', '/', $path )
		);
		Functions\when( 'content_url' )->alias(
			static fn( string $path = '' ): string => self::URL_PREFIX . ( $path !== '' ? '/' . ltrim( $path, '/' ) : '' )
		);
	}

	protected function tearDown(): void {
		$this->rrmdir( WP_CONTENT_DIR );
		parent::tearDown();
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/** Writes a manifest JSON file under WP_CONTENT_DIR, returns its absolute path. */
	private function write_manifest( string $relative_dir, array $payload ): string {
		$full_dir = WP_CONTENT_DIR . '/' . $relative_dir . '/.vite';
		mkdir( $full_dir, 0777, true );
		$path = $full_dir . '/manifest.json';
		file_put_contents( $path, json_encode( $payload ) );
		return $path;
	}

	// ──── collect_imports (MR-1, MR-2, MR-3) ────

	public function test_MR_1_collect_imports_BFS_includes_transitive_imports_deduped(): void {
		$path = $this->write_manifest(
			'theme1',
			array(
				'entry.ts' => array( 'file' => 'entry.js', 'imports' => array( 'a.ts', 'b.ts' ) ),
				'a.ts'     => array( 'file' => 'a.js', 'imports' => array( 'shared.ts' ) ),
				'b.ts'     => array( 'file' => 'b.js', 'imports' => array( 'shared.ts' ) ),
				'shared.ts' => array( 'file' => 'shared.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		$merged = ManifestRegistry::get_merged();
		$urls   = ManifestRegistry::collect_entry_js_urls( $merged, 'entry.ts' );

		$this->assertContains( self::URL_PREFIX . '/theme1/entry.js', $urls );
		$this->assertContains( self::URL_PREFIX . '/theme1/a.js', $urls );
		$this->assertContains( self::URL_PREFIX . '/theme1/b.js', $urls );
		$this->assertContains( self::URL_PREFIX . '/theme1/shared.js', $urls );
		$this->assertCount( 4, $urls, 'shared.js must dedup across a.ts and b.ts' );
	}

	public function test_MR_2_collect_imports_with_cycle_terminates_finite_set(): void {
		$path = $this->write_manifest(
			'theme2',
			array(
				'A.ts' => array( 'file' => 'A.js', 'imports' => array( 'B.ts' ) ),
				'B.ts' => array( 'file' => 'B.js', 'imports' => array( 'A.ts' ) ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		$merged = ManifestRegistry::get_merged();
		// Would loop forever without the $seen guard; if we get here, the BFS terminated.
		$urls = ManifestRegistry::collect_entry_js_urls( $merged, 'A.ts' );
		$this->assertEqualsCanonicalizing(
			array( self::URL_PREFIX . '/theme2/A.js', self::URL_PREFIX . '/theme2/B.js' ),
			$urls
		);
	}

	public function test_MR_3_collect_imports_with_missing_entry_silently_drops(): void {
		$path = $this->write_manifest(
			'theme3',
			array(
				'entry.ts' => array( 'file' => 'entry.js', 'imports' => array( 'present.ts', 'missing.ts' ) ),
				'present.ts' => array( 'file' => 'present.js' ),
				// `missing.ts` deliberately absent from the manifest.
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		$merged = ManifestRegistry::get_merged();
		$urls   = ManifestRegistry::collect_entry_js_urls( $merged, 'entry.ts' );

		$this->assertContains( self::URL_PREFIX . '/theme3/entry.js', $urls );
		$this->assertContains( self::URL_PREFIX . '/theme3/present.js', $urls );
		$this->assertCount( 2, $urls );
	}

	// ──── derive_base_url (MR-4, MR-5, MR-6, MR-7) ────

	public function test_MR_4_derive_base_url_happy_path_stamps_content_url_prefix(): void {
		$path = $this->write_manifest( 'plugins/widget', array( 'x.ts' => array( 'file' => 'x.js' ) ) );
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		$merged = ManifestRegistry::get_merged();
		$this->assertSame( self::URL_PREFIX . '/plugins/widget/', $merged['x.ts']['_arts_base_url'] );
	}

	public function test_MR_5_derive_base_url_symlink_fallback_resolves_via_realpath(): void {
		// Symlink fallback engages when the manifest's SURFACE path is OUTSIDE
		// WP_CONTENT_DIR (first prefix check fails) but its realpath resolves
		// to a directory INSIDE it. Mirrors the Hostinger plugin-symlink case
		// the production code defends against.
		$real_target = WP_CONTENT_DIR . '/inside-target';
		mkdir( $real_target . '/.vite', 0777, true );
		file_put_contents( $real_target . '/.vite/manifest.json', json_encode( array( 'y.ts' => array( 'file' => 'y.js' ) ) ) );

		$outside_link = sys_get_temp_dir() . '/arts-cr-outside-link-' . uniqid();
		if ( ! @symlink( $real_target, $outside_link ) ) {
			$this->markTestSkipped( 'symlink creation unsupported on this filesystem' );
		}
		try {
			$path = $outside_link . '/.vite/manifest.json';
			Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

			$merged = ManifestRegistry::get_merged();
			// Surface path outside content dir → first branch fails. realpath
			// resolves to inside-target → second branch succeeds.
			$this->assertSame( self::URL_PREFIX . '/inside-target/', $merged['y.ts']['_arts_base_url'] );
		} finally {
			@unlink( $outside_link );
		}
	}

	public function test_MR_6_derive_base_url_outside_wp_content_returns_empty(): void {
		$outside_dir = sys_get_temp_dir() . '/arts-cr-outside-content/.vite';
		mkdir( $outside_dir, 0777, true );
		$path = $outside_dir . '/manifest.json';
		file_put_contents( $path, json_encode( array( 'z.ts' => array( 'file' => 'z.js' ) ) ) );

		try {
			Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

			$merged = ManifestRegistry::get_merged();
			$this->assertSame( '', $merged['z.ts']['_arts_base_url'] );
		} finally {
			unlink( $path );
			rmdir( $outside_dir );
			rmdir( dirname( $outside_dir ) );
		}
	}

	public function test_MR_7_derive_base_url_does_NOT_canonicalize_traversal_dots(): void {
		// Documents existing behaviour: derive_base_url does textual prefix
		// matching against the raw path. A `..` segment inside a path under
		// WP_CONTENT_DIR survives into the emitted base URL — there's no
		// rejection layer here. Defense-in-depth lives downstream
		// (WpContentPathInverter's file_exists gate).
		$path = $this->write_manifest( 'plugins/widget/../widget', array( 'k.ts' => array( 'file' => 'k.js' ) ) );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		$merged = ManifestRegistry::get_merged();
		// The base URL keeps the `..` segment verbatim (regression cell — if
		// a future change canonicalizes, update this assertion accordingly).
		$this->assertStringContainsString( '..', $merged['k.ts']['_arts_base_url'] );
	}

	// ──── get_dev_manifest (MR-8, MR-9) ────

	public function test_MR_8_get_dev_manifest_dispatches_filter_once_then_memoizes(): void {
		Filters\expectApplied( 'arts_runtime/dev_manifest' )
			->once()
			->andReturn( array( 'Hero' => 'http://dev/Hero.js' ) );

		$first  = ManifestRegistry::get_dev_manifest();
		$second = ManifestRegistry::get_dev_manifest();
		$third  = ManifestRegistry::get_dev_manifest();

		$this->assertSame( array( 'Hero' => 'http://dev/Hero.js' ), $first );
		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
	}

	public function test_MR_9_get_dev_manifest_normalizes_wrong_type_input_to_empty_array(): void {
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn( 'not-an-array' );

		$this->assertSame( array(), ManifestRegistry::get_dev_manifest() );
	}

	public function test_MR_9b_get_dev_manifest_filters_invalid_entries(): void {
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn(
			array(
				'Valid'      => 'http://dev/Valid.js',
				42           => 'http://dev/IntKey.js', // non-string key rejected
				'EmptyUrl'   => '',                       // empty URL rejected
				'NonString'  => 123,                      // non-string value rejected
			)
		);

		$this->assertSame( array( 'Valid' => 'http://dev/Valid.js' ), ManifestRegistry::get_dev_manifest() );
	}

	// ──── manifest decode failures (MR-10) ────

	public function test_MR_10_malformed_json_manifest_is_skipped_no_throw(): void {
		$dir = WP_CONTENT_DIR . '/malformed/.vite';
		mkdir( $dir, 0777, true );
		$path = $dir . '/manifest.json';
		file_put_contents( $path, '{ this is not valid json' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		// No throw; returns empty merged manifest.
		$merged = ManifestRegistry::get_merged();
		$this->assertSame( array(), $merged );
	}

	public function test_MR_10b_missing_manifest_file_is_skipped_no_throw(): void {
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn(
			array( WP_CONTENT_DIR . '/nonexistent/.vite/manifest.json' )
		);
		$this->assertSame( array(), ManifestRegistry::get_merged() );
	}

	public function test_MR_10c_non_string_path_entries_are_skipped(): void {
		$path = $this->write_manifest( 'theme10', array( 'k.ts' => array( 'file' => 'k.js' ) ) );
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn(
			array( $path, 42, null, array() )
		);

		$merged = ManifestRegistry::get_merged();
		$this->assertArrayHasKey( 'k.ts', $merged );
	}
}
