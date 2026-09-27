<?php
/**
 * Tests for FixedWindowLimiter.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\RateLimit;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\RateLimit\FixedWindowLimiter;
use AIHazirSite\Tests\Support\MemoryCache;
use AIHazirSite\Tests\Support\StaticSecret;
use PHPUnit\Framework\TestCase;

/**
 * Limit per window, per client and per scope; no raw client identifier stored.
 *
 * @covers \AIHazirSite\Core\RateLimit\FixedWindowLimiter
 */
final class FixedWindowLimiterTest extends TestCase {

	/**
	 * A clock whose time the test moves.
	 *
	 * @return Clock&object{time: int}
	 */
	private static function clock(): Clock {
		return new class() implements Clock {
			/**
			 * Unix time.
			 *
			 * @var int
			 */
			public int $time = 1790000050;

			/**
			 * Day.
			 */
			public function today(): string {
				return gmdate( 'Y-m-d', $this->time );
			}

			/**
			 * Time.
			 */
			public function now(): int {
				return $this->time;
			}
		};
	}

	/**
	 * The (limit+1)-th request in a window is refused with the seconds left; the next window starts fresh.
	 */
	public function test_limit_and_retry_after(): void {
		$cache   = new MemoryCache();
		$clock   = self::clock();
		$limiter = new FixedWindowLimiter( $cache, $clock, new StaticSecret(), 3, 60 );

		$this->assertNull( $limiter->hit( '203.0.113.5' ) );
		$this->assertNull( $limiter->hit( '203.0.113.5' ) );
		$this->assertNull( $limiter->hit( '203.0.113.5' ) );
		$this->assertSame( 50, $limiter->hit( '203.0.113.5' ), '10 s into the window: 50 s left.' );
		$this->assertNull( $limiter->hit( '198.51.100.7' ), 'Other clients have their own counter.' );

		$clock->time += 50;
		$this->assertNull( $limiter->hit( '203.0.113.5' ), 'New window.' );
	}

	/**
	 * Scopes are separate and the raw IP never appears in the cache.
	 */
	public function test_scope_and_no_raw_ip(): void {
		$cache = new MemoryCache();
		$clock = self::clock();
		$rest  = new FixedWindowLimiter( $cache, $clock, new StaticSecret(), 1, 60, 'rest' );
		$offer = new FixedWindowLimiter( $cache, $clock, new StaticSecret(), 1, 60, 'offer' );

		$this->assertNull( $rest->hit( '203.0.113.5' ) );
		$this->assertNull( $offer->hit( '203.0.113.5' ) );
		$this->assertIsInt( $rest->hit( '203.0.113.5' ) );

		$this->assertCount( 2, $cache->values );
		foreach ( array_keys( $cache->values ) as $key ) {
			$this->assertStringStartsWith( 'aihs_rl_', $key );
			$this->assertStringNotContainsString( '203.0.113.5', $key );
			$this->assertLessThanOrEqual( 172, strlen( $key ), 'Fits a WordPress transient name.' );
		}
		$this->assertSame( array( 51, 51 ), array_values( $cache->ttl ) );
	}
}
