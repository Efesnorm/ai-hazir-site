<?php
/**
 * Fixed-window request limit.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\RateLimit;

use AIHazirSite\Core\Contracts\Cache;
use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\RateLimiter;
use AIHazirSite\Core\Contracts\Secret;

/**
 * At most `$limit` requests per client in each `$window`-second window. The client is kept
 * only as a salted HMAC (never the raw IP), in an expiring cache entry per window.
 * Counting is not atomic: under heavy parallel load a few extra requests may pass (a simple
 * safeguard, not a security boundary).
 */
final class FixedWindowLimiter implements RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param Cache  $cache  Expiring storage.
	 * @param Clock  $clock  Current time.
	 * @param Secret $secret HMAC of the client identifier.
	 * @param int    $limit  Requests allowed per window.
	 * @param int    $window Window length in seconds.
	 * @param string $scope  Separates counters of different channels.
	 */
	public function __construct(
		private readonly Cache $cache,
		private readonly Clock $clock,
		private readonly Secret $secret,
		private readonly int $limit = 60,
		private readonly int $window = 60,
		private readonly string $scope = 'rest'
	) {
	}

	/**
	 * Counts one request; null when allowed, else seconds until the next window.
	 *
	 * @param string $client Client identifier.
	 */
	public function hit( string $client ): ?int {
		$now    = $this->clock->now();
		$window = max( 1, $this->window );
		$slot   = intdiv( $now, $window );
		$left   = ( $slot + 1 ) * $window - $now;
		$key    = 'aihs_rl_' . substr( $this->secret->hmac( $this->scope . '|' . $client ), 0, 24 ) . '_' . $slot;

		$count = (int) $this->cache->get( $key );
		if ( $count >= max( 1, $this->limit ) ) {
			return $left;
		}
		$this->cache->set( $key, $count + 1, $left + 1 );
		return null;
	}
}
