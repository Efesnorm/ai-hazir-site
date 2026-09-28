<?php
/**
 * Schema.org output with an empty profile name.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Schema;

use AIHazirSite\Adapters\Schema\SchemaBuilder;
use AIHazirSite\Adapters\Schema\SchemaValidator;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * A fresh install has no profile name yet: the site name is used (as llms.txt and the A2A card do),
 * so the output stays valid instead of an "Organization: name zorunlu" error.
 *
 * @covers \AIHazirSite\Adapters\Schema\SchemaBuilder
 */
final class SiteNameFallbackTest extends TestCase {

	/**
	 * Empty profile, both Organization modes (ours, or another SEO plugin's).
	 */
	public function test_site_name_when_profile_has_no_name(): void {
		$empty     = new CompanyProfile( '', '', '', array(), '', '', array() );
		$validator = new SchemaValidator();

		foreach ( array( true, false ) as $link ) {
			$builder = new SchemaBuilder( F::SITE_URL, $link, null, 'Makedonya' );
			$feed    = $builder->catalog( $empty, array( F::offer() ), F::TODAY, F::SITE_URL . 'ai-katalog/' );
			$this->assertSame( 'AI Katalog – Makedonya', $feed['name'] );
			$this->assertSame( array(), $validator->validate( $feed )['errors'], $link ? 'our @id' : 'named node' );
			if ( ! $link ) {
				$this->assertSame( 'Makedonya', $feed['publisher']['name'] );
				$this->assertSame( 'Makedonya', $feed['dataFeedElement'][0]['item']['offers']['seller']['name'] );
			}
		}

		$home = ( new SchemaBuilder( F::SITE_URL, true, null, 'Makedonya' ) )->home( $empty, '2026-09-22T08:00:00Z' );
		$this->assertSame( array( 'Makedonya', 'Makedonya' ), array_column( $home['@graph'], 'name' ) );
		$this->assertSame( array(), $validator->validate( $home )['errors'] );
	}

	/**
	 * A profile name always wins over the site name.
	 */
	public function test_profile_name_wins(): void {
		$feed = ( new SchemaBuilder( F::SITE_URL, false, null, 'Makedonya' ) )->catalog( F::profile(), array( F::offer() ), F::TODAY, F::SITE_URL . 'ai-katalog/' );
		$this->assertSame( 'Örnek Kablo A.Ş.', $feed['publisher']['name'] );
	}
}
