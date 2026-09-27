<?php
/**
 * The compliance wizard changes nothing outside the plugin.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Architecture;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Compliance\Wizard\Wizard;
use AIHazirSite\Core\Compliance\Wizard\WizardJournal;
use AIHazirSite\Core\Contracts\Settings;
use AIHazirSite\Core\Features;
use AIHazirSite\Tests\Support\FixedClock;
use AIHazirSite\Tests\Support\MemoryListingRepository;
use AIHazirSite\Tests\Support\MemoryProfileRepository;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Unit\Compliance\Wizard\WizardTest;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * (1) Every setting the core wizard writes is an `aihs_` option; (2) the wizard's code
 * (core and WordPress) calls no function that writes options, theme mods, posts, users,
 * plugins or files directly.
 *
 * @coversNothing
 */
final class WizardWritesOnlyPluginDataTest extends TestCase {

	/**
	 * Direct writers the wizard code must not call.
	 */
	private const FORBIDDEN = array( 'update_option', 'add_option', 'delete_option', 'update_site_option', 'delete_site_option', 'set_theme_mod', 'remove_theme_mod', 'switch_theme', 'activate_plugin', 'deactivate_plugins', 'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'update_post_meta', 'delete_post_meta', 'update_user_meta', 'wp_update_user', 'file_put_contents', 'fopen', 'fwrite', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch', 'chmod', 'flush_rewrite_rules' );

	/**
	 * Folders holding wizard code.
	 */
	private const FOLDERS = array( 'Core/Compliance/Wizard', 'WordPress/Compliance/Wizard' );

	/**
	 * A full wizard run (all steps, then undo) writes only `aihs_` keys.
	 */
	public function test_only_plugin_options_are_written(): void {
		$settings = new class() implements Settings {
			/**
			 * Keys written or deleted.
			 *
			 * @var list<string>
			 */
			public array $written = array();

			/**
			 * Backing store.
			 *
			 * @var MemorySettings
			 */
			private MemorySettings $inner;

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->inner = new MemorySettings();
			}

			/**
			 * Reads.
			 *
			 * @param string $key           Key.
			 * @param mixed  $default_value Default.
			 */
			public function get( string $key, mixed $default_value = null ): mixed {
				return $this->inner->get( $key, $default_value );
			}

			/**
			 * Writes and records.
			 *
			 * @param string $key      Key.
			 * @param mixed  $value    Value.
			 * @param bool   $autoload Autoload.
			 */
			public function set( string $key, mixed $value, bool $autoload = false ): void {
				$this->written[] = $key;
				$this->inner->set( $key, $value, $autoload );
			}

			/**
			 * Deletes and records.
			 *
			 * @param string $key Key.
			 */
			public function delete( string $key ): void {
				$this->written[] = $key;
				$this->inner->delete( $key );
			}
		};
		Features::use_settings( $settings );
		$wizard = new Wizard( $settings, new CatalogService( new MemoryListingRepository(), new MemoryProfileRepository(), new FixedClock() ), new WizardJournal( $settings ), new FixedClock() );

		foreach ( WizardTest::inputs() as $step => $input ) {
			$this->assertSame( array(), $wizard->apply( $step, $input ) );
		}
		foreach ( array_reverse( array_keys( WizardTest::inputs() ) ) as $step ) {
			$this->assertNull( $wizard->undo( $step ) );
		}

		$this->assertNotEmpty( $settings->written );
		foreach ( array_unique( $settings->written ) as $key ) {
			$this->assertStringStartsWith( 'aihs_', $key );
		}
	}

	/**
	 * No direct writer calls in the wizard's code.
	 */
	public function test_no_direct_writers(): void {
		$root       = dirname( __DIR__, 3 ) . '/src/';
		$violations = array();
		$scanned    = 0;
		foreach ( self::FOLDERS as $folder ) {
			if ( ! is_dir( $root . $folder ) ) {
				continue;
			}
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $folder ) ) as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				++$scanned;
				$calls = CatalogWritesOnlyThroughServiceTest::calls( (string) file_get_contents( $file->getPathname() ), false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				foreach ( array_intersect( array_keys( $calls ), self::FORBIDDEN ) as $name ) {
					$violations[] = $folder . '/' . $file->getFilename() . ': ' . $name . '()';
				}
			}
		}

		$this->assertGreaterThan( 0, $scanned );
		$this->assertSame( array(), $violations );
	}
}
