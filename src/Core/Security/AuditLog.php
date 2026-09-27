<?php
/**
 * Records every write attempt.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Security;

use AIHazirSite\Core\Contracts\AuditRepository;
use AIHazirSite\Core\Contracts\Clock;

/**
 * One entry per write attempt: time, channel, salted client HMAC, action, outcome, inquiry id
 * and a short machine code. No content, no contact data, no raw IP.
 */
final class AuditLog {

	public const OUTCOME_ACCEPTED     = 'accepted';
	public const OUTCOME_QUARANTINED  = 'quarantined';
	public const OUTCOME_RATE_LIMITED = 'rate_limited';
	public const OUTCOME_INVALID      = 'invalid';
	public const OUTCOME_NOTIFIED     = 'notified';
	public const OUTCOME_NOTIFY_FAIL  = 'notify_failed';
	public const OUTCOME_CHANGED      = 'changed';
	public const OUTCOME_DELETED      = 'deleted';

	/**
	 * Constructor.
	 *
	 * @param AuditRepository $entries Storage.
	 * @param Clock           $clock   Time.
	 */
	public function __construct(
		private readonly AuditRepository $entries,
		private readonly Clock $clock
	) {
	}

	/**
	 * Appends an entry.
	 *
	 * @param string   $channel     mcp | rest | admin | cron.
	 * @param string   $client_hash Client HMAC ('' for admin/cron).
	 * @param string   $action      submit | notify | status | delete | purge.
	 * @param string   $outcome     self::OUTCOME_*.
	 * @param int|null $inquiry_id  Inquiry.
	 * @param string   $detail      Short machine code (e.g. field names, new status).
	 */
	public function record( string $channel, string $client_hash, string $action, string $outcome, ?int $inquiry_id = null, string $detail = '' ): void {
		$this->entries->append(
			array(
				'at'          => gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() ),
				'channel'     => substr( $channel, 0, 16 ),
				'client_hash' => substr( $client_hash, 0, 64 ),
				'action'      => substr( $action, 0, 16 ),
				'outcome'     => substr( $outcome, 0, 16 ),
				'inquiry_id'  => $inquiry_id,
				'detail'      => substr( $detail, 0, 191 ),
			)
		);
	}

	/**
	 * Deletes entries older than a time (same retention as the inquiries: the client HMAC is
	 * pseudonymous data).
	 *
	 * @param string $before ISO 8601 UTC.
	 */
	public function purge( string $before ): int {
		return $this->entries->remove_before( $before );
	}
}
