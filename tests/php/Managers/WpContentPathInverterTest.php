<?php
/**
 * WpContentPathInverter — URL-to-local-path with query strip + prefix
 * check + file_exists gate.
 *
 * `file_exists` is NOT stubbed — uses real temp files so the gate
 * behaves authentically. Each test sets up its own subdir under
 * WP_CONTENT_DIR and `content_url()` is stubbed to return a fixed
 * prefix.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\WpContentPathInverter;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Functions;

final class WpContentPathInverterTest extends AbstractTestCase {
	private const URL_PREFIX = 'https://example.test/wp-content';

	protected function setUp(): void {
		parent::setUp();
		if ( ! is_dir( WP_CONTENT_DIR ) ) {
			mkdir( WP_CONTENT_DIR, 0777, true );
		}
		Functions\when( 'content_url' )->justReturn( self::URL_PREFIX );
		Functions\when( 'wp_normalize_path' )->alias(
			static fn( string $path ): string => str_replace( '\\', '/', $path )
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
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	public function test_INV_1_happy_path_returns_local_path_for_existing_file_under_content_url(): void {
		$rel = 'themes/foo/assets/main.js';
		$abs = WP_CONTENT_DIR . '/' . $rel;
		mkdir( dirname( $abs ), 0777, true );
		file_put_contents( $abs, '// js' );

		$out = WpContentPathInverter::url_to_local_path( self::URL_PREFIX . '/' . $rel );
		$this->assertSame( str_replace( '\\', '/', $abs ), $out );
	}

	public function test_INV_2_query_string_stripped_before_file_lookup(): void {
		$rel = 'themes/foo/x.js';
		$abs = WP_CONTENT_DIR . '/' . $rel;
		mkdir( dirname( $abs ), 0777, true );
		file_put_contents( $abs, '// js' );

		$out = WpContentPathInverter::url_to_local_path( self::URL_PREFIX . '/' . $rel . '?ver=2' );
		$this->assertSame( str_replace( '\\', '/', $abs ), $out );
	}

	public function test_INV_3_url_outside_content_url_prefix_returns_null(): void {
		$out = WpContentPathInverter::url_to_local_path( 'https://cdn.example.test/some/asset.js' );
		$this->assertNull( $out );
	}

	public function test_INV_4_url_with_traversal_dots_returns_null_when_resolved_path_missing(): void {
		// CAVEAT: this cell does NOT validate that `..` traversal is safe.
		// The source's contract is the prefix check (INV-3) plus the
		// file_exists gate (INV-5). With `..` segments the prefix check
		// passes; the kernel then resolves the path during file_exists,
		// and we get null today purely because `themes/` isn't materialized
		// in this cell's setUp — if a future change created the directory,
		// the kernel would walk up and could resolve to a real file outside
		// WP_CONTENT_DIR (e.g. /etc/passwd). A real traversal-safety
		// guarantee would need path canonicalization in the source.
		$out = WpContentPathInverter::url_to_local_path( self::URL_PREFIX . '/themes/../../../etc/passwd' );
		$this->assertNull( $out );
	}

	public function test_INV_5_missing_file_returns_null_even_with_valid_prefix(): void {
		$out = WpContentPathInverter::url_to_local_path( self::URL_PREFIX . '/themes/nonexistent/missing.js' );
		$this->assertNull( $out );
	}

	public function test_INV_6_empty_url_returns_null(): void {
		$out = WpContentPathInverter::url_to_local_path( '' );
		$this->assertNull( $out );
	}
}
