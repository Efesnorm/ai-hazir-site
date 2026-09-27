<?php
/**
 * AI bot access (U2) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Access;

use AIHazirSite\Core\Access\PolicyStore;
use AIHazirSite\Core\Access\RobotsRules;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\WordPress\Access\Admin\AccessPage;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\WpSettings;

/**
 * Adds our block to WordPress's virtual robots.txt through the `robots_txt` filter, after
 * WordPress and other plugins (priority 100). A physical robots.txt file is never touched.
 * With the `bot_access` feature off nothing is added and the stored policy is kept.
 */
final class AccessModule implements Module {

	public const FILTER_PRIORITY = 100;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::BOT_ACCESS ) ) {
			return;
		}
		add_filter( 'robots_txt', array( self::class, 'filter_robots' ), self::FILTER_PRIORITY );
		if ( is_admin() ) {
			( new AccessPage() )->register();
		}
	}

	/**
	 * Nothing scheduled.
	 */
	public function deactivate(): void {
	}

	/**
	 * `robots_txt` filter: our block replaced or appended; everything else unchanged.
	 *
	 * @param mixed $output robots.txt built so far.
	 */
	public static function filter_robots( mixed $output ): string {
		$output = is_string( $output ) ? $output : '';
		if ( ! Features::is_enabled( Features::BOT_ACCESS ) ) {
			return RobotsRules::remove( $output );
		}
		return self::rules()->apply( $output, self::store()->get(), Registry::bots() );
	}

	/**
	 * Rules with WordPress's protected paths repeated in "allow" groups (same paths as do_robots()).
	 */
	public static function rules(): RobotsRules {
		return new RobotsRules(
			array(
				'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ),
				'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ),
			)
		);
	}

	/**
	 * Policy storage.
	 */
	public static function store(): PolicyStore {
		return new PolicyStore( new WpSettings() );
	}

	/**
	 * Path of a physical robots.txt in the site root, or null. When it exists the web server
	 * serves it directly and WordPress (and our filter) never runs for /robots.txt.
	 */
	public static function physical_file(): ?string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$path = trailingslashit( get_home_path() ) . 'robots.txt';
		return file_exists( $path ) ? $path : null;
	}

	/**
	 * The virtual robots.txt as WordPress would serve it now (same start as do_robots(),
	 * then every `robots_txt` filter, including other plugins').
	 */
	public static function virtual_robots(): string {
		$public = get_option( 'blog_public' );
		$output = "User-agent: *\n"
			. 'Disallow: ' . wp_parse_url( admin_url(), PHP_URL_PATH ) . "\n"
			. 'Allow: ' . wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ) . "\n";

		/** This filter is documented in wp-includes/functions.php (do_robots). */
		return (string) apply_filters( 'robots_txt', $output, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
	}
}
