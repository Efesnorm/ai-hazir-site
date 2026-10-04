<?php
/**
 * JSON schemas of the REST responses.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Rest;

use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\I18n\Localizer;
use AIHazirSite\Core\Network\SiblingCatalog;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateField;

/**
 * The contract of each endpoint, in the JSON Schema subset WordPress validates
 * (type, enum, pattern, format, required, properties, additionalProperties, items, minimum…).
 * Decimal amounts are strings ("42.50") so no rounding happens between the catalog and the client.
 * Served at /aihs/v1/schema/<name> so agents can learn the shapes.
 */
final class RestSchemas {

	public const DRAFT   = 'http://json-schema.org/draft-04/schema#';
	public const DECIMAL = '^[0-9]{1,12}([.][0-9]{1,4})?$';
	public const DATE    = '^[0-9]{4}-[0-9]{2}-[0-9]{2}$';

	/**
	 * Schema names.
	 */
	public const NAMES = array( 'profile', 'listings', 'listing', 'templates' );

	/**
	 * Schema by name.
	 *
	 * @param string $name         One of self::NAMES.
	 * @param bool   $multilingual With the optional multilingual keys (1.1.0).
	 * @return array<string, mixed>
	 */
	public static function get( string $name, bool $multilingual = false ): array {
		$schema = match ( $name ) {
			'profile'   => self::profile(),
			'listings'  => self::listings(),
			'listing'   => self::document( 'aihs-listing', self::listing() ),
			'templates' => self::templates(),
			default     => array(),
		};
		return $multilingual ? self::with_languages( $name, $schema ) : $schema;
	}

	/**
	 * Sibling suggestions (1.22.0): an optional `network_suggestions` list on a listings document (search answers
	 * while `network_suggestions` is on).
	 *
	 * @param array<string, mixed> $schema Listings schema.
	 * @return array<string, mixed>
	 */
	public static function with_suggestions( array $schema ): array {
		$nullable                                    = array( 'type' => array( 'string', 'null' ) );
		$schema['properties']['network_suggestions'] = array(
			'type'        => 'array',
			'maxItems'    => SiblingCatalog::LIMIT,
			'description' => 'Bu sitede uygun ilan yoksa (veya network=true istendiyse) doğrulanmış kardeş portallardaki uygun ilanlar. Kardeş sitenin herkese açık kataloğundan, retrieved_at anında alınmıştır.',
			'items'       => self::object(
				array(
					'site'         => self::string(),
					'site_name'    => self::string(),
					'country'      => array(
						'type'    => 'string',
						'pattern' => '^([A-Z]{2})?$',
					),
					'business'     => $nullable,
					'title'        => self::string(),
					'type'         => array(
						'type' => 'string',
						'enum' => ListingType::ALL,
					),
					'category'     => self::string(),
					'region'       => self::string(),
					'url'          => self::string(),
					'nace'         => array(
						'type'    => array( 'string', 'null' ),
						'pattern' => '^[A-V]$',
					),
					'retrieved_at' => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
				)
			),
		);
		return $schema;
	}

	/**
	 * Portal mode (1.2.0): listings carry `business` (reference or null); GET /businesses.
	 *
	 * @param string               $name   One of self::NAMES or 'businesses'.
	 * @param array<string, mixed> $schema Schema from get() (ignored for 'businesses').
	 * @return array<string, mixed>
	 */
	public static function with_portal( string $name, array $schema ): array {
		$reference = array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'id'   => self::integer( 1 ),
				'slug' => self::string(),
				'name' => self::string(),
				'url'  => self::string(),
			),
			'required'             => array( 'id', 'slug', 'name', 'url' ),
			'additionalProperties' => false,
		);
		switch ( $name ) {
			case 'listing':
				$schema['properties']['business'] = $reference;
				break;
			case 'listings':
				$schema['properties']['items']['items']['properties']['business'] = $reference;
				break;
			case 'businesses':
				$schema = self::document(
					'aihs-businesses',
					self::object(
						array(
							'items'       => array(
								'type'  => 'array',
								'items' => self::object(
									array(
										'id'             => self::integer( 1 ),
										'slug'           => self::string(),
										'name'           => self::string(),
										'sector'         => self::string(),
										'country'        => self::string(),
										'languages'      => self::strings(),
										'certifications' => self::strings(),
										'contact_email'  => self::string(),
										'contact_phone'  => self::string(),
										'catalog_url'    => self::string(),
										'nace'           => self::nace(),
									),
									array( 'nace' )
								),
							),
							'updated_at'  => self::nullable_datetime(),
							'valid_until' => array( 'type' => 'null' ),
						)
					)
				);
				break;
		}
		return $schema;
	}

	/**
	 * A document schema with the optional multilingual keys (1.1.0): `language` on the document
	 * and `translation` on the profile and on every listing. Single-language sites never get them.
	 *
	 * @param string               $name   One of self::NAMES.
	 * @param array<string, mixed> $schema Schema from get().
	 * @return array<string, mixed>
	 */
	public static function with_languages( string $name, array $schema ): array {
		$language = array(
			'type'    => 'string',
			'pattern' => '^[a-z]{2}$',
		);
		$marker   = self::object(
			array(
				'language'          => $language,
				'missing'           => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
						'enum' => array_merge( Localizer::LISTING_FIELDS, Localizer::PROFILE_FIELDS ),
					),
				),
				'fallback_language' => array(
					'type'    => array( 'string', 'null' ),
					'pattern' => '^[a-z]{2}$',
				),
			)
		);
		switch ( $name ) {
			case 'profile':
			case 'listing':
				$schema['properties']['language']    = $language;
				$schema['properties']['translation'] = $marker;
				break;
			case 'listings':
				$schema['properties']['language']                                    = $language;
				$schema['properties']['items']['items']['properties']['translation'] = $marker;
				break;
		}
		return $schema;
	}

	/**
	 * GET /profile.
	 *
	 * @return array<string, mixed>
	 */
	public static function profile(): array {
		return self::document(
			'aihs-profile',
			self::object(
				array(
					'name'           => self::string(),
					'sector'         => self::string(),
					'country'        => array(
						'type'    => 'string',
						'pattern' => '^([A-Z]{2})?$',
					),
					'languages'      => self::strings(),
					'contact_email'  => self::string(),
					'contact_phone'  => self::string(),
					'certifications' => self::strings(),
					'template'       => self::string(),
					'url'            => self::string(),
					'catalog_url'    => self::string(),
					'updated_at'     => self::nullable_datetime(),
					'valid_until'    => array( 'type' => 'null' ),
					'nace'           => self::nace(),
				),
				array( 'nace' )
			)
		);
	}

	/**
	 * NACE Rev. 2.1 section letter (1.22.0, optional).
	 *
	 * @return array<string, mixed>
	 */
	private static function nace(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[A-V]$',
		);
	}

	/**
	 * GET /listings.
	 *
	 * @return array<string, mixed>
	 */
	public static function listings(): array {
		return self::document(
			'aihs-listings',
			self::object(
				array(
					'items'       => array(
						'type'  => 'array',
						'items' => self::listing(),
					),
					'page'        => self::integer( 1 ),
					'per_page'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => ListingsQuery::MAX_PER_PAGE,
					),
					'total'       => self::integer( 0 ),
					'total_pages' => self::integer( 0 ),
					'updated_at'  => self::nullable_datetime(),
					'valid_until' => array( 'type' => 'null' ),
				)
			)
		);
	}

	/**
	 * One listing (item of /listings, body of /listings/{id}).
	 *
	 * @return array<string, mixed>
	 */
	public static function listing(): array {
		$amount = array(
			'type'    => array( 'string', 'null' ),
			'pattern' => self::DECIMAL,
		);
		return self::object(
			array(
				'id'             => self::integer( 1 ),
				'type'           => array(
					'type' => 'string',
					'enum' => ListingType::ALL,
				),
				'title'          => self::string(),
				'description'    => self::string(),
				'category'       => self::string(),
				'url'            => self::string(),
				'quantity'       => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'value' => array(
							'type'    => 'string',
							'pattern' => self::DECIMAL,
						),
						'unit'  => self::string(),
					),
					'required'             => array( 'value', 'unit' ),
					'additionalProperties' => false,
				),
				'price'          => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'min'      => $amount,
						'max'      => $amount,
						'currency' => array(
							'type'    => 'string',
							'pattern' => '^[A-Z]{3}$',
						),
					),
					'required'             => array( 'min', 'max', 'currency' ),
					'additionalProperties' => false,
				),
				'region'         => self::string(),
				'lead_time_days' => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 0,
				),
				'valid_until'    => array(
					'type'    => 'string',
					'pattern' => self::DATE,
				),
				'updated_at'     => self::nullable_datetime(),
				'template'       => self::string(),
				'attributes'     => array(
					'type'  => 'array',
					'items' => self::object(
						array(
							'name'         => self::string(),
							'label'        => self::string(),
							'value'        => self::string(),
							'unit'         => self::string(),
							'verified'     => array( 'type' => 'boolean' ),
							'confirmed_at' => self::nullable_datetime(),
						)
					),
				),
			),
			array( 'price' )
		);
	}

	/**
	 * GET /templates.
	 *
	 * @return array<string, mixed>
	 */
	public static function templates(): array {
		$field = self::object(
			array(
				'name'        => self::string(),
				'label'       => self::string(),
				'type'        => array(
					'type' => 'string',
					'enum' => TemplateField::TYPES,
				),
				'unit'        => self::string(),
				'unit_code'   => self::string(),
				'required'    => array( 'type' => 'boolean' ),
				'allowed'     => self::strings(),
				'help'        => self::string(),
				'fresh_hours' => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
			)
		);
		return self::document(
			'aihs-templates',
			self::object(
				array(
					'items'       => array(
						'type'  => 'array',
						'items' => self::object(
							array(
								'id'            => self::string(),
								'version'       => self::integer( 1 ),
								'name'          => self::string(),
								'description'   => self::string(),
								'item_type'     => array(
									'type' => 'string',
									'enum' => Template::ITEM_TYPES,
								),
								'price_allowed' => array( 'type' => 'boolean' ),
								'fields'        => array(
									'type'  => 'array',
									'items' => $field,
								),
							)
						),
					),
					'updated_at'  => array( 'type' => 'null' ),
					'valid_until' => array( 'type' => 'null' ),
				)
			)
		);
	}

	/**
	 * Top-level schema with $schema and title.
	 *
	 * @param string               $title  Title.
	 * @param array<string, mixed> $schema Schema.
	 * @return array<string, mixed>
	 */
	private static function document( string $title, array $schema ): array {
		return array_merge(
			array(
				'$schema' => self::DRAFT,
				'title'   => $title,
			),
			$schema
		);
	}

	/**
	 * Closed object whose properties are all required except the optional ones.
	 *
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @param string[]                            $optional   Optional property names.
	 * @return array<string, mixed>
	 */
	private static function object( array $properties, array $optional = array() ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_values( array_diff( array_keys( $properties ), $optional ) ),
			'additionalProperties' => false,
		);
	}

	/**
	 * String.
	 *
	 * @return array<string, string>
	 */
	private static function string(): array {
		return array( 'type' => 'string' );
	}

	/**
	 * List of strings.
	 *
	 * @return array<string, mixed>
	 */
	private static function strings(): array {
		return array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Integer from a minimum.
	 *
	 * @param int $minimum Minimum.
	 * @return array<string, mixed>
	 */
	private static function integer( int $minimum ): array {
		return array(
			'type'    => 'integer',
			'minimum' => $minimum,
		);
	}

	/**
	 * ISO 8601 date-time or null.
	 *
	 * @return array<string, mixed>
	 */
	private static function nullable_datetime(): array {
		return array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);
	}
}
