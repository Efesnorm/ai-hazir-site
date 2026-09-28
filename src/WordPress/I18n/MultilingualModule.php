<?php
/**
 * Multilingual catalog (A8).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\I18n;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Module;

/**
 * While `multilingual` (and the catalog) is on: the Çeviriler screen. The channels ask
 * Multilingual::language() themselves, which is null while this feature is off.
 */
final class MultilingualModule implements Module {

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( Features::is_enabled( Features::MULTILINGUAL ) && Features::is_enabled( Features::CATALOG ) && is_admin() ) {
			( new TranslationsAdmin() )->register();
		}
	}

	/**
	 * Nothing scheduled.
	 */
	public function deactivate(): void {
	}
}
