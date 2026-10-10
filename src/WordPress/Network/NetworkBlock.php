<?php
/**
 * "Komşu ülkelerde" block and shortcode (1.23.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Network;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Nace;
use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkLink;
use AIHazirSite\Core\Network\SiblingCatalog;
use AIHazirSite\WordPress\Module;

/**
 * While `network_block` is on: verified sibling portals' listings from the cached catalogs (no request to a sibling
 * while the page is built), links tagged with the standard UTM parameters. Nothing is drawn when nothing matches.
 * The shortcode is always registered so a switched-off block renders nothing instead of its tag.
 */
final class NetworkBlock implements Module {

	public const SHORTCODE     = 'aihs_komsu_ulkeler';
	public const BLOCK         = 'ai-hazir-site/komsu-ulkeler';
	public const DEFAULT_COUNT = 6;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
		if ( self::enabled() ) {
			add_action( 'init', array( self::class, 'register_block' ) );
		}
	}

	/**
	 * Nothing to undo (no stored state, no schedule).
	 */
	public function deactivate(): void {
	}

	/**
	 * Whether the block is on.
	 */
	public static function enabled(): bool {
		return Features::is_enabled( Features::PORTAL_NETWORK ) && Features::is_enabled( Features::NETWORK_BLOCK );
	}

	/**
	 * Registers the block from blocks/komsu-ulkeler/block.json.
	 */
	public static function register_block(): void {
		register_block_type(
			dirname( __DIR__, 3 ) . '/blocks/komsu-ulkeler',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
		if ( ! is_admin() ) {
			return; // The editor's choices are needed only on admin screens.
		}
		$sectors = array();
		foreach ( array_keys( Nace::SECTIONS ) as $letter ) {
			$sectors[ $letter ] = Nace::label( $letter );
		}
		$data = array(
			'sectors' => $sectors,
			'sites'   => array_map(
				static fn( array $site ): array => array(
					'url'  => $site['url'],
					'name' => $site['name'],
				),
				NetworkModule::view()->siblings()
			),
		);
		wp_add_inline_script( generate_block_asset_handle( self::BLOCK, 'editorScript' ), 'window.aihsNetworkBlock = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * Block / shortcode HTML, or '' (off, nothing cached or nothing matching). Everything is escaped here.
	 *
	 * @param mixed $attributes Block attributes or shortcode attributes.
	 */
	public static function render( mixed $attributes = array() ): string {
		if ( ! self::enabled() ) {
			return '';
		}
		$attributes = is_array( $attributes ) ? $attributes : array();
		$text       = static fn( string $k ): string => isset( $attributes[ $k ] ) && is_scalar( $attributes[ $k ] ) ? sanitize_text_field( (string) $attributes[ $k ] ) : '';
		$type       = ListingType::is_valid( $text( 'type' ) ) ? $text( 'type' ) : '';
		$count      = '' === $text( 'count' ) ? self::DEFAULT_COUNT : absint( $text( 'count' ) );
		$site       = '' === $text( 'site' ) ? '' : trailingslashit( esc_url_raw( $text( 'site' ) ) );
		$search     = new ListingSearch( $type, $text( 'category' ), $text( 'region' ) );
		$sector     = Nace::section( $text( 'sector' ) );
		// 1.26.0: listings shared with this site ("Ağda yayınla") come first.
		$shared = array_values( array_filter( NetworkSharing::shared( $search, $sector ), static fn( array $i ): bool => '' === $site || $site === $i['site'] ) );
		$seen   = array_column( $shared, 'url' );
		$others = array_filter(
			SiblingCatalog::pick( NetworkCatalog::catalogs(), $search, $sector, $site, max( 1, min( SiblingCatalog::BLOCK_MAX, $count ) ), time() ),
			static fn( array $i ): bool => ! in_array( $i['url'], $seen, true )
		);
		$items  = array_slice( array_merge( $shared, array_values( $others ) ), 0, max( 1, min( SiblingCatalog::BLOCK_MAX, $count ) ) );
		if ( array() === $items ) {
			return '';
		}

		$source = NetworkLink::host( home_url( '/' ) );
		$id     = wp_unique_id( 'aihs-komsu-ulkeler-' );
		$title  = '' === $text( 'title' ) ? __( 'Komşu ülkelerde', 'ai-hazir-site' ) : $text( 'title' );
		$html   = '<section class="aihs-komsu-ulkeler" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2><ul>';
		foreach ( $items as $item ) {
			$details = array_filter( array( (string) ( $item['business'] ?? '' ), (string) $item['region'] ) );
			$portal  = (string) $item['site_name'] . ( '' === (string) $item['country'] ? '' : ' (' . (string) $item['country'] . ')' );
			$html   .= '<li><a href="' . esc_url( NetworkLink::tag( (string) $item['url'], $source ) ) . '">' . esc_html( (string) $item['title'] ) . '</a>'
				. ( array() === $details ? '' : ' – ' . esc_html( implode( ', ', $details ) ) )
				. ( '' === trim( $portal ) ? '' : ' · ' . esc_html( $portal ) )
				. '</li>';
		}
		return $html . '</ul></section>';
	}
}
