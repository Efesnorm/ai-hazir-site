<?php
/**
 * Inquiry tables, encryption and retention inside WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Inquiry;

use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryContact;
use AIHazirSite\WordPress\Inquiry\InquiryModule;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Migrations\Migration_0_12_0;
use AIHazirSite\WordPress\Platform\SodiumCipher;
use WP_UnitTestCase;

/**
 * Migration, repositories, encryption at rest, retention purge.
 *
 * @covers \AIHazirSite\WordPress\Migrations\Migration_0_12_0
 * @covers \AIHazirSite\WordPress\Inquiry\WpInquiryRepository
 * @covers \AIHazirSite\WordPress\Inquiry\WpAuditRepository
 * @covers \AIHazirSite\WordPress\Inquiry\InquiryModule
 * @covers \AIHazirSite\WordPress\Platform\SodiumCipher
 */
final class InquiryStorageTest extends WP_UnitTestCase {

	/**
	 * Empty tables.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( array( WpInquiryRepository::table(), WpAuditRepository::table() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( 'aihs_inquiry_settings' );
	}

	/**
	 * An inquiry created at a given time.
	 *
	 * @param string $created_at ISO 8601 UTC.
	 * @param string $email      Contact e-mail.
	 */
	private static function inquiry( string $created_at, string $email = 'satinalma@alici.example' ): Inquiry {
		return new Inquiry( null, 'quote_request', 'ai', 'mcp', null, 'Kablo', 'NYY kablo için teklif rica ederiz.', new InquiryContact( 'Ayşe', 'Alıcı A.Ş.', $email, '+90 212 555 00 00' ), 'new', 10, array( 'Bağlantı içeriyor.' ), str_repeat( 'a', 64 ), $created_at );
	}

	/**
	 * Both tables exist; down() drops them and up() brings them back.
	 */
	public function test_migration(): void {
		global $wpdb;
		// Real (non-temporary) DDL: the WordPress test case otherwise turns DROP/CREATE into temporary tables.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$exists = static fn( string $table ): bool => $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame( 1200, ( new Migration_0_12_0() )->version() );
		$this->assertTrue( $exists( WpInquiryRepository::table() ) );
		$this->assertTrue( $exists( WpAuditRepository::table() ) );

		( new Migration_0_12_0() )->down();
		$this->assertFalse( $exists( WpInquiryRepository::table() ) );
		$this->assertFalse( $exists( WpAuditRepository::table() ) );

		( new Migration_0_12_0() )->up();
		$this->assertTrue( $exists( WpInquiryRepository::table() ) );
	}

	/**
	 * Round trip; the contact column holds no readable personal data.
	 */
	public function test_round_trip_and_encryption(): void {
		global $wpdb;
		$repository = new WpInquiryRepository();
		$stored     = $repository->store_inquiry( self::inquiry( '2026-09-27T12:00:00Z' ) );

		$found = $repository->find( (int) $stored->id );
		$this->assertNotNull( $found );
		$this->assertSame( array( 'Ayşe', 'Alıcı A.Ş.', 'satinalma@alici.example', '+90 212 555 00 00' ), array_values( $found->contact->to_array() ) );
		$this->assertSame( array( '2026-09-27T12:00:00Z', array( 'Bağlantı içeriyor.' ), null ), array( $found->created_at, $found->spam_reasons, $found->listing_id ) );

		$raw = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT contact FROM %i WHERE id = %d', WpInquiryRepository::table(), $stored->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Each value holds a character outside the base64 alphabet ("@", " ", "ş"), so a random
		// ciphertext can never contain it by chance.
		foreach ( array( 'satinalma@alici.example', 'Ayşe', '+90 212 555 00 00', 'Alıcı A.Ş.' ) as $plain ) {
			$this->assertStringNotContainsString( $plain, $raw );
		}
		$this->assertNull( SodiumCipher::decrypt( 'bozuk' ) );
		$this->assertNull( SodiumCipher::decrypt( base64_encode( str_repeat( 'x', 64 ) ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertTrue( $repository->change_status( (int) $stored->id, 'quarantine' ) );
		$this->assertSame( array( (int) $stored->id ), array_map( static fn( Inquiry $i ): int => (int) $i->id, $repository->list_inquiries( 'quarantine' ) ) );
		$this->assertSame( array(), $repository->list_inquiries( 'new' ) );
		$this->assertSame( 1, $repository->count_recent( str_repeat( 'a', 64 ), '2026-09-27T11:00:00Z' ) );
		$this->assertSame( 0, $repository->count_recent( str_repeat( 'a', 64 ), '2026-09-27T12:00:01Z' ) );
	}

	/**
	 * The daily purge deletes inquiries (with contact data) and audit entries past 180 days.
	 */
	public function test_retention_purge(): void {
		$repository = new WpInquiryRepository();
		$audit      = new WpAuditRepository();
		$day        = static fn( int $days ): string => gmdate( 'Y-m-d\TH:i:s\Z', time() - $days * DAY_IN_SECONDS );

		$old    = $repository->store_inquiry( self::inquiry( $day( 181 ), 'eski@alici.example' ) );
		$recent = $repository->store_inquiry( self::inquiry( $day( 179 ), 'yeni@alici.example' ) );
		foreach ( array( 181, 179 ) as $days ) {
			$audit->append(
				array(
					'at'          => $day( $days ),
					'channel'     => 'rest',
					'client_hash' => str_repeat( 'b', 64 ),
					'action'      => 'submit',
					'outcome'     => 'accepted',
					'inquiry_id'  => null,
					'detail'      => 'gun=' . $days,
				)
			);
		}

		InquiryModule::purge();

		$this->assertNull( $repository->find( (int) $old->id ), '181 days: deleted.' );
		$this->assertSame( 'yeni@alici.example', $repository->find( (int) $recent->id )?->contact->email, '179 days: kept.' );
		$details = array_column( $audit->latest(), 'detail' );
		$this->assertContains( 'gun=179', $details );
		$this->assertNotContains( 'gun=181', $details );
		$this->assertContains( 'count=1', $details, 'The purge itself is audited.' );

		update_option( 'aihs_inquiry_settings', array( 'retention_days' => 30 ) );
		InquiryModule::purge();
		$this->assertNull( $repository->find( (int) $recent->id ), 'A shorter retention applies at the next purge.' );
	}
}
