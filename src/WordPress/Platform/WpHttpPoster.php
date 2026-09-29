<?php
/**
 * HttpPoster with the WordPress HTTP API.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

use AIHazirSite\Core\Contracts\HttpPoster;
use AIHazirSite\Core\Contracts\HttpStatusPoster;

/**
 * JSON POST with wp_safe_remote_post() (no redirects to internal addresses). The user agent names
 * only the plugin and its version, not the site.
 */
final class WpHttpPoster implements HttpPoster, HttpStatusPoster {

	/**
	 * Posts; true on a 2xx answer.
	 *
	 * @param string $url  URL.
	 * @param string $json JSON body.
	 */
	public function post( string $url, string $json ): bool {
		$code = $this->send( $url, $json );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Posts; the HTTP status code, or 0 without an answer (1.10.0).
	 *
	 * @param string $url  URL.
	 * @param string $json JSON body.
	 */
	public function post_json( string $url, string $json ): int {
		return $this->send( $url, $json );
	}

	/**
	 * The request.
	 *
	 * @param string $url  URL.
	 * @param string $json JSON body.
	 */
	private function send( string $url, string $json ): int {
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'AI Hazir Site/' . ( defined( 'AIHS_VERSION' ) ? AIHS_VERSION : '0' ),
				'headers'    => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'       => $json,
			)
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}
}
