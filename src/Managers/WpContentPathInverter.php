<?php

declare(strict_types=1);

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Inverts an absolute asset URL to its local filesystem path. CDN-rewritten
 * URLs return null — callers use that signal to skip disk reads.
 */
class WpContentPathInverter {
	private function __construct() {}

	public static function url_to_local_path( string $url ): ?string {
		// Defensive parse keeps `?ver=...` from leaking into the disk path.
		$query_pos = strpos( $url, '?' );
		if ( $query_pos !== false ) {
			$url = substr( $url, 0, $query_pos );
		}

		$content_url = content_url();
		if ( strpos( $url, $content_url . '/' ) !== 0 ) {
			return null;
		}

		$relative   = ltrim( substr( $url, strlen( $content_url ) ), '/' );
		$local_path = wp_normalize_path( WP_CONTENT_DIR . '/' . $relative );
		if ( ! file_exists( $local_path ) ) {
			return null;
		}
		return $local_path;
	}
}
