<?php
/**
 * PreloadEmitter — `<link rel="modulepreload">` generation with
 * cross-component URL dedup + transitive-imports inclusion.
 *
 * Exercises the integration: real ComponentScanner state, real
 * ManifestRegistry (loaded from on-disk manifest under WP_CONTENT_DIR).
 * Both registries' static caches reset between cells.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\ComponentScanner;
use Arts\ComponentRuntime\Managers\ManifestRegistry;
use Arts\ComponentRuntime\Managers\PreloadEmitter;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionClass;

final class PreloadEmitterTest extends AbstractTestCase {
	private const URL_PREFIX = 'https://example.test/wp-content';

	protected function setUp(): void {
		parent::setUp();

		// Reset ManifestRegistry static caches.
		$mr = new ReflectionClass( ManifestRegistry::class );
		foreach ( array( 'cache', 'imports_cache', 'dev_manifest_cache' ) as $prop ) {
			$p = $mr->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, $prop === 'imports_cache' ? array() : null );
		}

		// Reset ComponentScanner registry.
		$scanner = new ReflectionClass( ComponentScanner::class );
		$prop    = $scanner->getProperty( 'components' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0777, true );
		}

		Functions\when( 'wp_normalize_path' )->alias(
			static fn( string $path ): string => str_replace( '\\', '/', $path )
		);
		Functions\when( 'content_url' )->alias(
			static fn( string $path = '' ): string => self::URL_PREFIX . ( $path !== '' ? '/' . ltrim( $path, '/' ) : '' )
		);
		Functions\when( 'esc_url' )->returnArg();
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

	private function write_manifest( string $relative_dir, array $payload ): string {
		$full_dir = WP_CONTENT_DIR . '/' . $relative_dir . '/.vite';
		mkdir( $full_dir, 0777, true );
		$path = $full_dir . '/manifest.json';
		file_put_contents( $path, json_encode( $payload ) );
		return $path;
	}

	public function test_PRE_1_emits_one_link_per_unique_entry_chunk_url(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/components/Hero/Hero.ts' => array( 'file' => 'hero.js' ),
				'src/components/Foo/Foo.ts'   => array( 'file' => 'foo.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		ComponentScanner::register_component( 'Hero' );
		ComponentScanner::register_component( 'Foo' );

		$out = PreloadEmitter::generate();

		$this->assertStringContainsString( '<link rel="modulepreload" href="' . self::URL_PREFIX . '/theme/hero.js">', $out );
		$this->assertStringContainsString( '<link rel="modulepreload" href="' . self::URL_PREFIX . '/theme/foo.js">', $out );
		$this->assertSame( 2, substr_count( $out, '<link rel="modulepreload"' ) );
	}

	public function test_PRE_2_dedups_shared_chunk_across_components(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/components/Hero/Hero.ts' => array(
					'file'    => 'hero.js',
					'imports' => array( 'shared.ts' ),
				),
				'src/components/Foo/Foo.ts'   => array(
					'file'    => 'foo.js',
					'imports' => array( 'shared.ts' ),
				),
				'shared.ts'                   => array( 'file' => 'shared.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		ComponentScanner::register_component( 'Hero' );
		ComponentScanner::register_component( 'Foo' );

		$out = PreloadEmitter::generate();

		// Shared chunk appears exactly once even though two components reach it.
		$this->assertSame( 1, substr_count( $out, 'shared.js' ) );
		$this->assertSame( 3, substr_count( $out, '<link rel="modulepreload"' ) );
	}

	public function test_PRE_3_empty_component_set_returns_empty_string(): void {
		// No components registered.
		$this->assertSame( '', PreloadEmitter::generate() );
	}

	public function test_PRE_4_transitive_imports_are_preloaded(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/components/Hero/Hero.ts' => array(
					'file'    => 'hero.js',
					'imports' => array( 'a.ts' ),
				),
				'a.ts'                        => array(
					'file'    => 'a.js',
					'imports' => array( 'b.ts' ),
				),
				'b.ts'                        => array( 'file' => 'b.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		ComponentScanner::register_component( 'Hero' );

		$out = PreloadEmitter::generate();

		$this->assertStringContainsString( 'hero.js', $out );
		$this->assertStringContainsString( 'a.js', $out );
		$this->assertStringContainsString( 'b.js', $out );
	}

	public function test_PRE_5_component_with_no_manifest_match_is_skipped(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/components/Hero/Hero.ts' => array( 'file' => 'hero.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );

		ComponentScanner::register_component( 'Hero' );
		ComponentScanner::register_component( 'NotInManifest' );

		$out = PreloadEmitter::generate();

		$this->assertStringContainsString( 'hero.js', $out );
		$this->assertSame( 1, substr_count( $out, '<link rel="modulepreload"' ) );
	}
}
