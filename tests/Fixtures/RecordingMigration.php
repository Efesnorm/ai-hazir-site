<?php
/**
 * Test migration that only records calls.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Fixtures;

use AIHazirSite\Core\Migrations\MigrationInterface;

/**
 * Records `up:<version>` / `down:<version>` in a shared log.
 */
final class RecordingMigration implements MigrationInterface {

	/**
	 * Call log shared by all instances.
	 *
	 * @var list<string>
	 */
	public static array $log = array();

	/**
	 * Constructor.
	 *
	 * @param int $version Migration version.
	 */
	public function __construct( private int $version ) {
	}

	/**
	 * Migration version.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Records the up call.
	 */
	public function up(): void {
		self::$log[] = 'up:' . $this->version;
	}

	/**
	 * Records the down call.
	 */
	public function down(): void {
		self::$log[] = 'down:' . $this->version;
	}
}
