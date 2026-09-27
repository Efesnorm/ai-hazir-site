<?php
/**
 * Request rate limit port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Counts requests per client. The REST API (A5) uses a fixed window; the offer channel (A7)
 * can plug in a stricter implementation behind the same interface.
 */
interface RateLimiter {

	/**
	 * Counts one request of a client.
	 *
	 * @param string $client Client identifier (e.g. IP address; implementations must not store it raw).
	 * @return int|null Null when allowed; otherwise seconds until the client may try again.
	 */
	public function hit( string $client ): ?int;
}
