<?php
/**
 * Listing validity rule.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingValidity;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Validity rule tests.
 *
 * @covers \AIHazirSite\Core\Catalog\ListingValidity
 */
final class ListingValidityTest extends TestCase {

	/**
	 * Own date wins; otherwise last update + 90 days; never stored = today + 90 days.
	 */
	public function test_valid_until(): void {
		$this->assertSame( '2026-12-31', ListingValidity::valid_until( F::offer(), F::TODAY ) );
		$this->assertSame( '2026-12-24', ListingValidity::valid_until( F::unpriced(), F::TODAY ) );
		$this->assertSame( '2026-09-17', ListingValidity::valid_until( F::stale(), F::TODAY ) );
		$this->assertSame( '2026-12-26', ListingValidity::valid_until( new Listing( null, 'offer', 'Yeni' ), F::TODAY ) );
	}

	/**
	 * Current until the last day inclusive; unknown types never.
	 */
	public function test_is_current(): void {
		$this->assertTrue( ListingValidity::is_current( F::offer(), F::TODAY ) );
		$this->assertTrue( ListingValidity::is_current( F::expired(), '2026-09-26' ), 'The last day itself is still valid.' );
		$this->assertFalse( ListingValidity::is_current( F::expired(), F::TODAY ) );
		$this->assertFalse( ListingValidity::is_current( F::stale(), F::TODAY ) );
		$this->assertFalse( ListingValidity::is_current( new Listing( 1, 'rent', 'Kiralık', valid_until: '2027-01-01' ), F::TODAY ) );
	}
}
