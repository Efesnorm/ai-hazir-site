<?php
/**
 * Outcome of a submission.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Inquiry;

/**
 * The stored inquiry (accepted or quarantined), or errors (invalid or rate limited).
 */
final class InquiryResult {

	/**
	 * Constructor.
	 *
	 * @param Inquiry|null          $inquiry     Stored inquiry.
	 * @param string                $outcome     AuditLog::OUTCOME_*.
	 * @param array<string, string> $errors      Field → message.
	 * @param int|null              $retry_after Seconds until a new try (rate limited).
	 */
	public function __construct(
		public readonly ?Inquiry $inquiry,
		public readonly string $outcome,
		public readonly array $errors = array(),
		public readonly ?int $retry_after = null
	) {
	}

	/**
	 * Whether the inquiry was stored.
	 */
	public function is_stored(): bool {
		return null !== $this->inquiry;
	}
}
