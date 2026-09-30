<?php
/**
 * Aggregated measurement report.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\HitRepository;

/**
 * The single source of numbers for the admin page, the CSV export and the CLI,
 * so all of them always show the same figures.
 *
 * @phpstan-type ReportRow array{section: string, source: string, path: string, verified: int, unverified: int, total: int}
 */
final class Report {

	public const PERIODS = array( 7, 28 );

	public const SECTION_BOTS      = 'bots';
	public const SECTION_PAGES     = 'pages';
	public const SECTION_REFERRALS = 'referrals';
	public const SECTION_AI_FILES  = 'ai_files';
	public const SECTION_MCP       = 'mcp';
	public const SECTION_TEST      = 'test';

	public const TOP_PAGES = 10;

	/**
	 * Paths published for AI agents (llms.txt, the AI catalog page), reported on their own
	 * even when they are not among the top pages. Already counted like any other path.
	 */
	public const AI_FILES = array( '/llms.txt', '/ai-katalog/', '/ai-katalog' );

	/**
	 * Constructor.
	 *
	 * @param HitRepository   $hits       Counter storage.
	 * @param Clock           $clock      Provides today.
	 * @param int             $days       Number of days including today (at least 1).
	 * @param Classifier|null $classifier Name lookup; loaded from data/ when null.
	 */
	public function __construct(
		private readonly HitRepository $hits,
		private readonly Clock $clock,
		private readonly int $days,
		private ?Classifier $classifier = null
	) {
	}

	/**
	 * First day included (Y-m-d).
	 */
	public function since(): string {
		$days = max( 1, $this->days );
		return gmdate( 'Y-m-d', (int) strtotime( $this->clock->today() . ' 00:00:00 UTC' ) - ( $days - 1 ) * 86400 );
	}

	/**
	 * All report rows: bots, then the top pages read by bots, then referrals, then bot
	 * visits to the AI files (only files that were read).
	 *
	 * @return array[]
	 *
	 * @phpstan-return list<ReportRow>
	 */
	public function rows(): array {
		$since = $this->since();
		$rows  = array();

		foreach ( $this->hits->totals( Hit::KIND_BOT, $since, HitRepository::GROUP_SOURCE ) as $totals ) {
			$rows[] = $this->row( self::SECTION_BOTS, $this->bot_name( $totals['key'] ), '', $totals );
		}
		foreach ( $this->hits->totals( Hit::KIND_BOT, $since, HitRepository::GROUP_PATH, self::TOP_PAGES ) as $totals ) {
			$rows[] = $this->row( self::SECTION_PAGES, '', $totals['key'], $totals );
		}
		foreach ( $this->hits->totals( Hit::KIND_REFERRAL, $since, HitRepository::GROUP_SOURCE ) as $totals ) {
			$rows[] = $this->row( self::SECTION_REFERRALS, $this->referrer_name( $totals['key'] ), '', $totals );
		}
		foreach ( $this->hits->totals( Hit::KIND_BOT, $since, HitRepository::GROUP_PATH ) as $totals ) {
			if ( in_array( $totals['key'], self::AI_FILES, true ) ) {
				$rows[] = $this->row( self::SECTION_AI_FILES, '', $totals['key'], $totals );
			}
		}
		foreach ( $this->hits->totals( Hit::KIND_MCP, $since, HitRepository::GROUP_SOURCE ) as $totals ) {
			$rows[] = $this->row( self::SECTION_MCP, $totals['key'], '', $totals );
		}
		// 1.17.0: our own test requests, excluded from everything above (source: bot id, MCP tool or "test").
		foreach ( $this->hits->totals( Hit::KIND_TEST, $since, HitRepository::GROUP_SOURCE ) as $totals ) {
			$rows[] = $this->row( self::SECTION_TEST, $this->bot_name( $totals['key'] ), '', $totals );
		}

		return $rows;
	}

	/**
	 * Rows of one section.
	 *
	 * @param array[] $rows    All rows.
	 * @param string  $section Section.
	 * @return array[]
	 *
	 * @phpstan-param list<ReportRow> $rows
	 * @phpstan-return list<ReportRow>
	 */
	public static function section( array $rows, string $section ): array {
		return array_values( array_filter( $rows, static fn( array $row ): bool => $section === $row['section'] ) );
	}

	/**
	 * Days value from user input: one of self::PERIODS, else the first period.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_period( mixed $value ): int {
		$days = is_numeric( $value ) ? abs( (int) $value ) : 0;
		return in_array( $days, self::PERIODS, true ) ? $days : self::PERIODS[0];
	}

	/**
	 * Builds one row.
	 *
	 * @param string $section Section.
	 * @param string $source  Display name.
	 * @param string $path    Path.
	 * @param array  $totals  Totals.
	 * @return array
	 *
	 * @phpstan-param array{key: string, verified: int, unverified: int, total: int} $totals
	 * @phpstan-return ReportRow
	 */
	private function row( string $section, string $source, string $path, array $totals ): array {
		return array(
			'section'    => $section,
			'source'     => $source,
			'path'       => $path,
			'verified'   => $totals['verified'],
			'unverified' => $totals['unverified'],
			'total'      => $totals['total'],
		);
	}

	/**
	 * Bot display name (the id if the bot was removed from the list).
	 *
	 * @param string $id Bot id.
	 */
	private function bot_name( string $id ): string {
		return $this->classifier()->bot( $id )->name ?? $id;
	}

	/**
	 * Referrer display name (the id if the referrer was removed from the list).
	 *
	 * @param string $id Referrer id.
	 */
	private function referrer_name( string $id ): string {
		foreach ( $this->classifier()->referrers() as $referrer ) {
			if ( $referrer->id === $id ) {
				return $referrer->name;
			}
		}
		return $id;
	}

	/**
	 * Classifier, loaded on first use.
	 */
	private function classifier(): Classifier {
		if ( null === $this->classifier ) {
			$this->classifier = Classifier::from_data();
		}
		return $this->classifier;
	}
}
