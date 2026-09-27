<?php
/**
 * ProfileRepository on the aihs_profile option.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog;

use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Contracts\ProfileRepository;
use AIHazirSite\Core\Contracts\Settings;
use AIHazirSite\WordPress\Platform\WpSettings;

/**
 * Stores the company profile in one option and its save time in another.
 */
final class WpProfileRepository implements ProfileRepository {

	public const OPTION         = 'aihs_profile';
	public const UPDATED_OPTION = 'aihs_profile_updated';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Storage.
	 */
	public function __construct( private readonly Settings $settings = new WpSettings() ) {
	}

	/**
	 * Stored profile.
	 */
	public function get(): CompanyProfile {
		return CompanyProfile::from_array( $this->settings->get( self::OPTION, array() ) );
	}

	/**
	 * Replaces the profile and records the time.
	 *
	 * @param CompanyProfile $profile Profile.
	 */
	public function store_profile( CompanyProfile $profile ): void {
		$this->settings->set( self::OPTION, $profile->to_array(), false );
		$this->settings->set( self::UPDATED_OPTION, gmdate( 'Y-m-d\TH:i:s\Z' ), false );
	}

	/**
	 * Removes the profile.
	 */
	public function remove_profile(): void {
		$this->settings->delete( self::OPTION );
		$this->settings->delete( self::UPDATED_OPTION );
	}

	/**
	 * Puts back an earlier state exactly.
	 *
	 * @param CompanyProfile|null $profile    Earlier profile (null = none).
	 * @param string|null         $updated_at Earlier save time.
	 */
	public function restore_profile( ?CompanyProfile $profile, ?string $updated_at ): void {
		if ( null === $profile ) {
			$this->settings->delete( self::OPTION );
			$this->settings->delete( self::UPDATED_OPTION );
			return;
		}
		$this->settings->set( self::OPTION, $profile->to_array(), false );
		if ( null === $updated_at ) {
			$this->settings->delete( self::UPDATED_OPTION );
		} else {
			$this->settings->set( self::UPDATED_OPTION, $updated_at, false );
		}
	}

	/**
	 * Last save time.
	 */
	public function updated_at(): ?string {
		$value = $this->settings->get( self::UPDATED_OPTION, '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
