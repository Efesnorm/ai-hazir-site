<?php
/**
 * Our entries in another plugin's list setting.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Integrations;

/**
 * Adds our entries to a list the site owner also edits, remembering which ones we added, so turning
 * the integration off removes exactly those and nothing the owner had (1.8.0). Comparison is
 * case-insensitive, as the page-cache plugins match user agents.
 */
final class ManagedList {

	/**
	 * The list with our entries added.
	 *
	 * @param string[] $existing Current list.
	 * @param string[] $ours     Entries we want.
	 * @return array{list: list<string>, added: list<string>} New list, and the entries that were not there.
	 */
	public static function add( array $existing, array $ours ): array {
		$have  = array_map( 'strtolower', $existing );
		$added = array();
		foreach ( $ours as $entry ) {
			if ( ! in_array( strtolower( $entry ), $have, true ) ) {
				$added[] = $entry;
				$have[]  = strtolower( $entry );
			}
		}
		return array(
			'list'  => array_values( array_merge( $existing, $added ) ),
			'added' => $added,
		);
	}

	/**
	 * The list without the entries we added (the owner's own entries stay).
	 *
	 * @param string[] $existing Current list.
	 * @param string[] $added    Entries we added earlier.
	 * @return list<string>
	 */
	public static function remove( array $existing, array $added ): array {
		$ours = array_map( 'strtolower', $added );
		return array_values( array_filter( $existing, static fn( string $entry ): bool => ! in_array( strtolower( $entry ), $ours, true ) ) );
	}
}
