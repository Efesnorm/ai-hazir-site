<?php
/**
 * AI catalog (A1) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpClock;

/**
 * The post type is always registered so stored listings stay intact;
 * admin screens are added only while the `catalog` feature is on.
 */
final class CatalogModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		PostType::register();
	}

	/**
	 * Nothing scheduled.
	 */
	public function deactivate(): void {
	}

	/**
	 * The catalog write service with the WordPress adapters.
	 */
	public static function service(): CatalogService {
		return new CatalogService( new WpListingRepository(), new WpProfileRepository(), new WpClock() );
	}
}
