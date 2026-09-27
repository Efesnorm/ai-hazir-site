<?php
/**
 * PageFetcher backed by the WordPress HTTP API.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Platform;

use AIHazirSite\Core\Contracts\PageFetcher;
use AIHazirSite\Core\Contracts\PageResponse;

/**
 * Uses wp_remote_get(); response bodies are capped at 2 MB.
 */
final class WpPageFetcher implements PageFetcher {

	public const MAX_BYTES = 2097152;

	/**
	 * Fetches a URL.
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Timeout in seconds.
	 */
	public function fetch( string $url, array $headers = array(), int $timeout = 5 ): PageResponse {
		$start    = hrtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 3,
				'headers'             => $headers,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => $headers['User-Agent'] ?? 'AI Hazir Site/' . ( defined( 'AIHS_VERSION' ) ? AIHS_VERSION : '0' ) . ' (uyum taramasi)',
			)
		);
		$elapsed  = (int) ( ( hrtime( true ) - $start ) / 1e6 );

		if ( is_wp_error( $response ) ) {
			return PageResponse::failed( $response->get_error_message(), $elapsed );
		}

		$headers_out = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers_out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new PageResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			$headers_out,
			wp_remote_retrieve_body( $response ),
			$elapsed
		);
	}
}
