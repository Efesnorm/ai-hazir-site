<?php
/**
 * Inquiry storage in `{prefix}aihs_inquiries`.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Inquiry;

use AIHazirSite\Core\Contracts\InquiryRepository;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryContact;
use AIHazirSite\WordPress\Platform\SodiumCipher;

/**
 * The only code that writes the inquiries table (called only by InquiryService). The contact
 * data is stored encrypted (SodiumCipher); times are stored as UTC datetimes.
 */
final class WpInquiryRepository implements InquiryRepository {

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aihs_inquiries';
	}

	/**
	 * Stores.
	 *
	 * @param Inquiry $inquiry Inquiry.
	 */
	public function store_inquiry( Inquiry $inquiry ): Inquiry {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table.
			self::table(),
			array(
				'created_at'   => self::to_db( $inquiry->created_at ),
				'kind'         => $inquiry->kind,
				'source'       => $inquiry->source,
				'channel'      => $inquiry->channel,
				'listing_id'   => $inquiry->listing_id,
				'subject'      => $inquiry->subject,
				'message'      => $inquiry->message,
				'contact'      => SodiumCipher::encrypt( (string) wp_json_encode( $inquiry->contact->to_array() ) ),
				'status'       => $inquiry->status,
				'spam_score'   => $inquiry->spam_score,
				'spam_reasons' => (string) wp_json_encode( $inquiry->spam_reasons ),
				'client_hash'  => $inquiry->client_hash,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return $inquiry->with_id( (int) $wpdb->insert_id );
	}

	/**
	 * By id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Inquiry {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? self::from_row( $row ) : null;
	}

	/**
	 * Newest first.
	 *
	 * @param string $status Status filter.
	 * @param string $source Source filter.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @return list<Inquiry>
	 */
	public function list_inquiries( string $status = '', string $source = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ( %s = \'\' OR status = %s ) AND ( %s = \'\' OR source = %s ) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				self::table(),
				$status,
				$status,
				$source,
				$source,
				max( 1, $limit ),
				max( 0, $offset )
			),
			ARRAY_A
		);
		return array_values( array_map( array( self::class, 'from_row' ), is_array( $rows ) ? $rows : array() ) );
	}

	/**
	 * Changes status.
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 */
	public function change_status( int $id, string $status ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes one.
	 *
	 * @param int $id Id.
	 */
	public function remove_inquiry( int $id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes older ones.
	 *
	 * @param string $before ISO 8601 UTC.
	 */
	public function remove_before( string $before ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), self::to_db( $before ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Recent ones of a client.
	 *
	 * @param string $client_hash Client.
	 * @param string $since       ISO 8601 UTC.
	 */
	public function count_recent( string $client_hash, string $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE client_hash = %s AND created_at >= %s', self::table(), $client_hash, self::to_db( $since ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Number of inquiries since a time, by kind (no contact data is read).
	 *
	 * @param string $since ISO 8601 UTC.
	 * @return array<string, int>
	 */
	public function count_by_kind( string $since ): array {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY kind', self::table(), self::to_db( $since ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['kind'] ] = (int) $row['n'];
		}
		ksort( $counts );
		return $counts;
	}

	/**
	 * Row → Inquiry (contact decrypted; empty contact when it cannot be decrypted).
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function from_row( array $row ): Inquiry {
		$contact = SodiumCipher::decrypt( (string) ( $row['contact'] ?? '' ) );
		$reasons = json_decode( (string) ( $row['spam_reasons'] ?? '[]' ), true );
		return new Inquiry(
			(int) $row['id'],
			(string) $row['kind'],
			(string) $row['source'],
			(string) $row['channel'],
			null === $row['listing_id'] ? null : (int) $row['listing_id'],
			(string) $row['subject'],
			(string) $row['message'],
			InquiryContact::from_array( null === $contact ? array() : json_decode( $contact, true ) ),
			(string) $row['status'],
			(int) $row['spam_score'],
			is_array( $reasons ) ? array_values( array_map( 'strval', $reasons ) ) : array(),
			(string) $row['client_hash'],
			gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $row['created_at'] . ' UTC' ) )
		);
	}

	/**
	 * ISO 8601 UTC → MySQL datetime.
	 *
	 * @param string $iso ISO 8601.
	 */
	private static function to_db( string $iso ): string {
		return gmdate( 'Y-m-d H:i:s', (int) strtotime( $iso ) );
	}
}
