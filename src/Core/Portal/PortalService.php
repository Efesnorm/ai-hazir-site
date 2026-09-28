<?php
/**
 * The only place portal data is written.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Portal;

use AIHazirSite\Core\Catalog\CatalogService;
use AIHazirSite\Core\Catalog\ProfileValidator;
use AIHazirSite\Core\Catalog\ValidationResult;
use AIHazirSite\Core\Contracts\BusinessRepository;
use AIHazirSite\Core\Contracts\ListingBusinessRepository;
use AIHazirSite\Core\Contracts\ListingRepository;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Businesses and their listings (1.2.0). A business user works only through the
 * *_business_listing() methods, which refuse any listing of another business; listing data
 * itself is still validated and written by CatalogService.
 */
final class PortalService {

	/**
	 * Constructor.
	 *
	 * @param BusinessRepository        $businesses Business storage.
	 * @param ListingBusinessRepository $links      Listing → business links.
	 * @param ListingRepository         $listings   Listings (read only here).
	 * @param CatalogService            $catalog    Listing writes.
	 * @param TemplateRegistry|null     $templates  Sector templates for profile validation.
	 */
	public function __construct(
		private readonly BusinessRepository $businesses,
		private readonly ListingBusinessRepository $links,
		private readonly ListingRepository $listings,
		private readonly CatalogService $catalog,
		private readonly ?TemplateRegistry $templates = null
	) {
	}

	/**
	 * Validates and stores a business.
	 *
	 * @param array<string, mixed> $input Profile fields plus `slug` (empty = from the name).
	 * @param int|null             $id    Business to update; null to create.
	 * @return array{business: Business|null, errors: array<string, string>, warnings: list<string>}
	 */
	public function save_business( array $input, ?int $id = null ): array {
		if ( null !== $id && null === $this->businesses->business( $id ) ) {
			return self::failed( array( 'id' => 'İşletme bulunamadı.' ) );
		}
		$result = ( new ProfileValidator( $this->templates ) )->validate( $input );
		$errors = $result->errors;

		$slug = isset( $input['slug'] ) && is_scalar( $input['slug'] ) ? trim( (string) $input['slug'] ) : '';
		$slug = '' === $slug ? Business::slugify( isset( $input['name'] ) && is_scalar( $input['name'] ) ? (string) $input['name'] : '' ) : $slug;
		if ( 1 !== preg_match( Business::SLUG, $slug ) ) {
			$errors['slug'] = 'Adres kısaltması 2–60 karakter olmalı; küçük harf, rakam ve tire içerebilir.';
		} else {
			$other = $this->businesses->business_by_slug( $slug );
			if ( null !== $other && $other->id !== $id ) {
				$errors['slug'] = 'Bu adres kısaltması başka bir işletmede kullanılıyor.';
			}
		}

		$profile = $result->profile();
		if ( array() !== $errors || null === $profile ) {
			return self::failed( $errors, $result->warnings );
		}
		return array(
			'business' => $this->businesses->store_business( new Business( $id, $slug, $profile ) ),
			'errors'   => array(),
			'warnings' => $result->warnings,
		);
	}

	/**
	 * Deletes a business; refused while it still has listings.
	 *
	 * @param int $id Business id.
	 * @return string '' when deleted, else the reason.
	 */
	public function delete_business( int $id ): string {
		if ( null === $this->businesses->business( $id ) ) {
			return 'İşletme bulunamadı.';
		}
		$existing = array_filter( $this->links->listings_of( $id ), fn( int $listing ): bool => null !== $this->listings->find( $listing ) );
		if ( array() !== $existing ) {
			return 'İşletmenin ilanları var; önce ilanları silin ya da başka bir işletmeye bağlayın.';
		}
		$this->businesses->remove_business( $id );
		return '';
	}

	/**
	 * Links a listing to a business (null = the portal itself).
	 *
	 * @param int      $listing_id  Listing id.
	 * @param int|null $business_id Business id, or null.
	 */
	public function assign_listing( int $listing_id, ?int $business_id ): bool {
		if ( null === $this->listings->find( $listing_id ) || ( null !== $business_id && null === $this->businesses->business( $business_id ) ) ) {
			return false;
		}
		$this->links->store_listing_business( $listing_id, $business_id );
		return true;
	}

	/**
	 * Whether a listing belongs to a business.
	 *
	 * @param int $business_id Business id.
	 * @param int $listing_id  Listing id.
	 */
	public function owns( int $business_id, int $listing_id ): bool {
		return $this->links->business_of( $listing_id ) === $business_id && null !== $this->listings->find( $listing_id );
	}

	/**
	 * A business user saves one of its listings (or a new one, linked to the business).
	 *
	 * @param int                  $business_id Business of the user.
	 * @param array<string, mixed> $input       Listing input (as CatalogService::save_listing()).
	 * @param int|null             $id          Listing to update; null to create.
	 */
	public function save_business_listing( int $business_id, array $input, ?int $id = null ): ValidationResult {
		if ( null === $this->businesses->business( $business_id ) ) {
			return new ValidationResult( null, array( 'business' => 'İşletme bulunamadı.' ) );
		}
		if ( null !== $id && ! $this->owns( $business_id, $id ) ) {
			return new ValidationResult( null, array( 'id' => 'Bu ilan işletmenize ait değil.' ) );
		}
		$result  = $this->catalog->save_listing( $input, $id );
		$listing = $result->listing();
		if ( null !== $listing && null !== $listing->id && null === $id ) {
			$this->links->store_listing_business( $listing->id, $business_id );
		}
		return $result;
	}

	/**
	 * A business user deletes one of its listings.
	 *
	 * @param int $business_id Business of the user.
	 * @param int $id          Listing id.
	 */
	public function delete_business_listing( int $business_id, int $id ): bool {
		return $this->owns( $business_id, $id ) && $this->catalog->delete_listing( $id );
	}

	/**
	 * A failed save.
	 *
	 * @param array<string, string> $errors   Errors.
	 * @param string[]              $warnings Warnings.
	 * @return array{business: null, errors: array<string, string>, warnings: list<string>}
	 */
	private static function failed( array $errors, array $warnings = array() ): array {
		return array(
			'business' => null,
			'errors'   => $errors,
			'warnings' => array_values( $warnings ),
		);
	}
}
