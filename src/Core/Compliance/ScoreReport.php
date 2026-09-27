<?php
/**
 * Result of a compliance scan.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

/**
 * Score out of 100 over the measured checks, with per-check detail.
 *
 * @phpstan-type Row array{id: string, weight: int, ratio: float|null, points: float, gain: float, level: string, findings: list<string>, fix: string}
 */
final class ScoreReport {

	/**
	 * Constructor.
	 *
	 * @param string  $scanned_at    ISO 8601 UTC time.
	 * @param int     $score_version Scoring rules version.
	 * @param array[] $results       One row per check, in check order.
	 * @param int     $elapsed_ms    Time spent on requests.
	 *
	 * @phpstan-param list<Row> $results
	 */
	public function __construct(
		public readonly string $scanned_at,
		public readonly int $score_version,
		public readonly array $results,
		public readonly int $elapsed_ms = 0
	) {
	}

	/**
	 * Score 0–100 over the measured checks; null when nothing could be measured.
	 */
	public function score(): ?int {
		$measured = $this->measured_weight();
		if ( 0 === $measured ) {
			return null;
		}
		$points = array_sum( array_column( $this->results, 'points' ) );
		return (int) round( 100 * $points / $measured );
	}

	/**
	 * Sum of the weights of measured checks.
	 */
	public function measured_weight(): int {
		$weight = 0;
		foreach ( $this->results as $row ) {
			if ( null !== $row['ratio'] ) {
				$weight += $row['weight'];
			}
		}
		return $weight;
	}

	/**
	 * Sum of all weights.
	 */
	public function total_weight(): int {
		return (int) array_sum( array_column( $this->results, 'weight' ) );
	}

	/**
	 * Rows ordered for display: largest possible gain first, then unmeasured, then complete.
	 *
	 * @return array[]
	 *
	 * @phpstan-return list<Row>
	 */
	public function by_gain(): array {
		$rows = $this->results;
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$rank = static fn( array $r ): int => null === $r['ratio'] ? 1 : ( $r['gain'] > 0 ? 0 : 2 );
				if ( $rank( $a ) !== $rank( $b ) ) {
					return $rank( $a ) <=> $rank( $b );
				}
				if ( $a['gain'] !== $b['gain'] ) {
					return $b['gain'] <=> $a['gain'];
				}
				return $b['weight'] <=> $a['weight'];
			}
		);
		return $rows;
	}

	/**
	 * Row of one check.
	 *
	 * @param string $id Check id.
	 * @return array|null
	 *
	 * @phpstan-return Row|null
	 */
	public function result( string $id ): ?array {
		foreach ( $this->results as $row ) {
			if ( $id === $row['id'] ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Plain array for storage.
	 *
	 * @return array{scanned_at: string, score_version: int, elapsed_ms: int, results: list<Row>}
	 */
	public function to_array(): array {
		return array(
			'scanned_at'    => $this->scanned_at,
			'score_version' => $this->score_version,
			'elapsed_ms'    => $this->elapsed_ms,
			'results'       => $this->results,
		);
	}

	/**
	 * Restores a stored report; null when the data is not a report.
	 *
	 * @param mixed $data Stored data.
	 */
	public static function from_array( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['scanned_at'], $data['score_version'], $data['results'] ) || ! is_array( $data['results'] ) ) {
			return null;
		}

		$rows = array();
		foreach ( $data['results'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['weight'] ) ) {
				return null;
			}
			$rows[] = array(
				'id'       => (string) $row['id'],
				'weight'   => (int) $row['weight'],
				'ratio'    => isset( $row['ratio'] ) ? (float) $row['ratio'] : null,
				'points'   => (float) ( $row['points'] ?? 0 ),
				'gain'     => (float) ( $row['gain'] ?? 0 ),
				'level'    => (string) ( $row['level'] ?? CheckResult::INFO ),
				'findings' => array_values( array_map( 'strval', (array) ( $row['findings'] ?? array() ) ) ),
				'fix'      => (string) ( $row['fix'] ?? '' ),
			);
		}

		return new self( (string) $data['scanned_at'], (int) $data['score_version'], $rows, (int) ( $data['elapsed_ms'] ?? 0 ) );
	}
}
