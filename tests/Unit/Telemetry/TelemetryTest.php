<?php
/**
 * Telemetry consent and sending.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Telemetry;

use AIHazirSite\Core\Contracts\HttpPoster;
use AIHazirSite\Core\Telemetry\TelemetryService;
use AIHazirSite\Core\Telemetry\TelemetrySummary;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Unit\Report\ComplianceReportDataTest;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Nothing leaves without consent; only totals leave.
 *
 * @covers \AIHazirSite\Core\Telemetry\TelemetryService
 * @covers \AIHazirSite\Core\Telemetry\TelemetrySummary
 */
final class TelemetryTest extends UnitTestCase {

	/**
	 * Recorded posts.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $posts = array();

	/**
	 * Service under test.
	 *
	 * @var TelemetryService
	 */
	private TelemetryService $service;

	/**
	 * Settings.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/**
	 * Recording poster.
	 */
	protected function setUp(): void {
		parent::setUp();
		$posts          = &$this->posts;
		$poster         = new class( $posts ) implements HttpPoster {
			/**
			 * Constructor.
			 *
			 * @param array<int, array{0: string, 1: string}> $posts Recorded posts.
			 */
			public function __construct( private array &$posts ) {
			}

			/**
			 * Records.
			 *
			 * @param string $url  URL.
			 * @param string $json Body.
			 */
			public function post( string $url, string $json ): bool {
				$this->posts[] = array( $url, $json );
				return true;
			}
		};
		$this->settings = new MemorySettings();
		$this->service  = new TelemetryService( $this->settings, $poster, new FixedClock( '2026-09-28' ) );
	}

	/**
	 * A summary.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary(): array {
		return TelemetrySummary::build( ComplianceReportDataTest::scan( '2026-09-27T10:00:00Z', array( 'structured_data' => 0.0 ) ), 12, 20, 3, '1.3.0', '7.1' );
	}

	/**
	 * Without consent, with the feature off, or without an https endpoint: nothing is sent.
	 */
	public function test_nothing_without_consent(): void {
		$this->assertFalse( $this->service->send( true, 'https://panel.example/ozet', self::summary() ) );
		$this->service->give_consent();
		$this->assertFalse( $this->service->send( false, 'https://panel.example/ozet', self::summary() ), 'Feature off.' );
		$this->assertFalse( $this->service->send( true, '', self::summary() ), 'No endpoint.' );
		$this->assertFalse( $this->service->send( true, 'http://panel.example/ozet', self::summary() ), 'Not https.' );
		$this->assertSame( array(), $this->posts );

		// Consent to an older notice is not consent to the current one.
		$stored                   = $this->settings->get( TelemetryService::OPTION );
		$stored['notice_version'] = TelemetryService::NOTICE_VERSION - 1;
		$this->settings->set( TelemetryService::OPTION, $stored );
		$this->assertNull( $this->service->consent() );
		$this->assertFalse( $this->service->send( true, 'https://panel.example/ozet', self::summary() ) );

		// Revoked: stops at once.
		$this->service->give_consent();
		$this->service->revoke();
		$this->assertFalse( $this->service->send( true, 'https://panel.example/ozet', self::summary() ) );
		$this->assertSame( array(), $this->posts );
	}

	/**
	 * With consent: exactly the listed totals, a random site id and the send time.
	 */
	public function test_sends_only_totals(): void {
		$this->service->give_consent();
		$consent = $this->service->consent();
		$this->assertNotNull( $consent );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $consent['site_id'] );
		$this->assertSame( '2026-09-28T12:00:00Z', $consent['given_at'] );

		$this->assertTrue( $this->service->send( true, 'https://panel.example/ozet', array_merge( self::summary(), array( 'email' => 'x@y.example' ) ) ) );
		$this->assertCount( 1, $this->posts );
		$body = json_decode( $this->posts[0][1], true );
		$this->assertSame( array_merge( array( 'site_id', 'sent_at' ), TelemetrySummary::FIELDS ), array_keys( $body ), 'Only the listed keys; extra input is dropped.' );
		$this->assertSame( 80, $body['score'] );
		$this->assertSame( 12, $body['ai_bot_reads_7d'] );
		$this->assertSame( 12, $body['ai_bot_reads_verified_7d'], 'Verified never exceeds the total.' );
		$this->assertSame( 3, $body['inquiries_7d'] );
		$this->assertSame( $consent['site_id'], $body['site_id'] );

		$this->assertNull( TelemetrySummary::build( null, 0, 0, 0, '1.3.0', '7.1' )['score'] );
	}
}
