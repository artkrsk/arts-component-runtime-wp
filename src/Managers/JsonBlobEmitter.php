<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds `<script id="…" type="application/json">` blobs from PHP arrays.
 * Centralises `wp_json_encode` + `wp_get_inline_script_tag` with consistent
 * encoding flags (`JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES`)
 * so every blob is safe to splice verbatim.
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
