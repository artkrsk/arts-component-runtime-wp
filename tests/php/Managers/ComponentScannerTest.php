<?php
/**
 * ComponentScanner — HTML walk via WP_HTML_Tag_Processor + regex name
 * validation + dedup.
 *
 * SCAN-1/2 hit the private `is_valid_component_name` through reflection
 * (regex is the load-bearing surface; reaching it via scan() would
 * couple the assertion to attribute parsing irrelevant to the regex).
 *
 * SCAN-4/5 use the real WP_HTML_Tag_Processor classmap-loaded from
 * roots/wordpress via bootstrap.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\ComponentScanner;
use Arts\ComponentRuntime\Tests\AbstractTestCase;
use ReflectionClass;
use ReflectionMethod;

final class ComponentScannerTest extends AbstractTestCase {
	protected function setUp(): void {
		parent::setUp();
		$prop = ( new ReflectionClass( ComponentScanner::class ) )->getProperty( 'components' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	private function isValid( string $name ): bool {
		$method = new ReflectionMethod( ComponentScanner::class, 'is_valid_component_name' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $name );
	}

	public function test_SCAN_1_is_valid_accepts_documented_character_classes(): void {
		$this->assertTrue( $this->isValid( 'Hero' ) );
		$this->assertTrue( $this->isValid( 'Foo-Bar_Baz' ) );
		$this->assertTrue( $this->isValid( 'a' ) );
		$this->assertTrue( $this->isValid( '@velum/Hero' ) );
		$this->assertTrue( $this->isValid( 'vendor/scope/Name' ) );
		$this->assertTrue( $this->isValid( 'A1_2-3' ) );
	}

	public function test_SCAN_2_is_valid_rejects_dangerous_and_malformed_inputs(): void {
		$this->assertFalse( $this->isValid( '' ) );
		$this->assertFalse( $this->isValid( '..' ) );
		$this->assertFalse( $this->isValid( '../etc/passwd' ) );
		$this->assertFalse( $this->isValid( '<script>' ) );
		$this->assertFalse( $this->isValid( ' Hero' ) );
		$this->assertFalse( $this->isValid( 'Hero ' ) );
		$this->assertFalse( $this->isValid( ".HiddenStart" ) );
		$this->assertFalse( $this->isValid( 'Hér0' ) ); // accented (outside ASCII class)
		$this->assertFalse( $this->isValid( 'Hero!' ) );
		$this->assertFalse( $this->isValid( "Hero\x00" ) );
		$this->assertFalse( $this->isValid( "Hero\nFoo" ) ); // internal newline
	}

	public function test_SCAN_2b_is_valid_accepts_TRAILING_newline_documenting_php_regex_quirk(): void {
		// PHP's `$` anchor (without `/m`) matches at end-of-string OR just
		// before a single trailing `\n`. Source uses `/^...+$/`, so a name
		// like "Hero\n" passes. Documenting the gap; a future tightening
		// would switch to `\z` and update this cell.
		$this->assertTrue( $this->isValid( "Hero\n" ) );
	}

	public function test_SCAN_3_register_and_get_components_round_trip_with_dedup(): void {
		ComponentScanner::register_component( 'Hero' );
		ComponentScanner::register_component( 'Hero' ); // dup
		ComponentScanner::register_component( 'Foo' );

		$out = ComponentScanner::get_components();
		sort( $out );
		$this->assertSame( array( 'Foo', 'Hero' ), $out );
	}

	public function test_SCAN_4_scan_registers_single_valid_component(): void {
		ComponentScanner::scan( '<div data-arts-component-name="Hero">x</div>' );

		$this->assertSame( array( 'Hero' ), ComponentScanner::get_components() );
	}

	public function test_SCAN_5_scan_mixed_valid_and_invalid_names_registers_only_valid_dedup(): void {
		$html = '<div data-arts-component-name="Hero"></div>'
			. '<div data-arts-component-name="Hero"></div>'
			. '<div data-arts-component-name="Foo"></div>'
			. '<div data-arts-component-name=""></div>'
			. '<div data-arts-component-name="<script>"></div>'
			. '<div data-arts-component-name="..">no</div>';

		ComponentScanner::scan( $html );

		$out = ComponentScanner::get_components();
		sort( $out );
		$this->assertSame( array( 'Foo', 'Hero' ), $out );
	}

	public function test_SCAN_6_scan_empty_html_is_a_noop(): void {
		ComponentScanner::scan( '' );
		$this->assertSame( array(), ComponentScanner::get_components() );
	}
}
