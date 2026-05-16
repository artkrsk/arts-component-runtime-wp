<?php
/**
 * ComponentDiscovery — charset-meta splice detection, head-anchor
 * fallback, process() guards (empty / re-pass / CLEAN phase),
 * register() short-circuits.
 *
 * Private methods (find_charset_meta_end, inject_after_charset,
 * inject_before_head_close) covered via reflection. The static `$emitted`
 * flag is reset between cells.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\ComponentDiscovery;
use Arts\ComponentRuntime\Managers\ComponentScanner;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ReflectionClass;
use ReflectionMethod;

final class ComponentDiscoveryTest extends AbstractTestCase {
	protected function setUp(): void {
		parent::setUp();

		// Reset $emitted.
		$prop = ( new ReflectionClass( ComponentDiscovery::class ) )->getProperty( 'emitted' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );

		// Reset ComponentScanner so cells using process() start clean.
		$scanner = ( new ReflectionClass( ComponentScanner::class ) )->getProperty( 'components' );
		$scanner->setAccessible( true );
		$scanner->setValue( null, array() );
	}

	private static function callPrivate( string $method, array $args ): mixed {
		$ref = new ReflectionMethod( ComponentDiscovery::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	// ──── find_charset_meta_end (DISC-1 .. DISC-4) ────

	public function test_DISC_1_find_charset_meta_end_happy_path_returns_offset_after_tag(): void {
		$html   = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>x</title></head></html>';
		$offset = self::callPrivate( 'find_charset_meta_end', array( $html ) );

		$this->assertIsInt( $offset );
		// Offset should be at the end of the charset meta tag (just after `>`).
		$this->assertSame( '<title>', substr( $html, $offset, 7 ) );
	}

	public function test_DISC_2_find_charset_meta_end_skips_charset_inside_html_comment(): void {
		$html   = '<!DOCTYPE html><html><head><!-- old: <meta charset="latin1"> --><meta charset="utf-8"></head></html>';
		$offset = self::callPrivate( 'find_charset_meta_end', array( $html ) );

		$this->assertIsInt( $offset );
		// Must have skipped the commented-out match; the splice point
		// is after the REAL (post-comment) charset meta — so substr from
		// $offset should NOT contain the live charset meta in its tail.
		$tail = substr( $html, $offset );
		$this->assertStringNotContainsString( '<meta charset="utf-8"', $tail );
	}

	public function test_DISC_3_find_charset_meta_end_regex_does_NOT_require_charset_to_be_an_attribute_name(): void {
		// The source regex `<meta\b[^>]*\bcharset\b[^>]*>` only checks that
		// the word `charset` appears anywhere inside the `<meta ...>` tag's
		// attribute area — not that it's an attribute NAME. A tag like
		// `<meta name="x" content="charset stuff">` matches.
		//
		// In practice this is harmless (auto-injection picks a splice point
		// either way) but the cell documents the actual behaviour so a
		// future tightening is intentional, not accidental.
		$html   = '<!DOCTYPE html><html><head><meta name="x" content="charset stuff"></head></html>';
		$offset = self::callPrivate( 'find_charset_meta_end', array( $html ) );

		$this->assertIsInt( $offset );
	}

	public function test_DISC_4_find_charset_meta_end_charset_past_2KB_window_returns_null(): void {
		// 2 KB of padding before the charset → outside the search window.
		$padding = str_repeat( '<!-- padding -->', 200 ); // ~3 KB
		$html    = '<!DOCTYPE html><html><head>' . $padding . '<meta charset="utf-8"></head></html>';

		$offset = self::callPrivate( 'find_charset_meta_end', array( $html ) );
		$this->assertNull( $offset );
	}

	public function test_DISC_5_find_charset_meta_end_no_meta_returns_null(): void {
		$html   = '<!DOCTYPE html><html><head><title>no charset here</title></head></html>';
		$offset = self::callPrivate( 'find_charset_meta_end', array( $html ) );
		$this->assertNull( $offset );
	}

	// ──── inject_before_head_close (DISC-6) ────

	public function test_DISC_6_inject_before_head_close_splices_before_close_tag(): void {
		$html = '<head><title>x</title></head><body></body>';
		$out  = self::callPrivate( 'inject_before_head_close', array( $html, '<!-- PAYLOAD -->' ) );

		$this->assertSame( '<head><title>x</title><!-- PAYLOAD --></head><body></body>', $out );
	}

	public function test_DISC_6b_inject_before_head_close_returns_html_unchanged_when_no_close_tag(): void {
		$html = '<head><title>x</title><body></body>';
		$out  = self::callPrivate( 'inject_before_head_close', array( $html, '<!-- PAYLOAD -->' ) );

		$this->assertSame( $html, $out );
	}

	// ──── process() guards (DISC-7, DISC-8) ────

	public function test_DISC_7_process_empty_buffer_returns_empty(): void {
		$this->assertSame( '', ComponentDiscovery::process( '' ) );
	}

	public function test_DISC_7b_process_after_emitted_returns_buffer_unchanged_for_litespeed_re_pass(): void {
		// Force $emitted = true (simulates a previous successful emission).
		$prop = ( new ReflectionClass( ComponentDiscovery::class ) )->getProperty( 'emitted' );
		$prop->setAccessible( true );
		$prop->setValue( null, true );

		$html = '<head><meta charset="utf-8"></head>';
		$this->assertSame( $html, ComponentDiscovery::process( $html ) );
	}

	public function test_DISC_8_process_with_PHP_OUTPUT_HANDLER_CLEAN_phase_returns_buffer_unchanged(): void {
		$html = '<head><meta charset="utf-8"></head>';
		$this->assertSame( $html, ComponentDiscovery::process( $html, PHP_OUTPUT_HANDLER_CLEAN ) );
	}

	// ──── register() short-circuits (DISC-9, DISC-10) ────

	public function test_DISC_9_register_short_circuits_when_is_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Actions\expectAdded( 'template_redirect' )->never();

		ComponentDiscovery::register();
	}

	public function test_DISC_9b_register_short_circuits_when_wp_doing_cron(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Actions\expectAdded( 'template_redirect' )->never();

		ComponentDiscovery::register();
	}

	public function test_DISC_10_register_short_circuits_when_auto_discover_filter_returns_false(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Filters\expectApplied( 'arts_runtime/auto_discover' )->andReturn( false );
		Actions\expectAdded( 'template_redirect' )->never();

		ComponentDiscovery::register();
	}

	public function test_DISC_10b_register_adds_template_redirect_action_in_normal_request(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Filters\expectApplied( 'arts_runtime/auto_discover' )->andReturn( true );

		Actions\expectAdded( 'template_redirect' )->once();

		ComponentDiscovery::register();
	}
}
