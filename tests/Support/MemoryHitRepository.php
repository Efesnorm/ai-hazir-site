<?php
/**
 * In-memory HitRepository for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\HitRepository;

/**
 * Same semantics as the database table: one row per (day, kind, source, path, verified).
 */
final class MemoryHitRepository implements HitRepository {

	/**
	 * Rows keyed by the unique key.
	 *
	 * @var array<string, array{day: string, kind: string, source_id: string, path: string, verified: bool, hits: int}>
	 */
	public array $rows = array();

	/**
	 * Adds one hit.
	 *
	 * @param string $day       Day.
	 * @param string $kind      Kind.
	 * @param string $source_id Source.
	 * @param string $path      Path.
	 * @param bool   $verified  Verified.
	 */
	public function increment( string $day, string $kind, string $source_id, string $path, bool $verified ): bool {
		$key = implode( "\0", array( $day, $kind, $source_id, $path, $verified ? '1' : '0' ) );
		if ( ! isset( $this->rows[ $key ] ) ) {
			$this->rows[ $key ] = array(
				'day'       => $day,
				'kind'      => $kind,
				'source_id' => $source_id,
				'path'      => $path,
				'verified'  => $verified,
				'hits'      => 0,
			);
		}
		++$this->rows[ $key ]['hits'];
		return true;
	}

	/**
	 * Deletes old rows.
	 *
	 * @param string $before_day Day.
	 */
	public function prune( string $before_day ): int {
		$before     = count( $this->rows );
		$this->rows = array_filter( $this->rows, static fn( array $row ): bool => $row['day'] >= $before_day );
		return $before - count( $this->rows );
	}

	/**
	 * Totals.
	 *
	 * @param string $kind     Kind.
	 * @param string $since    First day.
	 * @param string $group_by Group column.
	 * @param int    $limit    Limit.
	 * @return array[]
	 *
	 * @phpstan-return list<array{key: string, verified: int, unverified: int, total: int}>
	 */
	public function totals( string $kind, string $since, string $group_by, int $limit = 0 ): array {
		$totals = array();
		foreach ( $this->rows as $row ) {
			if ( $row['kind'] !== $kind || $row['day'] < $since ) {
				continue;
			}
			$key = self::GROUP_PATH === $group_by ? $row['path'] : $row['source_id'];
			if ( ! isset( $totals[ $key ] ) ) {
				$totals[ $key ] = array(
					'key'        => $key,
					'verified'   => 0,
					'unverified' => 0,
					'total'      => 0,
				);
			}
			$totals[ $key ][ $row['verified'] ? 'verified' : 'unverified' ] += $row['hits'];
			$totals[ $key ]['total']                                        += $row['hits'];
		}

		$totals = array_values( $totals );
		usort(
			$totals,
			static function ( array $a, array $b ): int {
				$by_total = $b['total'] <=> $a['total'];
				return 0 !== $by_total ? $by_total : strcmp( $a['key'], $b['key'] );
			}
		);

		return $limit > 0 ? array_slice( $totals, 0, $limit ) : $totals;
	}
}
