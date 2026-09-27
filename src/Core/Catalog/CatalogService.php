<?php
/**
 * The only place catalog data is written.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

use AIHazirSite\Core\Contracts\Clock;
use AIHazirSite\Core\Contracts\ListingRepository;
use AIHazirSite\Core\Contracts\ProfileRepository;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Validates before every write. Admin forms, imports and future channels all go through here.
 */
final class CatalogService {

	/**
	 * Constructor.
	 *
	 * @param ListingRepository     $listings Listing storage.
	 * @param ProfileRepository     $profiles Profile storage.
	 * @param Clock                 $clock     Provides today for date rules.
	 * @param TemplateRegistry|null $templates Sector templates; null when templates are off.
	 */
	public function __construct(
		private readonly ListingRepository $listings,
		private readonly ProfileRepository $profiles,
		private readonly Clock $clock,
		private readonly ?TemplateRegistry $templates = null
	) {
	}

	/**
	 * Validates and stores a listing. With an id, only a listing of the same type is updated.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param int|null             $id    Listing to update; null to create.
	 */
	public function save_listing( array $input, ?int $id = null ): ValidationResult {
		$existing = null;
		if ( null !== $id ) {
			$existing = $this->listings->find( $id );
			if ( null === $existing ) {
				return new ValidationResult( null, array( 'id' => 'İlan bulunamadı.' ) );
			}
		}

		$result  = ( new ListingValidator( $this->templates ) )->validate( $input, $this->clock->today(), $existing );
		$listing = $result->listing();
		if ( null === $listing ) {
			return $result;
		}

		return $result->with_value( $this->listings->store_listing( $listing ) );
	}

	/**
	 * Deletes a listing; refuses when it does not exist or is of another type.
	 *
	 * @param int         $id   Id.
	 * @param string|null $type Expected type (protects other types from a crafted request).
	 */
	public function delete_listing( int $id, ?string $type = null ): bool {
		$existing = $this->listings->find( $id );
		if ( null === $existing || ( null !== $type && $existing->type !== $type ) ) {
			return false;
		}
		return $this->listings->remove_listing( $id );
	}

	/**
	 * Validates and stores the company profile.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public function save_profile( array $input ): ValidationResult {
		// A form without the template choice (templates off) keeps the stored one.
		if ( ! isset( $input['template'] ) ) {
			$input['template'] = null === $this->templates ? '' : $this->profiles->get()->template;
		}
		$result  = ( new ProfileValidator( $this->templates ) )->validate( $input );
		$profile = $result->profile();
		if ( null !== $profile ) {
			$this->profiles->store_profile( $profile );
		}
		return $result;
	}

	/**
	 * Removes all catalog data (uninstall with the site owner's opt-in).
	 *
	 * @return int Listings deleted.
	 */
	public function purge(): int {
		$deleted = 0;
		foreach ( $this->listings->ids() as $id ) {
			$deleted += (int) $this->listings->remove_listing( $id );
		}
		$this->profiles->remove_profile();
		return $deleted;
	}
}
