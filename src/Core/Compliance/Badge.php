<?php
/**
 * When the "AI Hazır" badge may be shown.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

/**
 * The badge is earned only by a measured score at or above the threshold (default 70) in the
 * latest scan. No scan, an unmeasured scan or a lower score: no badge at all.
 */
final class Badge {

	public const DEFAULT_THRESHOLD = 70;

	/**
	 * Whether the latest scan earns the badge.
	 *
	 * @param ScoreReport|null $latest    Latest scan.
	 * @param int              $threshold Minimum score (clamped to 1–100).
	 */
	public static function earned( ?ScoreReport $latest, int $threshold = self::DEFAULT_THRESHOLD ): bool {
		$score = $latest?->score();
		return null !== $score && $score >= max( 1, min( 100, $threshold ) );
	}
}
