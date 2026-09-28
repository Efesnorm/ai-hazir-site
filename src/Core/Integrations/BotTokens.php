<?php
/**
 * AI bot user-agent tokens for other plugins' exclusion lists.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Integrations;

use AIHazirSite\Core\Measurement\Bot;
use AIHazirSite\Core\Measurement\Registry;

/**
 * The product tokens of the A0 bot list (data/ai-bots.json), e.g. "GPTBot", "ChatGPT-User" (1.8.0).
 * Page-cache plugins match their "never cache user agents" entries as substrings or regex, so only
 * plain tokens (letters, digits, dot, underscore, hyphen) are given to them.
 */
final class BotTokens {

	/**
	 * Tokens, in list order, without duplicates.
	 *
	 * @param list<Bot>|null $bots Bots (default: the bundled list).
	 * @return list<string>
	 */
	public static function all( ?array $bots = null ): array {
		$tokens = array();
		foreach ( $bots ?? Registry::bots() as $bot ) {
			if ( 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $bot->name ) && ! in_array( $bot->name, $tokens, true ) ) {
				$tokens[] = $bot->name;
			}
		}
		return $tokens;
	}
}
