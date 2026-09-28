<?php
/**
 * A listing that may meet a need.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Matching;

use AIHazirSite\Core\Catalog\Listing;

/**
 * An offer or supply listing and where it comes from ('' = this site, else the partner site URL).
 */
final class Candidate {

	/**
	 * Constructor.
	 *
	 * @param Listing $listing Listing.
	 * @param string  $source  '' for this site, else the partner's REST base URL.
	 * @param string  $url     Where a person can see the listing.
	 */
	public function __construct(
		public readonly Listing $listing,
		public readonly string $source = '',
		public readonly string $url = ''
	) {
	}

	/**
	 * Stable key for ordering and display.
	 */
	public function key(): string {
		return $this->source . '#' . (int) $this->listing->id;
	}
}
