<?php
/**
 * Which release a site may install.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Updates;

/**
 * Canary rollout: sites on the "pilot" channel see a new release at once; "general" sites
 * see it DELAY_HOURS after its release time. Also picks the rollback target.
 */
final class CanaryPolicy {

	public const PILOT       = 'pilot';
	public const GENERAL     = 'general';
	public const CHANNELS    = array( self::PILOT, self::GENERAL );
	public const DELAY_HOURS = 48;

	/**
	 * The newest release newer than the installed one that this channel may install now, or null.
	 *
	 * @param Release[] $releases Releases (any order).
	 * @param string    $current  Installed version.
	 * @param string    $channel  self::PILOT or self::GENERAL (anything else counts as general).
	 * @param string    $now      ISO 8601 UTC.
	 *
	 * @phpstan-param list<Release> $releases
	 */
	public static function available( array $releases, string $current, string $channel, string $now ): ?Release {
		$now   = (int) strtotime( $now );
		$delay = self::PILOT === $channel ? 0 : self::DELAY_HOURS * 3600;
		$best  = null;
		foreach ( $releases as $release ) {
			if ( version_compare( $release->version, $current, '<=' ) || (int) strtotime( $release->released_at ) + $delay > $now ) {
				continue;
			}
			if ( null === $best || version_compare( $release->version, $best->version, '>' ) ) {
				$best = $release;
			}
		}
		return $best;
	}

	/**
	 * The newest release older than the installed one (the rollback target), or null.
	 *
	 * @param Release[] $releases Releases (any order).
	 * @param string    $current  Installed version.
	 *
	 * @phpstan-param list<Release> $releases
	 */
	public static function previous( array $releases, string $current ): ?Release {
		$best = null;
		foreach ( $releases as $release ) {
			if ( version_compare( $release->version, $current, '<' ) && ( null === $best || version_compare( $release->version, $best->version, '>' ) ) ) {
				$best = $release;
			}
		}
		return $best;
	}
}
