<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * JSON blob emitter with `JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES`
 * — every emitted blob is safe to splice verbatim into HTML without escaping
 * the `<`/`&` characters that could otherwise break out of `<script>`.
 */
class JsonBlobEmitter {
	private function __construct() {}

	/**
	 * @param array<string, mixed>|array<int, mixed> $payload JSON-serialisable payload.
	 */
	public static function emit( string $id, array $payload ): string {
		$json = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return '';
		}
		return wp_get_inline_script_tag(
			$json,
			array(
				'id'   => $id,
				'type' => 'application/json',
			)
		);
	}
}
