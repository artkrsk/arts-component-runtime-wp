<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Scans rendered HTML for `data-arts-component-name` attributes and
 * accumulates the unique set for the current request. The three emitters
 * read the union at injection time.
 */
class ComponentScanner {
	/** @var array<string, true> */
	private static array $components = array();

	/**
	 * Walks `$html` via `WP_HTML_Tag_Processor` and registers every
	 * unique component name encountered. Skips empty / invalid names.
	 */
	public static function scan( string $html ): void {
		$found = array();
		$p     = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$name = $p->get_attribute( 'data-arts-component-name' );
			if ( is_string( $name ) && $name !== '' && self::is_valid_component_name( $name ) ) {
				$found[ $name ] = true;
			}
		}
		foreach ( array_keys( $found ) as $name ) {
			self::register_component( $name );
		}
	}

	public static function register_component( string $name ): void {
		self::$components[ $name ] = true;
	}

	/** @return string[] */
	public static function get_components(): array {
		return array_keys( self::$components );
	}

	/** Allows `[A-Za-z0-9_@/-]+` — matches the documented `@velum/Hero` convention. */
	private static function is_valid_component_name( string $name ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9_@\/-]+$/', $name );
	}
}
