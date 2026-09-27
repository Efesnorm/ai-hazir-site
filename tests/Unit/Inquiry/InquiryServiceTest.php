<?php
/**
 * Tests for InquiryService and InquiryValidator.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Inquiry;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Contracts\InquiryNotifier;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryService;
use AIHazirSite\Core\Inquiry\InquirySettings;
use AIHazirSite\Core\Security\AuditLog;
use AIHazirSite\Core\Security\TokenBucketLimiter;
use AIHazirSite\Tests\Support\MemoryAuditRepository;
use AIHazirSite\Tests\Support\MemoryCache;
use AIHazirSite\Tests\Support\MemoryInquiryRepository;
use AIHazirSite\Tests\Support\MovableClock;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use AIHazirSite\Tests\Support\StaticSecret;
use PHPUnit\Framework\TestCase;

/**
 * Submission flow, limits, spam, status changes, retention.
 *
 * @covers \AIHazirSite\Core\Inquiry\InquiryService
 * @covers \AIHazirSite\Core\Inquiry\InquiryValidator
 * @covers \AIHazirSite\Core\Security\AuditLog
 */
final class InquiryServiceTest extends TestCase {

	/**
	 * Storage.
	 *
	 * @var MemoryInquiryRepository
	 */
	private MemoryInquiryRepository $inquiries;

	/**
	 * Audit storage.
	 *
	 * @var MemoryAuditRepository
	 */
	private MemoryAuditRepository $audit;

	/**
	 * Clock.
	 *
	 * @var MovableClock
	 */
	private MovableClock $clock;

	/**
	 * Notified inquiries.
	 *
	 * @var list<Inquiry>
	 */
	private array $notified = array();

	/**
	 * Fresh storage and clock.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->inquiries = new MemoryInquiryRepository();
		$this->audit     = new MemoryAuditRepository();
		$this->clock     = new MovableClock();
		$this->notified  = array();
	}

	/**
	 * Service with 3/minute and 20/day buckets and the cable offer as listing 11.
	 */
	private function service(): InquiryService {
		$cache    = new MemoryCache();
		$notified = &$this->notified;
		$notifier = new class( $notified ) implements InquiryNotifier {
			/**
			 * Constructor.
			 *
			 * @param Inquiry[] $log Log (by reference).
			 *
			 * @phpstan-param list<Inquiry> $log
			 */
			public function __construct( private array &$log ) {
			}

			/**
			 * Records the notification.
			 *
			 * @param Inquiry $inquiry Inquiry.
			 */
			public function notify_owner( Inquiry $inquiry ): bool {
				$this->log[] = $inquiry;
				return true;
			}
		};

		return new InquiryService(
			$this->inquiries,
			new AuditLog( $this->audit, $this->clock ),
			new StaticSecret(),
			$this->clock,
			new InquirySettings(),
			array(
				new TokenBucketLimiter( $cache, $this->clock, new StaticSecret(), 3, 60, 'inquiry-minute' ),
				new TokenBucketLimiter( $cache, $this->clock, new StaticSecret(), 20, 86400, 'inquiry-day' ),
			),
			static fn( int $id ): ?Listing => 11 === $id ? F::offer() : null,
			$notifier
		);
	}

	/**
	 * A valid quote request.
	 *
	 * @param array<string, mixed> $overrides Changes.
	 * @return array<string, mixed>
	 */
	private static function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'kind'       => 'quote_request',
				'listing_id' => 11,
				'subject'    => 'NYY kablo teklifi',
				'message'    => '3x2,5 NYY kablodan 500 metre için teklif rica ederiz.',
				'contact'    => array(
					'name'  => 'Satın alma',
					'email' => 'satinalma@alici.example',
				),
			),
			$overrides
		);
	}

	/**
	 * A valid inquiry is stored as new, audited and the owner is notified.
	 */
	public function test_accepted(): void {
		$result = $this->service()->submit( self::input(), Inquiry::CHANNEL_MCP, Inquiry::SOURCE_AI, '203.0.113.5', Inquiry::KINDS );

		$this->assertTrue( $result->is_stored() );
		$this->assertSame( array( Inquiry::STATUS_NEW, 11, 'ai', 'mcp' ), array( $result->inquiry?->status, $result->inquiry?->listing_id, $result->inquiry?->source, $result->inquiry?->channel ) );
		$this->assertSame( array( 'accepted', 'notified' ), $this->audit->outcomes() );
		$this->assertCount( 1, $this->notified );
		$this->assertStringNotContainsString( '203.0.113.5', (string) json_encode( $this->audit->entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertStringNotContainsString( 'satinalma@', (string) json_encode( $this->audit->entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Over the limit: refused, with the retry time, and audited (fake clock).
	 */
	public function test_rate_limit(): void {
		$service = $this->service();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $service->submit( self::input(), 'rest', 'human', '203.0.113.5', Inquiry::KINDS )->is_stored() );
		}

		$refused = $service->submit( self::input(), 'rest', 'human', '203.0.113.5', Inquiry::KINDS );
		$this->assertFalse( $refused->is_stored() );
		$this->assertSame( array( 'rate_limited', 20 ), array( $refused->outcome, $refused->retry_after ) );
		$this->assertSame( 'rate_limited', $this->audit->latest( 1 )[0]['outcome'] );
		$this->assertCount( 3, $this->inquiries->items );

		$this->clock->advance( 20 );
		$this->assertTrue( $service->submit( self::input(), 'rest', 'human', '203.0.113.5', Inquiry::KINDS )->is_stored() );
	}

	/**
	 * Spam goes to quarantine and is not notified; invalid input is refused and audited.
	 */
	public function test_spam_and_invalid(): void {
		$service = $this->service();
		$spam    = $service->submit( self::input( array( 'message' => 'Visit http://a.example http://b.example http://c.example' ) ), 'rest', 'unknown', '198.51.100.7', Inquiry::KINDS );

		$this->assertSame( Inquiry::STATUS_QUARANTINE, $spam->inquiry?->status );
		$this->assertSame( 'quarantined', $spam->outcome );
		$this->assertSame( array(), $this->notified );

		$invalid = $service->submit( self::input( array( 'message' => 'kısa', 'listing_id' => 999, 'contact' => array() ) ), 'rest', 'human', '198.51.100.8', Inquiry::KINDS ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'listing_id', 'message', 'contact' ), array_keys( $invalid->errors ) );
		$this->assertSame( array( 'invalid', 'listing_id,message,contact' ), array( $this->audit->latest( 1 )[0]['outcome'], $this->audit->latest( 1 )[0]['detail'] ) );
	}

	/**
	 * A site that allows only referral requests: other kinds refused; fees may not be asked.
	 */
	public function test_referral_only(): void {
		$service = $this->service();
		$kinds   = array( Inquiry::KIND_REFERRAL );

		$this->assertArrayHasKey( 'kind', $service->submit( self::input(), 'mcp', 'ai', '192.0.2.1', $kinds )->errors );

		$ok = $service->submit(
			array(
				'subject' => 'Tahkim',
				'message' => 'Uluslararası tahkim davalarında deneyiminiz ve referans işleriniz hakkında bilgi alabilir miyiz?',
				'contact' => array( 'phone' => '+90 212 555 00 00' ),
			),
			'mcp',
			'ai',
			'192.0.2.2',
			$kinds
		);
		$this->assertSame( Inquiry::KIND_REFERRAL, $ok->inquiry?->kind, 'Kind defaults to the only allowed one.' );

		foreach ( array( 'Vekalet ücreti ne kadar?', 'What is your fee for arbitration cases?', 'Dava masrafı ve avans nedir?' ) as $message ) {
			$refused = $service->submit( array( 'message' => $message . ' Bilgi rica ederiz.', 'contact' => array( 'email' => 'a@b.example' ) ), 'mcp', 'ai', '192.0.2.3', $kinds ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->assertStringContainsString( 'ücret sorulamaz', $refused->errors['message'] ?? '', $message );
		}
	}

	/**
	 * Submissions are only ever new or quarantine; a person changes the status later.
	 */
	public function test_never_approved_on_submit(): void {
		$service = $this->service();
		foreach ( array( 'approved', 'rejected' ) as $status ) {
			$result = $service->submit( self::input( array( 'status' => $status ) ), 'rest', 'human', '192.0.2.10', Inquiry::KINDS );
			$this->assertSame( Inquiry::STATUS_NEW, $result->inquiry?->status, 'Input cannot set the status.' );
		}

		$id = (int) $result->inquiry?->id;
		$this->assertTrue( $service->set_status( $id, Inquiry::STATUS_APPROVED ) );
		$this->assertSame( Inquiry::STATUS_APPROVED, $this->inquiries->find( $id )?->status );
		$this->assertFalse( $service->set_status( $id, 'otomatik' ) );
		$this->assertSame( array( 'changed', 'approved' ), array( $this->audit->latest( 1 )[0]['outcome'], $this->audit->latest( 1 )[0]['detail'] ) );
	}

	/**
	 * Retention: inquiries and audit entries older than 180 days are deleted; one can be deleted on request.
	 */
	public function test_retention_and_delete(): void {
		$service = $this->service();
		$old     = (int) $service->submit( self::input(), 'rest', 'human', '192.0.2.20', Inquiry::KINDS )->inquiry?->id;
		$this->clock->advance( 2 * 86400 );
		$recent = (int) $service->submit( self::input(), 'rest', 'human', '192.0.2.21', Inquiry::KINDS )->inquiry?->id;

		$this->clock->advance( 179 * 86400 );
		$this->assertSame( 1, $service->purge(), '181 days old: deleted.' );
		$this->assertNull( $this->inquiries->find( $old ) );
		$this->assertNotNull( $this->inquiries->find( $recent ), '179 days old: kept.' );
		$this->assertSame( array( 'accepted', 'notified', 'deleted' ), $this->audit->outcomes(), 'Old audit entries went too.' );

		$this->assertTrue( $service->delete( $recent ) );
		$this->assertSame( array(), $this->inquiries->items );
	}
}
