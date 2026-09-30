<?php
/**
 * Test token in the User-Agent (1.17.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Core\Measurement\TestTraffic;
use PHPUnit\Framework\TestCase;

/**
 * Only the product token marks a request; real bot user agents never match.
 *
 * @covers \AIHazirSite\Core\Measurement\TestTraffic
 */
final class TestTrafficTest extends TestCase {

	/**
	 * Token as a product, any case.
	 */
	public function test_token_detected(): void {
		$this->assertTrue( TestTraffic::is_test( 'Mozilla/5.0 (compatible; GPTBot/1.3; +https://openai.com/gptbot) AIHazirSite-Test/1.0' ) );
		$this->assertTrue( TestTraffic::is_test( 'AIHazirSite-Test/1.0' ) );
		$this->assertTrue( TestTraffic::is_test( 'Mozilla/5.0 (aihazirsite-test/2; compatible)' ) );
	}

	/**
	 * Real user agents and look-alikes do not match.
	 */
	public function test_other_agents_not_detected(): void {
		foreach ( array(
			'',
			'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.3; +https://openai.com/gptbot',
			'AI Hazir Site/tara (uyum taramasi)',
			'XAIHazirSite-Test/1.0',
			'AIHazirSite-Test',
			'Mozilla/5.0 (compatible; AIHazirSite-Tester/1.0)',
		) as $agent ) {
			$this->assertFalse( TestTraffic::is_test( $agent ), $agent );
		}
	}

	/**
	 * Marking appends the token once.
	 */
	public function test_mark(): void {
		$marked = TestTraffic::mark( 'Mozilla/5.0 (compatible; GPTBot/1.3)' );
		$this->assertSame( 'Mozilla/5.0 (compatible; GPTBot/1.3) AIHazirSite-Test/1.0', $marked );
		$this->assertSame( $marked, TestTraffic::mark( $marked ) );
		$this->assertTrue( TestTraffic::is_test( TestTraffic::mark( '' ) ) );
	}
}
