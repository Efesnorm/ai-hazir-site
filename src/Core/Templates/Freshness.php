<?php
/**
 * Short-lived field values (e.g. remaining tour places).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Templates;

use AIHazirSite\Core\Catalog\Listing;

/**
 * A field with `fresh_hours` is confirmed by saving the listing; once that many hours have
 * passed since the last save, AI outputs mark its value "not verified" instead of stating it.
 */
final class Freshness {

	/**
	 * Whether the field's value is past its freshness limit.
	 *
	 * @param TemplateField $field   Field.
	 * @param Listing       $listing Listing (its updated_at is the confirmation time).
	 * @param string        $now     ISO 8601 date-time.
	 */
	public static function is_stale( TemplateField $field, Listing $listing, string $now ): bool {
		if ( null === $field->fresh_hours || null === $listing->updated_at ) {
			return false;
		}
		$saved = strtotime( $listing->updated_at );
		$at    = strtotime( $now );
		return false !== $saved && false !== $at && $at - $saved > $field->fresh_hours * 3600;
	}
}
