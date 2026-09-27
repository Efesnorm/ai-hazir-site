<?php
/**
 * Adding a sector is adding a file.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Templates;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Templates\TemplateRegistry;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Llms\LlmsModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use WP_UnitTestCase;

/**
 * A JSON file dropped into data/templates (or a folder added by filter) works without code changes.
 *
 * @covers \AIHazirSite\WordPress\Templates\TemplatesModule
 * @covers \AIHazirSite\Core\Templates\TemplateRegistry
 */
final class NewTemplateFileTest extends WP_UnitTestCase {

	/**
	 * Files created by the test.
	 *
	 * @var list<string>
	 */
	private array $files = array();

	/**
	 * Templates and llms.txt on.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'aihs_llms_cache' );
		Features::set( Features::CATALOG, true );
		Features::set( Features::TEMPLATES, true );
		Features::set( Features::LLMS_TXT, true );
		TemplatesModule::reset();
	}

	/**
	 * Removes the test files.
	 */
	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		remove_all_filters( 'aihs_template_dirs' );
		TemplatesModule::reset();
		parent::tear_down();
	}

	/**
	 * Writes a template file.
	 *
	 * @param string $path    Path.
	 * @param string $content JSON.
	 */
	private function write( string $path, string $content ): void {
		$this->assertFalse( file_exists( $path ), 'No leftover from an earlier run.' );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->files[] = $path;
	}

	/**
	 * A new file in data/templates: choosable, validated, published.
	 */
	public function test_new_file_in_data_templates(): void {
		$this->write( TemplateRegistry::data_dir() . '/zz_insaat.json', '{"id":"zz_insaat","version":1,"name":"İnşaat malzemesi","fields":[{"name":"alan","label":"Alan","type":"decimal","unit":"m²","unit_code":"MTK","required":true}]}' );
		TemplatesModule::reset();

		$this->assertStringContainsString( '<option value="zz_insaat">İnşaat malzemesi</option>', CatalogAdmin::render_profile_form( ( new WpProfileRepository() )->get(), array( 'errors' => array(), 'input' => array(), 'warnings' => array() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$service = CatalogModule::service();
		$this->assertTrue( $service->save_profile( array( 'name' => 'Yapı A.Ş.', 'template' => 'zz_insaat' ) )->is_valid() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'attributes.alan' ), array_keys( $service->save_listing( array( 'type' => 'offer', 'title' => 'Seramik', 'template' => 'zz_insaat' ) )->errors ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$saved = $service->save_listing( array( 'type' => 'offer', 'title' => 'Seramik', 'template' => 'zz_insaat', 'attributes' => array( 'alan' => '120,5' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'alan' => '120.5' ), $saved->listing()?->attributes );
		$this->assertStringContainsString( 'Alan: 120.5 m²', (string) LlmsModule::response( '/llms.txt' ) );
	}

	/**
	 * Folders added with the aihs_template_dirs filter are read too; broken files are reported to admins.
	 */
	public function test_filter_folder_and_broken_file_notice(): void {
		$dir = get_temp_dir() . 'aihs-extra-templates';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		$this->write( $dir . '/tekstil.json', '{"id":"tekstil","version":1,"name":"Tekstil","fields":[]}' );
		$this->write( $dir . '/bozuk.json', '{"id":' );
		add_filter( 'aihs_template_dirs', static fn( array $dirs ): array => array_merge( $dirs, array( $dir ) ) );
		TemplatesModule::reset();

		$this->assertTrue( TemplatesModule::registry()?->has( 'tekstil' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		TemplatesModule::admin_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( '<code>bozuk.json</code>: JSON okunamadı', $notice );
	}
}
