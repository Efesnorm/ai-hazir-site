<?php
/**
 * PackageInstaller with WordPress's Plugin_Upgrader.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Updates;

use AIHazirSite\Core\Contracts\PackageInstaller;

/**
 * Installs a package over the installed plugin the way the "upload plugin → replace current"
 * screen does: Plugin_Upgrader::install() with overwrite_package (WordPress 5.5+), quietly.
 */
final class WpPackageInstaller implements PackageInstaller {

	/**
	 * Installs; '' on success, else the reason.
	 *
	 * @param string $package_url Package zip URL.
	 */
	public function install( string $package_url ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $package_url, array( 'overwrite_package' => true ) );
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		return true === $result ? '' : __( 'Paket kurulamadı.', 'ai-hazir-site' );
	}
}
