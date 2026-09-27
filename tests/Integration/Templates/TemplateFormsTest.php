<?php
/**
 * Template-driven admin forms and outputs.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Templates;

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Schema\CatalogPage;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_UnitTestCase;

/**
 * Forms per template (snapshots in tests/Snapshots/templates/<id>.form.html).
 *
 * @covers \AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin
 * @covers \AIHazirSite\WordPress\Templates\TemplatesModule
 */
final class TemplateFormsTest extends WP_UnitTestCase {

	/**
	 * Admin page.
	 *
	 * @var CatalogAdmin
	 */
	private CatalogAdmin $admin;

	/**
	 * Administrator with catalog and templates on.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'aihs_llms_cache' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Features::set( Features::CATALOG, true );
		Features::set( Features::TEMPLATES, true );
		TemplatesModule::reset();
		$this->admin = new CatalogAdmin();
	}

	/**
	 * Clears request globals and the loaded registry.
	 */
	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'] );
		TemplatesModule::reset();
		parent::tear_down();
	}

	/**
	 * Empty form state.
	 *
	 * @return array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>}
	 */
	private static function state(): array {
		return array(
			'errors'   => array(),
			'input'    => array(),
			'warnings' => array(),
		);
	}

	/**
	 * Stores a profile using the given template.
	 *
	 * @param string $template Template id.
	 */
	private static function profile( string $template ): void {
		$result = CatalogModule::service()->save_profile(
			array(
				'name'     => 'Örnek A.Ş.',
				'template' => $template,
			)
		);
		self::assertTrue( $result->is_valid(), implode( ' | ', $result->errors ) );
	}

	/**
	 * Form HTML without per-request values (nonce, referer, site address).
	 *
	 * @param string $html HTML.
	 */
	private static function normalize( string $html ): string {
		$html = (string) preg_replace( '/name="_wpnonce" value="[0-9a-f]+"/', 'name="_wpnonce" value="NONCE"', $html );
		$html = (string) preg_replace( '/name="_wp_http_referer" value="[^"]*"/', 'name="_wp_http_referer" value="REFERER"', $html );
		return str_replace( admin_url(), 'ADMIN/', $html ) . "\n";
	}

	/**
	 * One case per shipped sector template.
	 *
	 * @return array<string, array{string}>
	 */
	public static function templates(): array {
		return array(
			'product'        => array( 'product' ),
			'export_product' => array( 'export_product' ),
			'service'        => array( 'service' ),
			'tour'           => array( 'tour' ),
		);
	}

	/**
	 * New-listing form of each template equals its reviewed snapshot.
	 *
	 * @dataProvider templates
	 * @param string $id Template id.
	 */
	public function test_form_snapshot( string $id ): void {
		self::profile( $id );
		$html = self::normalize( CatalogAdmin::render_form( 'offer', null, self::state() ) );
		$file = dirname( __DIR__, 2 ) . '/Snapshots/templates/' . $id . '.form.html';

		if ( ! file_exists( $file ) ) {
			file_put_contents( $file, $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->markTestIncomplete( 'Snapshot written; review it and run again.' );
		}
		$this->assertSame( (string) file_get_contents( $file ), $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Field kinds: select for enum, date input, required marker, unit in the label; no price on service.
	 */
	public function test_field_kinds_and_price_rule(): void {
		self::profile( 'product' );
		$product = CatalogAdmin::render_form( 'offer', null, self::state() );
		$this->assertStringContainsString( '<select id="aihs-attr-iletken" name="attr[iletken]">', $product );
		$this->assertStringContainsString( '>Kesit (mm²)</label>', $product );
		$this->assertStringContainsString( 'name="template" value="product"', $product );
		$this->assertStringContainsString( 'name="price_min"', $product );

		self::profile( 'service' );
		$service = CatalogAdmin::render_form( 'offer', null, self::state() );
		$this->assertStringNotContainsString( 'name="price_min"', $service );
		$this->assertStringNotContainsString( 'name="currency"', $service );
		$this->assertStringContainsString( '>Uzmanlık alanı *</label>', $service );

		self::profile( 'tour' );
		$this->assertStringContainsString( '<input type="date" class="regular-text" id="aihs-attr-baslangic-tarihi" name="attr[baslangic_tarihi]" required', CatalogAdmin::render_form( 'offer', null, self::state() ) );
	}

	/**
	 * Saving through the form: template fields and free extras are stored; errors come back per field.
	 */
	public function test_save_through_form(): void {
		self::profile( 'tour' );
		$_REQUEST['_wpnonce'] = wp_create_nonce( CatalogAdmin::SAVE_LISTING );
		$post                 = array(
			'type'       => 'offer',
			'id'         => '',
			'template'   => 'tour',
			'title'      => 'Kapadokya turu',
			'price_min'  => '250',
			'currency'   => 'EUR',
			'attributes' => "not: serbest\nkalan_yer: 99",
			'attr'       => array(
				'baslangic_tarihi' => '2026-10-15',
				'kalan_yer'        => 'on iki',
			),
		);

		$redirect = $this->admin->handle_save_listing( $post );
		$this->assertStringContainsString( 'new=1', $redirect );
		$state = FormState::take();
		$this->assertArrayHasKey( 'attributes.kalan_yer', $state['errors'] );
		$form = CatalogAdmin::render_form( 'offer', null, $state );
		$this->assertStringContainsString( 'id="aihs-attr-kalan-yer-error"', $form );
		$this->assertStringContainsString( 'value="on iki"', $form, 'The typed value is kept.' );

		$post['attr']['kalan_yer'] = '12';
		$this->assertStringContainsString( 'message=saved', $this->admin->handle_save_listing( $post ) );
		$stored = ( new WpListingRepository() )->all( 'offer' )[0];
		$this->assertSame( 'tour', $stored->template );
		$this->assertSame(
			array(
				'not'              => 'serbest',
				'kalan_yer'        => '12',
				'baslangic_tarihi' => '2026-10-15',
			),
			$stored->attributes,
			'The form field wins over a same-named free line.'
		);

		// Edit form: template fields in their inputs, only the extras in the free text.
		$edit = CatalogAdmin::render_form( 'offer', $stored, self::state() );
		$this->assertStringContainsString( 'name="attr[kalan_yer]" inputmode="numeric" value="12"', $edit );
		$this->assertStringContainsString( '>not: serbest</textarea>', $edit );
		$this->assertStringNotContainsString( 'name="template"', $edit, 'The template of a stored listing cannot be changed.' );
	}

	/**
	 * Profile: template choice listed and saved; off, the choice is hidden and the stored one kept.
	 */
	public function test_profile_template_choice(): void {
		$html = CatalogAdmin::render_profile_form( ( new WpProfileRepository() )->get(), self::state() );
		foreach ( array( 'Genel', 'Ürün (kablo)', 'İhracat ürünü', 'Hizmet (hukuk)', 'Tur' ) as $name ) {
			$this->assertStringContainsString( '>' . $name . '</option>', $html );
		}

		$_REQUEST['_wpnonce'] = wp_create_nonce( CatalogAdmin::SAVE_PROFILE );
		$this->admin->handle_save_profile(
			array(
				'name'     => 'Tur A.Ş.',
				'template' => 'tour',
			)
		);
		$this->assertSame( 'tour', ( new WpProfileRepository() )->get()->template );

		Features::set( Features::TEMPLATES, false );
		TemplatesModule::reset();
		$this->assertStringNotContainsString( 'name="template"', CatalogAdmin::render_profile_form( ( new WpProfileRepository() )->get(), self::state() ) );
		$this->assertStringNotContainsString( 'Sektör şablonu', CatalogAdmin::render_form( 'offer', null, self::state() ) );
		$this->admin->handle_save_profile( array( 'name' => 'Tur A.Ş.' ) );
		$this->assertSame( 'tour', ( new WpProfileRepository() )->get()->template, 'Kept while templates are off.' );
	}

	/**
	 * The catalog page and llms.txt show template fields with labels and units.
	 */
	public function test_outputs_use_template(): void {
		Features::set( Features::LLMS_TXT, true );
		self::profile( 'tour' );
		CatalogModule::service()->save_listing(
			array(
				'type'       => 'offer',
				'title'      => 'Kapadokya turu',
				'template'   => 'tour',
				'attributes' => array(
					'baslangic_tarihi' => '2026-10-15',
					'sure_gun'         => '3',
					'kalan_yer'        => '6',
				),
			)
		);

		$this->assertStringContainsString( 'Başlangıç tarihi: 2026-10-15; Süre: 3 gün; Kalan yer: 6;', (string) LlmsModule::response( '/llms.txt' ) );
		$this->assertStringContainsString( 'Başlangıç tarihi: 2026-10-15; Süre: 3 gün; Kalan yer: 6;', CatalogPage::render_html() );
	}
}
