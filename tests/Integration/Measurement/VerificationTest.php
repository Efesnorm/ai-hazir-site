<?php
/**
 * IP list refresh cron and verified counting inside WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\Core\Lifecycle;
use AIHazirSite\Core\Storage\HitStore;
use AIHazirSite\Modules\Measurement\IpRanges;
use AIHazirSite\Modules\Measurement\MeasurementModule;
use AIHazirSite\Modules\Measurement\Tracker;
use AIHazirSite\Modules\Measurement\Verifier;
use WP_Error;
use WP_UnitTestCase;

/**
 * Verification integration tests. HTTP is mocked with `pre_http_request`.
 *
 * @covers \AIHazirSite\Modules\Measurement\MeasurementModule
 * @covers \AIHazirSite\Modules\Measurement\IpRanges
 * @covers \AIHazirSite\Modules\Measurement\Verifier
 */
final class VerificationTest extends WP_UnitTestCase {

	private const OPENAI_GPTBOT = 'https://openai.com/gptbot.json';

	/**
	 * Clean state.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( IpRanges::OPTION );
	}

	/**
	 * Mocks every outgoing HTTP request.
	 *
	 * @param callable $handler fn( string $url ): array|WP_Error.
	 */
	private function mock_http( callable $handler ): void {
		add_filter(
			'pre_http_request',
			static fn( $pre, array $args, string $url ) => $handler( $url ),
			10,
			3
		);
	}

	/**
	 * The daily refresh is scheduled while measurement is on and removed on deactivation.
	 */
	public function test_refresh_is_scheduled_and_cleared_on_deactivation(): void {
		( new MeasurementModule() )->schedule_events();
		$this->assertNotFalse( wp_next_scheduled( MeasurementModule::REFRESH_HOOK ) );

		Lifecycle::deactivate();
		$this->assertFalse( wp_next_scheduled( MeasurementModule::REFRESH_HOOK ) );
	}

	/**
	 * Refresh downloads every ip_ranges source once; a GPTBot request from a listed IP is verified.
	 */
	public function test_refresh_then_verified_hit(): void {
		$requested = array();
		$this->mock_http(
			static function ( string $url ) use ( &$requested ): array {
				$requested[] = $url;
				$body        = self::OPENAI_GPTBOT === $url ? '{"prefixes":[{"ipv4Prefix":"20.125.66.80/28"}]}' : '{"prefixes":[{"ipv4Prefix":"192.0.2.0/24"}]}';
				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			}
		);

		$result = ( new MeasurementModule() )->refresh_ip_ranges();

		$this->assertSame( 1, $result[ self::OPENAI_GPTBOT ] );
		$this->assertSame( array_unique( $requested ), $requested, 'Shared lists are downloaded once.' );
		$this->assertCount( 8, $requested );
		$this->assertFalse( wp_load_alloptions()[ IpRanges::OPTION ] ?? false, 'IP lists are not autoloaded.' );

		$tracker = new Tracker( new HitStore(), null, new Verifier( new IpRanges() ) );
		$ua      = 'Mozilla/5.0 (compatible; GPTBot/1.4; +https://openai.com/gptbot)';
		$tracker->handle(
			array(
				'HTTP_USER_AGENT' => $ua,
				'REQUEST_URI'     => '/',
				'REMOTE_ADDR'     => '20.125.66.81',
			)
		);
		$tracker->handle(
			array(
				'HTTP_USER_AGENT' => $ua,
				'REQUEST_URI'     => '/',
				'REMOTE_ADDR'     => '198.51.100.7',
			)
		);

		global $wpdb;
		$this->assertSame(
			array( '0', '1' ),
			$wpdb->get_col( $wpdb->prepare( 'SELECT verified FROM %i ORDER BY verified', HitStore::table() ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Unreachable sources: refresh finishes, stores nothing, and hits are counted unverified.
	 */
	public function test_unreachable_sources_count_unverified(): void {
		$this->mock_http( static fn(): WP_Error => new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$result = ( new MeasurementModule() )->refresh_ip_ranges();
		$this->assertSame( array( 0 ), array_values( array_unique( $result ) ) );

		$tracker = new Tracker( new HitStore(), null, new Verifier( new IpRanges() ) );
		$this->assertTrue(
			$tracker->handle(
				array(
					'HTTP_USER_AGENT' => 'GPTBot/1.4',
					'REQUEST_URI'     => '/',
					'REMOTE_ADDR'     => '20.125.66.81',
				)
			)
		);

		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT verified FROM %i', HitStore::table() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
