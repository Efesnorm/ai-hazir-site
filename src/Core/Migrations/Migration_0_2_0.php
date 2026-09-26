<?php
/**
 * 0.2.0: AI measurement table.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Migrations;

use AIHazirSite\Core\Storage\HitStore;

/**
 * Creates `{prefix}aihs_hits`: one row per day, kind, source, path and verification state.
 *
 * The unique key includes `path` (up to 191 characters) and needs the InnoDB
 * DYNAMIC row format (default since MySQL 5.7 / MariaDB 10.2).
 */
final class Migration_0_2_0 implements MigrationInterface {

	/**
	 * Version number: 0.2.0 → 200.
	 */
	public function version(): int {
		return 200;
	}

	/**
	 * Creates the table.
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = HitStore::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			kind varchar(16) NOT NULL,
			source_id varchar(64) NOT NULL,
			path varchar(191) NOT NULL,
			verified tinyint(1) unsigned NOT NULL DEFAULT 0,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY day_kind_source_path_verified (day,kind,source_id,path,verified),
			KEY day (day)
			) {$charset};"
		);
	}

	/**
	 * Drops the table.
	 */
	public function down(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Migration rollback.
	}
}
