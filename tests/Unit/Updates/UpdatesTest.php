<?php
/**
 * Update manifest, canary rollout and rollback.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Updates;

use AIHazirSite\Core\Contracts\PackageInstaller;
use AIHazirSite\Core\Licensing\FreeLicense;
use AIHazirSite\Core\Licensing\LicenseChecker;
use AIHazirSite\Core\Migrations\Migrator;
use AIHazirSite\Core\Updates\CanaryPolicy;
use AIHazirSite\Core\Updates\Release;
use AIHazirSite\Core\Updates\ReleaseManifest;
use AIHazirSite\Core\Updates\RollbackService;
use AIHazirSite\Tests\Fixtures\RecordingMigration;
use AIHazirSite\Tests\Support\MemorySettings;
use AIHazirSite\Tests\Unit\UnitTestCase;

/**
 * Pure update rules.
 *
 * @covers \AIHazirSite\Core\Updates\ReleaseManifest
 * @covers \AIHazirSite\Core\Updates\CanaryPolicy
 * @covers \AIHazirSite\Core\Updates\RollbackService
 * @covers \AIHazirSite\Core\Licensing\FreeLicense
 */
final class UpdatesTest extends UnitTestCase {

	/**
	 * A manifest with three valid releases and invalid entries.
	 */
	public static function manifest(): string {
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Unit test without WordPress.
			array(
				'slug'     => 'ai-hazir-site',
				'releases' => array(
					array(
						'version'      => '1.2.0',
						'download_url' => 'https://guncelleme.example/ai-hazir-site-1.2.0.zip',
						'released_at'  => '2026-09-20T10:00:00Z',
						'db_version'   => 1200,
					),
					array(
						'version'      => '1.4.0',
						'download_url' => 'https://guncelleme.example/ai-hazir-site-1.4.0.zip',
						'released_at'  => '2026-10-01T10:00:00Z',
						'db_version'   => 1400,
						'requires'     => '6.9',
						'requires_php' => '8.1',
						'tested'       => '7.1',
						'sections'     => array(
							'changelog' => '<p>Yeni</p>',
							'bozuk'     => array( 'x' ),
						),
					),
					array(
						'version'      => '1.3.0',
						'download_url' => 'https://guncelleme.example/ai-hazir-site-1.3.0.zip',
						'released_at'  => '2026-09-25T10:00:00Z',
						'db_version'   => 1200,
					),
					array(
						'version'      => '1.5.0',
						'download_url' => 'http://guvensiz.example/x.zip',
						'released_at'  => '2026-10-01T10:00:00Z',
						'db_version'   => 1500,
					),
					array(
						'version'      => 'bozuk',
						'download_url' => 'https://guncelleme.example/x.zip',
						'released_at'  => '2026-10-01T10:00:00Z',
						'db_version'   => 1,
					),
					array(
						'version'      => '1.6.0',
						'download_url' => 'https://guncelleme.example/x.zip',
						'released_at'  => 'dün',
						'db_version'   => 1,
					),
					'bozuk',
				),
			)
		);
	}

	/**
	 * Only valid https releases, newest first; another slug gives nothing.
	 */
	public function test_manifest(): void {
		$releases = ReleaseManifest::parse( self::manifest(), 'ai-hazir-site' );
		$this->assertSame( array( '1.4.0', '1.3.0', '1.2.0' ), array_map( static fn( Release $r ): string => $r->version, $releases ) );
		$this->assertSame( array( 'changelog' => '<p>Yeni</p>' ), $releases[0]->sections );
		$this->assertSame( array( '6.9', '8.1', '7.1', 1400 ), array( $releases[0]->requires, $releases[0]->requires_php, $releases[0]->tested, $releases[0]->db_version ) );
		$this->assertSame( array(), ReleaseManifest::parse( self::manifest(), 'baska-eklenti' ) );
		$this->assertSame( array(), ReleaseManifest::parse( 'bozuk', 'ai-hazir-site' ) );
	}

	/**
	 * Pilot sees a release at once; general 48 hours later; nothing older or equal.
	 */
	public function test_canary(): void {
		$releases = ReleaseManifest::parse( self::manifest(), 'ai-hazir-site' );

		$this->assertSame( '1.4.0', CanaryPolicy::available( $releases, '1.2.0', CanaryPolicy::PILOT, '2026-10-01T10:00:00Z' )?->version );
		$this->assertSame( '1.3.0', CanaryPolicy::available( $releases, '1.2.0', CanaryPolicy::GENERAL, '2026-10-01T10:00:00Z' )?->version, 'General: 1.3.0 is older than 48 h, 1.4.0 is not.' );
		$this->assertSame( '1.3.0', CanaryPolicy::available( $releases, '1.2.0', CanaryPolicy::GENERAL, '2026-10-03T09:59:59Z' )?->version );
		$this->assertSame( '1.4.0', CanaryPolicy::available( $releases, '1.2.0', CanaryPolicy::GENERAL, '2026-10-03T10:00:00Z' )?->version );
		$this->assertSame( '1.4.0', CanaryPolicy::available( $releases, '1.2.0', 'bilinmeyen', '2026-10-03T10:00:00Z' )?->version );
		$this->assertNull( CanaryPolicy::available( $releases, '1.1.0', CanaryPolicy::GENERAL, '2026-09-22T09:59:59Z' ), 'General: 1.2.0 is not 48 h old yet.' );
		$this->assertSame( '1.2.0', CanaryPolicy::available( $releases, '1.1.0', CanaryPolicy::PILOT, '2026-09-22T09:59:59Z' )?->version );
		$this->assertNull( CanaryPolicy::available( $releases, '1.4.0', CanaryPolicy::PILOT, '2026-12-01T00:00:00Z' ) );
		$this->assertNull( CanaryPolicy::available( $releases, '1.2.0', CanaryPolicy::PILOT, '2026-09-20T09:00:00Z' ), 'Nothing released yet.' );

		$this->assertSame( '1.3.0', CanaryPolicy::previous( $releases, '1.4.0' )?->version );
		$this->assertSame( '1.2.0', CanaryPolicy::previous( $releases, '1.2.5' )?->version );
		$this->assertNull( CanaryPolicy::previous( $releases, '1.2.0' ) );
	}

	/**
	 * Rollback: schema first (newer migrations down, newest first), then the package.
	 */
	public function test_rollback(): void {
		RecordingMigration::$log = array();
		$settings                = new MemorySettings();
		$migrator                = new Migrator( array( new RecordingMigration( 200 ), new RecordingMigration( 1200 ), new RecordingMigration( 1400 ) ), $settings );
		$migrator->migrate();
		RecordingMigration::$log = array();

		$installer = new class() implements PackageInstaller {
			/**
			 * Installed URLs.
			 *
			 * @var list<string>
			 */
			public array $installed = array();

			/**
			 * Error to return.
			 *
			 * @var string
			 */
			public string $error = '';

			/**
			 * Records the install (after the schema was rolled back).
			 *
			 * @param string $package_url URL.
			 */
			public function install( string $package_url ): string {
				$this->installed[] = $package_url . ' @' . implode( ',', RecordingMigration::$log );
				return $this->error;
			}
		};
		$service   = new RollbackService( $migrator, $installer );
		$releases  = ReleaseManifest::parse( self::manifest(), 'ai-hazir-site' );

		$result = $service->rollback( $releases[2] ); // 1.2.0, db 1200.
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 1400 ), $result['reverted'] );
		$this->assertSame( 1200, $migrator->current_version() );
		$this->assertSame( array( 'https://guncelleme.example/ai-hazir-site-1.2.0.zip @down:1400' ), $installer->installed, 'Package installed after the schema went back.' );

		$installer->error = 'İndirilemedi.';
		$failed           = $service->rollback( new Release( '1.1.0', 'https://x.example/1.1.0.zip', '2026-09-01T00:00:00Z', 200 ) );
		$this->assertFalse( $failed['ok'] );
		$this->assertSame( 'İndirilemedi.', $failed['error'] );

		$this->assertFalse( $service->rollback( new Release( '1.9.0', 'https://x.example/1.9.0.zip', '2026-09-01T00:00:00Z', 9999 ) )['ok'], 'A newer schema is not a rollback.' );

		$license = new FreeLicense();
		$this->assertSame( LicenseChecker::TIER_FREE, $license->tier() );
		$this->assertFalse( $license->allows( 'anything' ) );
	}
}
