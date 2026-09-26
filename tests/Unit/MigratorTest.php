<?php
/**
 * Unit tests for Migrator (options mocked).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit;

use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Tests\Fixtures\RecordingMigration;
use Brain\Monkey\Functions;
use InvalidArgumentException;

/**
 * Migrator unit tests.
 *
 * @covers \AIHazirSite\Core\Migrations\Migrator
 */
final class MigratorTest extends UnitTestCase {

	/**
	 * In-memory option store.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Mocks the options API with an in-memory array.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->options           = array();
		RecordingMigration::$log = array();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Migrations run in version order regardless of input order.
	 */
	public function test_runs_in_version_order(): void {
		$migrator = new Migrator( array( new RecordingMigration( 3 ), new RecordingMigration( 1 ), new RecordingMigration( 2 ) ) );

		$this->assertSame( array( 1, 2, 3 ), $migrator->migrate() );
		$this->assertSame( array( 'up:1', 'up:2', 'up:3' ), RecordingMigration::$log );
		$this->assertSame( 3, $migrator->current_version() );
	}

	/**
	 * Rollback to a middle version only reverts newer migrations.
	 */
	public function test_rollback_to_target(): void {
		$migrator = new Migrator( array( new RecordingMigration( 1 ), new RecordingMigration( 2 ), new RecordingMigration( 3 ) ) );
		$migrator->migrate();

		$this->assertSame( array( 3, 2 ), $migrator->rollback( 1 ) );
		$this->assertSame( 1, $migrator->current_version() );
	}

	/**
	 * Duplicate versions are rejected.
	 */
	public function test_duplicate_versions_throw(): void {
		$this->expectException( InvalidArgumentException::class );

		new Migrator( array( new RecordingMigration( 1 ), new RecordingMigration( 1 ) ) );
	}

	/**
	 * Non-positive versions are rejected.
	 */
	public function test_non_positive_version_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		new Migrator( array( new RecordingMigration( 0 ) ) );
	}

	/**
	 * With no migrations nothing happens.
	 */
	public function test_empty_list_is_noop(): void {
		$migrator = new Migrator( array() );

		$this->assertSame( array(), $migrator->migrate() );
		$this->assertSame( array(), $migrator->rollback() );
		$this->assertSame( 0, $migrator->current_version() );
	}
}
