<?php
/**
 * IndexNow (1.10.0) – WordPress wiring.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\IndexNow;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\IndexNow\IndexNowService;
use AIHazirSite\WordPress\Catalog\PostType;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Integrations\CatalogSitemap;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Platform\PageCache;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Platform\WpHttpPoster;
use AIHazirSite\WordPress\Platform\WpSettings;
use AIHazirSite\WordPress\Portal\WpBusinessRepository;

/**
 * While `indexnow` is on: serves the key file /{key}.txt, and when a listing, the profile or a business
 * changes, schedules one submission of the public catalog pages (the 1.8.0 sitemap list) 10 minutes
 * later, at most once an hour. Off by default; turned on on the Integrations or Settings screen.
 */
final class IndexNowModule implements Module {

	public const HOOK  = 'aihs_indexnow_submit';
	public const DELAY = 600;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		if ( ! Features::is_enabled( Features::INDEXNOW ) ) {
			return;
		}
		add_action( 'parse_request', array( self::class, 'maybe_serve_key' ), 20 );
		add_action( self::HOOK, array( self::class, 'run_scheduled' ) );
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'schedule' ) );
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ), 10, 2 );
		foreach ( array( WpProfileRepository::OPTION, WpBusinessRepository::OPTION ) as $option ) {
			add_action( 'add_option_' . $option, array( self::class, 'schedule' ) );
			add_action( 'update_option_' . $option, array( self::class, 'schedule' ) );
		}
	}

	/**
	 * Removes the scheduled submission.
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The service.
	 */
	public static function service(): IndexNowService {
		return new IndexNowService( new WpSettings(), new WpHttpPoster(), new WpClock() );
	}

	/**
	 * Turns the feature on (creating the key) or off (dropping the scheduled submission).
	 *
	 * @param bool $on New state.
	 */
	public static function enable( bool $on ): void {
		Features::set( Features::INDEXNOW, $on );
		if ( $on ) {
			self::service()->ensure_key();
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Address of the key file ('' without a key).
	 */
	public static function key_url(): string {
		$key = self::service()->key();
		return '' === $key ? '' : home_url( '/' . $key . '.txt' );
	}

	/**
	 * One submission, 10 minutes from now or when the hourly limit allows (never two scheduled).
	 */
	public static function schedule(): void {
		if ( ! Features::is_enabled( Features::INDEXNOW ) || false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		wp_schedule_single_event( max( time() + self::DELAY, self::service()->next_allowed() ), self::HOOK );
	}

	/**
	 * `deleted_post`: our listings only.
	 *
	 * @param int   $post_id Post id.
	 * @param mixed $post    Post.
	 */
	public static function on_deleted_post( int $post_id, mixed $post = null ): void {
		if ( $post instanceof \WP_Post && PostType::NAME === $post->post_type ) {
			self::schedule();
		}
	}

	/**
	 * The scheduled event.
	 */
	public static function run_scheduled(): void {
		self::submit();
	}

	/**
	 * Sends the public catalog pages now (scheduled event, or the "Şimdi bildir" button).
	 *
	 * @return array{at: int, status: int, count: int}|null Result, or null when nothing was sent.
	 */
	public static function submit(): ?array {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return self::service()->submit(
			Features::is_enabled( Features::INDEXNOW ),
			is_string( $host ) ? $host : '',
			self::key_url(),
			array_column( CatalogSitemap::urls(), 'loc' )
		);
	}

	/**
	 * `parse_request`: serves /{key}.txt and stops.
	 */
	public static function maybe_serve_key(): void {
		$key = self::service()->key();
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with a fixed path.
		if ( '' === $key || (string) wp_parse_url( self::key_url(), PHP_URL_PATH ) !== wp_parse_url( $uri, PHP_URL_PATH ) ) {
			return;
		}
		PageCache::exclude();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( $key );
		exit;
	}
}
