<?php
/**
 * Data removal on uninstall.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress;

use AIHazirSite\Core\Access\PolicyStore;
use AIHazirSite\Core\Compliance\ScanStore;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\Core\Measurement\IpRanges;

/**
 * Deletes plugin data ONLY when the site owner opted in via `aihs_delete_data_on_uninstall`.
 */
final class Uninstaller {

	/**
	 * Opt-in option name.
	 */
	public const DELETE_OPTION = 'aihs_delete_data_on_uninstall';

	/**
	 * Options owned by the plugin.
	 *
	 * @return list<string>
	 */
	public static function options(): array {
		return array(
			Features::OPTION,
			Migrator::OPTION,
			self::DELETE_OPTION,
			IpRanges::OPTION,
			ScanStore::OPTION,
			PolicyStore::OPTION,
		);
	}

	/**
	 * Whether the site owner opted in to data removal.
	 */
	public static function should_delete_data(): bool {
		return wp_validate_boolean( get_option( self::DELETE_OPTION, false ) );
	}

	/**
	 * Removes plugin data if opted in.
	 *
	 * @return bool True when data was removed.
	 */
	public static function run(): bool {
		if ( ! self::should_delete_data() ) {
			return false;
		}

		( new Migrator( Plugin::migrations(), new WpSettings() ) )->rollback( 0 );

		// Listings and the company profile go through the catalog's single write point.
		CatalogModule::service()->purge();

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}

		return true;
	}
}
