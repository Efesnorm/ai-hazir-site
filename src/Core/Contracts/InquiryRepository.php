<?php
/**
 * Inquiry storage port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

use AIHazirSite\Core\Inquiry\Inquiry;

/**
 * Stores inquiries. Write methods are called only by InquiryService (architecture test).
 */
interface InquiryRepository {

	/**
	 * Stores a new inquiry and returns it with its id.
	 *
	 * @param Inquiry $inquiry Inquiry.
	 */
	public function store_inquiry( Inquiry $inquiry ): Inquiry;

	/**
	 * Inquiry by id.
	 *
	 * @param int $id Id.
	 */
	public function find( int $id ): ?Inquiry;

	/**
	 * Inquiries, newest first.
	 *
	 * @param string $status '' or a status.
	 * @param string $source '' or a source.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @return list<Inquiry>
	 */
	public function list_inquiries( string $status = '', string $source = '', int $limit = 50, int $offset = 0 ): array;

	/**
	 * Changes the status.
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 */
	public function change_status( int $id, string $status ): bool;

	/**
	 * Deletes one inquiry.
	 *
	 * @param int $id Id.
	 */
	public function remove_inquiry( int $id ): bool;

	/**
	 * Deletes inquiries created before a time.
	 *
	 * @param string $before ISO 8601 UTC.
	 * @return int Deleted.
	 */
	public function remove_before( string $before ): int;

	/**
	 * Inquiries of one client since a time (for the spam rules).
	 *
	 * @param string $client_hash Client HMAC.
	 * @param string $since       ISO 8601 UTC.
	 */
	public function count_recent( string $client_hash, string $since ): int;
}
