<?php
/**
 * Hit counter storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Aggregated hit counters. The only way the core writes measurement data.
 *
 * @phpstan-type HitTotals array{key: string, verified: int, unverified: int, total: int}
 */
interface HitRepository {

	public const GROUP_SOURCE = 'source_id';
	public const GROUP_PATH   = 'path';

	/**
	 * Adds one hit.
	 *
	 * @param string $day       Date in Y-m-d.
	 * @param string $kind      Hit kind.
	 * @param string $source_id Bot or referrer id.
	 * @param string $path      Normalized path.
	 * @param bool   $verified  Whether the bot was verified.
	 * @return bool True when written.
	 */
	public function increment( string $day, string $kind, string $source_id, string $path, bool $verified ): bool;

	/**
	 * Deletes rows with a day before `$before_day`.
	 *
	 * @param string $before_day Date in Y-m-d.
	 * @return int Deleted rows.
	 */
	public function prune( string $before_day ): int;

	/**
	 * Sums per source or per path since a day, largest total first (ties by key).
	 *
	 * @param string $kind     Hit kind.
	 * @param string $since    First day included (Y-m-d).
	 * @param string $group_by self::GROUP_SOURCE or self::GROUP_PATH.
	 * @param int    $limit    Maximum rows; 0 = no limit.
	 * @return array[]
	 *
	 * @phpstan-return list<HitTotals>
	 */
	public function totals( string $kind, string $since, string $group_by, int $limit = 0 ): array;
}
