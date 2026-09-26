<?php
/**
 * Migration_0_2_0, WpdbHitRepository and upgrade-on-load.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\WordPress\Lifecycle;
use AIHazirSite\WordPress\Migrations\Migration_0_2_0;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Uninstaller;
use AIHazirSite\Core\Measurement\IpRanges;
use AIHazirSite\WordPress\Platform\WpSettings;
use WP_UnitTestCase;

/**
 * Hits table integration tests.
 *
 * @covers \AIHazirSite\WordPress\Migrations\Migration_0_2_0
 * @covers \AIHazirSite\WordPress\Storage\WpdbHitRepository
 * @covers \AIHazirSite\WordPress\Lifecycle::maybe_upgrade
 */
final class HitsTableTest extends WP_UnitTestCase {

	/**
	 * Store under test.
	 *
	 * @var WpdbHitRepository
	 */
	private WpdbHitRepository $store;

	/**
	 * Empties the table (inside the test transaction).
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->store = new WpdbHitRepository();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Real (non-temporary) DDL for tests that drop and create the table.
	 */
	private function allow_real_ddl(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Whether the hits table exists.
	 */
	private function table_exists(): bool {
		global $wpdb;
		return WpdbHitRepository::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The plugin load already migrated the test site.
	 */
	public function test_table_is_created_on_load(): void {
		$this->assertTrue( $this->table_exists() );
		$this->assertSame( 200, ( new Migrator( array( new Migration_0_2_0() ), new WpSettings() ) )->current_version() );
	}

	/**
	 * Rollback drops the table, up() recreates it with the expected columns.
	 */
	public function test_down_drops_and_up_recreates(): void {
		global $wpdb;
		$this->allow_real_ddl();
		$migration = new Migration_0_2_0();

		$migration->down();
		$this->assertFalse( $this->table_exists() );

		$migration->up();
		$this->assertTrue( $this->table_exists() );

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'id', 'day', 'kind', 'source_id', 'path', 'verified', 'hits' ), $columns );
	}

	/**
	 * A site updated from 0.1.0 (no table, db version 0) is migrated on the next load.
	 */
	public function test_update_from_010_migrates_on_load(): void {
		$this->allow_real_ddl();
		( new Migration_0_2_0() )->down();
		update_option( Migrator::OPTION, 0 );

		Lifecycle::maybe_upgrade();

		$this->assertTrue( $this->table_exists() );
		$this->assertSame( 200, (int) get_option( Migrator::OPTION ) );
	}

	/**
	 * With the opt-in on, uninstall drops the table and removes every plugin option.
	 */
	public function test_uninstall_with_opt_in_drops_table(): void {
		$this->allow_real_ddl();
		update_option( Migrator::OPTION, 200 );
		update_option( IpRanges::OPTION, array( 'x' => array() ) );
		update_option( Uninstaller::DELETE_OPTION, '1' );

		$this->assertTrue( Uninstaller::run() );

		$this->assertFalse( $this->table_exists() );
		foreach ( Uninstaller::options() as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}

		( new Migration_0_2_0() )->up();
		update_option( Migrator::OPTION, 200 );
	}

	/**
	 * The same key twice gives one row with hits = 2; other keys get their own row.
	 */
	public function test_increment_upserts_one_row(): void {
		global $wpdb;

		$this->assertTrue( $this->store->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/urunler/', true ) );
		$this->assertTrue( $this->store->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/urunler/', true ) );
		$this->store->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/urunler/', false );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT verified, hits FROM %i ORDER BY verified', WpdbHitRepository::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame(
			array(
				array(
					'verified' => '0',
					'hits'     => '1',
				),
				array(
					'verified' => '1',
					'hits'     => '2',
				),
			),
			$rows
		);
	}

	/**
	 * Query strings are dropped and long paths truncated before storage.
	 */
	public function test_path_is_normalized(): void {
		global $wpdb;

		$this->store->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/a?email=x@example.com', false );
		$this->store->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/' . str_repeat( 'b', 300 ), false );

		$paths = $wpdb->get_col( $wpdb->prepare( 'SELECT path FROM %i ORDER BY id', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame( '/a', $paths[0] );
		$this->assertSame( Hit::PATH_MAX, strlen( $paths[1] ) );
	}

	/**
	 * Pruning deletes only rows older than the cutoff.
	 */
	public function test_prune_deletes_old_rows(): void {
		global $wpdb;

		$this->store->increment( '2025-01-01', Hit::KIND_BOT, 'gptbot', '/', false );
		$this->store->increment( '2026-09-27', Hit::KIND_BOT, 'gptbot', '/', false );

		$this->assertSame( 1, $this->store->prune( '2025-08-23' ) );
		$this->assertSame( array( '2026-09-27' ), $wpdb->get_col( $wpdb->prepare( 'SELECT day FROM %i', WpdbHitRepository::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
