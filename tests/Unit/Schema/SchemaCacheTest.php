<?php
/**
 * Tests for SchemaCache.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Schema;

use AIHazirSite\Adapters\Schema\SchemaBuilder;
use AIHazirSite\Adapters\Schema\SchemaCache;
use AIHazirSite\Core\Contracts\Settings;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Last-valid-output behaviour.
 *
 * @covers \AIHazirSite\Adapters\Schema\SchemaCache
 */
final class SchemaCacheTest extends TestCase {

	/**
	 * Settings that count writes.
	 *
	 * @var Settings&object{writes: int}
	 */
	private Settings $settings;

	/**
	 * Fresh counting settings.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = new class() implements Settings {
			/**
			 * Number of set() calls.
			 *
			 * @var int
			 */
			public int $writes = 0;

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
			 * Get.
			 *
			 * @param string $key           Key.
			 * @param mixed  $default_value Default.
			 */
			public function get( string $key, mixed $default_value = null ): mixed {
				return $this->inner->get( $key, $default_value );
			}

			/**
			 * Set (counted).
			 *
			 * @param string $key      Key.
			 * @param mixed  $value    Value.
			 * @param bool   $autoload Autoload.
			 */
			public function set( string $key, mixed $value, bool $autoload = false ): void {
				++$this->writes;
				$this->inner->set( $key, $value, $autoload );
			}

			/**
			 * Delete.
			 *
			 * @param string $key Key.
			 */
			public function delete( string $key ): void {
				$this->inner->delete( $key );
			}
		};
	}

	/**
	 * A valid document.
	 *
	 * @return array<string, mixed>
	 */
	private static function valid(): array {
		return ( new SchemaBuilder( F::SITE_URL ) )->home( F::profile(), '2026-09-22T08:00:00Z' );
	}

	/**
	 * A broken document (Organization without name, bad date).
	 *
	 * @return array<string, mixed>
	 */
	private static function broken(): array {
		$doc                              = self::valid();
		$doc['@graph'][0]['name']         = '';
		$doc['@graph'][1]['dateModified'] = 'bozuk';
		return $doc;
	}

	/**
	 * Valid output is published and cached; the same output again causes no write.
	 */
	public function test_valid_output_is_published_and_cached_once(): void {
		$cache = new SchemaCache( $this->settings );

		$this->assertSame( self::valid(), $cache->publish( 'home', self::valid(), '2026-09-27T10:00:00Z' ) );
		$this->assertSame( 1, $this->settings->writes );

		$cache->publish( 'home', self::valid(), '2026-09-27T10:01:00Z' );
		$cache->publish( 'home', self::valid(), '2026-09-27T10:02:00Z' );
		$this->assertSame( 1, $this->settings->writes, 'Unchanged output: no database write per page view.' );
	}

	/**
	 * On a validation error the last valid output is served and the error is recorded.
	 */
	public function test_error_serves_last_valid_output(): void {
		$cache = new SchemaCache( $this->settings );
		$cache->publish( 'home', self::valid(), '2026-09-27T10:00:00Z' );

		$served = $cache->publish( 'home', self::broken(), '2026-09-27T11:00:00Z' );

		$this->assertSame( self::valid(), $served, 'Broken output is never published.' );
		$errors = $cache->errors();
		$this->assertSame( '2026-09-27T11:00:00Z', $errors['home']['at'] );
		$this->assertStringContainsString( 'name zorunlu', implode( ' ', $errors['home']['errors'] ) );

		$writes = $this->settings->writes;
		$cache->publish( 'home', self::broken(), '2026-09-27T11:05:00Z' );
		$this->assertSame( $writes, $this->settings->writes, 'Same error again: no write.' );

		$cache->publish( 'home', self::valid(), '2026-09-27T12:00:00Z' );
		$this->assertSame( array(), $cache->errors(), 'Fixed output clears the error.' );
	}

	/**
	 * Without any earlier valid output nothing is published; pages are cached separately.
	 */
	public function test_no_cache_publishes_nothing(): void {
		$cache = new SchemaCache( $this->settings );

		$this->assertNull( $cache->publish( 'catalog', self::broken(), '2026-09-27T10:00:00Z' ) );
		$cache->publish( 'home', self::valid(), '2026-09-27T10:00:00Z' );
		$this->assertNull( $cache->publish( 'catalog', self::broken(), '2026-09-27T10:01:00Z' ), 'Home cache is not used for catalog.' );
	}
}
