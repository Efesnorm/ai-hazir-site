<?php
/**
 * The plugin's single top-level admin menu (1.15.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Admin;

use AIHazirSite\WordPress\Updates\UpdateModule;

/**
 * Every screen of the plugin sits under one top-level menu, "AI Hazır Site" (WordPress menu API:
 * add_menu_page / add_submenu_page). The settings screen owns the top-level entry; the other screens
 * add themselves with add(), each only while its feature is on. Links from before 1.15.0
 * (tools.php?page=… and options-general.php?page=…) are redirected to admin.php?page=….
 */
final class AdminMenu {

	/**
	 * Top-level menu slug (the settings screen).
	 */
	public const PARENT = 'aihs-settings';

	/**
	 * Position of the top-level entry (where the AI Katalog menu was before 1.15.0).
	 */
	public const POSITION = 58;

	/**
	 * Sub-menu order by page slug (pages not listed keep their order after these).
	 */
	public const ORDER = array(
		'aihs-settings',
		'aihs-catalog',
		'aihs-catalog-demand',
		'aihs-catalog-supply',
		'aihs-catalog-profile',
		'aihs-inquiries',
		'aihs-matching',
		'aihs-portal',
		'aihs-network',
		'aihs-network-report',
		'aihs-catalog-translations',
		'aihs-measurement',
		'aihs-compliance',
		'aihs-wizard',
		'aihs-report',
		'aihs-bot-access',
		'aihs-integrations',
		UpdateModule::PAGE,
	);

	/**
	 * Pages that lived under Tools or Settings before 1.15.0.
	 */
	public const LEGACY = array(
		'tools.php'           => array( 'aihs-measurement', 'aihs-compliance', 'aihs-wizard', 'aihs-report', 'aihs-bot-access', 'aihs-integrations', 'aihs-inquiries' ),
		'options-general.php' => array( 'aihs-settings', UpdateModule::PAGE ),
	);

	/**
	 * Registers the ordering and the redirect of old links.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'sort' ), PHP_INT_MAX );
		// WordPress refuses an unknown tools.php?page=… before admin_init (wp-admin/menu.php), right after
		// this action; options-general.php?page=… for a page that still resolves reaches admin_init.
		add_action( 'admin_page_access_denied', array( self::class, 'redirect_legacy' ) );
		add_action( 'admin_init', array( self::class, 'redirect_legacy' ) );
	}

	/**
	 * Adds a screen under the top-level menu.
	 *
	 * @param string   $title      Page and menu title.
	 * @param string   $capability Capability.
	 * @param string   $slug       Page slug.
	 * @param callable $render     Renders the page.
	 * @return string|false Hook suffix, or false without the capability.
	 */
	public static function add( string $title, string $capability, string $slug, callable $render ): string|false {
		return add_submenu_page( self::PARENT, $title, $title, $capability, $slug, $render );
	}

	/**
	 * Address of a screen.
	 *
	 * @param string                    $slug Page slug.
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Whether the current admin request shows one of the plugin's screens.
	 */
	public static function is_own_screen(): bool {
		global $plugin_page;
		return is_string( $plugin_page ) && in_array( $plugin_page, self::slugs(), true );
	}

	/**
	 * Every page slug of the menu.
	 *
	 * @return list<string>
	 */
	public static function slugs(): array {
		return self::ORDER;
	}

	/**
	 * Puts the sub-menu in ORDER (runs after every screen has added itself).
	 */
	public static function sort(): void {
		global $submenu;
		if ( ! is_array( $submenu ) || ! isset( $submenu[ self::PARENT ] ) || ! is_array( $submenu[ self::PARENT ] ) ) {
			return;
		}
		$rank  = array_flip( self::ORDER );
		$items = array_values( $submenu[ self::PARENT ] );
		$keys  = array_keys( $items );
		usort(
			$keys,
			static function ( int $a, int $b ) use ( $items, $rank ): int {
				$ra = $rank[ (string) ( $items[ $a ][2] ?? '' ) ] ?? PHP_INT_MAX;
				$rb = $rank[ (string) ( $items[ $b ][2] ?? '' ) ] ?? PHP_INT_MAX;
				return 0 !== ( $ra <=> $rb ) ? $ra <=> $rb : $a <=> $b;
			}
		);
		$submenu[ self::PARENT ] = array_map( static fn( int $k ): array => $items[ $k ], $keys ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ordering our own sub-menu.
	}

	/**
	 * Redirects tools.php?page=aihs-… and options-general.php?page=aihs-… to the new address, with the
	 * same query arguments (bookmarks, links in e-mails sent before 1.15.0).
	 */
	public static function redirect_legacy(): void {
		$target = self::legacy_target( (string) ( $GLOBALS['pagenow'] ?? '' ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect only; every screen checks its own nonce.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		if ( null === $target || 'get' !== $method ) {
			return;
		}
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * New address for an old one, or null when the request is not an old address of the plugin.
	 *
	 * @param string                   $pagenow Requested admin file.
	 * @param array<int|string, mixed> $query   Query arguments (as in $_GET).
	 */
	public static function legacy_target( string $pagenow, array $query ): ?string {
		$page = isset( $query['page'] ) && is_string( $query['page'] ) ? sanitize_key( wp_unslash( $query['page'] ) ) : '';
		if ( ! isset( self::LEGACY[ $pagenow ] ) || ! in_array( $page, self::LEGACY[ $pagenow ], true ) ) {
			return null;
		}
		$args = array();
		foreach ( $query as $key => $value ) {
			if ( is_string( $key ) && 'page' !== $key && is_string( $value ) ) {
				$args[ sanitize_key( $key ) ] = rawurlencode( sanitize_text_field( wp_unslash( $value ) ) );
			}
		}
		return self::url( $page, $args );
	}
}
