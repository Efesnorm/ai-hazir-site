<?php
/**
 * Tests for TemplateValidator.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Templates;

use AIHazirSite\Core\Templates\TemplateRegistry;
use AIHazirSite\Core\Templates\TemplateValidator;
use PHPUnit\Framework\TestCase;

/**
 * Validation per shipped template.
 *
 * @covers \AIHazirSite\Core\Templates\TemplateValidator
 */
final class TemplateValidatorTest extends TestCase {

	/**
	 * Validates against a shipped template.
	 *
	 * @param string                $id         Template id.
	 * @param array<string, string> $attributes Attributes.
	 * @param bool                  $has_price  Whether a price was given.
	 * @return array{attributes: array<string, string>, errors: array<string, string>}
	 */
	private static function check( string $id, array $attributes, bool $has_price = false ): array {
		return ( new TemplateValidator() )->validate( ( new TemplateRegistry( array( TemplateRegistry::data_dir() ) ) )->get( $id ), $attributes, $has_price );
	}

	/**
	 * Product (cable): values normalized, extras kept, bad values rejected.
	 */
	public function test_product(): void {
		$ok = self::check(
			'product',
			array(
				'kesit'        => '2,5',
				'damar_sayisi' => '3',
				'iletken'      => 'bakır',
				'standart'     => 'TSE, IEC 60502-1, TSE',
				'renk'         => 'Siyah',
				'ozel_not'     => 'Serbest ek özellik',
			),
			true
		);
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame(
			array(
				'kesit'        => '2.5',
				'damar_sayisi' => '3',
				'iletken'      => 'Bakır',
				'standart'     => 'TSE, IEC 60502-1',
				'renk'         => 'Siyah',
				'ozel_not'     => 'Serbest ek özellik',
			),
			$ok['attributes']
		);

		$bad = self::check(
			'product',
			array(
				'kesit'        => '-1',
				'damar_sayisi' => '3.5',
				'iletken'      => 'Demir',
			)
		);
		$this->assertSame( array( 'attributes.kesit', 'attributes.damar_sayisi', 'attributes.iletken' ), array_keys( $bad['errors'] ) );
		$this->assertSame( 'İletken şunlardan biri olmalı: Bakır, Alüminyum.', $bad['errors']['attributes.iletken'] );
	}

	/**
	 * Export product: codes are upper-cased and checked; Incoterms only.
	 */
	public function test_export_product(): void {
		$ok = self::check(
			'export_product',
			array(
				'gtip'           => '8544.49.93.00.00',
				'mense'          => 'tr',
				'teslim_sekli'   => 'fob',
				'hedef_pazarlar' => 'de, fr',
				'moq'            => '500',
			),
			true
		);
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( array( 'TR', 'FOB', 'DE, FR' ), array( $ok['attributes']['mense'], $ok['attributes']['teslim_sekli'], $ok['attributes']['hedef_pazarlar'] ) );

		$bad = self::check(
			'export_product',
			array(
				'gtip'           => '85',
				'mense'          => 'Türkiye',
				'teslim_sekli'   => 'FOB İstanbul',
				'hedef_pazarlar' => 'DE, Almanya',
			)
		);
		$this->assertSame( array( 'attributes.gtip', 'attributes.mense', 'attributes.teslim_sekli', 'attributes.hedef_pazarlar' ), array_keys( $bad['errors'] ) );
		$this->assertStringContainsString( '"ALMANYA"', $bad['errors']['attributes.hedef_pazarlar'] );
	}

	/**
	 * Service: required field; a price is refused.
	 */
	public function test_service(): void {
		$ok = self::check(
			'service',
			array(
				'uzmanlik_alani' => 'Ticaret hukuku, Tahkim',
				'diller'         => 'TR, en',
			)
		);
		$this->assertSame( array(), $ok['errors'] );
		$this->assertSame( 'tr, en', $ok['attributes']['diller'] );

		$bad = self::check( 'service', array(), true );
		$this->assertSame(
			array(
				'price_min'                 => '"Hizmet (hukuk)" şablonunda fiyat girilmez; fiyat alanlarını boş bırakın.',
				'attributes.uzmanlik_alani' => 'Uzmanlık alanı zorunludur.',
			),
			$bad['errors']
		);
	}

	/**
	 * Tour: required real date; integers only; empty optional values dropped.
	 */
	public function test_tour(): void {
		$ok = self::check(
			'tour',
			array(
				'baslangic_tarihi' => '2026-10-15',
				'sure_gun'         => '7',
				'kalan_yer'        => '12',
				'kontenjan'        => '',
			),
			true
		);
		$this->assertSame( array(), $ok['errors'] );
		$this->assertArrayNotHasKey( 'kontenjan', $ok['attributes'] );

		$bad = self::check(
			'tour',
			array(
				'baslangic_tarihi' => '2026-02-30',
				'kalan_yer'        => 'on iki',
			)
		);
		$this->assertSame( array( 'attributes.baslangic_tarihi', 'attributes.kalan_yer' ), array_keys( $bad['errors'] ) );
	}

	/**
	 * General: nothing is checked, attributes pass through.
	 */
	public function test_general(): void {
		$this->assertSame(
			array(
				'attributes' => array( 'kesit' => 'her şey' ),
				'errors'     => array(),
			),
			self::check( 'general', array( 'kesit' => 'her şey' ), true )
		);
	}
}
