<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Single source of truth for the component-key layouts the framework
 * accepts in a Vite manifest. Both directions of the convention live here:
 *
 *   - `resolve_key()` — given a component name, return the manifest key
 *                       that maps to it (or null if no candidate matches).
 *   - `extract_name()` — given a manifest key, return the component name
 *                        the framework would advertise (or null if the key
 *                        doesn't fit any known layout).
 *
 * Today's accepted layouts:
 *
 *   - Subdirectory — `src/components/<Name>/<Name>.ts(x)` (Velum
 *     convention; component owns a folder with `.ts` + `.sass` + assets).
 *   - Flat — `src/components/<Name>.ts(x)` (prototype convention).
 *
 * Component names may carry forward slashes (e.g. `@velum/Hero` for
 * namespaced product components); concatenation handles them naturally
 * because Vite manifest keys are source paths.
 *
 * Adding a new layout = appending one template to `KEY_TEMPLATES` and
 * extending `EXTENSIONS` if needed; both `resolve_key` and `extract_name`
 * pick it up on the next request.
 */
class ComponentLayoutResolver {
	/**
	 * Candidate templates with `{name}` placeholder. Order matters —
	 * subdirectory wins when both layouts exist for the same name; flat
	 * is only checked when no subdirectory key matches.
	 */
	private const KEY_TEMPLATES = array(
		'src/components/{name}/{name}',
		'src/components/{name}',
	);

	/** Source-file extensions the framework accepts at the manifest-key tail. */
	private const EXTENSIONS = array( 'ts', 'tsx' );

	private function __construct() {}

	/**
	 * Returns the manifest key for `$name`, or null if no candidate matches.
	 *
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

	/**
	 * Returns the component name `$key` advertises, or null if the key
	 * doesn't fit any known layout. Inverts `resolve_key`'s template list.
	 */
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
	 * Expands `$name` into every accepted manifest-key candidate.
	 *
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
	 * Compiles `$template` into a regex anchored at both ends, with `{name}`
	 * captured and any of `EXTENSIONS` matched at the tail. Returns the
	 * captured name or null if `$key` doesn't match.
	 *
	 * Subdirectory templates contain `{name}` twice; the second occurrence
	 * back-references the first via `\1` so basename-must-match-dirname
	 * is enforced — `src/components/Hero/Other.ts` fails to match.
	 *
	 * Splits the template on `{name}` so each non-placeholder segment can be
	 * `preg_quote`d independently; this avoids dealing with the post-quote
	 * `\{name\}` form (PCRE escapes `{` / `}` as quantifier metachars).
	 */
	private static function match_template( string $template, string $key ): ?string {
		$segments = explode( '{name}', $template );
		if ( count( $segments ) < 2 ) {
			return null;
		}
		$parts = array( preg_quote( $segments[0], '#' ) );
		$count = count( $segments );
		for ( $i = 1; $i < $count; $i++ ) {
			$parts[] = ( $i === 1 ) ? '([^/]+)' : '\\1';
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
