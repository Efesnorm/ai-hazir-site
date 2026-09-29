<?php
/**
 * Keeps rate-limit tests inside one window.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

/**
 * The limiters count per clock minute (FixedWindowLimiter, 60 s). Requests that straddle a minute
 * boundary land in two windows and are all allowed, which made the rate-limit tests fail now and then
 * (since 0.10.0/0.11.0). Starting with at least 5 seconds left in the minute keeps a test's few requests
 * in one window.
 */
final class RateLimitWindow {

	/**
	 * Waits for the next minute when fewer than 5 seconds are left in this one.
	 */
	public static function away_from_edge(): void {
		$left = 60 - ( time() % 60 );
		if ( $left < 5 ) {
			sleep( $left );
		}
	}
}
