<?php
/**
 * WP-CLI: wp aihs hits report.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Measurement\Cli;

use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use WP_CLI;

/**
 * AI bot ve yönlendirme ölçüm kayıtları.
 */
final class HitsCommand {

	public const FIELDS  = array( 'section', 'source', 'path', 'verified', 'unverified', 'total' );
	public const FORMATS = array( 'table', 'csv' );

	/**
	 * Ölçüm raporunu gösterir (yönetim sayfasıyla aynı sayılar).
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Bugün dahil kaç gün.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--format=<format>]
	 * : Çıktı biçimi.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp aihs hits report --days=7
	 *     wp aihs hits report --days=28 --format=csv > ai-olcum.csv
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function report( array $args, array $assoc_args ): void {
		$days   = absint( $assoc_args['days'] ?? 28 );
		$format = $assoc_args['format'] ?? 'table';

		if ( $days < 1 ) {
			WP_CLI::error( '--days en az 1 olmalı.' );
		}
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			WP_CLI::error( '--format table veya csv olmalı.' );
		}

		WP_CLI\Utils\format_items( $format, ReportPage::report( $days )->rows(), self::FIELDS );
	}
}
