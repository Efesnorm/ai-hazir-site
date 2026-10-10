<?php
/**
 * "Ağda yayınla" – WordPress side (1.26.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Network;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\ListingSearch;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkShare;
use AIHazirSite\Core\Network\SiblingCatalog;
use AIHazirSite\WordPress\Catalog\WpListingRepository;

/**
 * While `network_share` is on:
 * - the sharing site: a "Ağda yayınla" choice in the listing form (whole network or chosen verified siblings), stored
 *   with the listing; REST and MCP listing answers carry `network_share`;
 * - every site: listings siblings share with it (read with the hourly sibling catalogs) appear in its /ai-katalog/ page,
 *   llms.txt, REST/MCP `network_listings`, the "Komşu ülkelerde" block (first) and as `mentions` in its catalog JSON-LD,
 *   always linking to the original page. Nothing is copied.
 */
final class NetworkSharing {

	public const FORM = 'network_share_form';

	/**
	 * Whether sharing is on.
	 */
	public static function enabled(): bool {
		return Features::is_enabled( Features::PORTAL_NETWORK ) && Features::is_enabled( Features::NETWORK_SHARE );
	}

	/**
	 * Verified siblings this site can share with ([] when off or not in a verified network).
	 *
	 * @return list<array{url: string, name: string, country: string}>
	 */
	public static function siblings(): array {
		return self::enabled() ? NetworkModule::view()->siblings() : array();
	}

	/**
	 * The listing form's "Ağda yayınla" row ('' when sharing is not possible). Everything escaped here.
	 *
	 * @param Listing|null $listing Listing being edited (null for a new one).
	 */
	public static function form_row( ?Listing $listing ): string {
		$siblings = self::siblings();
		if ( array() === $siblings ) {
			return '';
		}
		$share = null === $listing || null === $listing->id ? null : ( new WpListingRepository() )->network_share( $listing->id );
		$html  = '<tr id="aihs-network-share"><th scope="row">' . esc_html__( 'Ağda yayınla', 'ai-hazir-site' ) . '</th><td>'
			. '<input type="hidden" name="' . esc_attr( self::FORM ) . '" value="1">'
			. '<label><input type="checkbox" name="network_share_all" value="1"' . checked( true === ( $share['all'] ?? false ), true, false ) . '> <strong>' . esc_html__( 'Tüm ağda', 'ai-hazir-site' ) . '</strong></label><br>';
		foreach ( $siblings as $site ) {
			$html .= '<label><input type="checkbox" name="network_share_sites[]" value="' . esc_attr( $site['url'] ) . '"' . checked( in_array( $site['url'], $share['sites'] ?? array(), true ), true, false ) . '> ' . esc_html( ( '' !== $site['name'] ? $site['name'] . ' – ' : '' ) . $site['url'] ) . '</label><br>';
		}
		return $html . '<p class="description">' . esc_html__( 'İlan kopyalanmaz: seçilen portallar bu ilanı kendi kataloglarında, llms.txt\'de ve AI yanıtlarında bu sitedeki asıl ilana bağlantıyla gösterir. Değişiklikler en geç 1–2 saatte yansır.', 'ai-hazir-site' ) . '</p></td></tr>';
	}

	/**
	 * Saves the posted choice (only when the form showed the row, so a site without verified siblings keeps it).
	 *
	 * @param int          $listing_id Saved listing.
	 * @param array<mixed> $post       Unslashed $_POST.
	 */
	public static function save( int $listing_id, array $post ): void {
		if ( $listing_id <= 0 || empty( $post[ self::FORM ] ) || ! self::enabled() ) {
			return;
		}
		$sites = array();
		foreach ( is_array( $post['network_share_sites'] ?? null ) ? $post['network_share_sites'] : array() as $site ) {
			$sites[] = esc_url_raw( (string) $site, array( 'https' ) );
		}
		$share = NetworkShare::from_input( ! empty( $post['network_share_all'] ), $sites, array_column( self::siblings(), 'url' ) );
		( new WpListingRepository() )->store_network_share( $listing_id, $share );
	}

	/**
	 * Adds `network_share` to a listing body or to each item of a listings body (only shared ones).
	 *
	 * @param array<string, mixed> $body Body.
	 * @return array<string, mixed>
	 */
	public static function with_shares( array $body ): array {
		if ( ! self::enabled() ) {
			return $body;
		}
		$repository = new WpListingRepository();
		if ( isset( $body['items'] ) && is_array( $body['items'] ) ) {
			foreach ( $body['items'] as $i => $item ) {
				$share = is_array( $item ) ? $repository->network_share( (int) ( $item['id'] ?? 0 ) ) : null;
				if ( null !== $share ) {
					$body['items'][ $i ]['network_share'] = $share;
				}
			}
		} elseif ( isset( $body['id'] ) ) {
			$share = $repository->network_share( (int) $body['id'] );
			if ( null !== $share ) {
				$body['network_share'] = $share;
			}
		}
		return $body;
	}

	/**
	 * Listings siblings share with this site, matching a search.
	 *
	 * @param ListingSearch|null $search Search (null = all).
	 * @param string             $sector NACE section letter or ''.
	 * @return list<array<string, mixed>>
	 */
	public static function shared( ?ListingSearch $search = null, string $sector = '' ): array {
		if ( ! self::enabled() ) {
			return array();
		}
		return SiblingCatalog::shared( NetworkCatalog::catalogs(), NetworkModule::self_url(), $search ?? new ListingSearch(), $sector, time() );
	}

	/**
	 * A listings answer with `network_share` on own items and `network_listings` (when siblings share any).
	 *
	 * @param array<string, mixed> $body   Listings body.
	 * @param ListingSearch        $search Search.
	 * @param string               $sector NACE section letter or ''.
	 * @return array<string, mixed>
	 */
	public static function decorate_listings( array $body, ListingSearch $search, string $sector ): array {
		if ( ! self::enabled() ) {
			return $body;
		}
		$body   = self::with_shares( $body );
		$shared = self::shared( $search, $sector );
		if ( array() !== $shared ) {
			$body['network_listings'] = $shared;
		}
		return $body;
	}

	/**
	 * The /ai-katalog/ section of shared listings ('' for none). Everything escaped here.
	 */
	public static function catalog_html(): string {
		$shared = self::shared();
		if ( array() === $shared ) {
			return '';
		}
		$html = '<section id="aihs-network-listings"><h2>' . esc_html__( 'Ağdaki ilanlar', 'ai-hazir-site' ) . '</h2><p>' . esc_html__( 'Kardeş portalların bu siteyle paylaştığı ilanlar; ayrıntılar ve talep için asıl ilan sayfasına gidin.', 'ai-hazir-site' ) . '</p><ul>';
		foreach ( $shared as $item ) {
			$source = (string) $item['site_name'] . ( '' === (string) $item['country'] ? '' : ' (' . (string) $item['country'] . ')' );
			$extra  = array_filter( array( (string) ( $item['business'] ?? '' ), (string) $item['region'] ) );
			$html  .= '<li><a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( (string) $item['title'] ) . '</a>'
				. ( array() === $extra ? '' : ' – ' . esc_html( implode( ', ', $extra ) ) )
				. ( '' === trim( $source ) ? '' : '<br><small>' . esc_html__( 'Kaynak', 'ai-hazir-site' ) . ': ' . esc_html( $source ) . '</small>' )
				. '</li>';
		}
		return $html . '</ul></section>';
	}

	/**
	 * The catalog JSON-LD with the shared listings as `mentions` (a reference to the original, never an Offer of this
	 * site, so search engines and AI see one listing).
	 *
	 * @param array<string, mixed> $document Catalog document.
	 * @return array<string, mixed>
	 */
	public static function decorate_catalog( array $document ): array {
		$shared = self::shared();
		if ( array() === $shared ) {
			return $document;
		}
		$document['mentions'] = array_map(
			static fn( array $item ): array => array(
				'@id'  => (string) $item['url'],
				'url'  => (string) $item['url'],
				'name' => (string) $item['title'],
			),
			$shared
		);
		return $document;
	}
}
