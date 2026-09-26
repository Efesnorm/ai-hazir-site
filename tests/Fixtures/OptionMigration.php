<?php
/**
 * Test migration with a real, reversible side effect.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Fixtures;

use AIHazirSite\Core\Migrations\MigrationInterface;

/**
 * `up()` adds the option `aihs_test_migration_<version>`, `down()` deletes it.
 * Every call is also recorded in a shared log.
 */
final class OptionMigration implements MigrationInterface {

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
	 * Option written by this migration.
	 */
	public function option_name(): string {
		return 'aihs_test_migration_' . $this->version;
	}

	/**
	 * Migration version.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Adds the option.
	 */
	public function up(): void {
		self::$log[] = 'up:' . $this->version;
		add_option( $this->option_name(), 'applied' );
	}

	/**
	 * Deletes the option.
	 */
	public function down(): void {
		self::$log[] = 'down:' . $this->version;
		delete_option( $this->option_name() );
	}
}
