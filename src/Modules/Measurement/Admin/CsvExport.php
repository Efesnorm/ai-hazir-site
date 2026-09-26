<?php
/**
 * CSV rendering of the report.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Modules\Measurement\Admin;

use AIHazirSite\Modules\Measurement\Report;

/**
 * Turns report rows into CSV (UTF-8 with BOM so spreadsheet apps show Turkish characters).
 *
 * @phpstan-import-type ReportRow from Report
 */
final class CsvExport {

	public const BOM = "\xEF\xBB\xBF";

	/**
	 * Section labels.
	 *
	 * @return array<string, string>
	 */
	public static function section_labels(): array {
		return array(
			Report::SECTION_BOTS      => __( 'Bot', 'ai-hazir-site' ),
			Report::SECTION_PAGES     => __( 'Sayfa', 'ai-hazir-site' ),
			Report::SECTION_REFERRALS => __( 'Yönlendirme', 'ai-hazir-site' ),
		);
	}

	/**
	 * CSV document.
	 *
	 * @param array[] $rows Report rows.
	 *
	 * @phpstan-param list<ReportRow> $rows
	 */
	public static function to_csv( array $rows ): string {
		$labels = self::section_labels();
		$lines  = array(
			array(
				__( 'Bölüm', 'ai-hazir-site' ),
				__( 'Kaynak', 'ai-hazir-site' ),
				__( 'Sayfa', 'ai-hazir-site' ),
				__( 'Doğrulanmış', 'ai-hazir-site' ),
				__( 'Doğrulanmamış', 'ai-hazir-site' ),
				__( 'Toplam', 'ai-hazir-site' ),
			),
		);
		foreach ( $rows as $row ) {
			$lines[] = array(
				$labels[ $row['section'] ] ?? $row['section'],
				self::cell( $row['source'] ),
				self::cell( $row['path'] ),
				(string) $row['verified'],
				(string) $row['unverified'],
				(string) $row['total'],
			);
		}

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory buffer.
		if ( false === $handle ) {
			return '';
		}
		foreach ( $lines as $line ) {
			fputcsv( $handle, $line, ',', '"', '' );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return self::BOM . $csv;
	}

	/**
	 * Neutralizes spreadsheet formulas (CSV injection) in text cells.
	 *
	 * @param string $value Cell value.
	 */
	private static function cell( string $value ): string {
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}
}
