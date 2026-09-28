<?php
/**
 * Portal mode entry point.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Portal;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\Core\Portal\PortalService;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Templates\TemplatesModule;

/**
 * Shared helpers of the portal (1.2.0): the service, a user's business, business page addresses
 * and the listing → business map the channels use.
 */
final class Portal {

	public const USER_META  = 'aihs_business';
	public const CAPABILITY = 'aihs_manage_business';
	public const QUERY_VAR  = 'aihs_business_page';
	public const BASE       = 'ai-katalog/isletme';
	public const REWRITE    = '^ai-katalog/isletme/([a-z0-9-]+)/?$';

	/**
	 * Whether portal mode is on.
	 */
	public static function active(): bool {
		return Features::is_enabled( Features::PORTAL_MODE );
	}

	/**
	 * Business storage.
	 */
	public static function businesses(): WpBusinessRepository {
		return new WpBusinessRepository();
	}

	/**
	 * The portal write service.
	 */
	public static function service(): PortalService {
		$listings = new WpListingRepository();
		return new PortalService( self::businesses(), $listings, $listings, CatalogModule::service(), TemplatesModule::registry() );
	}

	/**
	 * The business a user manages, or null.
	 *
	 * @param int $user_id User id.
	 */
	public static function user_business( int $user_id ): ?Business {
		$id = get_user_meta( $user_id, self::USER_META, true );
		return is_numeric( $id ) && (int) $id > 0 ? self::businesses()->business( (int) $id ) : null;
	}

	/**
	 * Users linked to a business.
	 *
	 * @param int $business_id Business id.
	 * @return list<\WP_User>
	 */
	public static function users_of( int $business_id ): array {
		return array_values(
			get_users(
				array(
					'meta_key'   => self::USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Few users.
					'meta_value' => (string) $business_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Few users.
				)
			)
		);
	}

	/**
	 * Business catalog page URL.
	 *
	 * @param Business $business Business.
	 */
	public static function page_url( Business $business ): string {
		return home_url( '/' . self::BASE . '/' . $business->slug . '/' );
	}

	/**
	 * Business catalog page path, as A0 measurement records it.
	 *
	 * @param Business $business Business.
	 */
	public static function page_path( Business $business ): string {
		$path = wp_parse_url( self::page_url( $business ), PHP_URL_PATH );
		return is_string( $path ) ? $path : '/';
	}

	/**
	 * A business as a schema.org Organization node (seller of its listings).
	 *
	 * @param Business $business Business.
	 * @return array<string, string>
	 */
	public static function organization( Business $business ): array {
		return array(
			'@type' => 'Organization',
			'@id'   => self::page_url( $business ) . '#organization',
			'name'  => $business->profile->name,
			'url'   => self::page_url( $business ),
		);
	}

	/**
	 * Listing id → business (only listings that belong to an existing business).
	 *
	 * @param Listing[] $listings Listings.
	 * @return array<int, Business>
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function owners( array $listings ): array {
		$repository = new WpListingRepository();
		$by_id      = array();
		foreach ( self::businesses()->businesses() as $business ) {
			$by_id[ (int) $business->id ] = $business;
		}
		$owners = array();
		foreach ( $listings as $listing ) {
			$business = null === $listing->id ? null : $repository->business_of( $listing->id );
			if ( null !== $business && isset( $by_id[ $business ] ) ) {
				$owners[ (int) $listing->id ] = $by_id[ $business ];
			}
		}
		return $owners;
	}

	/**
	 * Listing id → business reference {id, slug, name, url} for the channels.
	 *
	 * @param Listing[] $listings Listings.
	 * @return array<int, array{id: int, slug: string, name: string, url: string}>
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function references( array $listings ): array {
		return array_map(
			static fn( Business $b ): array => array(
				'id'   => (int) $b->id,
				'slug' => $b->slug,
				'name' => $b->profile->name,
				'url'  => self::page_url( $b ),
			),
			self::owners( $listings )
		);
	}

	/**
	 * Listings of one business (by slug), or null when there is no such business.
	 *
	 * @param Listing[] $listings Listings.
	 * @param string    $slug     Business slug.
	 * @return list<Listing>|null
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function filter( array $listings, string $slug ): ?array {
		$business = self::businesses()->business_by_slug( $slug );
		if ( null === $business ) {
			return null;
		}
		$owners = self::owners( $listings );
		return array_values( array_filter( $listings, static fn( Listing $l ): bool => null !== $l->id && ( $owners[ $l->id ]->id ?? null ) === $business->id ) );
	}

	/**
	 * `user_has_cap`: a user linked to an existing business may manage that business's listings.
	 *
	 * @param array<string, bool> $allcaps User capabilities.
	 * @param string[]            $caps    Required primitive capabilities.
	 * @param array<mixed>        $args    Requested capability and arguments.
	 * @param \WP_User            $user    User.
	 * @return array<string, bool>
	 */
	public static function grant( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		if ( in_array( self::CAPABILITY, $caps, true ) && null !== self::user_business( $user->ID ) ) {
			$allcaps[ self::CAPABILITY ] = true;
		}
		return $allcaps;
	}
}
