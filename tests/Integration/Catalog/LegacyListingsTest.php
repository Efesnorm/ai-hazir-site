<?php
/**
 * Listings saved before 0.8.0 (no template).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Catalog;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Templates\TemplateRegistry;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\PostType;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Platform\WpClock;
use WP_UnitTestCase;

/**
 * An A1 listing (0.4.0 storage, no _aihs_template) is shown, edited and saved as "general".
 *
 * @covers \AIHazirSite\WordPress\Catalog\WpListingRepository
 * @covers \AIHazirSite\Core\Catalog\ListingValidator
 */
final class LegacyListingsTest extends WP_UnitTestCase {

	/**
	 * Stores a listing exactly as 0.4.0–0.7.0 did (no template meta).
	 */
	private static function legacy_listing(): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => PostType::NAME,
				'post_status'  => 'publish',
				'post_title'   => 'Eski NYY kablo',
				'post_content' => 'A1 döneminden kalan ilan.',
			)
		);
		update_post_meta( $id, '_aihs_type', 'offer' );
		update_post_meta( $id, '_aihs_price_min', '40' );
		update_post_meta( $id, '_aihs_currency', 'TRY' );
		update_post_meta( $id, '_aihs_attributes', array( 'kesit' => '3x2,5 mm²' ) );
		return $id;
	}

	/**
	 * Read as "general" with its attributes; listed; the edit form opens with its values.
	 */
	public function test_legacy_listing_is_displayed(): void {
		$id      = self::legacy_listing();
		$listing = ( new WpListingRepository() )->find( $id );

		$this->assertNotNull( $listing );
		$this->assertSame( 'general', $listing->template );
		$this->assertSame( array( 'kesit' => '3x2,5 mm²' ), $listing->attributes );
		$this->assertStringContainsString( 'Eski NYY kablo', CatalogAdmin::render_list( 'offer', array( $listing ), gmdate( 'Y-m-d' ) ) );
		$state = array(
			'errors'   => array(),
			'input'    => array(),
			'warnings' => array(),
		);
		$this->assertStringContainsString( 'kesit: 3x2,5 mm²', CatalogAdmin::render_form( 'offer', $listing, $state ) );
	}

	/**
	 * Saved with templates on: stays "general", attributes kept, template meta now written.
	 */
	public function test_legacy_listing_is_saved(): void {
		$id      = self::legacy_listing();
		$service = new CatalogService( new WpListingRepository(), new WpProfileRepository(), new WpClock(), new TemplateRegistry( array( TemplateRegistry::data_dir() ) ) );

		$result = $service->save_listing(
			array(
				'type'       => 'offer',
				'title'      => 'Eski NYY kablo',
				'price_min'  => '41',
				'currency'   => 'TRY',
				'attributes' => 'kesit: 3x2,5 mm²',
			),
			$id
		);

		$this->assertTrue( $result->is_valid(), implode( ' | ', $result->errors ) );
		$this->assertSame( 'general', get_post_meta( $id, '_aihs_template', true ) );
		$this->assertSame( array( 'kesit' => '3x2,5 mm²' ), ( new WpListingRepository() )->find( $id )?->attributes );
		$this->assertSame( '41', ( new WpListingRepository() )->find( $id )?->price_min );
	}

	/**
	 * A templated listing round-trips through post meta.
	 */
	public function test_template_is_stored(): void {
		$service = new CatalogService( new WpListingRepository(), new WpProfileRepository(), new WpClock(), new TemplateRegistry( array( TemplateRegistry::data_dir() ) ) );
		$id      = (int) $service->save_listing( array( 'type' => 'offer', 'title' => 'Tur', 'template' => 'tour', 'attributes' => array( 'baslangic_tarihi' => '2026-10-15' ) ) )->listing()?->id; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'tour', ( new WpListingRepository() )->find( $id )?->template );
	}
}
