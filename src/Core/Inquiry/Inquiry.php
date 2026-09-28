<?php
/**
 * An inquiry left by an AI agent or a person.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Inquiry;

/**
 * Immutable inquiry. New inquiries are only ever `new` or `quarantine`; `approved` and
 * `rejected` are set by a person in the admin screen (InquiryService::set_status).
 */
final class Inquiry {

	public const SOURCE_AI      = 'ai';
	public const SOURCE_HUMAN   = 'human';
	public const SOURCE_UNKNOWN = 'unknown';
	public const SOURCES        = array( self::SOURCE_AI, self::SOURCE_HUMAN, self::SOURCE_UNKNOWN );

	public const KIND_QUOTE_REQUEST = 'quote_request';
	public const KIND_OFFER         = 'offer';
	public const KIND_REFERRAL      = 'referral';
	public const KINDS              = array( self::KIND_QUOTE_REQUEST, self::KIND_OFFER, self::KIND_REFERRAL );

	public const STATUS_NEW        = 'new';
	public const STATUS_APPROVED   = 'approved';
	public const STATUS_REJECTED   = 'rejected';
	public const STATUS_QUARANTINE = 'quarantine';
	public const STATUSES          = array( self::STATUS_NEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_QUARANTINE );

	public const CHANNEL_MCP  = 'mcp';
	public const CHANNEL_REST = 'rest';
	public const CHANNEL_A2A  = 'a2a';

	/**
	 * Constructor.
	 *
	 * @param int|null       $id           Storage id.
	 * @param string         $kind         self::KINDS.
	 * @param string         $source       self::SOURCES.
	 * @param string         $channel      mcp | rest.
	 * @param int|null       $listing_id   Listing the inquiry is about.
	 * @param string         $subject      Subject.
	 * @param string         $message      Message.
	 * @param InquiryContact $contact      Contact (personal data).
	 * @param string         $status       self::STATUSES.
	 * @param int            $spam_score   0–100.
	 * @param string[]       $spam_reasons Why it scored.
	 * @param string         $client_hash  Salted HMAC of the client (never the IP).
	 * @param string         $created_at   ISO 8601 UTC.
	 *
	 * @phpstan-param list<string> $spam_reasons
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $kind,
		public readonly string $source,
		public readonly string $channel,
		public readonly ?int $listing_id,
		public readonly string $subject,
		public readonly string $message,
		public readonly InquiryContact $contact,
		public readonly string $status,
		public readonly int $spam_score,
		public readonly array $spam_reasons,
		public readonly string $client_hash,
		public readonly string $created_at
	) {
	}

	/**
	 * Copy with a storage id.
	 *
	 * @param int $id Id.
	 */
	public function with_id( int $id ): self {
		return new self( $id, $this->kind, $this->source, $this->channel, $this->listing_id, $this->subject, $this->message, $this->contact, $this->status, $this->spam_score, $this->spam_reasons, $this->client_hash, $this->created_at );
	}

	/**
	 * Copy with another status.
	 *
	 * @param string $status Status.
	 */
	public function with_status( string $status ): self {
		return new self( $this->id, $this->kind, $this->source, $this->channel, $this->listing_id, $this->subject, $this->message, $this->contact, $status, $this->spam_score, $this->spam_reasons, $this->client_hash, $this->created_at );
	}
}
