<?php
/**
 * Integration test bootstrap. Runs inside wp-env:
 * `composer test:integration`.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

$aihs_root      = dirname( __DIR__, 2 );
$aihs_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $aihs_tests_dir || ! file_exists( $aihs_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test kütüphanesi bulunamadı. Entegrasyon testlerini wp-env içinde çalıştırın: composer test:integration\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $aihs_root . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $aihs_root . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress test suite constant.

// wp-env's default wp-tests-config.php shares the development site's table prefix,
// and the test suite drops those tables. Use a config with a separate prefix instead.
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress test suite constant.

require_once $aihs_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $aihs_root ): void {
		require_once $aihs_root . '/ai-hazir-site.php';
	}
);

require $aihs_tests_dir . '/includes/bootstrap.php';
