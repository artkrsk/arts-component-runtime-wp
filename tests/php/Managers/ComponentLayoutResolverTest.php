<?php
/**
 * ComponentLayoutResolver — Vite manifest key ↔ component-name mapping.
 *
 * Pure regex + back-reference logic; no WP surface. Tests cover both
 * `extract_name` (key → name) and `resolve_key` (name → key) directions
 * across the subdirectory vs flat layout precedence.
 */

declare(strict_types=1);

namespace Arts\ComponentRuntime\Tests\Managers;

use Arts\ComponentRuntime\Managers\ComponentLayoutResolver;
use Arts\ComponentRuntime\Tests\AbstractTestCase;

final class ComponentLayoutResolverTest extends AbstractTestCase {
	public function test_LAYO_1_extract_name_subdirectory_layout_returns_basename(): void {
		$this->assertSame( 'Hero', ComponentLayoutResolver::extract_name( 'src/components/Hero/Hero.ts' ) );
	}

	public function test_LAYO_2_extract_name_flat_layout_returns_basename(): void {
		$this->assertSame( 'Hero', ComponentLayoutResolver::extract_name( 'src/components/Hero.ts' ) );
	}

	public function test_LAYO_3_subdirectory_back_reference_enforces_match_but_flat_captures_with_slash(): void {
		// Subdirectory template fails: `Hero/Hero.ts` would match, `Hero/Foo.ts` doesn't
		// (the `\1` back-reference requires basename == dirname).
		// Flat template's name character class allows `/` (so `@vendor/Hero` is valid)
		// — `Hero/Foo` qualifies as a vendor-namespace-shaped name, captured as-is.
		// Documents the asymmetry; tightening flat to reject embedded `/` would
		// break vendor-namespaced names round-tripping.
		$this->assertSame(
			'Hero/Foo',
			ComponentLayoutResolver::extract_name( 'src/components/Hero/Foo.ts' )
		);
	}

	public function test_LAYO_4_vendor_namespaced_name_round_trips(): void {
		$this->assertSame(
			'@velum/Hero',
			ComponentLayoutResolver::extract_name( 'src/components/@velum/Hero/@velum/Hero.ts' )
		);
	}

	public function test_LAYO_5_extension_alternation_ts_and_tsx_both_match(): void {
		$this->assertSame( 'Hero', ComponentLayoutResolver::extract_name( 'src/components/Hero/Hero.ts' ) );
		$this->assertSame( 'Hero', ComponentLayoutResolver::extract_name( 'src/components/Hero/Hero.tsx' ) );
		$this->assertSame( 'Hero', ComponentLayoutResolver::extract_name( 'src/components/Hero.tsx' ) );
	}

	public function test_LAYO_6_resolve_key_subdirectory_wins_over_flat_when_both_present(): void {
		$merged = array(
			'src/components/Hero/Hero.ts' => array( 'file' => 'sub.js' ),
			'src/components/Hero.ts'      => array( 'file' => 'flat.js' ),
		);
		$this->assertSame( 'src/components/Hero/Hero.ts', ComponentLayoutResolver::resolve_key( $merged, 'Hero' ) );
	}

	public function test_LAYO_6b_resolve_key_falls_back_to_flat_when_subdirectory_absent(): void {
		$merged = array(
			'src/components/Hero.ts' => array( 'file' => 'flat.js' ),
		);
		$this->assertSame( 'src/components/Hero.ts', ComponentLayoutResolver::resolve_key( $merged, 'Hero' ) );
	}

	public function test_LAYO_6c_resolve_key_returns_null_when_no_candidate_matches(): void {
		$merged = array(
			'src/components/OtherWidget/OtherWidget.ts' => array( 'file' => 'other.js' ),
		);
		$this->assertNull( ComponentLayoutResolver::resolve_key( $merged, 'Hero' ) );
	}

	public function test_LAYO_7_extract_name_malformed_keys_return_null(): void {
		$this->assertNull( ComponentLayoutResolver::extract_name( 'something/random.ts' ) );
		$this->assertNull( ComponentLayoutResolver::extract_name( 'src/components/Hero/' ) );
		$this->assertNull( ComponentLayoutResolver::extract_name( '' ) );
		$this->assertNull( ComponentLayoutResolver::extract_name( 'src/components/Hero/Hero.js' ) );
	}
}
