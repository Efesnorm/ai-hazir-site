<?php
/**
 * Token bucket request limit.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Security;

use AIHazirSite\Core\Contracts\Cache;
use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\RateLimiter;
use AIHazirSite\Core\Contracts\Secret;

/**
 * Each client has a bucket of `$capacity` tokens that refills evenly over `$period` seconds;
 * a request takes one token. A full bucket allows short bursts, the refill rate caps the average
 * (e.g. 3 a minute, 20 a day). The client is kept only as a salted HMAC, never raw.
 * Counting is not atomic (a simple safeguard, not a security boundary).
 */
final class TokenBucketLimiter implements RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param Cache  $cache    Expiring storage.
	 * @param Clock  $clock    Current time.
	 * @param Secret $secret   HMAC of the client.
	 * @param int    $capacity Tokens in a full bucket (at least 1).
	 * @param int    $period   Seconds to refill a full bucket (at least 1).
	 * @param string $scope    Separates buckets of different limits.
	 */
	public function __construct(
		private readonly Cache $cache,
		private readonly Clock $clock,
		private readonly Secret $secret,
		private readonly int $capacity,
		private readonly int $period,
		private readonly string $scope
	) {
	}

	/**
	 * Takes a token; null when allowed, else seconds until one token is back.
	 *
	 * @param string $client Client identifier.
	 */
	public function hit( string $client ): ?int {
		$capacity = max( 1, $this->capacity );
		$rate     = $capacity / max( 1, $this->period );
		$now      = $this->clock->now();
		$key      = 'aihs_tb_' . substr( $this->secret->hmac( $this->scope . '|' . $client ), 0, 24 );

		$state  = $this->cache->get( $key );
		$tokens = is_array( $state ) && isset( $state['tokens'], $state['at'] ) ? (float) $state['tokens'] : (float) $capacity;
		$at     = is_array( $state ) && isset( $state['at'] ) ? (int) $state['at'] : $now;
		$tokens = min( (float) $capacity, $tokens + max( 0, $now - $at ) * $rate );

		if ( $tokens < 1.0 ) {
			$this->cache->set( $key, array( 'tokens' => $tokens, 'at' => $now ), max( 1, $this->period ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			return (int) ceil( ( 1.0 - $tokens ) / $rate );
		}
		$this->cache->set( $key, array( 'tokens' => $tokens - 1.0, 'at' => $now ), max( 1, $this->period ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		return null;
	}
}
