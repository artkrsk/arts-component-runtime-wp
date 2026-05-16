<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Single source of truth for component-key layouts in a Vite manifest.
 * `resolve_key` (name → key), `extract_name` (key → name).
 *
 * Accepted layouts:
 *   - Subdirectory — `src/components/<Name>/<Name>.ts(x)`
 *   - Flat — `src/components/<Name>.ts(x)`
 *
 * Names may carry forward slashes (e.g. `@velum/Hero`).
 */
class ComponentLayoutResolver {
	/** Order matters — subdirectory wins when both layouts exist. */
	private const KEY_TEMPLATES = array(
		'src/components/{name}/{name}',
		'src/components/{name}',
	);

	private const EXTENSIONS = array( 'ts', 'tsx' );

	private function __construct() {}

	/**
	 * @param array<string, array<string, mixed>> $merged
	 */
	public static function resolve_key( array $merged, string $name ): ?string {
		foreach ( self::candidate_keys( $name ) as $key ) {
			if ( isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
				return $key;
			}
		}
		return null;
	}

	public static function extract_name( string $key ): ?string {
		foreach ( self::KEY_TEMPLATES as $template ) {
			$name = self::match_template( $template, $key );
			if ( $name !== null ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * @return string[]
	 */
	private static function candidate_keys( string $name ): array {
		$keys = array();
		foreach ( self::KEY_TEMPLATES as $template ) {
			$base = str_replace( '{name}', $name, $template );
			foreach ( self::EXTENSIONS as $ext ) {
				$keys[] = $base . '.' . $ext;
			}
		}
		return $keys;
	}

	/**
	 * Subdirectory templates back-reference `{name}` via `\1` so
	 * basename-must-match-dirname holds (`src/components/Hero/Other.ts` fails).
	 *
	 * Splits on `{name}` and `preg_quote`s each segment to avoid the post-quote
	 * `\{name\}` form (PCRE escapes `{`/`}` as quantifier metachars).
	 */
	private static function match_template( string $template, string $key ): ?string {
		$segments = explode( '{name}', $template );
		if ( count( $segments ) < 2 ) {
			return null;
		}
		$parts = array( preg_quote( $segments[0], '#' ) );
		$count = count( $segments );
		// Name character class mirrors `ComponentScanner::is_valid_component_name`
		// so vendor-namespaced names like `@vendor/Hero` round-trip through the
		// layout resolver. Greedy match with `\1` back-reference forces the
		// captured name to be consistent across template segments and the
		// trailing `\.(ts|tsx)$` anchor disambiguates where the name ends.
		for ( $i = 1; $i < $count; $i++ ) {
			$parts[] = ( $i === 1 ) ? '([a-zA-Z0-9_@/-]+)' : '\\1';
			$parts[] = preg_quote( $segments[ $i ], '#' );
		}
		$ext_alt = implode( '|', self::EXTENSIONS );
		$regex   = '#^' . implode( '', $parts ) . '\.(?:' . $ext_alt . ')$#';
		if ( preg_match( $regex, $key, $m ) === 1 ) {
			return $m[1];
		}
		return null;
	}
}
