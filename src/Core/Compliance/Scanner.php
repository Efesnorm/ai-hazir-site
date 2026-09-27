<?php
/**
 * Runs the compliance checks and computes the score.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

use AIHazirSite\Core\Contracts\Clock;
use InvalidArgumentException;
use Throwable;

/**
 * Score = 100 × Σ(weight × ratio) / Σ(weight of measured checks).
 * A check that throws or cannot measure is reported as unmeasured and left out.
 */
final class Scanner {

	/**
	 * Version of the scoring rules; bump when checks or weights change.
	 */
	public const SCORE_VERSION = 1;

	/**
	 * Checks in display order.
	 *
	 * @var list<Check>
	 */
	private array $checks;

	/**
	 * Constructor.
	 *
	 * @param Check[] $checks Checks.
	 * @param Clock   $clock  Timestamps the report.
	 * @throws InvalidArgumentException On duplicate ids or non-positive weights.
	 *
	 * @phpstan-param list<Check> $checks
	 */
	public function __construct( array $checks, private readonly Clock $clock ) {
		$ids = array();
		foreach ( $checks as $check ) {
			if ( isset( $ids[ $check->id() ] ) ) {
				throw new InvalidArgumentException( 'Duplicate check id: ' . $check->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing.
			}
			if ( $check->weight() < 1 ) {
				throw new InvalidArgumentException( 'Check weight must be positive: ' . $check->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing.
			}
			$ids[ $check->id() ] = true;
		}
		$this->checks = $checks;
	}

	/**
	 * Checks in display order.
	 *
	 * @return list<Check>
	 */
	public function checks(): array {
		return $this->checks;
	}

	/**
	 * Scans the site.
	 *
	 * @param Site $site Site.
	 */
	public function scan( Site $site ): ScoreReport {
		$rows = array();
		foreach ( $this->checks as $check ) {
			try {
				$result = $check->run( $site );
			} catch ( Throwable $e ) {
				$result = CheckResult::unmeasured( 'Kontrol çalışırken hata oluştu: ' . $e->getMessage() );
			}

			$weight = $check->weight();
			$points = null === $result->ratio ? 0.0 : round( $weight * $result->ratio, 2 );
			$rows[] = array(
				'id'       => $check->id(),
				'weight'   => $weight,
				'ratio'    => $result->ratio,
				'points'   => $points,
				'gain'     => null === $result->ratio ? 0.0 : round( $weight - $points, 2 ),
				'level'    => $result->level,
				'findings' => $result->findings,
				'fix'      => $result->fix,
			);
		}

		return new ScoreReport( gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() ), self::SCORE_VERSION, $rows, $site->elapsed_ms() );
	}
}
