<?php
/**
 * Page-cache exclusion.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration;

use AIHazirSite\WordPress\Platform\PageCache;
use WP_UnitTestCase;

/**
 * DONOTCACHEPAGE is set (and an existing value is left alone).
 *
 * @covers \AIHazirSite\WordPress\Platform\PageCache
 */
final class PageCacheTest extends WP_UnitTestCase {

	/**
	 * The shared constant page-cache plugins read.
	 */
	public function test_sets_donotcachepage(): void {
		PageCache::exclude();
		PageCache::exclude();
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( (bool) constant( 'DONOTCACHEPAGE' ) );
	}
}
