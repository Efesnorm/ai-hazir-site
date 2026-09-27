<?php
/**
 * The compliance wizard changes nothing outside the plugin.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Integration\Compliance;

use AIHazirSite\WordPress\Catalog\PostType;

/**
 * A full wizard run (every step, finish, some undos) leaves every option outside the plugin,
 * the theme settings, other posts and the files in the site root as they were.
 * Allowed exceptions (approved): `rewrite_rules` (WordPress's computed cache; the catalog page
 * module adds /ai-katalog/ to it when its feature is on, the wizard never writes it) and
 * transients (temporary caches, e.g. the form state).
 *
 * @coversNothing
 */
final class WizardTouchesNothingElseTest extends WizardTestCase {

	/**
	 * Everything outside the plugin that could be changed.
	 *
	 * @return array<string, mixed>
	 */
	private static function outside(): array {
		global $wpdb;
		$rows    = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$options = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) $row['option_name'];
			if ( str_starts_with( $name, 'aihs_' ) || str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' ) || 'rewrite_rules' === $name ) {
				continue;
			}
			$options[ $name ] = $row['option_value'];
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$root  = trailingslashit( get_home_path() );
		$files = array();
		foreach ( (array) scandir( $root ) as $file ) {
			if ( is_file( $root . $file ) ) {
				$files[ (string) $file ] = md5_file( $root . $file );
			}
		}

		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT(ID, ':', post_type, ':', post_modified_gmt) FROM {$wpdb->posts} WHERE post_type <> %s ORDER BY ID", PostType::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array(
			'options'        => $options,
			'blog_public'    => get_option( 'blog_public' ),
			'theme_mods'     => get_theme_mods(),
			'active_plugins' => get_option( 'active_plugins' ),
			'files'          => $files,
			'posts'          => $posts,
		);
	}

	/**
	 * Nothing outside the plugin changes.
	 */
	public function test_nothing_outside_the_plugin_changes(): void {
		update_option( 'blog_public', '1' );
		$before = self::outside();

		$applied = $this->run_wizard();
		$this->assertContains( 'schema', $applied );
		$this->finish();
		$this->undo( 'llms' );
		$this->undo( 'schema' );

		$this->assertSame( $before, self::outside() );
		$this->assertFalse( file_exists( trailingslashit( get_home_path() ) . 'llms.txt' ), 'No physical llms.txt was written.' );
	}
}
