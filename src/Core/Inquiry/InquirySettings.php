<?php
/**
 * Adjustable inquiry settings.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Inquiry;

/**
 * Retention of personal data, per-client limits and the quarantine threshold, kept within safe bounds.
 */
final class InquirySettings {

	public const OPTION = 'aihs_inquiry_settings';

	/**
	 * Constructor.
	 *
	 * @param int $retention_days Days until an inquiry (with its contact data) is deleted.
	 * @param int $per_minute     Inquiries a client may leave per minute.
	 * @param int $per_day        Inquiries a client may leave per day.
	 * @param int $spam_threshold Score from which an inquiry goes to quarantine.
	 */
	public function __construct(
		public readonly int $retention_days = 180,
		public readonly int $per_minute = 3,
		public readonly int $per_day = 20,
		public readonly int $spam_threshold = 50
	) {
	}

	/**
	 * From stored or submitted values, clamped (1–3650 days, 1–60 a minute, 1–1000 a day, 1–100 score).
	 *
	 * @param mixed $data Data.
	 */
	public static function from_array( mixed $data ): self {
		$data    = is_array( $data ) ? $data : array();
		$default = new self();
		$int     = static fn( string $k, int $fallback, int $min, int $max ): int => isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ? max( $min, min( $max, (int) $data[ $k ] ) ) : $fallback;
		return new self(
			$int( 'retention_days', $default->retention_days, 1, 3650 ),
			$int( 'per_minute', $default->per_minute, 1, 60 ),
			$int( 'per_day', $default->per_day, 1, 1000 ),
			$int( 'spam_threshold', $default->spam_threshold, 1, 100 )
		);
	}

	/**
	 * Plain array.
	 *
	 * @return array{retention_days: int, per_minute: int, per_day: int, spam_threshold: int}
	 */
	public function to_array(): array {
		return array(
			'retention_days' => $this->retention_days,
			'per_minute'     => $this->per_minute,
			'per_day'        => $this->per_day,
			'spam_threshold' => $this->spam_threshold,
		);
	}
}
