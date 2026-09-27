<?php
/**
 * Ready-made access policies by bot category.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Access;

use AIHazirSite\Core\Measurement\Bot;
use InvalidArgumentException;

/**
 * Presets over the A0 bot list (data/ai-bots.json) and its categories.
 */
final class Presets {

	public const ALLOW_ALL       = 'allow_all';
	public const SEARCH_AND_USER = 'search_and_user';
	public const BLOCK_TRAINING  = 'block_training';

	public const ALL = array( self::ALLOW_ALL, self::SEARCH_AND_USER, self::BLOCK_TRAINING );

	/**
	 * Policy for a preset.
	 *
	 * - allow_all: every bot allow.
	 * - search_and_user: search and user agents allow, training bots disallow.
	 * - block_training: training bots disallow, others default.
	 *
	 * @param string $preset Preset id.
	 * @param Bot[]  $bots   Known bots.
	 * @throws InvalidArgumentException For an unknown preset.
	 */
	public static function policy( string $preset, array $bots ): BotPolicy {
		$modes = array();
		foreach ( $bots as $bot ) {
			$training          = 'training' === $bot->category;
			$modes[ $bot->id ] = match ( $preset ) {
				self::ALLOW_ALL       => BotPolicy::ALLOW,
				self::SEARCH_AND_USER => $training ? BotPolicy::DISALLOW : BotPolicy::ALLOW,
				self::BLOCK_TRAINING  => $training ? BotPolicy::DISALLOW : BotPolicy::DEFAULT,
				default               => throw new InvalidArgumentException( 'Unknown preset: ' . $preset ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing.
			};
		}
		return new BotPolicy( $modes );
	}
}
