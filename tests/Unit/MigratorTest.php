<?php
/**
 * Unit tests for Migrator (in-memory settings).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit;

use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Tests\Fixtures\RecordingMigration;
use AIHazirSite\Tests\Support\MemorySettings;
use InvalidArgumentException;

/**
 * Migrator unit tests.
 *
 * @covers \AIHazirSite\Core\Migrations\Migrator
 */
final class MigratorTest extends UnitTestCase {

	/**
	 * Settings that store the applied version.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/**
	 * Fresh in-memory settings.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings          = new MemorySettings();
		RecordingMigration::$log = array();
	}

	/**
	 * Migrations run in version order regardless of input order.
	 */
	public function test_runs_in_version_order(): void {
		$migrator = new Migrator( array( new RecordingMigration( 3 ), new RecordingMigration( 1 ), new RecordingMigration( 2 ) ), $this->settings );

		$this->assertSame( array( 1, 2, 3 ), $migrator->migrate() );
		$this->assertSame( array( 'up:1', 'up:2', 'up:3' ), RecordingMigration::$log );
		$this->assertSame( 3, $migrator->current_version() );
		$this->assertTrue( $this->settings->autoload[ Migrator::OPTION ], 'Checked on every request, so autoloaded.' );
	}

	/**
	 * Rollback to a middle version only reverts newer migrations.
	 */
	public function test_rollback_to_target(): void {
		$migrator = new Migrator( array( new RecordingMigration( 1 ), new RecordingMigration( 2 ), new RecordingMigration( 3 ) ), $this->settings );
		$migrator->migrate();

		$this->assertSame( array( 3, 2 ), $migrator->rollback( 1 ) );
		$this->assertSame( 1, $migrator->current_version() );
	}

	/**
	 * Duplicate versions are rejected.
	 */
	public function test_duplicate_versions_throw(): void {
		$this->expectException( InvalidArgumentException::class );

		new Migrator( array( new RecordingMigration( 1 ), new RecordingMigration( 1 ) ), $this->settings );
	}

	/**
	 * Non-positive versions are rejected.
	 */
	public function test_non_positive_version_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		new Migrator( array( new RecordingMigration( 0 ) ), $this->settings );
	}

	/**
	 * With no migrations nothing happens.
	 */
	public function test_empty_list_is_noop(): void {
		$migrator = new Migrator( array(), $this->settings );

		$this->assertSame( array(), $migrator->migrate() );
		$this->assertSame( array(), $migrator->rollback() );
		$this->assertSame( 0, $migrator->current_version() );
	}
}
