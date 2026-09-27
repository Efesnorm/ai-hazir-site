<?php
/**
 * WP-CLI: wp aihs scan.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Compliance\Cli;

use AIHazirSite\WordPress\Compliance\ComplianceModule;
use WP_CLI;

/**
 * AI uyum taraması.
 */
final class ScanCommand {

	public const FIELDS  = array( 'id', 'weight', 'ratio', 'points', 'gain', 'level', 'fix' );
	public const FORMATS = array( 'table', 'json' );

	/**
	 * Siteyi tarar, sonucu kaydeder ve gösterir (yönetim sayfasıyla aynı rapor).
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Çıktı biçimi.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--base-url=<url>]
	 * : Site adresi yerine bu adresi tara (yerel geliştirme; ör. http://host.docker.internal:8888).
	 *
	 * ## EXAMPLES
	 *
	 *     wp aihs scan
	 *     wp aihs scan --format=json
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			WP_CLI::error( '--format table veya json olmalı.' );
		}
		$base = $assoc_args['base-url'] ?? null;
		if ( null !== $base && ( false === filter_var( $base, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $base ) ) ) {
			WP_CLI::error( '--base-url geçerli bir adres değil.' );
		}

		$report = ComplianceModule::run( $base );

		if ( 'json' === $format ) {
			WP_CLI::log( (string) wp_json_encode( array( 'score' => $report->score() ) + $report->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$rows = array();
		foreach ( $report->by_gain() as $row ) {
			$rows[] = array(
				'id'     => $row['id'],
				'weight' => $row['weight'],
				'ratio'  => null === $row['ratio'] ? 'ölçülemedi' : (string) round( $row['ratio'], 2 ),
				'points' => $row['points'],
				'gain'   => $row['gain'],
				'level'  => $row['level'],
				'fix'    => $row['fix'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, self::FIELDS );

		$score = $report->score();
		WP_CLI::log(
			sprintf(
				'Puan: %s · ölçülen ağırlık %d/100 · süre %.1f sn · puanlama sürümü %d',
				null === $score ? '–' : $score . '/100',
				$report->measured_weight(),
				$report->elapsed_ms / 1000,
				$report->score_version
			)
		);
	}
}
