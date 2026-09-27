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
 * Stores the company profile in one option.
 */
final class WpProfileRepository implements ProfileRepository {

	public const OPTION = 'aihs_profile';

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
	 * Replaces the profile.
	 *
	 * @param CompanyProfile $profile Profile.
	 */
	public function store_profile( CompanyProfile $profile ): void {
		$this->settings->set( self::OPTION, $profile->to_array(), false );
	}

	/**
	 * Removes the profile.
	 */
	public function remove_profile(): void {
		$this->settings->delete( self::OPTION );
	}
}
