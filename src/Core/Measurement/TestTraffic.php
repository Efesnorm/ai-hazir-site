<?php
/**
 * Test requests marked in the User-Agent (1.17.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

/**
 * Our own tests (live checks, bin/tara, the A2A demo) add the product token "AIHazirSite-Test/<version>"
 * (RFC 9110 §10.1.5 product syntax) to their User-Agent, e.g.
 * "Mozilla/5.0 (compatible; GPTBot/1.3; +https://openai.com/gptbot) AIHazirSite-Test/1.0". The measurement
 * then counts them apart from real traffic, like the "internal traffic" filter of web analytics. Real bots
 * never send the token; anyone who does only removes their own requests from the statistics.
 */
final class TestTraffic {

	public const TOKEN = 'AIHazirSite-Test';

	/**
	 * Whether a User-Agent carries the test token as a product (not inside another word).
	 *
	 * @param string $user_agent User-Agent header.
	 */
	public static function is_test( string $user_agent ): bool {
		return 1 === preg_match( '#(?:^|[\s;(])' . preg_quote( self::TOKEN, '#' ) . '/#i', $user_agent );
	}

	/**
	 * The User-Agent with the test token appended (for our tools).
	 *
	 * @param string $user_agent User-Agent.
	 * @param string $version    Token version.
	 */
	public static function mark( string $user_agent, string $version = '1.0' ): string {
		return self::is_test( $user_agent ) ? $user_agent : trim( $user_agent . ' ' . self::TOKEN . '/' . $version );
	}
}
