<?php
/**
 * Tracker inside WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Storage\HitStore;
use AIHazirSite\Modules\Measurement\Bot;
use AIHazirSite\Modules\Measurement\Tracker;
use WP_UnitTestCase;

/**
 * Tracker integration tests.
 *
 * @covers \AIHazirSite\Modules\Measurement\Tracker
 * @covers \AIHazirSite\Modules\Measurement\MeasurementModule
 */
final class TrackerTest extends WP_UnitTestCase {

	private const GPTBOT_UA  = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot) marker-7f3a9c';
	private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
	private const IP         = '203.0.113.77';

	/**
	 * Tracker under test.
	 *
	 * @var Tracker
	 */
	private Tracker $tracker;

	/**
	 * Fresh tracker and empty table; measurement at its default (on).
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', HitStore::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( Features::OPTION );
		$this->tracker = new Tracker( new HitStore() );
	}

	/**
	 * Restores the front-end context.
	 */
	public function tear_down(): void {
		set_current_screen( 'front' );
		remove_all_filters( 'wp_doing_cron' );
		parent::tear_down();
	}

	/**
	 * A GPTBot request.
	 *
	 * @param string $uri Request URI.
	 * @return array<string, string>
	 */
	private function bot_request( string $uri = '/urunler/?utm_source=x' ): array {
		return array(
			'HTTP_USER_AGENT' => self::GPTBOT_UA,
			'REQUEST_URI'     => $uri,
			'REMOTE_ADDR'     => self::IP,
		);
	}

	/**
	 * All rows of the hits table.
	 *
	 * @return list<array<string, string>>
	 */
	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT day, kind, source_id, path, verified, hits FROM %i ORDER BY id', HitStore::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * The module hooks the tracker when measurement is on (the default).
	 */
	public function test_module_hooks_tracker_by_default(): void {
		$this->assertTrue( has_action( 'parse_request' ) );
		$this->assertTrue( has_action( 'shutdown' ) );
	}

	/**
	 * Same bot, same page, same day twice → one row with hits = 2.
	 */
	public function test_same_bot_same_page_twice_is_one_row(): void {
		$this->assertTrue( $this->tracker->handle( $this->bot_request() ) );
		$this->assertTrue( $this->tracker->handle( $this->bot_request() ) );

		$this->assertSame(
			array(
				array(
					'day'       => current_time( 'Y-m-d' ),
					'kind'      => 'bot',
					'source_id' => 'gptbot',
					'path'      => '/urunler/',
					'verified'  => '0',
					'hits'      => '2',
				),
			),
			$this->rows()
		);
	}

	/**
	 * A human coming from ChatGPT is counted as a referral; plain visits are not counted.
	 */
	public function test_referral_counted_and_plain_visit_ignored(): void {
		$this->tracker->handle(
			array(
				'HTTP_USER_AGENT' => self::BROWSER_UA,
				'HTTP_REFERER'    => 'https://chatgpt.com/',
				'REQUEST_URI'     => '/hakkimizda/',
			)
		);
		$this->assertFalse(
			$this->tracker->handle(
				array(
					'HTTP_USER_AGENT' => self::BROWSER_UA,
					'HTTP_REFERER'    => 'https://www.google.com/',
					'REQUEST_URI'     => '/hakkimizda/',
				)
			)
		);
		$this->tracker->handle( array( 'HTTP_USER_AGENT' => self::BROWSER_UA, 'REQUEST_URI' => '/?utm_source=chatgpt.com' ), array( 'utm_source' => 'chatgpt.com' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'referral', 'chatgpt', '/hakkimizda/' ), array( $rows[0]['kind'], $rows[0]['source_id'], $rows[0]['path'] ) );
		$this->assertSame( '/', $rows[1]['path'] );
	}

	/**
	 * Admin screens and cron requests are not counted.
	 */
	public function test_admin_and_cron_requests_are_not_counted(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$this->assertFalse( $this->tracker->handle( $this->bot_request() ) );

		set_current_screen( 'front' );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( $this->tracker->handle( $this->bot_request() ) );

		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * Neither the raw IP nor the full user agent reaches the database.
	 */
	public function test_no_raw_ip_or_user_agent_is_stored(): void {
		global $wpdb;

		$tracker = new Tracker( new HitStore(), null, static fn(): bool => true );
		$this->assertTrue( $tracker->handle( $this->bot_request() ) );

		foreach ( array( self::IP, 'marker-7f3a9c', 'utm_source' ) as $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE CONCAT_WS(" ", day, kind, source_id, path, verified, hits) LIKE %s', HitStore::table(), $like ) ), $needle ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_value LIKE %s', $wpdb->options, $like ) ), $needle ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * With the feature off nothing is written.
	 */
	public function test_nothing_is_written_when_feature_is_off(): void {
		Features::set( Features::MEASUREMENT, false );

		$this->assertFalse( $this->tracker->handle( $this->bot_request() ) );
		$this->assertFalse( $this->tracker->handle( array( 'HTTP_REFERER' => 'https://claude.ai/' ) ) );

		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A throwing verifier counts the hit as unverified instead of breaking the request.
	 */
	public function test_failing_verifier_counts_unverified(): void {
		$tracker = new Tracker(
			new HitStore(),
			null,
			static function (): bool {
				throw new \RuntimeException( 'source unreachable' );
			}
		);

		$this->assertTrue( $tracker->handle( $this->bot_request() ) );
		$this->assertSame( '0', $this->rows()[0]['verified'] );
	}

	/**
	 * The tracker adds on average less than 5 ms per counted request.
	 */
	public function test_average_overhead_is_below_5ms(): void {
		$runs = 200;
		$this->tracker->handle( $this->bot_request() ); // Warm-up: loads data files.

		$start = hrtime( true );
		for ( $i = 0; $i < $runs; $i++ ) {
			$this->tracker->handle( $this->bot_request( '/sayfa-' . ( $i % 20 ) . '/' ) );
		}
		$average_ms = ( hrtime( true ) - $start ) / 1e6 / $runs;

		$this->assertLessThan( 5.0, $average_ms, sprintf( 'Average %.3f ms', $average_ms ) );
	}
}
