<?php
/**
 * HTTP client backed by the WordPress HTTP API.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

use AIHazirSite\Core\Contracts\HttpClient;

/**
 * Uses wp_remote_get() with a short timeout and an identifying user agent.
 */
final class WpHttpClient implements HttpClient {

	/**
	 * Body of a 200 response, or null.
	 *
	 * @param string $url URL.
	 */
	public function get( string $url ): ?string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'AI Hazir Site/' . ( defined( 'AIHS_VERSION' ) ? AIHS_VERSION : '0' ) . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}
}
