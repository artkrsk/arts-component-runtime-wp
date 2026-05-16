<?php
/**
 * ComponentCssEmitter — XSS guard, mode dispatch, dedup logic, dev-served
 * skip, skip-filter, coverage blob.
 *
 * The widest test — exercises link mode (URL dedup), inline mode (local-path
 * dedup + file_get_contents + </style> XSS guard), mode/skip/dev-manifest
 * filters, and the coverage blob shape.
 *
 * Resets ManifestRegistry + ComponentScanner + the emitter's own
 * cached_bundle so cells survive random execution order.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\ComponentCssEmitter;
use Arts\ComponentRuntime\Managers\ComponentScanner;
use Arts\ComponentRuntime\Managers\ManifestRegistry;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionClass;

final class ComponentCssEmitterTest extends AbstractTestCase {
	private const URL_PREFIX = 'https://example.test/wp-content';

	protected function setUp(): void {
		parent::setUp();

		$mr = new ReflectionClass( ManifestRegistry::class );
		foreach ( array( 'cache', 'imports_cache', 'dev_manifest_cache' ) as $prop ) {
			$p = $mr->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, $prop === 'imports_cache' ? array() : null );
		}

		$scanner = new ReflectionClass( ComponentScanner::class );
		$prop    = $scanner->getProperty( 'components' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		$css = new ReflectionClass( ComponentCssEmitter::class );
		$p   = $css->getProperty( 'cached_bundle' );
		$p->setAccessible( true );
		$p->setValue( null, null );

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
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $component = -1 ) => parse_url( $url, $component )
		);
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

	private function write_css( string $relative_path, string $contents ): void {
		$abs = WP_CONTENT_DIR . '/' . $relative_path;
		if ( ! is_dir( dirname( $abs ) ) ) {
			mkdir( dirname( $abs ), 0777, true );
		}
		file_put_contents( $abs, $contents );
	}

	public function test_CSS_1_emit_inline_escapes_closing_style_tag(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/X/X.ts' => array(
					'file' => 'x.js',
					'css'  => array( 'x.css' ),
				),
			)
		);
		$this->write_css( 'theme/x.css', '.a{color:red}</style><script>alert(1)</script>' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );

		ComponentScanner::register_component( 'X' );

		$out = ComponentCssEmitter::generate_styles();

		$this->assertStringNotContainsString( '</style><script>', $out );
		$this->assertStringContainsString( '<\\/style>', $out );
	}

	public function test_CSS_2_emit_inline_escape_is_case_insensitive(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/X/X.ts' => array(
					'file' => 'x.js',
					'css'  => array( 'x.css' ),
				),
			)
		);
		$this->write_css( 'theme/x.css', '</STYLE></Style></StYlE>' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );

		ComponentScanner::register_component( 'X' );

		$out = ComponentCssEmitter::generate_styles();

		// Each case-variant rewritten; the only "literal </style>" remaining
		// is the one closing the emitted `<style>` tag itself.
		$this->assertSame( 1, substr_count( strtolower( $out ), '</style>' ) );
		$this->assertSame( 3, substr_count( $out, '<\\/style>' ) );
	}

	public function test_CSS_3_inline_mode_dedups_by_local_path(): void {
		// Two components, both pointing at the same shared CSS chunk.
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array(
					'file' => 'a.js',
					'css'  => array( 'shared.css' ),
				),
				'src/components/B/B.ts' => array(
					'file' => 'b.js',
					'css'  => array( 'shared.css' ),
				),
			)
		);
		$this->write_css( 'theme/shared.css', '.shared{}' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );

		ComponentScanner::register_component( 'A' );
		ComponentScanner::register_component( 'B' );

		$out = ComponentCssEmitter::generate_styles();

		$this->assertSame( 1, substr_count( $out, '.shared{}' ), 'shared CSS body inlined once' );
	}

	public function test_CSS_4_link_mode_emits_per_chunk_with_url_dedup(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array(
					'file' => 'a.js',
					'css'  => array( 'shared.css' ),
				),
				'src/components/B/B.ts' => array(
					'file' => 'b.js',
					'css'  => array( 'shared.css' ),
				),
			)
		);

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );
		Filters\expectApplied( 'arts_runtime/component_css/mode' )->andReturn( 'link' );

		ComponentScanner::register_component( 'A' );
		ComponentScanner::register_component( 'B' );

		$out = ComponentCssEmitter::generate_styles();

		// Link mode bypasses file_get_contents — pure URL emission with dedup.
		$this->assertSame( 1, substr_count( $out, '<link rel="stylesheet"' ) );
		$this->assertStringContainsString( 'shared.css', $out );
	}

	public function test_CSS_5_mode_filter_link_switches_emission(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array(
					'file' => 'a.js',
					'css'  => array( 'a.css' ),
				),
			)
		);

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );
		Filters\expectApplied( 'arts_runtime/component_css/mode' )->andReturn( 'link' );

		ComponentScanner::register_component( 'A' );

		$out = ComponentCssEmitter::generate_styles();

		$this->assertStringContainsString( '<link rel="stylesheet"', $out );
		$this->assertStringNotContainsString( '<style id=', $out );
	}

	public function test_CSS_6_skip_filter_true_omits_component_css(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array(
					'file' => 'a.js',
					'css'  => array( 'a.css' ),
				),
			)
		);
		$this->write_css( 'theme/a.css', '.a{}' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );
		Filters\expectApplied( 'arts_runtime/component_css/should_skip' )->andReturn( true );

		ComponentScanner::register_component( 'A' );

		$out = ComponentCssEmitter::generate_styles();

		$this->assertSame( '', $out );
	}

	public function test_CSS_7_skip_filter_falsy_non_bool_treated_as_falsy(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array(
					'file' => 'a.js',
					'css'  => array( 'a.css' ),
				),
			)
		);
		$this->write_css( 'theme/a.css', '.a{color:red}' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );
		Filters\expectApplied( 'arts_runtime/component_css/should_skip' )->andReturn( null );

		ComponentScanner::register_component( 'A' );

		$out = ComponentCssEmitter::generate_styles();

		// null is falsy → emission continues.
		$this->assertStringContainsString( '.a{color:red}', $out );
	}

	public function test_CSS_8_dev_served_component_is_auto_skipped_in_both_modes(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array(
					'file' => 'a.js',
					'css'  => array( 'a.css' ),
				),
			)
		);
		$this->write_css( 'theme/a.css', '.a{}' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );
		Filters\expectApplied( 'arts_runtime/dev_manifest' )->andReturn(
			array( 'A' => 'http://localhost:5173/A.ts' )
		);

		ComponentScanner::register_component( 'A' );

		$out = ComponentCssEmitter::generate_styles();

		// CSS skipped because A is dev-served — HMR `<style>` handles styling.
		$this->assertSame( '', $out );
	}

	public function test_CSS_9_empty_chunk_list_returns_empty(): void {
		// Components scanned but none has CSS chunks.
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array( 'file' => 'a.js' ), // no css[]
			)
		);
		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );

		ComponentScanner::register_component( 'A' );

		$this->assertSame( '', ComponentCssEmitter::generate_styles() );
		$this->assertSame( '', ComponentCssEmitter::generate_coverage_blob() );
	}

	public function test_CSS_10_coverage_blob_emits_covered_component_names(): void {
		$manifest = $this->write_manifest(
			'theme',
			array(
				'src/components/A/A.ts' => array(
					'file' => 'a.js',
					'css'  => array( 'a.css' ),
				),
			)
		);
		$this->write_css( 'theme/a.css', '.a{}' );

		Filters\expectApplied( 'arts_runtime/manifest_paths' )->andReturn( array( $manifest ) );

		ComponentScanner::register_component( 'A' );

		$blob = ComponentCssEmitter::generate_coverage_blob();

		// JSON blob inside `<script type="application/json" id="arts-cr-css-coverage">[...]</script>`.
		$matches = array();
		preg_match( '#<script type="application/json" id="arts-cr-css-coverage">(.+?)</script>#', $blob, $matches );
		$this->assertNotEmpty( $matches );
		$this->assertSame( array( 'A' ), json_decode( $matches[1], true ) );
	}
}
