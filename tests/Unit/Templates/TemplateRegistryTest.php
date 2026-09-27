<?php
/**
 * Tests for TemplateRegistry and the template file format.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Templates;

use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Shipped templates load; broken files are skipped and reported.
 *
 * @covers \AIHazirSite\Core\Templates\TemplateRegistry
 * @covers \AIHazirSite\Core\Templates\Template
 * @covers \AIHazirSite\Core\Templates\TemplateField
 */
final class TemplateRegistryTest extends TestCase {

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Creates an empty temporary folder.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/aihs-templates-' . uniqid();
		mkdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
	}

	/**
	 * Removes the temporary folder.
	 */
	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tearDown();
	}

	/**
	 * Writes a file into the temporary folder.
	 *
	 * @param string $name    File name.
	 * @param string $content Content.
	 */
	private function write( string $name, string $content ): void {
		file_put_contents( $this->dir . '/' . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * The five shipped templates load without errors.
	 */
	public function test_shipped_templates(): void {
		$registry = new TemplateRegistry( array( TemplateRegistry::data_dir() ) );

		$this->assertSame( array(), $registry->errors() );
		$this->assertSame( array( 'general', 'export_product', 'product', 'service', 'tour' ), array_keys( $registry->all() ) );
		$this->assertSame( array(), $registry->get( 'general' )->fields );
		$this->assertSame( array( 'kesit', 'damar_sayisi', 'iletken', 'izolasyon', 'standart', 'renk', 'makara_boyu' ), array_column( $registry->get( 'product' )->fields, 'name' ) );
		$this->assertSame( array( 'gtip', 'mense', 'teslim_sekli', 'hedef_pazarlar', 'sertifikalar', 'moq' ), array_column( $registry->get( 'export_product' )->fields, 'name' ) );
		$this->assertSame( array( 'uzmanlik_alani', 'ofis_ulkesi', 'diller' ), array_column( $registry->get( 'service' )->fields, 'name' ) );
		$this->assertSame( array( 'baslangic_tarihi', 'sure_gun', 'kontenjan', 'kalan_yer', 'dahil_olanlar', 'iptal_kosulu', 'bulusma_noktasi' ), array_column( $registry->get( 'tour' )->fields, 'name' ) );

		$this->assertFalse( $registry->get( 'service' )->price );
		$this->assertSame( 'Service', $registry->get( 'service' )->item_type );
		$this->assertSame( 'TouristTrip', $registry->get( 'tour' )->item_type );
		$this->assertSame( 24, $registry->get( 'tour' )->field( 'kalan_yer' )?->fresh_hours );
		$this->assertTrue( $registry->get( 'tour' )->has_freshness() );
		$this->assertFalse( $registry->get( 'product' )->has_freshness() );
		$this->assertSame( 'MMK', $registry->get( 'product' )->field( 'kesit' )?->unit_code );
	}

	/**
	 * Unknown or empty ids resolve to "general"; it exists even without its file.
	 */
	public function test_general_fallback(): void {
		$registry = new TemplateRegistry( array( $this->dir ) );

		$this->assertSame( array( 'general' ), array_keys( $registry->all() ) );
		$this->assertSame( 'general', $registry->get( 'silinmis_sablon' )->id );
		$this->assertSame( 'general', $registry->get( '' )->id );
		$this->assertFalse( $registry->has( 'tour' ) );
	}

	/**
	 * A new file is a new template; broken files and duplicate ids are skipped with a reason.
	 */
	public function test_new_and_broken_files(): void {
		$this->write( 'a_ok.json', '{"id":"insaat","version":1,"name":"İnşaat","fields":[{"name":"metrekare","label":"Alan","type":"decimal","unit":"m²"}]}' );
		$this->write( 'b_dup.json', '{"id":"insaat","version":2,"name":"Kopya"}' );
		$this->write( 'c_json.json', '{"id":' );
		$this->write( 'd_enum.json', '{"id":"x","version":1,"name":"X","fields":[{"name":"renk","label":"Renk","type":"enum"}]}' );
		$this->write( 'e_type.json', '{"id":"y","version":1,"name":"Y","item_type":"Event"}' );
		$this->write( 'f_pattern.json', '{"id":"z","version":1,"name":"Z","fields":[{"name":"kod","label":"Kod","type":"text","pattern":"(["}]}' );
		$this->write( 'g_schema.json', '{"id":"w","version":1,"name":"W","fields":[{"name":"kod","label":"Kod","type":"text","schema":{"node":"offer","property":"sku","as":"text"}}]}' );
		$this->write( 'h_field.json', '{"id":"v","version":1,"name":"V","fields":[{"name":"Kod","label":"Kod","type":"text"}]}' );
		$this->write( 'not-json.txt', 'yok' );

		$registry = new TemplateRegistry( array( $this->dir ) );
		$errors   = $registry->errors();

		$this->assertSame( array( 'general', 'insaat' ), array_keys( $registry->all() ) );
		$this->assertSame( 'm²', $registry->get( 'insaat' )->field( 'metrekare' )?->unit );
		$this->assertCount( 7, $errors );
		$this->assertStringContainsString( '"insaat" kimliği başka bir dosyada zaten tanımlı', $errors[ $this->dir . '/b_dup.json' ] );
		$this->assertStringStartsWith( 'JSON okunamadı', $errors[ $this->dir . '/c_json.json' ] );
		$this->assertStringContainsString( 'enum türünde izin verilen değerler', $errors[ $this->dir . '/d_enum.json' ] );
		$this->assertStringContainsString( 'item_type', $errors[ $this->dir . '/e_type.json' ] );
		$this->assertStringContainsString( 'desen (pattern) geçersiz', $errors[ $this->dir . '/f_pattern.json' ] );
		$this->assertStringContainsString( 'schema eşlemesi geçersiz', $errors[ $this->dir . '/g_schema.json' ] );
		$this->assertStringContainsString( 'alan adı "Kod" geçersiz', $errors[ $this->dir . '/h_field.json' ] );
	}

	/**
	 * Built-in general template.
	 */
	public function test_builtin_general(): void {
		$general = Template::general();

		$this->assertSame( array( 'general', 1, 'Genel', 'Product', true ), array( $general->id, $general->version, $general->name, $general->item_type, $general->price ) );
	}
}
