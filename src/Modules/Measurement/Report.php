<?php
/**
 * Aggregated measurement report.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement;

use AIHazirSite\Core\Storage\HitStore;

/**
 * The single source of numbers for the admin page, the CSV export and WP-CLI,
 * so all three always show the same figures.
 *
 * @phpstan-type ReportRow array{section: string, source: string, path: string, verified: int, unverified: int, total: int}
 */
final class Report {

	public const PERIODS = array( 7, 28 );

	public const SECTION_BOTS      = 'bots';
	public const SECTION_PAGES     = 'pages';
	public const SECTION_REFERRALS = 'referrals';

	public const TOP_PAGES = 10;

	/**
	 * Constructor.
	 *
	 * @param int             $days       Number of days including today (at least 1).
	 * @param Classifier|null $classifier Name lookup; loaded from data/ when null.
	 * @param string|null     $today      Y-m-d; defaults to today in the site's timezone.
	 */
	public function __construct(
		private readonly int $days,
		private ?Classifier $classifier = null,
		private readonly ?string $today = null
	) {
	}

	/**
	 * First day included (Y-m-d).
	 */
	public function since(): string {
		$today = $this->today ?? current_time( 'Y-m-d' );
		$days  = max( 1, $this->days );
		return gmdate( 'Y-m-d', (int) strtotime( $today . ' 00:00:00 UTC' ) - ( $days - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * All report rows: bots, then the top pages read by bots, then referrals.
	 *
	 * @return list<ReportRow>
	 */
	public function rows(): array {
		global $wpdb;

		$since = $this->since();
		$table = HitStore::table();
		$sum   = 'SUM(CASE WHEN verified = 1 THEN hits ELSE 0 END) AS verified, SUM(CASE WHEN verified = 1 THEN 0 ELSE hits END) AS unverified, SUM(hits) AS total';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregates on the plugin table; $sum is a constant.
		$bots      = $wpdb->get_results( $wpdb->prepare( "SELECT source_id, {$sum} FROM %i WHERE kind = %s AND day >= %s GROUP BY source_id ORDER BY total DESC, source_id ASC", $table, HitStore::KIND_BOT, $since ), ARRAY_A );
		$pages     = $wpdb->get_results( $wpdb->prepare( "SELECT path, {$sum} FROM %i WHERE kind = %s AND day >= %s GROUP BY path ORDER BY total DESC, path ASC LIMIT %d", $table, HitStore::KIND_BOT, $since, self::TOP_PAGES ), ARRAY_A );
		$referrals = $wpdb->get_results( $wpdb->prepare( "SELECT source_id, {$sum} FROM %i WHERE kind = %s AND day >= %s GROUP BY source_id ORDER BY total DESC, source_id ASC", $table, HitStore::KIND_REFERRAL, $since ), ARRAY_A );
		// phpcs:enable

		$rows = array();
		foreach ( (array) $bots as $row ) {
			$rows[] = $this->row( self::SECTION_BOTS, $this->bot_name( (string) $row['source_id'] ), '', $row );
		}
		foreach ( (array) $pages as $row ) {
			$rows[] = $this->row( self::SECTION_PAGES, '', (string) $row['path'], $row );
		}
		foreach ( (array) $referrals as $row ) {
			$rows[] = $this->row( self::SECTION_REFERRALS, $this->referrer_name( (string) $row['source_id'] ), '', $row );
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
		$days = is_scalar( $value ) ? absint( $value ) : 0;
		return in_array( $days, self::PERIODS, true ) ? $days : self::PERIODS[0];
	}

	/**
	 * Builds one row.
	 *
	 * @param string               $section Section.
	 * @param string               $source  Display name.
	 * @param string               $path    Path.
	 * @param array<string, mixed> $data   Aggregates.
	 * @return ReportRow
	 */
	private function row( string $section, string $source, string $path, array $data ): array {
		return array(
			'section'    => $section,
			'source'     => $source,
			'path'       => $path,
			'verified'   => (int) $data['verified'],
			'unverified' => (int) $data['unverified'],
			'total'      => (int) $data['total'],
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
