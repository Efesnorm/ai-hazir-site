<?php
/**
 * Migration_0_2_0, HitStore and upgrade-on-load.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\Core\Lifecycle;
use AIHazirSite\Core\Migrations\Migration_0_2_0;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Core\Storage\HitStore;
use WP_UnitTestCase;

/**
 * Hits table integration tests.
 *
 * @covers \AIHazirSite\Core\Migrations\Migration_0_2_0
 * @covers \AIHazirSite\Core\Storage\HitStore
 * @covers \AIHazirSite\Core\Lifecycle::maybe_upgrade
 */
final class HitsTableTest extends WP_UnitTestCase {

	/**
	 * Store under test.
	 *
	 * @var HitStore
	 */
	private HitStore $store;

	/**
	 * Empties the table (inside the test transaction).
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->store = new HitStore();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		return HitStore::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The plugin load already migrated the test site.
	 */
	public function test_table_is_created_on_load(): void {
		$this->assertTrue( $this->table_exists() );
		$this->assertSame( 200, ( new Migrator( array( new Migration_0_2_0() ) ) )->current_version() );
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

		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
	 * The same key twice gives one row with hits = 2; other keys get their own row.
	 */
	public function test_increment_upserts_one_row(): void {
		global $wpdb;

		$this->assertTrue( $this->store->increment( '2026-09-27', HitStore::KIND_BOT, 'gptbot', '/urunler/', true ) );
		$this->assertTrue( $this->store->increment( '2026-09-27', HitStore::KIND_BOT, 'gptbot', '/urunler/', true ) );
		$this->store->increment( '2026-09-27', HitStore::KIND_BOT, 'gptbot', '/urunler/', false );

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT verified, hits FROM %i ORDER BY verified', HitStore::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

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

		$this->store->increment( '2026-09-27', HitStore::KIND_BOT, 'gptbot', '/a?email=x@example.com', false );
		$this->store->increment( '2026-09-27', HitStore::KIND_BOT, 'gptbot', '/' . str_repeat( 'b', 300 ), false );

		$paths = $wpdb->get_col( $wpdb->prepare( 'SELECT path FROM %i ORDER BY id', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame( '/a', $paths[0] );
		$this->assertSame( HitStore::PATH_MAX, strlen( $paths[1] ) );
	}

	/**
	 * Pruning deletes only rows older than the cutoff.
	 */
	public function test_prune_deletes_old_rows(): void {
		global $wpdb;

		$this->store->increment( '2025-01-01', HitStore::KIND_BOT, 'gptbot', '/', false );
		$this->store->increment( '2026-09-27', HitStore::KIND_BOT, 'gptbot', '/', false );

		$this->assertSame( 1, $this->store->prune( '2025-08-23' ) );
		$this->assertSame( array( '2026-09-27' ), $wpdb->get_col( $wpdb->prepare( 'SELECT day FROM %i', HitStore::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
