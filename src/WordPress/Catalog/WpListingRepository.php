<?php
/**
 * ListingRepository on the aihs_listing post type.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Contracts\ListingRepository;
use RuntimeException;
use WP_Post;

/**
 * The only code that writes listing posts and their meta.
 */
final class WpListingRepository implements ListingRepository {

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
		$ids = get_posts(
			array(
				'post_type'        => PostType::NAME,
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'meta_key'         => PostType::META['type'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small private catalog.
				'meta_value'       => $type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small private catalog.
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);

		$listings = array();
		foreach ( $ids as $id ) {
			$listing = $this->find( (int) $id );
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
	public function save_listing( Listing $listing ): Listing {
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
	public function delete_listing( int $id ): bool {
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
}
