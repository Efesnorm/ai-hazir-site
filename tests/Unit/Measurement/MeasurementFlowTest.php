<?php
/**
 * The whole measurement flow without WordPress.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Measurement;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\CsvExport;
use AIHazirSite\Core\Measurement\IpRanges;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\Core\Measurement\Request;
use AIHazirSite\Core\Measurement\Tracker;
use AIHazirSite\Core\Measurement\Verifier;
use AIHazirSite\Tests\Support\FakeHttpClient;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryCache;
use AIHazirSite\Tests\Support\MemoryHitRepository;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Support\StaticSecret;
use PHPUnit\Framework\TestCase;

/**
 * Classify → verify → count → report → CSV with in-memory adapters only.
 * Extends the plain PHPUnit TestCase: no Brain Monkey, so any WordPress
 * function call would be a fatal "undefined function" error.
 *
 * @covers \AIHazirSite\Core\Measurement\Tracker
 * @covers \AIHazirSite\Core\Measurement\Report
 */
final class MeasurementFlowTest extends TestCase {

	private const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.4; +https://openai.com/gptbot)';
	private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

	/**
	 * Adapters.
	 *
	 * @var MemorySettings
	 */
	private MemorySettings $settings;

	/**
	 * Counter storage.
	 *
	 * @var MemoryHitRepository
	 */
	private MemoryHitRepository $hits;

	/**
	 * Clock.
	 *
	 * @var FixedClock
	 */
	private FixedClock $clock;

	/**
	 * Wires the core with in-memory adapters and downloads the IP lists once.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new MemorySettings();
		$this->hits     = new MemoryHitRepository();
		$this->clock    = new FixedClock( '2026-09-27' );
		Features::use_settings( $this->settings );

		$urls = array();
		foreach ( Registry::bots() as $bot ) {
			if ( 'ip_ranges' === $bot->verify ) {
				$urls[] = $bot->verify_source;
			}
		}
		$http = new FakeHttpClient( array( 'https://openai.com/gptbot.json' => '{"prefixes":[{"ipv4Prefix":"20.125.66.80/28"}]}' ) );
		( new IpRanges( $this->settings, $http, $this->clock ) )->refresh( $urls );
	}

	/**
	 * A tracker as a platform would build it.
	 */
	private function tracker(): Tracker {
		$ranges = new IpRanges( $this->settings, new FakeHttpClient(), $this->clock );
		return new Tracker( $this->hits, $this->clock, null, new Verifier( $ranges, new MemoryCache(), new StaticSecret() ) );
	}

	/**
	 * End to end: the CSV shows exactly what was counted.
	 */
	public function test_end_to_end(): void {
		$requests = array(
			new Request( self::GPTBOT, '/urunler/?utm_source=x', '20.125.66.81' ),
			new Request( self::GPTBOT, '/urunler/', '20.125.66.90' ),
			new Request( self::GPTBOT, '/urunler/', '198.51.100.7' ),
			new Request( 'ClaudeBot/1.0', '/iletisim/', '203.0.113.5' ),
			new Request( self::CHROME, '/', '', 'https://chatgpt.com/' ),
			new Request( self::CHROME, '/?utm_source=perplexity.ai', '', '', 'perplexity.ai' ),
			new Request( self::CHROME, '/', '', 'https://www.google.com/' ),
		);
		$written  = 0;
		foreach ( $requests as $request ) {
			$written += (int) $this->tracker()->handle( $request );
		}
		$this->assertSame( 6, $written, 'The plain Google visit is not written.' );

		$this->clock->day = '2026-09-28';
		$csv              = CsvExport::to_csv( ( new Report( $this->hits, $this->clock, 7 ) )->rows() );

		$this->assertSame(
			CsvExport::BOM
			. "Bölüm,Kaynak,Sayfa,Doğrulanmış,Doğrulanmamış,Toplam\n"
			. "Bot,GPTBot,,2,1,3\n"
			. "Bot,ClaudeBot,,0,1,1\n"
			. "Sayfa,,/urunler/,2,1,3\n"
			. "Sayfa,,/iletisim/,0,1,1\n"
			. "Yönlendirme,ChatGPT,,0,1,1\n"
			. "Yönlendirme,Perplexity,,0,1,1\n",
			$csv
		);
	}

	/**
	 * With the feature off the core writes nothing.
	 */
	public function test_feature_off_writes_nothing(): void {
		Features::set( Features::MEASUREMENT, false );

		$this->assertFalse( $this->tracker()->handle( new Request( self::GPTBOT, '/', '20.125.66.81' ) ) );
		$this->assertSame( array(), $this->hits->rows );
	}
}
