<?php
/**
 * ListingRepository on the aihs_listing post type.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Contracts\ListingBusinessRepository;
use AIHazirSite\Core\Contracts\ListingRepository;
use AIHazirSite\Core\Contracts\ListingTranslationRepository;
use RuntimeException;
use WP_Post;

/**
 * The only code that writes listing posts and their meta.
 */
final class WpListingRepository implements ListingRepository, ListingTranslationRepository, ListingBusinessRepository {

	/**
	 * Post meta holding the business id of a listing (1.2.0 portal mode); removed with the listing.
	 */
	public const BUSINESS_META = '_aihs_business';

	/**
	 * Post meta holding the entered translations (1.1.0); removed with the listing.
	 */
	public const TRANSLATIONS_META = '_aihs_translations';

	/**
	 * Listing by id (null for other post types).
	 *
	 * @param int $id Post id.
	 */
	public function find( int $id ): ?Listing {
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || PostType::NAME !== $post->post_type ) {
			return null;
		}

		$data = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'updated_at'  => (string) get_post_modified_time( 'Y-m-d\TH:i:s\Z', true, $post ),
		);
		foreach ( PostType::META as $field => $key ) {
			$data[ $field ] = get_post_meta( $post->ID, $key, true );
		}

		return Listing::from_array( $data );
	}

	/**
	 * Listings of one type, most recently updated first.
	 *
	 * @param string $type  Type.
	 * @param int    $limit Limit.
	 * @return list<Listing>
	 */
	public function all( string $type, int $limit = 200 ): array {
		// Whole posts, not ids (1.14.1): WP_Query then loads the posts and all their meta in one query each,
		// so find() below reads from the cache instead of two queries per listing.
		$posts = get_posts(
			array(
				'post_type'              => PostType::NAME,
				'post_status'            => 'publish',
				'numberposts'            => $limit,
				// Newest id first among listings updated in the same second, so the order is stable.
				'orderby'                => array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				'meta_key'               => PostType::META['type'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small private catalog.
				'meta_value'             => $type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small private catalog.
				'suppress_filters'       => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$listings = array();
		foreach ( $posts as $post ) {
			$listing = $this->find( (int) $post->ID );
			if ( null !== $listing ) {
				$listings[] = $listing;
			}
		}
		return $listings;
	}

	/**
	 * Inserts or updates the post and all meta fields.
	 *
	 * @param Listing $listing Valid listing.
	 * @throws RuntimeException When WordPress refuses the write.
	 */
	public function store_listing( Listing $listing ): Listing {
		$post = array(
			'post_type'    => PostType::NAME,
			'post_status'  => 'publish',
			'post_title'   => $listing->title,
			'post_content' => $listing->description,
		);
		if ( null !== $listing->id ) {
			$post['ID'] = $listing->id;
		}

		// wp_insert_post() and update_post_meta() unslash their input.
		$id = null === $listing->id ? wp_insert_post( wp_slash( $post ), true ) : wp_update_post( wp_slash( $post ), true );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( esc_html( $id->get_error_message() ) );
		}

		foreach ( PostType::META as $field => $key ) {
			$value = $listing->{$field};
			if ( null === $value || '' === $value || array() === $value ) {
				delete_post_meta( (int) $id, $key );
			} else {
				update_post_meta( (int) $id, $key, wp_slash( $value ) );
			}
		}

		clean_post_cache( (int) $id );
		$stored = $this->find( (int) $id );
		if ( null === $stored ) {
			throw new RuntimeException( 'Saved listing could not be read back.' );
		}
		return $stored;
	}

	/**
	 * Deletes a listing post permanently (never other post types).
	 *
	 * @param int $id Post id.
	 */
	public function remove_listing( int $id ): bool {
		if ( null === $this->find( $id ) ) {
			return false;
		}
		return wp_delete_post( $id, true ) instanceof WP_Post;
	}

	/**
	 * Ids of all listings in any status.
	 *
	 * @return list<int>
	 */
	public function ids(): array {
		$ids = get_posts(
			array(
				'post_type'        => PostType::NAME,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Business id of a listing, or null (the portal itself).
	 *
	 * @param int $listing_id Listing id.
	 */
	public function business_of( int $listing_id ): ?int {
		$value = get_post_meta( $listing_id, self::BUSINESS_META, true );
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * Listing ids of a business.
	 *
	 * @param int $business_id Business id.
	 * @return list<int>
	 */
	public function listings_of( int $business_id ): array {
		$ids = get_posts(
			array(
				'post_type'        => PostType::NAME,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => self::BUSINESS_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small private catalog.
				'meta_value'       => (string) $business_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small private catalog.
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Links a listing to a business (called only by PortalService).
	 *
	 * @param int      $listing_id  Listing id.
	 * @param int|null $business_id Business id, or null for the portal.
	 */
	public function store_listing_business( int $listing_id, ?int $business_id ): void {
		if ( null === $business_id ) {
			delete_post_meta( $listing_id, self::BUSINESS_META );
		} else {
			update_post_meta( $listing_id, self::BUSINESS_META, $business_id );
		}
	}

	/**
	 * Stored translations of a listing.
	 *
	 * @param int $id Listing id.
	 * @return array<string, array<string, string>>
	 */
	public function translations( int $id ): array {
		$stored = get_post_meta( $id, self::TRANSLATIONS_META, true );
		$clean  = array();
		foreach ( is_array( $stored ) ? $stored : array() as $language => $fields ) {
			foreach ( is_array( $fields ) ? $fields : array() as $field => $text ) {
				if ( is_string( $text ) ) {
					$clean[ (string) $language ][ (string) $field ] = $text;
				}
			}
		}
		return $clean;
	}

	/**
	 * Replaces the translations of a listing (called only by CatalogService).
	 *
	 * @param int                                  $id           Listing id.
	 * @param array<string, array<string, string>> $translations Clean translations.
	 */
	public function store_translations( int $id, array $translations ): void {
		if ( array() === $translations ) {
			delete_post_meta( $id, self::TRANSLATIONS_META );
		} else {
			update_post_meta( $id, self::TRANSLATIONS_META, wp_slash( $translations ) );
		}
	}
}
