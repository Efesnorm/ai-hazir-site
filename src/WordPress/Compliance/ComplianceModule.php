<?php
/**
 * AI compliance scan (U1) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Compliance;

use AIHazirSite\Core\Compliance\Scanner;
use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Compliance\ScanToken;
use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Compliance\Site;
use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Compliance\Admin\CompliancePage;
use AIHazirSite\WordPress\Compliance\Cli\ScanCommand;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpPageFetcher;
use AIHazirSite\WordPress\Platform\WpSecret;
use AIHazirSite\WordPress\Platform\WpSettings;
use WP_CLI;

/**
 * Registers the admin page and the CLI command while `compliance_scan` is on.
 * Stored scans are kept when the feature is turned off.
 */
final class ComplianceModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::COMPLIANCE_SCAN ) ) {
			return;
		}
		if ( is_admin() ) {
			( new CompliancePage() )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'aihs scan', ScanCommand::class );
		}
	}

	/**
	 * Nothing scheduled.
	 */
	public function deactivate(): void {
	}

	/**
	 * Scans the site, stores and returns the report.
	 *
	 * @param string|null $base_url Address to fetch instead of home_url() (local development).
	 */
	public static function run( ?string $base_url = null ): ScoreReport {
		$report = ( new Scanner( Scanner::default_checks(), new WpClock() ) )->scan( self::site( $base_url ) );
		self::store()->add( $report );
		return $report;
	}

	/**
	 * The site as seen by the scanner.
	 *
	 * @param string|null $base_url Address to fetch instead of home_url().
	 */
	public static function site( ?string $base_url = null ): Site {
		$home  = home_url( '/' );
		$alias = array();
		if ( null !== $base_url && '' !== $base_url ) {
			$host    = (string) wp_parse_url( $home, PHP_URL_HOST );
			$port    = wp_parse_url( $home, PHP_URL_PORT );
			$alias[] = null === $port ? $host : $host . ':' . $port;
		}

		return new Site( $base_url ? $base_url : $home, new WpPageFetcher(), ScanToken::headers( new WpSecret() ), $alias );
	}

	/**
	 * Scan history.
	 */
	public static function store(): ScanStore {
		return new ScanStore( new WpSettings() );
	}
}
