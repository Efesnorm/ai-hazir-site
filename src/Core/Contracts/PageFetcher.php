<?php
/**
 * Outgoing HTTP GET with status and headers.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Fetches a URL and reports status, headers, body and time spent. Never throws.
 */
interface PageFetcher {

	/**
	 * Fetches a URL (GET, follows up to 3 redirects).
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Request headers.
	 * @param int                   $timeout Timeout in seconds.
	 */
	public function fetch( string $url, array $headers = array(), int $timeout = 5 ): PageResponse;
}
