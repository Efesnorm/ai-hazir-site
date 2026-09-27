<?php
/**
 * The compliance report's data, from the scan and measurement records only.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Report;

use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Measurement\Report;

/**
 * One structure for the admin view, the PDF and the verification page, so every number shown is
 * the number stored: scores and points come from ScoreReport, measurement figures from the A0
 * Report rows. Nothing is recomputed except differences (latest − first).
 *
 * @phpstan-import-type Row from ScoreReport
 * @phpstan-import-type ReportRow from Report
 */
final class ComplianceReportData {

	/**
	 * Builds the report data.
	 *
	 * @param ScoreReport|null    $first       First scan.
	 * @param ScoreReport|null    $latest      Latest scan.
	 * @param array[]             $measurement A0 report rows (28 days).
	 * @param array<string, bool> $features    Feature key → on.
	 * @param string              $site        Site name.
	 * @param string              $generated   ISO 8601 time of generation.
	 * @return array{site: string, generated_at: string, latest: array{score: int|null, scanned_at: string, score_version: int}|null, first: array{score: int|null, scanned_at: string, score_version: int}|null, score_change: int|null, comparable: bool, checks: list<array{id: string, weight: int, ratio: float|null, points: float, gain: float, level: string, findings: list<string>, fix: string, first_points: float|null, change: float|null}>, measurement: array<string, list<array{source: string, path: string, verified: int, unverified: int, total: int}>>, measurement_totals: array<string, int>, features: list<string>}
	 *
	 * @phpstan-param list<ReportRow> $measurement
	 */
	public static function build( ?ScoreReport $first, ?ScoreReport $latest, array $measurement, array $features, string $site, string $generated ): array {
		$summary = static fn( ?ScoreReport $r ): ?array => null === $r ? null : array(
			'score'         => $r->score(),
			'scanned_at'    => $r->scanned_at,
			'score_version' => $r->score_version,
		);

		$comparable = null !== $first && null !== $latest && $first->score_version === $latest->score_version;
		$checks     = array();
		foreach ( null === $latest ? array() : $latest->results as $row ) {
			$before   = $first?->result( $row['id'] );
			$measured = null !== $row['ratio'] && null !== $before && null !== $before['ratio'];
			$checks[] = array(
				'id'           => $row['id'],
				'weight'       => $row['weight'],
				'ratio'        => $row['ratio'],
				'points'       => $row['points'],
				'gain'         => $row['gain'],
				'level'        => $row['level'],
				'findings'     => $row['findings'],
				'fix'          => $row['fix'],
				'first_points' => null === $before || null === $before['ratio'] ? null : $before['points'],
				'change'       => $comparable && $measured ? round( $row['points'] - $before['points'], 2 ) : null,
			);
		}

		$sections = array( Report::SECTION_BOTS, Report::SECTION_PAGES, Report::SECTION_REFERRALS, Report::SECTION_AI_FILES, Report::SECTION_MCP );
		$grouped  = array();
		$totals   = array();
		foreach ( $sections as $section ) {
			$rows                = Report::section( $measurement, $section );
			$grouped[ $section ] = array_map(
				static fn( array $r ): array => array(
					'source'     => $r['source'],
					'path'       => $r['path'],
					'verified'   => $r['verified'],
					'unverified' => $r['unverified'],
					'total'      => $r['total'],
				),
				$rows
			);
			$totals[ $section ]  = (int) array_sum( array_column( $rows, 'total' ) );
		}

		$latest_score = $latest?->score();
		$first_score  = $first?->score();

		return array(
			'site'               => $site,
			'generated_at'       => $generated,
			'latest'             => $summary( $latest ),
			'first'              => $summary( $first ),
			'score_change'       => $comparable && null !== $latest_score && null !== $first_score ? $latest_score - $first_score : null,
			'comparable'         => $comparable,
			'checks'             => $checks,
			'measurement'        => $grouped,
			'measurement_totals' => $totals,
			'features'           => array_keys( array_filter( $features ) ),
		);
	}
}
