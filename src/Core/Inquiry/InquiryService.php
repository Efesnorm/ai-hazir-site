<?php
/**
 * The single write point for inquiries.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Inquiry;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\InquiryNotifier;
use AIHazirSite\Core\Contracts\InquiryRepository;
use AIHazirSite\Core\Contracts\RateLimiter;
use AIHazirSite\Core\Contracts\Secret;
use AIHazirSite\Core\Security\AuditLog;
use AIHazirSite\Core\Security\SpamScorer;

/**
 * Every channel (REST, MCP) submits here: rate limit → validation → spam score → store →
 * audit → notify the site owner. A submission is only ever stored as `new` or `quarantine`;
 * nothing is approved automatically and nothing is sent to the submitter. Status changes and
 * deletions come from the admin screen (a person), retention purges from the daily cron.
 */
final class InquiryService {

	/**
	 * Constructor.
	 *
	 * @param InquiryRepository    $inquiries Storage.
	 * @param AuditLog             $audit     Audit log.
	 * @param Secret               $secret    Client HMAC.
	 * @param Clock                $clock     Time.
	 * @param InquirySettings      $settings  Settings.
	 * @param RateLimiter[]        $limiters  Per-client limits (e.g. per minute, per day).
	 * @param \Closure             $listing   Current listing by id: fn( int ): ?Listing.
	 * @param InquiryNotifier|null $notifier  Site owner notification.
	 * @param SpamScorer           $scorer    Spam rules.
	 *
	 * @phpstan-param list<RateLimiter> $limiters
	 */
	public function __construct(
		private readonly InquiryRepository $inquiries,
		private readonly AuditLog $audit,
		private readonly Secret $secret,
		private readonly Clock $clock,
		private readonly InquirySettings $settings,
		private readonly array $limiters,
		private readonly \Closure $listing,
		private readonly ?InquiryNotifier $notifier = null,
		private readonly SpamScorer $scorer = new SpamScorer()
	) {
	}

	/**
	 * Submits an inquiry.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param string               $channel Inquiry::CHANNEL_*.
	 * @param string               $source  Inquiry::SOURCE_*.
	 * @param string               $client  Client identifier (e.g. IP; only its HMAC is kept).
	 * @param string[]             $kinds   Kinds allowed on this site.
	 *
	 * @phpstan-param list<string> $kinds
	 */
	public function submit( array $input, string $channel, string $source, string $client, array $kinds ): InquiryResult {
		$hash = $this->secret->hmac( 'inquiry|' . $client );

		foreach ( $this->limiters as $limiter ) {
			$retry = $limiter->hit( $client );
			if ( null !== $retry ) {
				$this->audit->record( $channel, $hash, 'submit', AuditLog::OUTCOME_RATE_LIMITED, null, 'retry=' . $retry );
				return new InquiryResult( null, AuditLog::OUTCOME_RATE_LIMITED, array( 'rate_limit' => sprintf( 'Çok fazla talep. %d saniye sonra tekrar deneyin.', $retry ) ), $retry );
			}
		}

		[ $clean, $errors ] = ( new InquiryValidator() )->validate( $input, $kinds, $this->listing );
		if ( null === $clean ) {
			$this->audit->record( $channel, $hash, 'submit', AuditLog::OUTCOME_INVALID, null, implode( ',', array_keys( $errors ) ) );
			return new InquiryResult( null, AuditLog::OUTCOME_INVALID, $errors );
		}

		$now    = $this->clock->now();
		$recent = $this->inquiries->count_recent( $hash, gmdate( 'Y-m-d\TH:i:s\Z', $now - 600 ) );
		$spam   = $this->scorer->score( $clean['subject'], $clean['message'], $clean['listing'], $recent );
		$status = $spam['score'] >= $this->settings->spam_threshold ? Inquiry::STATUS_QUARANTINE : Inquiry::STATUS_NEW;

		$stored = $this->inquiries->store_inquiry(
			new Inquiry(
				null,
				$clean['kind'],
				in_array( $source, Inquiry::SOURCES, true ) ? $source : Inquiry::SOURCE_UNKNOWN,
				$channel,
				$clean['listing']?->id,
				$clean['subject'],
				$clean['message'],
				$clean['contact'],
				$status,
				$spam['score'],
				$spam['reasons'],
				$hash,
				gmdate( 'Y-m-d\TH:i:s\Z', $now )
			)
		);

		$outcome = Inquiry::STATUS_QUARANTINE === $status ? AuditLog::OUTCOME_QUARANTINED : AuditLog::OUTCOME_ACCEPTED;
		$this->audit->record( $channel, $hash, 'submit', $outcome, $stored->id, 'score=' . $spam['score'] );

		if ( Inquiry::STATUS_NEW === $status && null !== $this->notifier ) {
			$sent = $this->notifier->notify_owner( $stored );
			$this->audit->record( $channel, $hash, 'notify', $sent ? AuditLog::OUTCOME_NOTIFIED : AuditLog::OUTCOME_NOTIFY_FAIL, $stored->id );
		}

		return new InquiryResult( $stored, $outcome );
	}

	/**
	 * Status change by a person in the admin screen (the only way to approve).
	 *
	 * @param int    $id     Inquiry.
	 * @param string $status Inquiry::STATUSES.
	 */
	public function set_status( int $id, string $status ): bool {
		if ( ! in_array( $status, Inquiry::STATUSES, true ) || null === $this->inquiries->find( $id ) ) {
			return false;
		}
		$changed = $this->inquiries->change_status( $id, $status );
		if ( $changed ) {
			$this->audit->record( 'admin', '', 'status', AuditLog::OUTCOME_CHANGED, $id, $status );
		}
		return $changed;
	}

	/**
	 * Deletes one inquiry (with its contact data) on request.
	 *
	 * @param int $id Inquiry.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->inquiries->remove_inquiry( $id );
		if ( $deleted ) {
			$this->audit->record( 'admin', '', 'delete', AuditLog::OUTCOME_DELETED, $id );
		}
		return $deleted;
	}

	/**
	 * Deletes inquiries and audit entries older than the retention period.
	 *
	 * @return int Deleted inquiries.
	 */
	public function purge(): int {
		$before  = gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() - $this->settings->retention_days * 86400 );
		$deleted = $this->inquiries->remove_before( $before );
		$this->audit->purge( $before );
		if ( $deleted > 0 ) {
			$this->audit->record( 'cron', '', 'purge', AuditLog::OUTCOME_DELETED, null, 'count=' . $deleted );
		}
		return $deleted;
	}
}
