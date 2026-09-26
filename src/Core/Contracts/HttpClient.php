<?php
/**
 * Outgoing HTTP port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Outgoing HTTP GET.
 */
interface HttpClient {

	/**
	 * Response body of a successful (200) GET, or null on any failure. Never throws.
	 *
	 * @param string $url URL.
	 */
	public function get( string $url ): ?string;
}
