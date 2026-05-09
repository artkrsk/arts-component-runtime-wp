<?php

namespace Arts\ComponentRuntime\Managers;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Inverts an absolute asset URL to the local filesystem path it points at,
 * by stripping `WP_CONTENT_URL` and prepending `WP_CONTENT_DIR`. CDN-
 * rewritten URLs have no local path and return null; this is the expected
 * signal for callers to skip disk reads (e.g. inline-CSS emission).
 */
class WpContentPathInverter {
	private function __construct() {}

	/**
	 * Returns the local filesystem path for `$url`, or null when the URL
	 * doesn't live under `WP_CONTENT_URL` (CDN-rewritten) or the resolved
	 * file no longer exists on disk (rebuilt / stale URL).
	 */
	public static function url_to_local_path( string $url ): ?string {
		// Strip query string — vite-hashed URLs shouldn't carry one, but
		// defensive parse keeps `?ver=...` from leaking into the disk path.
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
