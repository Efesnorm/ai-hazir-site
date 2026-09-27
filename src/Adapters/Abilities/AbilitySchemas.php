<?php
/**
 * Input and output schemas of the aihs/ abilities.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Abilities;

use AIHazirSite\Adapters\Rest\RestSchemas;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Query\Availability;
use AIHazirSite\Core\Catalog\Query\ListingSearch;

/**
 * Outputs are the REST bodies (RestSchemas), so REST and MCP answer the same question the same way.
 * Inputs are closed objects (additionalProperties false); descriptions guide AI agents.
 */
final class AbilitySchemas {

	/**
	 * Ability names → [input schema, output schema].
	 *
	 * @return array<string, array{input: array<string, mixed>, output: array<string, mixed>}>
	 */
	public static function all(): array {
		return array(
			'aihs/get-profile'        => array(
				'input'  => self::input( array() ),
				'output' => self::strip( RestSchemas::profile() ),
			),
			'aihs/search-listings'    => array(
				'input'  => self::input(
					array(
						'type'       => array(
							'type'        => 'string',
							'enum'        => ListingType::ALL,
							'description' => 'offer = firmanın sattığı, demand = firmanın aradığı, supply = siparişe üretip tedarik edebildiği.',
						),
						'category'   => array(
							'type'        => 'string',
							'description' => 'Kategori, tam eşleşme (büyük/küçük harf duyarsız).',
						),
						'region'     => array(
							'type'        => 'string',
							'description' => 'Bölge, tam eşleşme.',
						),
						'keyword'    => array(
							'type'        => 'string',
							'description' => 'Başlık, açıklama, kategori, bölge veya özellik değerlerinde geçen kelime (ör. "3x2,5").',
						),
						'attributes' => array(
							'type'                 => 'object',
							'description'          => 'Şablon alanı → değer (eşitlik). Alan adları aihs/get-profile ve REST /templates yanıtında.',
							'additionalProperties' => array( 'type' => 'string' ),
						),
						'page'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => ListingSearch::MAX_PER_PAGE,
							'default' => ListingSearch::DEFAULT_PER_PAGE,
						),
					)
				),
				'output' => self::strip( RestSchemas::listings() ),
			),
			'aihs/get-listing'        => array(
				'input'  => self::input( array( 'id' => self::id() ), array( 'id' ) ),
				'output' => RestSchemas::listing(),
			),
			'aihs/check-availability' => array(
				'input'  => self::input(
					array(
						'id'          => self::id(),
						'quantity'    => array(
							'type'        => 'number',
							'minimum'     => 0,
							'description' => 'İstenen miktar, ilanın biriminde (ör. metre).',
						),
						'within_days' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'En geç kaç gün içinde gerekli.',
						),
					),
					array( 'id' )
				),
				'output' => array(
					'type'                 => 'object',
					'properties'           => array(
						'answer'             => array(
							'type'        => 'string',
							'enum'        => array( Availability::YES, Availability::NO, Availability::UNKNOWN ),
							'description' => 'yes = karşılanabilir, no = karşılanamaz, unknown = ilanda bilgi yok (firmaya sorun).',
						),
						'reasons'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'listing_id'         => array( 'type' => array( 'integer', 'null' ) ),
						'available_quantity' => array(
							'type'    => array( 'string', 'null' ),
							'pattern' => RestSchemas::DECIMAL,
						),
						'unit'               => array( 'type' => 'string' ),
						'lead_time_days'     => array( 'type' => array( 'integer', 'null' ) ),
					),
					'required'             => array( 'answer', 'reasons', 'listing_id', 'available_quantity', 'unit', 'lead_time_days' ),
					'additionalProperties' => false,
				),
			),
		);
	}

	/**
	 * Closed input object.
	 *
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @param string[]                            $required   Required names.
	 * @return array<string, mixed>
	 */
	private static function input( array $properties, array $required = array() ): array {
		// No "properties" key at all when empty: an empty PHP array would be encoded as [] (not an object).
		$schema = array( 'type' => 'object' );
		if ( array() !== $properties ) {
			$schema['properties'] = $properties;
		}
		$schema['additionalProperties'] = false;
		if ( array() !== $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * Listing id.
	 *
	 * @return array<string, mixed>
	 */
	private static function id(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'İlan kimliği (aihs/search-listings sonucundaki id).',
		);
	}

	/**
	 * A REST document schema without its $schema and title keys.
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @return array<string, mixed>
	 */
	private static function strip( array $schema ): array {
		unset( $schema['$schema'], $schema['title'] );
		return $schema;
	}
}
