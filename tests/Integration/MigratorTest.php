<?php
/**
 * Migrator against the real options table.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Tests\Fixtures\OptionMigration;
use WP_UnitTestCase;

/**
 * Migrator integration tests.
 *
 * @covers \AIHazirSite\Core\Migrations\Migrator
 */
final class MigratorTest extends WP_UnitTestCase {

	/**
	 * Resets the log and stored version.
	 */
	public function set_up(): void {
		parent::set_up();
		OptionMigration::$log = array();
		delete_option( Migrator::OPTION );
	}

	/**
	 * Two migrations: applied in order, not re-applied, reverted with down().
	 */
	public function test_applies_in_order_once_and_rolls_back(): void {
		$first    = new OptionMigration( 1 );
		$second   = new OptionMigration( 2 );
		$migrator = new Migrator( array( $second, $first ) );

		// First run applies both, in version order.
		$this->assertSame( array( 1, 2 ), $migrator->migrate() );
		$this->assertSame( array( 'up:1', 'up:2' ), OptionMigration::$log );
		$this->assertSame( 2, (int) get_option( Migrator::OPTION ) );
		$this->assertSame( 'applied', get_option( $first->option_name() ) );
		$this->assertSame( 'applied', get_option( $second->option_name() ) );

		// Second run applies nothing.
		$this->assertSame( array(), ( new Migrator( array( $first, $second ) ) )->migrate() );
		$this->assertCount( 2, OptionMigration::$log );

		// Rollback reverts newest first and removes the side effects.
		$this->assertSame( array( 2, 1 ), $migrator->rollback() );
		$this->assertSame( array( 'up:1', 'up:2', 'down:2', 'down:1' ), OptionMigration::$log );
		$this->assertSame( 0, (int) get_option( Migrator::OPTION ) );
		$this->assertFalse( get_option( $first->option_name() ) );
		$this->assertFalse( get_option( $second->option_name() ) );
	}
}
