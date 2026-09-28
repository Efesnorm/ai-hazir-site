<?php
/**
 * The shared read path of the machine channels (REST, abilities / MCP).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog;

use AIHazirSite\Adapters\Rest\RestResponder;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\CatalogQuery;
use AIHazirSite\WordPress\I18n\Multilingual;
use AIHazirSite\WordPress\Platform\WpClock;
use AIHazirSite\WordPress\Schema\SchemaModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;

/**
 * One core query service and one body producer for every machine channel, so the same
 * question gets the same answer over REST and MCP.
 */
final class CatalogReader {

	/**
	 * Core query over the stored listings.
	 */
	public static function query(): CatalogQuery {
		return new CatalogQuery( new WpListingRepository(), new WpClock() );
	}

	/**
	 * Body producer for this site.
	 */
	public static function responder(): RestResponder {
		return new RestResponder( home_url( '/' ), SchemaModule::catalog_url(), TemplatesModule::registry() );
	}

	/**
	 * Listings in a language and their translation markers by id (1.1.0).
	 *
	 * @param Listing[] $listings Listings.
	 * @param string    $language Language.
	 * @return array{0: list<Listing>, 1: array<int, array{language: string, missing: list<string>, fallback_language: string|null}>}
	 *
	 * @phpstan-param list<Listing> $listings
	 */
	public static function localized( array $listings, string $language ): array {
		$records = array();
		$markers = array();
		foreach ( Multilingual::listings( $listings, $language ) as $localized ) {
			$record    = $localized->record;
			$records[] = $record instanceof Listing ? $record : null;
			if ( $record instanceof Listing && null !== $record->id ) {
				$markers[ $record->id ] = $localized->marker();
			}
		}
		return array( array_values( array_filter( $records ) ), $markers );
	}
}
