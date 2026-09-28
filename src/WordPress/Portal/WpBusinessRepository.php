<?php
/**
 * Portal businesses in an option.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Portal;

use AIHazirSite\Core\Contracts\BusinessRepository;
use AIHazirSite\Core\Contracts\Settings;
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\WordPress\Platform\WpSettings;

/**
 * Businesses live in the `aihs_businesses` option ({next, items}); not autoloaded.
 * Enough for portals with up to a few hundred businesses (see docs/planlar/gorev-15.md).
 */
final class WpBusinessRepository implements BusinessRepository {

	public const OPTION = 'aihs_businesses';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Storage.
	 */
	public function __construct( private readonly Settings $settings = new WpSettings() ) {
	}

	/**
	 * Every business, by name.
	 *
	 * @return list<Business>
	 */
	public function businesses(): array {
		$all = array();
		foreach ( $this->data()['items'] as $item ) {
			$business = Business::from_array( $item );
			if ( null !== $business ) {
				$all[] = $business;
			}
		}
		usort( $all, static fn( Business $a, Business $b ): int => strcmp( $a->profile->name, $b->profile->name ) );
		return $all;
	}

	/**
	 * A business by id.
	 *
	 * @param int $id Id.
	 */
	public function business( int $id ): ?Business {
		return Business::from_array( $this->data()['items'][ $id ] ?? null );
	}

	/**
	 * A business by slug.
	 *
	 * @param string $slug Slug.
	 */
	public function business_by_slug( string $slug ): ?Business {
		foreach ( $this->businesses() as $business ) {
			if ( $slug === $business->slug ) {
				return $business;
			}
		}
		return null;
	}

	/**
	 * Inserts or updates (called only by PortalService).
	 *
	 * @param Business $business Valid business.
	 */
	public function store_business( Business $business ): Business {
		$data = $this->data();
		$id   = $business->id ?? $data['next'];
		if ( null === $business->id ) {
			$data['next'] = $id + 1;
		}
		$stored               = new Business( $id, $business->slug, $business->profile, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$data['items'][ $id ] = $stored->to_array();
		$this->settings->set( self::OPTION, $data, false );
		return $stored;
	}

	/**
	 * Deletes (called only by PortalService).
	 *
	 * @param int $id Id.
	 */
	public function remove_business( int $id ): bool {
		$data = $this->data();
		if ( ! isset( $data['items'][ $id ] ) ) {
			return false;
		}
		unset( $data['items'][ $id ] );
		$this->settings->set( self::OPTION, $data, false );
		return true;
	}

	/**
	 * Stored data.
	 *
	 * @return array{next: int, items: array<int, mixed>}
	 */
	private function data(): array {
		$data  = $this->settings->get( self::OPTION, array() );
		$data  = is_array( $data ) ? $data : array();
		$items = is_array( $data['items'] ?? null ) ? $data['items'] : array();
		$next  = isset( $data['next'] ) && is_numeric( $data['next'] ) ? max( 1, (int) $data['next'] ) : 1;
		$ids   = array_map( 'intval', array_keys( $items ) );
		return array(
			'next'  => max( $next, array() === $ids ? 1 : max( $ids ) + 1 ),
			'items' => $items,
		);
	}
}
