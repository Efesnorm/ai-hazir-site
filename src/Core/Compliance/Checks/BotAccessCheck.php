<?php
/**
 * AI bot access check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Checks;

use AIHazirSite\Core\Access\BotPolicy;
use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Robots;
use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Core\Measurement\Registry;

/**
 * The robots.txt file does not block the known AI bots (RFC 9309 matching, bot list from data/ai-bots.json)
 * and sample pages answer 2xx to an AI bot user agent.
 *
 * Bots the site owner blocked on purpose with the AI bot access setting (U2) are reported as
 * "bilerek engellendi" and left out of the score (score_version 2).
 */
final class BotAccessCheck implements Check {

	public const USER_AGENT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)';
	public const MAX_PAGES  = 3;

	/**
	 * Constructor.
	 *
	 * @param BotPolicy|null $policy The site's own access policy (null when the setting is off).
	 */
	public function __construct( private readonly ?BotPolicy $policy = null ) {
	}

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
	 * 0.5 × share of AI bots allowed by robots.txt (intentional blocks excluded)
	 * + 0.5 × share of pages answering 2xx to a bot that is not intentionally blocked.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$robots_response = $site->fetch( 'robots.txt' );
		if ( ! $robots_response->reached() ) {
			return CheckResult::unmeasured( 'robots.txt adresine erişilemedi.' );
		}

		$robots      = Robots::from_response( $robots_response );
		$intentional = array();
		$blocked     = array();
		$counted     = 0;
		$user_agent  = null;
		foreach ( Registry::bots() as $bot ) {
			$on_purpose = null !== $this->policy && $this->policy->is_disallowed( $bot->id );
			$is_blocked = ! $robots->allows( $bot->name, '/' );
			if ( $on_purpose && $is_blocked ) {
				$intentional[] = $bot->name;
				continue;
			}
			++$counted;
			if ( $is_blocked ) {
				$blocked[] = $bot->name;
			}
			if ( null === $user_agent && ! $on_purpose ) {
				$user_agent = 'GPTBot' === $bot->name ? self::USER_AGENT : 'Mozilla/5.0 (compatible; ' . $bot->name . '/1.0)';
			}
		}
		$allowed = 0 === $counted ? 1.0 : ( $counted - count( $blocked ) ) / $counted;

		$findings = array();
		if ( $robots_response->status >= 500 ) {
			$findings[] = 'robots.txt sunucu hatası veriyor; RFC 9309 gereği botlar tüm siteyi yasak saymalı.';
		}
		if ( array() !== $blocked ) {
			$findings[] = 'robots.txt şu AI botlarını engelliyor: ' . implode( ', ', $blocked ) . '.';
		}
		if ( array() !== $intentional ) {
			$findings[] = 'Bilerek engellendi (AI Bot Erişimi ayarı; puandan düşülmedi): ' . implode( ', ', $intentional ) . '.';
		}

		$reached = 0;
		$ok      = 0;
		foreach ( null === $user_agent ? array() : array_slice( $site->pages(), 0, self::MAX_PAGES ) as $url ) {
			$response = $site->fetch( $url, array( 'User-Agent' => $user_agent ) );
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
			'robots.txt\'de AI botlarına izin verin (veya bilinçli engelleri AI Bot Erişimi ayarından yapın) ve güvenlik eklentisi/CDN\'in AI botlarını engellemediğini doğrulayın.'
		);
	}
}
