<?php
/**
 * Language settings and negotiation.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\I18n;

use AIHazirSite\Core\I18n\LanguageNegotiator;
use AIHazirSite\Core\I18n\LanguageSettings;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Normalisation, storage round trip and RFC 9110 / RFC 4647 negotiation.
 *
 * @covers \AIHazirSite\Core\I18n\LanguageSettings
 * @covers \AIHazirSite\Core\I18n\LanguageNegotiator
 */
final class LanguageTest extends UnitTestCase {

	/**
	 * Codes are reduced to their primary subtag; invalid and duplicate ones are dropped; default first.
	 */
	public function test_settings(): void {
		$settings = new LanguageSettings( 'tr', array( 'EN-gb', 'de', 'tr', 'xyz', 'en', 'pt_BR', '' ) );
		$this->assertSame( array( 'tr', 'en', 'de', 'pt' ), $settings->languages );
		$this->assertSame( array( 'en', 'de', 'pt' ), $settings->translated() );
		$this->assertTrue( $settings->is_multilingual() );
		$this->assertFalse( ( new LanguageSettings( 'tr' ) )->is_multilingual() );
		$this->assertTrue( $settings->has( 'de' ) );
		$this->assertFalse( $settings->has( 'fr' ) );

		$this->assertEquals( $settings, LanguageSettings::from_array( $settings->to_array(), 'en' ) );
		$fallback = LanguageSettings::from_array( 'bozuk', 'tr_TR' );
		$this->assertSame( array( 'tr' ), $fallback->languages );
		$this->assertSame( 'tr', LanguageSettings::from_array( array( 'default' => '??' ), '' )->default );
	}

	/**
	 * Explicit parameter → Accept-Language by quality → default.
	 */
	public function test_negotiation(): void {
		$negotiator = new LanguageNegotiator( new LanguageSettings( 'tr', array( 'en', 'de' ) ) );

		$this->assertSame( 'en', $negotiator->negotiate( 'en', 'de' ) );
		$this->assertSame( 'en', $negotiator->negotiate( 'EN-US' ) );
		$this->assertSame( 'de', $negotiator->negotiate( 'fr', 'fr-FR, de;q=0.8, en;q=0.5' ), 'Unpublished parameter falls through to the header.' );
		$this->assertSame( 'en', $negotiator->negotiate( null, 'de;q=0.3, en-GB;q=0.9' ) );
		$this->assertSame( 'de', $negotiator->negotiate( null, 'en;q=0, de' ), 'q=0 means not acceptable.' );
		$this->assertSame( 'tr', $negotiator->negotiate( null, 'fr, ja' ) );
		$this->assertSame( 'tr', $negotiator->negotiate( null, '*' ) );
		$this->assertSame( 'tr', $negotiator->negotiate( null, '' ) );
		$this->assertSame( 'tr', $negotiator->negotiate( '', 'bozuk;;;q=abc' ) );

		$this->assertSame( array( 'b', 'a', 'c' ), LanguageNegotiator::ranges( 'a;q=0.5, b, c;q=0.5' ), 'Equal quality keeps header order.' );
	}
}
