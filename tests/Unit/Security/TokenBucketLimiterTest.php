<?php
/**
 * Tests for TokenBucketLimiter.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Security;

use AIHazirSite\Core\Security\TokenBucketLimiter;
use AIHazirSite\Tests\Support\MemoryCache;
use AIHazirSite\Tests\Support\MovableClock;
use AIHazirSite\Tests\Support\StaticSecret;
use PHPUnit\Framework\TestCase;

/**
 * Burst, steady refill, separate clients and scopes, no raw client stored.
 *
 * @covers \AIHazirSite\Core\Security\TokenBucketLimiter
 */
final class TokenBucketLimiterTest extends TestCase {

	/**
	 * 3 a minute: a burst of 3, then one token every 20 s.
	 */
	public function test_minute_bucket(): void {
		$cache   = new MemoryCache();
		$clock   = new MovableClock();
		$limiter = new TokenBucketLimiter( $cache, $clock, new StaticSecret(), 3, 60, 'inquiry-minute' );

		$this->assertNull( $limiter->hit( '203.0.113.5' ) );
		$this->assertNull( $limiter->hit( '203.0.113.5' ) );
		$this->assertNull( $limiter->hit( '203.0.113.5' ) );
		$this->assertSame( 20, $limiter->hit( '203.0.113.5' ) );
		$this->assertNull( $limiter->hit( '198.51.100.7' ), 'Another client has its own bucket.' );

		$clock->advance( 19 );
		$this->assertSame( 1, $limiter->hit( '203.0.113.5' ) );
		$clock->advance( 1 );
		$this->assertNull( $limiter->hit( '203.0.113.5' ), 'One token back after 20 s.' );
		$this->assertIsInt( $limiter->hit( '203.0.113.5' ) );

		$clock->advance( 3600 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertNull( $limiter->hit( '203.0.113.5' ), 'Never more than the capacity.' );
		}
		$this->assertIsInt( $limiter->hit( '203.0.113.5' ) );
	}

	/**
	 * 20 a day: the 21st request of the day waits about 72 minutes; scopes are separate; no raw IP in keys or values.
	 */
	public function test_day_bucket_and_privacy(): void {
		$cache = new MemoryCache();
		$clock = new MovableClock();
		$day   = new TokenBucketLimiter( $cache, $clock, new StaticSecret(), 20, 86400, 'inquiry-day' );

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertNull( $day->hit( '203.0.113.5' ) );
		}
		$this->assertSame( 4320, $day->hit( '203.0.113.5' ) );

		$minute = new TokenBucketLimiter( $cache, $clock, new StaticSecret(), 3, 60, 'inquiry-minute' );
		$this->assertNull( $minute->hit( '203.0.113.5' ), 'Other scope, other bucket.' );

		$this->assertStringNotContainsString( '203.0.113.5', (string) json_encode( $cache->values ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		foreach ( array_keys( $cache->values ) as $key ) {
			$this->assertStringStartsWith( 'aihs_tb_', $key );
		}
	}
}
