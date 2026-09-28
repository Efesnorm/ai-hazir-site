<?php
/**
 * Plugin package installer port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Installs a plugin package over the installed plugin (rollback to an earlier release).
 */
interface PackageInstaller {

	/**
	 * Installs the package; returns '' on success, else the reason.
	 *
	 * @param string $package_url Package zip URL.
	 */
	public function install( string $package_url ): string;
}
