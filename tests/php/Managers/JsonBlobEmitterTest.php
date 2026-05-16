<?php
/**
 * JsonBlobEmitter — JSON encoding flags + inline-script wrapper.
 *
 * Stubs `wp_json_encode` with a real-PHP `json_encode` alias (same
 * flag semantics) and `wp_get_inline_script_tag` with a known-shape
 * alias so cells can assert the actual HTML output without relying
 * on WordPress runtime.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\JsonBlobEmitter;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Functions;

final class JsonBlobEmitterTest extends AbstractTestCase {
	protected function setUp(): void {
		parent::setUp();
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

	public function test_JSON_1_encodes_with_hex_tag_hex_amp_unescaped_slashes_flags(): void {
		$out = JsonBlobEmitter::emit( 'x', array( 'html' => "<a href='/foo'>&amp;</a>" ) );

		// JSON_HEX_TAG → '<' / '>' inside the JSON body are escaped to \\u003C / \\u003E.
		$this->assertStringContainsString( '\\u003C', $out );
		$this->assertStringContainsString( '\\u003E', $out );
		$this->assertStringNotContainsString( '"html":"<', $out );

		// JSON_HEX_AMP → '&' inside the JSON body is escaped to \\u0026.
		$this->assertStringContainsString( '\\u0026', $out );

		// JSON_UNESCAPED_SLASHES → '/' is left literal (no '\\/').
		$this->assertStringContainsString( '/foo', $out );
		$this->assertStringNotContainsString( '\\/foo', $out );
	}

	public function test_JSON_2_encode_failure_returns_empty_string(): void {
		// NAN is not JSON-encodable; wp_json_encode returns false → emit() returns ''.
		$out = JsonBlobEmitter::emit( 'broken', array( 'bad' => NAN ) );
		$this->assertSame( '', $out );
	}

	public function test_JSON_3_wraps_payload_in_application_json_script_tag_with_id(): void {
		$out = JsonBlobEmitter::emit( 'my-blob', array( 'ok' => true ) );
		$this->assertStringStartsWith( '<script type="application/json" id="my-blob">', $out );
		$this->assertStringEndsWith( '</script>', $out );
	}
}
