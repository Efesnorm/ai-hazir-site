<?php
/**
 * One-step return to the previous release.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Updates;

use AIHazirSite\Core\Contracts\PackageInstaller;
use AIHazirSite\Core\Migrations\Migrator;

/**
 * First the database goes back to the target release's schema (each newer migration's down(),
 * newest first), then the target package is installed. This order is required: the older code
 * does not know the newer migrations. If the install fails, the newer code still running migrates
 * the schema forward again on its next load (Lifecycle::maybe_upgrade), so the site keeps working.
 */
final class RollbackService {

	/**
	 * Constructor.
	 *
	 * @param Migrator         $migrator  Schema migrations.
	 * @param PackageInstaller $installer Package installer.
	 */
	public function __construct(
		private readonly Migrator $migrator,
		private readonly PackageInstaller $installer
	) {
	}

	/**
	 * Rolls back to a release.
	 *
	 * @param Release $target Release to return to (older than the installed one).
	 * @return array{ok: bool, reverted: list<int>, error: string}
	 */
	public function rollback( Release $target ): array {
		if ( $target->db_version > $this->migrator->current_version() ) {
			return array(
				'ok'       => false,
				'reverted' => array(),
				'error'    => 'Hedef sürümün veritabanı sürümü mevcut sürümden yeni; geri alma yapılmadı.',
			);
		}
		$reverted = $this->migrator->rollback( $target->db_version );
		$error    = $this->installer->install( $target->download_url );
		return array(
			'ok'       => '' === $error,
			'reverted' => $reverted,
			'error'    => $error,
		);
	}
}
