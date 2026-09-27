<?php
/**
 * Rules for the robots.txt file, built from the access policy.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Access;

use AIHazirSite\Core\Measurement\Bot;

/**
 * Builds our marked block (ASCII-only markers, safe for any parser) and adds it to an existing robots.txt without touching any other line.
 *
 * Per RFC 9309 §2.2.1 a bot with its own group ignores the "User-agent: *" group, so an "allow"
 * group repeats the platform's protected paths (given by the adapter) instead of opening them.
 */
final class RobotsRules {

	public const BEGIN = '# BEGIN AI Hazir Site - AI bot erisimi';
	public const END   = '# END AI Hazir Site';

	/**
	 * Constructor.
	 *
	 * @param string[] $allow_group_rules Rule lines for an "allow" group, e.g. "Disallow: /wp-admin/".
	 *
	 * @phpstan-param list<string> $allow_group_rules
	 */
	public function __construct( private readonly array $allow_group_rules = array( 'Allow: /' ) ) {
	}

	/**
	 * Our block ('' when every bot is on default). Groups follow the bot list order.
	 *
	 * @param BotPolicy $policy Policy.
	 * @param Bot[]     $bots   Known bots (their names are the robots.txt product tokens).
	 */
	public function block( BotPolicy $policy, array $bots ): string {
		$groups = array();
		foreach ( $bots as $bot ) {
			$mode = $policy->mode( $bot->id );
			if ( BotPolicy::DISALLOW === $mode ) {
				$groups[] = 'User-agent: ' . $bot->name . "\nDisallow: /";
			} elseif ( BotPolicy::ALLOW === $mode ) {
				$groups[] = 'User-agent: ' . $bot->name . "\n" . implode( "\n", $this->allow_group_rules );
			}
		}

		if ( array() === $groups ) {
			return '';
		}
		return self::BEGIN . "\n" . implode( "\n\n", $groups ) . "\n" . self::END . "\n";
	}

	/**
	 * The robots.txt text with our block replaced or appended; all other lines unchanged.
	 *
	 * @param string    $output Existing robots.txt.
	 * @param BotPolicy $policy Policy.
	 * @param Bot[]     $bots   Known bots.
	 */
	public function apply( string $output, BotPolicy $policy, array $bots ): string {
		$base  = self::remove( $output );
		$block = $this->block( $policy, $bots );
		if ( '' === $block ) {
			return $base;
		}
		$base = rtrim( $base, "\n" );
		return ( '' === $base ? '' : $base . "\n\n" ) . $block;
	}

	/**
	 * The robots.txt text without our block.
	 *
	 * @param string $output robots.txt.
	 */
	public static function remove( string $output ): string {
		$pattern = '/\n*' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\n?/su';
		$result  = preg_replace( $pattern, "\n", $output );
		if ( ! is_string( $result ) || $result === $output ) {
			return $output;
		}
		return '' === trim( $result ) ? '' : rtrim( $result, "\n" ) . "\n";
	}
}
