<?php
/**
 * CSV rendering of the report.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Measurement;

/**
 * Turns report rows into CSV (UTF-8 with BOM so spreadsheet apps show Turkish characters).
 *
 * Labels default to Turkish; platform adapters may pass translated labels.
 *
 * @phpstan-import-type ReportRow from Report
 */
final class CsvExport {

	public const BOM = "\xEF\xBB\xBF";

	public const HEADERS = array( 'Bölüm', 'Kaynak', 'Sayfa', 'Doğrulanmış', 'Doğrulanmamış', 'Toplam' );

	public const SECTION_LABELS = array(
		Report::SECTION_BOTS      => 'Bot',
		Report::SECTION_PAGES     => 'Sayfa',
		Report::SECTION_REFERRALS => 'Yönlendirme',
		Report::SECTION_AI_FILES  => 'AI dosyası',
		Report::SECTION_MCP       => 'MCP',
		Report::SECTION_TEST      => 'Test (hariç tutuldu)',
		Report::SECTION_NETWORK   => 'Ağ yönlendirmesi',
		Report::SECTION_NET_PAGES => 'Ağ yönlendirmesi sayfası',
	);

	/**
	 * CSV document.
	 *
	 * @param array[]                    $rows    Report rows.
	 * @param string[]|null              $headers Six column headers; defaults to self::HEADERS.
	 * @param array<string, string>|null $labels  Section labels; defaults to self::SECTION_LABELS.
	 *
	 * @phpstan-param list<ReportRow> $rows
	 */
	public static function to_csv( array $rows, ?array $headers = null, ?array $labels = null ): string {
		$labels = $labels ?? self::SECTION_LABELS;
		$lines  = array();
		foreach ( $rows as $row ) {
			$lines[] = array(
				$labels[ $row['section'] ] ?? $row['section'],
				$row['source'],
				$row['path'],
				(string) $row['verified'],
				(string) $row['unverified'],
				(string) $row['total'],
			);
		}
		return self::table( $headers ?? self::HEADERS, $lines );
	}

	/**
	 * Any table as CSV (1.24.0 network report): UTF-8 BOM, every cell escaped against formula injection.
	 *
	 * @param string[]   $headers Header row.
	 * @param string[][] $rows    Rows.
	 *
	 * @phpstan-param list<string[]> $rows
	 */
	public static function table( array $headers, array $rows ): string {
		$lines = array( array_values( $headers ) );
		foreach ( $rows as $row ) {
			$lines[] = array_map( array( self::class, 'cell' ), array_values( $row ) );
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
