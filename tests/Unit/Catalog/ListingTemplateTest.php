<?php
/**
 * Templates in listing and profile validation.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Catalog;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Templates\TemplateRegistry;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryListingRepository;
use AIHazirSite\Tests\Support\MemoryProfileRepository;
use PHPUnit\Framework\TestCase;

/**
 * ListingValidator and ProfileValidator with and without the template registry.
 *
 * @covers \AIHazirSite\Core\Catalog\ListingValidator
 * @covers \AIHazirSite\Core\Catalog\ProfileValidator
 * @covers \AIHazirSite\Core\Catalog\CatalogService
 */
final class ListingTemplateTest extends TestCase {

	/**
	 * Listing storage.
	 *
	 * @var MemoryListingRepository
	 */
	private MemoryListingRepository $listings;

	/**
	 * Profile storage.
	 *
	 * @var MemoryProfileRepository
	 */
	private MemoryProfileRepository $profiles;

	/**
	 * Fresh storage.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->listings = new MemoryListingRepository();
		$this->profiles = new MemoryProfileRepository();
	}

	/**
	 * Service with the shipped templates, or with templates off.
	 *
	 * @param bool $templates Whether templates are on.
	 */
	private function service( bool $templates = true ): CatalogService {
		return new CatalogService( $this->listings, $this->profiles, new FixedClock( '2026-09-27' ), $templates ? new TemplateRegistry( array( TemplateRegistry::data_dir() ) ) : null );
	}

	/**
	 * A new listing is checked against its template and stores the template id.
	 */
	public function test_new_listing_uses_template(): void {
		$bad = $this->service()->save_listing(
			array(
				'type'       => 'offer',
				'title'      => 'Kapadokya turu',
				'template'   => 'tour',
				'attributes' => "kalan_yer: on\nnot: serbest",
			)
		);
		$this->assertSame( array( 'attributes.baslangic_tarihi', 'attributes.kalan_yer' ), array_keys( $bad->errors ) );
		$this->assertSame( array(), $this->listings->ids() );

		$ok = $this->service()->save_listing(
			array(
				'type'       => 'offer',
				'title'      => 'Kapadokya turu',
				'template'   => 'tour',
				'attributes' => array(
					'baslangic_tarihi' => '2026-10-15',
					'kalan_yer'        => '012',
					'not'              => 'serbest',
				),
			)
		);
		$this->assertTrue( $ok->is_valid(), implode( ' | ', $ok->errors ) );
		$this->assertSame( 'tour', $ok->listing()?->template );
		$this->assertSame(
			array(
				'baslangic_tarihi' => '2026-10-15',
				'kalan_yer'        => '12',
				'not'              => 'serbest',
			),
			$ok->listing()?->attributes
		);
	}

	/**
	 * Unknown template, template change and a price on the service template are refused.
	 */
	public function test_refusals(): void {
		$this->assertSame( 'Bilinmeyen sektör şablonu.', $this->service()->save_listing( array( 'type' => 'offer', 'title' => 'X', 'template' => 'yok' ) )->errors['template'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$id = (int) $this->service()->save_listing( array( 'type' => 'offer', 'title' => 'Kablo', 'template' => 'product' ) )->listing()?->id; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'İlanın şablonu değiştirilemez.', $this->service()->save_listing( array( 'type' => 'offer', 'title' => 'Kablo', 'template' => 'tour' ), $id )->errors['template'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$priced = $this->service()->save_listing(
			array(
				'type'       => 'offer',
				'title'      => 'Danışmanlık',
				'template'   => 'service',
				'price_min'  => '1000',
				'currency'   => 'TRY',
				'attributes' => array( 'uzmanlik_alani' => 'Tahkim' ),
			)
		);
		$this->assertSame( array( 'price_min' ), array_keys( $priced->errors ) );
	}

	/**
	 * Editing keeps the stored template without resending it.
	 */
	public function test_edit_keeps_template(): void {
		$id     = (int) $this->service()->save_listing( array( 'type' => 'supply', 'title' => 'Kablo', 'template' => 'product', 'attributes' => array( 'kesit' => '2,5' ) ) )->listing()?->id; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$edited = $this->service()->save_listing( array( 'type' => 'supply', 'title' => 'Kablo 2', 'attributes' => array( 'kesit' => '4' ) ), $id ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( array( 'product', array( 'kesit' => '4' ) ), array( $edited->listing()?->template, $edited->listing()?->attributes ) );
	}

	/**
	 * Templates off: new listings are "general" and nothing is template-checked; stored templates are kept.
	 */
	public function test_templates_off(): void {
		$new = $this->service( false )->save_listing( array( 'type' => 'offer', 'title' => 'Tur', 'template' => 'tour', 'attributes' => array( 'kalan_yer' => 'çok' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'general', array( 'kalan_yer' => 'çok' ) ), array( $new->listing()?->template, $new->listing()?->attributes ) );

		$tour = $this->listings->store_listing( new Listing( null, 'offer', 'Eski tur', attributes: array( 'baslangic_tarihi' => '2026-10-15' ), template: 'tour' ) );
		$kept = $this->service( false )->save_listing(
			array(
				'type'  => 'offer',
				'title' => 'Eski tur',
			),
			$tour->id
		);
		$this->assertSame( 'tour', $kept->listing()?->template );
	}

	/**
	 * Listings stored before 0.8.0 (no template) read as "general" and save as before.
	 */
	public function test_legacy_listing(): void {
		$legacy = Listing::from_array(
			array(
				'id'         => 7,
				'type'       => 'offer',
				'title'      => 'Eski ilan',
				'attributes' => array( 'renk' => 'mavi' ),
			)
		);
		$this->assertSame( 'general', $legacy->template );

		$stored = $this->listings->store_listing( $legacy );
		$saved  = $this->service()->save_listing( array( 'type' => 'offer', 'title' => 'Eski ilan', 'attributes' => "renk: mavi\nnot: yeni" ), $stored->id ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertTrue( $saved->is_valid() );
		$this->assertSame( array( 'general', array( 'renk' => 'mavi', 'not' => 'yeni' ) ), array( $saved->listing()?->template, $saved->listing()?->attributes ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Profile: known template stored; unknown refused; a form without the choice keeps the stored one;
	 * with templates off the stored choice is kept.
	 */
	public function test_profile_template(): void {
		$this->assertSame( 'tour', $this->service()->save_profile( array( 'name' => 'Tur A.Ş.', 'template' => 'tour' ) )->profile()?->template ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'tour', $this->service()->save_profile( array( 'name' => 'Tur A.Ş.' ) )->profile()?->template );
		$this->assertSame( 'Bilinmeyen sektör şablonu.', $this->service()->save_profile( array( 'name' => 'Tur A.Ş.', 'template' => 'yok' ) )->errors['template'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		// Templates off: the choice is neither shown nor changed; the stored one survives any input.
		$this->assertSame( 'tour', $this->service( false )->save_profile( array( 'name' => 'Tur A.Ş.', 'template' => 'product' ) )->profile()?->template ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'general', ( new MemoryProfileRepository() )->get()->template );
	}
}
