<?php
/**
 * BootstrapEmitter — manifest-slice shape, dev overrides, bootstrap
 * script-tag emission with filter override.
 *
 * Manifest data flows through real ManifestRegistry + ComponentLayoutResolver
 * (no mocking). wp_json_encode / wp_get_*_script_tag stubbed via Brain
 * Monkey aliases so cells can assert against actual output.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\BootstrapEmitter;
use Arts\ComponentRuntime\Managers\ManifestRegistry;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionClass;

final class BootstrapEmitterTest extends AbstractTestCase {
	private const URL_PREFIX = 'https://example.test/wp-content';

	protected function setUp(): void {
		parent::setUp();

		$mr = new ReflectionClass( ManifestRegistry::class );
		foreach ( array( 'cache', 'imports_cache', 'dev_manifest_cache' ) as $prop ) {
			$p = $mr->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, $prop === 'imports_cache' ? array() : null );
		}

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
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data, $flags = 0 ) => json_encode( $data, $flags )
		);
		Functions\when( 'wp_get_inline_script_tag' )->alias(
			static function ( string $data, array $attrs ): string {
				$id   = $attrs['id'] ?? '';
				$type = $attrs['type'] ?? 'text/javascript';
				return sprintf( '<script type="%s" id="%s">%s</script>', $type, $id, $data );
			}
		);
		Functions\when( 'wp_get_script_tag' )->alias(
			static function ( array $attrs ): string {
				$type = $attrs['type'] ?? 'text/javascript';
				$src  = $attrs['src'] ?? '';
				return sprintf( '<script type="%s" src="%s"></script>', $type, $src );
			}
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

	private function write_manifest( string $relative_dir, array $payload ): string {
		$full_dir = WP_CONTENT_DIR . '/' . $relative_dir . '/.vite';
		mkdir( $full_dir, 0777, true );
		$path = $full_dir . '/manifest.json';
		file_put_contents( $path, json_encode( $payload ) );
		return $path;
	}

	public function test_BOOT_1_manifest_slice_keyed_by_name_with_file_and_flattened_css(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/components/Hero/Hero.ts' => array(
					'file'    => 'hero.js',
					'css'     => array( 'hero.css' ),
					'imports' => array( 'shared.ts' ),
				),
				'shared.ts'                   => array(
					'file' => 'shared.js',
					'css'  => array( 'shared.css' ),
				),
				'src/bootstrap.ts'            => array( 'file' => 'bootstrap.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn( array() );

		$out = BootstrapEmitter::generate();

		// The JSON blob is the first emission. Decode it back from the script tag.
		$matches = array();
		preg_match( '#<script type="application/json" id="arts-cr-manifest">(.+?)</script>#', $out, $matches );
		$this->assertNotEmpty( $matches, 'manifest blob present' );
		$slice = json_decode( $matches[1], true );

		$this->assertArrayHasKey( 'Hero', $slice );
		$this->assertSame( self::URL_PREFIX . '/theme/hero.js', $slice['Hero']['file'] );
		// Transitive CSS from shared.ts is flattened into Hero's `css[]`.
		$this->assertContains( self::URL_PREFIX . '/theme/hero.css', $slice['Hero']['css'] );
		$this->assertContains( self::URL_PREFIX . '/theme/shared.css', $slice['Hero']['css'] );
	}

	public function test_BOOT_2_dev_override_replaces_file_and_unsets_css(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/components/Hero/Hero.ts' => array(
					'file' => 'hero.js',
					'css'  => array( 'hero.css' ),
				),
				'src/bootstrap.ts'            => array( 'file' => 'bootstrap.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn(
			array( 'Hero' => 'http://localhost:5173/Hero.ts' )
		);

		$out     = BootstrapEmitter::generate();
		$matches = array();
		preg_match( '#<script type="application/json" id="arts-cr-manifest">(.+?)</script>#', $out, $matches );
		$slice = json_decode( $matches[1], true );

		$this->assertSame( 'http://localhost:5173/Hero.ts', $slice['Hero']['file'] );
		$this->assertArrayNotHasKey( 'css', $slice['Hero'], 'css[] dropped for dev-served component' );
	}

	public function test_BOOT_3_missing_bootstrap_entry_emits_no_script_tag(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/components/Hero/Hero.ts' => array( 'file' => 'hero.js' ),
				// no src/bootstrap.ts entry
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn( array() );

		$out = BootstrapEmitter::generate();

		$this->assertStringNotContainsString( '<script type="module"', $out );
	}

	public function test_BOOT_4_bootstrap_entry_filter_overrides_default_key(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/custom-bootstrap.ts' => array( 'file' => 'custom-bootstrap.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn( array() );
		Filters\expectApplied( 'arts_runtime/bootstrap_entry' )->andReturn( 'src/custom-bootstrap.ts' );

		$out = BootstrapEmitter::generate();

		$this->assertStringContainsString(
			'<script type="module" src="' . self::URL_PREFIX . '/theme/custom-bootstrap.js"></script>',
			$out
		);
	}

	public function test_BOOT_4b_bootstrap_entry_filter_returning_non_string_falls_back_to_default(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/bootstrap.ts' => array( 'file' => 'bootstrap.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn( array() );
		Filters\expectApplied( 'arts_runtime/bootstrap_entry' )->andReturn( null );

		$out = BootstrapEmitter::generate();

		// Falls back to DEFAULT_BOOTSTRAP_ENTRY = 'src/bootstrap.ts'.
		$this->assertStringContainsString(
			'<script type="module" src="' . self::URL_PREFIX . '/theme/bootstrap.js"></script>',
			$out
		);
	}

	public function test_BOOT_5_wp_get_script_tag_invoked_with_type_module_and_src(): void {
		$path = $this->write_manifest(
			'theme',
			array(
				'src/bootstrap.ts' => array( 'file' => 'bootstrap.js' ),
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $path ) );
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn( array() );

		$out = BootstrapEmitter::generate();

		// type=module + the resolved URL.
		$this->assertMatchesRegularExpression(
			'#<script type="module" src="https://example\.test/wp-content/theme/bootstrap\.js"></script>#',
			$out
		);
	}
}
