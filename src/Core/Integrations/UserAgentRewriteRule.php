<?php
/**
 * The LiteSpeed rewrite rule that keeps AI bots out of the server cache.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Integrations;

/**
 * LiteSpeed serves and stores nothing from its cache for a request that gets the environment
 * variable Cache-Control:no-cache from a rewrite rule (https://docs.litespeedtech.com/lscache/devguide/controls/);
 * LiteSpeed Cache's "Do Not Cache User Agents" writes this same rule. Platform-neutral (1.14.0).
 */
final class UserAgentRewriteRule {

	public const FIRST_LINE = '# AI Hazir Site: AI botlari icin sunucu onbellegi yok (baslangic)';
	public const LAST_LINE  = '# AI Hazir Site: AI botlari icin sunucu onbellegi yok (son)';

	/**
	 * The rule lines for these user-agent tokens (none without tokens).
	 *
	 * @param string[] $tokens Plain tokens, e.g. "GPTBot" (BotTokens::all()).
	 * @return list<string>
	 */
	public static function lines( array $tokens ): array {
		$quoted = array();
		foreach ( $tokens as $token ) {
			if ( '' !== $token ) {
				$quoted[] = preg_quote( $token, '/' );
			}
		}
		if ( array() === $quoted ) {
			return array();
		}
		return array(
			self::FIRST_LINE,
			'<IfModule LiteSpeed>',
			'RewriteEngine On',
			'RewriteCond %{HTTP_USER_AGENT} (' . implode( '|', array_values( array_unique( $quoted ) ) ) . ') [NC]',
			'RewriteRule .* - [E=Cache-Control:no-cache]',
			'</IfModule>',
			self::LAST_LINE,
		);
	}
}
