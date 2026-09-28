<?php
/**
 * The bot_access check with the site's own access policy (U2 → U1).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Compliance;

use AIHazirSite\Core\Access\BotPolicy;
use AIHazirSite\Core\Access\Presets;
use AIHazirSite\Core\Access\RobotsRules;
use AIHazirSite\Core\Compliance\Checks\BotAccessCheck;
use AIHazirSite\Core\Contracts\PageResponse;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\Tests\Support\ComplianceSites;
use AIHazirSite\Tests\Support\FakePageFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Intentional blocks are recognized and not penalized; other blocks still are.
 *
 * @covers \AIHazirSite\Core\Compliance\Checks\BotAccessCheck
 */
final class BotAccessPolicyTest extends TestCase {

	/**
	 * A site whose robots.txt is the given text; pages answer 2xx to bots.
	 *
	 * @param string $robots robots.txt.
	 */
	private static function site( string $robots ): \AIHazirSite\Core\Compliance\Site {
		return ComplianceSites::site(
			new FakePageFetcher(
				array(
					ComplianceSites::BASE                => '<p>x</p>',
					ComplianceSites::BASE . 'robots.txt' => new PageResponse( 200, array(), $robots ),
				)
			)
		);
	}

	/**
	 * Blocking training bots through the setting keeps the full score and explains why.
	 */
	public function test_intentional_blocks_are_not_penalized(): void {
		$bots   = Registry::bots();
		$policy = Presets::policy( Presets::BLOCK_TRAINING, $bots );
		$robots = ( new RobotsRules( array( 'Disallow: /wp-admin/' ) ) )->apply( "User-agent: *\nDisallow: /wp-admin/\n", $policy, $bots );

		$with    = ( new BotAccessCheck( $policy ) )->run( self::site( $robots ) );
		$without = ( new BotAccessCheck() )->run( self::site( $robots ) );

		$this->assertSame( 1.0, $with->ratio );
		$this->assertStringContainsString( 'Bilerek engellendi', implode( ' ', $with->findings ) );
		$this->assertStringContainsString( 'GPTBot', implode( ' ', $with->findings ) );
		$this->assertEqualsWithDelta( 0.5 * 11 / 18 + 0.5, $without->ratio, 0.0001, 'Without the policy the old rule applies (1.4.0: 18 bots, 7 training).' );
	}

	/**
	 * A block the owner did not choose is still penalized, even with a policy present.
	 */
	public function test_unintended_block_still_counts(): void {
		$policy = ( new BotPolicy() )->with( 'gptbot', BotPolicy::DISALLOW );
		$result = ( new BotAccessCheck( $policy ) )->run( self::site( "User-agent: GPTBot\nDisallow: /\n\nUser-agent: ClaudeBot\nDisallow: /\n" ) );

		$this->assertEqualsWithDelta( 0.5 * 16 / 17 + 0.5, $result->ratio, 0.0001, '1.4.0: 18 bots, GPTBot intentionally blocked, ClaudeBot not.' );
		$this->assertStringContainsString( 'engelliyor: ClaudeBot.', implode( ' ', $result->findings ) );
	}

	/**
	 * A policy "disallow" that robots.txt does not actually apply is not treated as intentional.
	 */
	public function test_policy_not_applied_is_not_intentional(): void {
		$policy = ( new BotPolicy() )->with( 'gptbot', BotPolicy::DISALLOW );
		$result = ( new BotAccessCheck( $policy ) )->run( self::site( "User-agent: *\nDisallow: /wp-admin/\n" ) );

		$this->assertSame( 1.0, $result->ratio );
		$this->assertStringNotContainsString( 'Bilerek', implode( ' ', $result->findings ) );
	}

	/**
	 * Pages are fetched as a bot that is not intentionally blocked.
	 */
	public function test_page_probe_uses_an_allowed_bot(): void {
		$fetcher = new FakePageFetcher(
			array(
				ComplianceSites::BASE                => '<p>x</p>',
				ComplianceSites::BASE . 'robots.txt' => new PageResponse( 200, array(), "User-agent: GPTBot\nDisallow: /\n" ),
			)
		);
		( new BotAccessCheck( ( new BotPolicy() )->with( 'gptbot', BotPolicy::DISALLOW ) ) )->run( ComplianceSites::site( $fetcher ) );

		$agents = array_filter( array_map( static fn( array $r ): string => $r['headers']['User-Agent'] ?? '', $fetcher->requests ) );
		$this->assertNotEmpty( $agents );
		foreach ( $agents as $agent ) {
			$this->assertStringNotContainsString( 'GPTBot', $agent );
			$this->assertStringContainsString( 'OAI-SearchBot', $agent );
		}
	}
}
