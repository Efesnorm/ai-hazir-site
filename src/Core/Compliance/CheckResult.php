<?php
/**
 * Outcome of one check.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

/**
 * Ratio 0–1 of the check's weight earned, or null when the check could not be measured
 * (e.g. the site was unreachable). Unmeasured checks are left out of the score.
 */
final class CheckResult {

	public const INFO    = 'info';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Constructor.
	 *
	 * @param float|null $ratio    0–1, or null when not measured.
	 * @param string[]   $findings What was found.
	 * @param string     $fix      How to improve.
	 * @param string     $level    self::INFO, self::WARNING or self::ERROR.
	 *
	 * @phpstan-param list<string> $findings
	 */
	private function __construct(
		public readonly ?float $ratio,
		public readonly array $findings,
		public readonly string $fix,
		public readonly string $level
	) {
	}

	/**
	 * A measured result. The level follows the ratio: 1 → info, ≥ 0.5 → warning, otherwise error.
	 *
	 * @param float    $ratio    0–1 (clamped).
	 * @param string[] $findings Findings.
	 * @param string   $fix      Fix suggestion ('' when nothing to fix).
	 *
	 * @phpstan-param list<string> $findings
	 */
	public static function measured( float $ratio, array $findings = array(), string $fix = '' ): self {
		$ratio = max( 0.0, min( 1.0, $ratio ) );
		$level = self::ERROR;
		if ( $ratio >= 1.0 ) {
			$level = self::INFO;
		} elseif ( $ratio >= 0.5 ) {
			$level = self::WARNING;
		}
		return new self( $ratio, $findings, $ratio >= 1.0 ? '' : $fix, $level );
	}

	/**
	 * A check that could not be measured; it does not count against the score.
	 *
	 * @param string $reason Why.
	 */
	public static function unmeasured( string $reason ): self {
		return new self( null, array( $reason ), '', self::INFO );
	}

	/**
	 * Whether the check was measured.
	 */
	public function is_measured(): bool {
		return null !== $this->ratio;
	}
}
