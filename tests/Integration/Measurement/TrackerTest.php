<?php
/**
 * Tracker inside WordPress (through the request listener).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Tracker;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Measurement\RequestListener;
use AIHazirSite\WordPress\Platform\WpClock;
use WP_UnitTestCase;

/**
 * Tracker integration tests.
 *
 * @covers \AIHazirSite\Core\Measurement\Tracker
 * @covers \AIHazirSite\WordPress\Measurement\RequestListener
 * @covers \AIHazirSite\WordPress\Measurement\MeasurementModule
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
	 * $_SERVER and $_GET before the test.
	 *
	 * @var array{0: array<mixed>, 1: array<mixed>}
	 */
	private array $globals;

	/**
	 * Fresh tracker and empty table; measurement at its default (on).
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', WpdbHitRepository::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( Features::OPTION );
		$this->tracker = new Tracker( new WpdbHitRepository(), new WpClock() );
		$this->globals = array( $_SERVER, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Saved to restore after the test.
	}

	/**
	 * Restores the front-end context.
	 */
	public function tear_down(): void {
		[ $_SERVER, $_GET ] = $this->globals;
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
	 * Runs one WordPress request through the listener (parse_request + shutdown).
	 *
	 * @param array<string, string> $server  Request headers ($_SERVER keys).
	 * @param array<string, string> $query   Query parameters ($_GET).
	 * @param Tracker|null          $tracker Tracker; defaults to the test tracker.
	 * @return bool True when a row was written.
	 */
	private function handle( array $server, array $query = array(), ?Tracker $tracker = null ): bool {
		$tracker = $tracker ?? $this->tracker;
		foreach ( array( 'HTTP_USER_AGENT', 'REQUEST_URI', 'REMOTE_ADDR', 'HTTP_REFERER' ) as $key ) {
			unset( $_SERVER[ $key ] );
		}
		$_SERVER = array_merge( $_SERVER, $server );
		$_GET    = $query;

		( new RequestListener( $tracker ) )->on_parse_request();
		return $tracker->flush();
	}

	/**
	 * All rows of the hits table.
	 *
	 * @return list<array<string, string>>
	 */
	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT day, kind, source_id, path, verified, hits FROM %i ORDER BY id', WpdbHitRepository::table() ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		$this->assertTrue( $this->handle( $this->bot_request() ) );
		$this->assertTrue( $this->handle( $this->bot_request() ) );

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
		$this->handle(
			array(
				'HTTP_USER_AGENT' => self::BROWSER_UA,
				'HTTP_REFERER'    => 'https://chatgpt.com/',
				'REQUEST_URI'     => '/hakkimizda/',
			)
		);
		$this->assertFalse(
			$this->handle(
				array(
					'HTTP_USER_AGENT' => self::BROWSER_UA,
					'HTTP_REFERER'    => 'https://www.google.com/',
					'REQUEST_URI'     => '/hakkimizda/',
				)
			)
		);
		$this->handle( array( 'HTTP_USER_AGENT' => self::BROWSER_UA, 'REQUEST_URI' => '/?utm_source=chatgpt.com' ), array( 'utm_source' => 'chatgpt.com' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

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
		$this->assertFalse( $this->handle( $this->bot_request() ) );

		set_current_screen( 'front' );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( $this->handle( $this->bot_request() ) );

		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * Neither the raw IP nor the full user agent reaches the database.
	 */
	public function test_no_raw_ip_or_user_agent_is_stored(): void {
		global $wpdb;

		$tracker = new Tracker( new WpdbHitRepository(), new WpClock(), null, static fn(): bool => true );
		$this->assertTrue( $this->handle( $this->bot_request(), array(), $tracker ) );

		foreach ( array( self::IP, 'marker-7f3a9c', 'utm_source' ) as $needle ) {
			$like = '%' . $wpdb->esc_like( $needle ) . '%';
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE CONCAT_WS(" ", day, kind, source_id, path, verified, hits) LIKE %s', WpdbHitRepository::table(), $like ) ), $needle ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_value LIKE %s', $wpdb->options, $like ) ), $needle ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * With the feature off nothing is written.
	 */
	public function test_nothing_is_written_when_feature_is_off(): void {
		Features::set( Features::MEASUREMENT, false );

		$this->assertFalse( $this->handle( $this->bot_request() ) );
		$this->assertFalse( $this->handle( array( 'HTTP_REFERER' => 'https://claude.ai/' ) ) );

		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A throwing verifier counts the hit as unverified instead of breaking the request.
	 */
	public function test_failing_verifier_counts_unverified(): void {
		$tracker = new Tracker(
			new WpdbHitRepository(),
			new WpClock(),
			null,
			static function (): bool {
				throw new \RuntimeException( 'source unreachable' );
			}
		);

		$this->assertTrue( $this->handle( $this->bot_request(), array(), $tracker ) );
		$this->assertSame( '0', $this->rows()[0]['verified'] );
	}

	/**
	 * The full WordPress path (listener + tracker + one write) adds on average less than 5 ms.
	 */
	public function test_average_overhead_is_below_5ms(): void {
		$runs = 200;
		$this->handle( $this->bot_request() ); // Warm-up: loads data files.

		$start = hrtime( true );
		for ( $i = 0; $i < $runs; $i++ ) {
			$this->handle( $this->bot_request( '/sayfa-' . ( $i % 20 ) . '/' ) );
		}
		$average_ms = ( hrtime( true ) - $start ) / 1e6 / $runs;

		$this->assertLessThan( 5.0, $average_ms, sprintf( 'Average %.3f ms', $average_ms ) );
	}
}
