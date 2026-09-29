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
 *
 * 1.12.1: the score is computed over the measured checks only, so a scan that could measure 20 of 100
 * could show "100". The public badge now also needs at least MIN_COVERAGE percent of the weight measured.
 */
final class Badge {

	public const DEFAULT_THRESHOLD = 70;
	public const MIN_COVERAGE      = 80;

	/**
	 * Whether the latest scan earns the badge.
	 *
	 * @param ScoreReport|null $latest    Latest scan.
	 * @param int              $threshold Minimum score (clamped to 1–100).
	 */
	public static function earned( ?ScoreReport $latest, int $threshold = self::DEFAULT_THRESHOLD ): bool {
		$score = $latest?->score();
		return null !== $score && $score >= max( 1, min( 100, $threshold ) ) && self::covered( $latest );
	}

	/**
	 * Whether enough of the scan was measured for a public badge.
	 *
	 * @param ScoreReport|null $report Scan.
	 */
	public static function covered( ?ScoreReport $report ): bool {
		$total = null === $report ? 0 : $report->total_weight();
		return $total > 0 && 100 * $report->measured_weight() >= self::MIN_COVERAGE * $total;
	}
}
