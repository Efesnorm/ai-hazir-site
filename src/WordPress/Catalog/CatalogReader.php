<?php
/**
 * The shared read path of the machine channels (REST, abilities / MCP).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog;

use AIHazirSite\Adapters\Rest\RestResponder;
use AIHazirSite\Core\Catalog\Query\CatalogQuery;
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
}
