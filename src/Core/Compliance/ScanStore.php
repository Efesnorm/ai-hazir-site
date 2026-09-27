<?php
/**
 * History of compliance scans.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

use AIHazirSite\Core\Contracts\Settings;

/**
 * Keeps the last 20 reports, newest first, in the `aihs_scans` setting (not autoloaded).
 * A setting is enough: the data is small (20 × a few KB), never queried, and needs no migration.
 */
final class ScanStore {

	public const OPTION = 'aihs_scans';
	public const KEEP   = 20;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Storage.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Adds a report and drops the oldest beyond KEEP.
	 *
	 * @param ScoreReport $report Report.
	 */
	public function add( ScoreReport $report ): void {
		$stored = $this->settings->get( self::OPTION, array() );
		$stored = is_array( $stored ) ? array_values( $stored ) : array();
		array_unshift( $stored, $report->to_array() );
		$this->settings->set( self::OPTION, array_slice( $stored, 0, self::KEEP ), false );
	}

	/**
	 * Reports, newest first (unreadable entries skipped).
	 *
	 * @return list<ScoreReport>
	 */
	public function all(): array {
		$stored  = $this->settings->get( self::OPTION, array() );
		$reports = array();
		foreach ( is_array( $stored ) ? $stored : array() as $data ) {
			$report = ScoreReport::from_array( $data );
			if ( null !== $report ) {
				$reports[] = $report;
			}
		}
		return $reports;
	}

	/**
	 * Newest report.
	 */
	public function latest(): ?ScoreReport {
		return $this->all()[0] ?? null;
	}

	/**
	 * Report before the newest one.
	 */
	public function previous(): ?ScoreReport {
		return $this->all()[1] ?? null;
	}
}
