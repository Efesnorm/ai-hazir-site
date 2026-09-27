<?php
/**
 * Inquiry and audit log tables.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Migrations;

use AIHazirSite\Core\Migrations\MigrationInterface;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;

/**
 * Adds `{prefix}aihs_inquiries` (contact data encrypted) and `{prefix}aihs_audit_log`
 * (no content, no contact data, no raw IP). Additive only; down() drops both.
 */
final class Migration_0_12_0 implements MigrationInterface {

	/**
	 * Version number: 0.12.0 → 1200.
	 */
	public function version(): int {
		return 1200;
	}

	/**
	 * Creates the tables.
	 */
	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$inquiries = WpInquiryRepository::table();
		$audit_log = WpAuditRepository::table();

		dbDelta(
			"CREATE TABLE {$inquiries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			kind varchar(16) NOT NULL,
			source varchar(16) NOT NULL,
			channel varchar(16) NOT NULL,
			listing_id bigint(20) unsigned DEFAULT NULL,
			subject varchar(200) NOT NULL DEFAULT '',
			message text NOT NULL,
			contact text NOT NULL,
			status varchar(16) NOT NULL,
			spam_score tinyint(3) unsigned NOT NULL DEFAULT 0,
			spam_reasons text NOT NULL,
			client_hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY client_created (client_hash,created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$audit_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			at datetime NOT NULL,
			channel varchar(16) NOT NULL,
			client_hash varchar(64) NOT NULL DEFAULT '',
			action varchar(16) NOT NULL,
			outcome varchar(16) NOT NULL,
			inquiry_id bigint(20) unsigned DEFAULT NULL,
			detail varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY at (at)
			) {$charset};"
		);
	}

	/**
	 * Drops the tables.
	 */
	public function down(): void {
		global $wpdb;

		foreach ( array( WpInquiryRepository::table(), WpAuditRepository::table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Migration rollback.
		}
	}
}
