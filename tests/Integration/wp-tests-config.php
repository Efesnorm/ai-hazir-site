<?php
/**
 * Test config: wp-env's generated config with an isolated table prefix.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

require getenv( 'WP_TESTS_DIR' ) . '/wp-tests-config.php';

// Overrides the prefix set above so tests never touch the development site's tables.
$table_prefix = 'aihstests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
