<?php
/**
 * Tests for IndexNowService (1.10.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\IndexNow;

use AIHazirSite\Core\Contracts\HttpStatusPoster;
use AIHazirSite\Core\IndexNow\IndexNowService;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Support\MovableClock;
use PHPUnit\Framework\TestCase;

/**
 * Key, request body, what is sent and when, answers.
 *
 * @covers \AIHazirSite\Core\IndexNow\IndexNowService
 */
final class IndexNowServiceTest extends TestCase {

	private const HOST = 'www.ornek.com';
	private const URLS = array( 'https://www.ornek.com/ai-katalog/' );

	/**
	 * Recorded requests.
	 *
	 * @var list<array{url: string, body: array<string, mixed>}>
	 */
	private array $sent = array();

	/**
	 * Answer of the fake endpoint.
	 *
	 * @var int
	 */
	private int $status = 200;

	/**
	 * Clock.
	 *
	 * @var MovableClock
	 */
	private MovableClock $clock;

	/**
	 * Service with memory storage and a recording poster.
	 */
	private function service(): IndexNowService {
		$this->clock = $this->clock ?? new MovableClock();
		$test        = $this;
		$poster      = new class( $test ) implements HttpStatusPoster {
			/**
			 * Constructor.
			 *
			 * @param IndexNowServiceTest $test Test.
			 */
			public function __construct( private readonly IndexNowServiceTest $test ) {
			}

			/**
			 * Records.
			 *
			 * @param string $url  URL.
			 * @param string $json Body.
			 */
			public function post_json( string $url, string $json ): int {
				return $this->test->record( $url, $json );
			}
		};
		return new IndexNowService( $this->settings, $poster, $this->clock );
	}

	/**
	 * Storage.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/**
	 * Fresh state.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new MemorySettings();
		$this->clock    = new MovableClock();
		$this->sent     = array();
		$this->status   = 200;
	}

	/**
	 * Called by the fake poster.
	 *
	 * @param string $url  URL.
	 * @param string $json Body.
	 */
	public function record( string $url, string $json ): int {
		$this->sent[] = array(
			'url'  => $url,
			'body' => (array) json_decode( $json, true ),
		);
		return $this->status;
	}

	/**
	 * A random key of the protocol's form, kept.
	 */
	public function test_key(): void {
		$service = $this->service();
		$this->assertSame( '', $service->key() );
		$key = $service->ensure_key();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key );
		$this->assertTrue( IndexNowService::valid_key( $key ) );
		$this->assertSame( $key, $this->service()->ensure_key() );

		$this->assertFalse( IndexNowService::valid_key( 'kisa' ) );
		$this->assertFalse( IndexNowService::valid_key( 'boşluk var 12345' ) );
		$this->assertTrue( IndexNowService::valid_key( 'abc-DEF-123' ) );
	}

	/**
	 * The protocol's body, sent to api.indexnow.org; only URLs on the host; the result is kept.
	 */
	public function test_submit(): void {
		$service = $this->service();
		$key     = $service->ensure_key();
		$result  = $service->submit( true, self::HOST, 'https://www.ornek.com/' . $key . '.txt', array_merge( self::URLS, array( 'https://baska.com/x', 'ftp://www.ornek.com/y', self::URLS[0] ) ) );

		$this->assertSame( 'https://api.indexnow.org/indexnow', $this->sent[0]['url'] );
		$this->assertSame(
			array(
				'host'        => self::HOST,
				'key'         => $key,
				'keyLocation' => 'https://www.ornek.com/' . $key . '.txt',
				'urlList'     => self::URLS,
			),
			$this->sent[0]['body']
		);
		$this->assertSame(
			array(
				'at'     => $this->clock->now(),
				'status' => 200,
				'count'  => 1,
			),
			$result
		);
		$this->assertSame( $result, $service->last() );
	}

	/**
	 * Nothing is sent when off, without a key or URL, with a foreign key file, or within the hour.
	 */
	public function test_not_sent(): void {
		$service  = $this->service();
		$location = 'https://www.ornek.com/k.txt';
		$this->assertNull( $service->submit( true, self::HOST, $location, self::URLS ), 'No key yet.' );
		$service->ensure_key();
		$this->assertNull( $service->submit( false, self::HOST, $location, self::URLS ), 'Off.' );
		$this->assertNull( $service->submit( true, self::HOST, $location, array( 'https://baska.com/' ) ), 'No URL on the host.' );
		$this->assertNull( $service->submit( true, self::HOST, 'https://baska.com/k.txt', self::URLS ), 'Key file elsewhere.' );
		$this->assertSame( array(), $this->sent );

		$this->assertNotNull( $service->submit( true, self::HOST, $location, self::URLS ) );
		$this->clock->advance( IndexNowService::MIN_INTERVAL - 1 );
		$this->assertNull( $service->submit( true, self::HOST, $location, self::URLS ), 'Within the hour.' );
		$this->clock->advance( 1 );
		$this->assertNotNull( $service->submit( true, self::HOST, $location, self::URLS ) );
		$this->assertCount( 2, $this->sent );
	}

	/**
	 * Failures are kept too (and still count for the hourly limit); answers have a meaning.
	 */
	public function test_answers(): void {
		$service      = $this->service();
		$this->status = 403;
		$service->ensure_key();
		$this->assertSame( 403, $service->submit( true, self::HOST, 'https://www.ornek.com/k.txt', self::URLS )['status'] ?? null );
		$this->assertSame( $this->clock->now() + IndexNowService::MIN_INTERVAL, $service->next_allowed() );

		foreach ( array( 200, 202, 400, 403, 422, 429, 0, 500 ) as $code ) {
			$this->assertNotSame( '', IndexNowService::meaning( $code ) );
		}
		$this->assertStringContainsString( 'bot korumas', IndexNowService::meaning( 403 ) );
	}
}
