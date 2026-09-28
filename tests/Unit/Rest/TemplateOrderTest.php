<?php
/**
 * GET /templates order does not depend on the listing order.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Rest;

use AIHazirSite\Adapters\Rest\RestResponder;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use AIHazirSite\Tests\Support\TemplateFixtures as T;
use PHPUnit\Framework\TestCase;

/**
 * General first, then the profile's template, then the listings' templates by id.
 *
 * @covers \AIHazirSite\Adapters\Rest\RestResponder
 */
final class TemplateOrderTest extends TestCase {

	/**
	 * Every order of the same listings gives the same template list.
	 */
	public function test_order_is_stable(): void {
		$responder = new RestResponder( F::SITE_URL, T::CATALOG, T::registry() );
		$profile   = new CompanyProfile( 'Örnek A.Ş.', template: 'product' );
		$listings  = array_values( T::listings() );
		$ids       = static fn( array $templates ): array => array_map( static fn( Template $t ): string => $t->id, $templates );

		$forward  = $ids( $responder->used_templates( $profile, $listings, F::TODAY ) );
		$backward = $ids( $responder->used_templates( $profile, array_reverse( $listings ), F::TODAY ) );

		$this->assertSame( $forward, $backward );
		$this->assertSame( array( 'general', 'product' ), array_slice( $forward, 0, 2 ) );
		$rest   = array_slice( $forward, 2 );
		$sorted = $rest;
		sort( $sorted );
		$this->assertSame( $sorted, $rest, 'The listings\' templates are in id order.' );
		$this->assertSame( array_unique( $forward ), $forward, 'No duplicates.' );
	}
}
