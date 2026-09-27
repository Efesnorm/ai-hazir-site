<?php
/**
 * AI bot access check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Checks;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Robots;
use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Core\Measurement\Registry;

/**
 * The robots.txt file does not block the known AI bots (RFC 9309 matching, bot list from data/ai-bots.json)
 * and sample pages answer 2xx to an AI bot user agent.
 */
final class BotAccessCheck implements Check {

	public const USER_AGENT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)';
	public const MAX_PAGES  = 3;

	/**
	 * Id.
	 */
	public function id(): string {
		return 'bot_access';
	}

	/**
	 * Weight.
	 */
	public function weight(): int {
		return 15;
	}

	/**
	 * 0.5 × share of AI bots allowed by robots.txt + 0.5 × share of pages answering 2xx to a bot.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$robots_response = $site->fetch( 'robots.txt' );
		if ( ! $robots_response->reached() ) {
			return CheckResult::unmeasured( 'robots.txt adresine erişilemedi.' );
		}

		$robots  = Robots::from_response( $robots_response );
		$tokens  = array_map( static fn( $bot ): string => $bot->name, Registry::bots() );
		$blocked = array_values( array_filter( $tokens, static fn( string $token ): bool => ! $robots->allows( $token, '/' ) ) );
		$allowed = array() === $tokens ? 1.0 : ( count( $tokens ) - count( $blocked ) ) / count( $tokens );

		$findings = array();
		if ( $robots_response->status >= 500 ) {
			$findings[] = 'robots.txt sunucu hatası veriyor; RFC 9309 gereği botlar tüm siteyi yasak saymalı.';
		}
		if ( array() !== $blocked ) {
			$findings[] = 'robots.txt şu AI botlarını engelliyor: ' . implode( ', ', $blocked ) . '.';
		}

		$reached = 0;
		$ok      = 0;
		foreach ( array_slice( $site->pages(), 0, self::MAX_PAGES ) as $url ) {
			$response = $site->fetch( $url, array( 'User-Agent' => self::USER_AGENT ) );
			if ( ! $response->reached() ) {
				continue;
			}
			++$reached;
			if ( $response->ok() ) {
				++$ok;
			} else {
				$findings[] = sprintf( '%s: AI bot kimliğiyle %d yanıtı dönüyor.', $url, $response->status );
			}
		}

		$ratio = 0 === $reached ? $allowed : 0.5 * $allowed + 0.5 * ( $ok / $reached );

		return CheckResult::measured(
			$ratio,
			$findings,
			'robots.txt\'de AI botlarına izin verin ve güvenlik eklentisi/CDN\'in AI botlarını engellemediğini doğrulayın.'
		);
	}
}
