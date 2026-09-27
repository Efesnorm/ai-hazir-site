<?php
/**
 * Tests for LlmsCache.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Llms;

use AIHazirSite\Adapters\Llms\LlmsCache;
use AIHazirSite\Tests\Support\MemorySettings;
use PHPUnit\Framework\TestCase;

/**
 * Rebuild only on changed input.
 *
 * @covers \AIHazirSite\Adapters\Llms\LlmsCache
 */
final class LlmsCacheTest extends TestCase {

	/**
	 * Same input: built once, then served from storage; changed input: rebuilt.
	 */
	public function test_rebuilds_only_when_input_changes(): void {
		$settings = new MemorySettings();
		$cache    = new LlmsCache( $settings );
		$builds   = 0;
		$build    = static function () use ( &$builds ): string {
			++$builds;
			return "# Örnek {$builds}\n";
		};

		$this->assertSame( "# Örnek 1\n", $cache->text( array( 'today' => '2026-09-27' ), $build ) );
		$this->assertSame( "# Örnek 1\n", $cache->text( array( 'today' => '2026-09-27' ), $build ) );
		$this->assertSame( "# Örnek 1\n", ( new LlmsCache( $settings ) )->text( array( 'today' => '2026-09-27' ), $build ), 'Stored across requests.' );
		$this->assertSame( 1, $builds );

		$this->assertSame( "# Örnek 2\n", $cache->text( array( 'today' => '2026-09-28' ), $build ) );
		$this->assertSame( 2, $builds );
	}

	/**
	 * Damaged storage is rebuilt, not served.
	 */
	public function test_damaged_storage_is_rebuilt(): void {
		$settings = new MemorySettings();
		$settings->set( LlmsCache::OPTION, 'bozuk' );

		$this->assertSame( "# Yeni\n", ( new LlmsCache( $settings ) )->text( array(), static fn(): string => "# Yeni\n" ) );
		$this->assertSame( "# Yeni\n", $settings->get( LlmsCache::OPTION )['text'] );
	}
}
