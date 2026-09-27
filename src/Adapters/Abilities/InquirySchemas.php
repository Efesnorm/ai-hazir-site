<?php
/**
 * Schemas of the inquiry abilities and REST endpoint.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\Abilities;

use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquiryValidator;

/**
 * `aihs/submit-inquiry` (quote request, offer or referral request) on ordinary sites;
 * `aihs/request-referral` (referral request only, no fees) on fee-less professional service sites
 * such as law firms. Exactly one of them exists on a site.
 */
final class InquirySchemas {

	public const SUBMIT   = 'aihs/submit-inquiry';
	public const REFERRAL = 'aihs/request-referral';

	/**
	 * Input schema.
	 *
	 * @param string[] $kinds Kinds allowed on the site.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param list<string> $kinds
	 */
	public static function input( array $kinds ): array {
		$properties = array(
			'kind'       => array(
				'type'        => 'string',
				'enum'        => $kinds,
				'description' => 'quote_request = fiyat/teklif isteği, offer = alıcı adına teklif, referral = yönlendirme/iletişim talebi.',
			),
			'listing_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Talebin ilgili olduğu ilan (aihs/search-listings sonucundaki id), isteğe bağlı.',
			),
			'subject'    => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'message'    => array(
				'type'        => 'string',
				'minLength'   => InquiryValidator::MESSAGE_MIN,
				'maxLength'   => InquiryValidator::MESSAGE_MAX,
				'description' => 'Talebin kendisi: ne, ne kadar, ne zaman, nereye.',
			),
			'contact'    => array(
				'type'                 => 'object',
				'description'          => 'Firmanın size dönebilmesi için; e-posta veya telefon gerekli. Yalnızca firma görür, süre sonunda silinir.',
				'properties'           => array(
					'name'    => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'company' => array(
						'type'      => 'string',
						'maxLength' => 150,
					),
					'email'   => array(
						'type'   => 'string',
						'format' => 'email',
					),
					'phone'   => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		);
		if ( array( Inquiry::KIND_REFERRAL ) === $kinds ) {
			unset( $properties['kind'] );
			$properties['message']['description'] = 'Uzmanlık alanı, dava türleri, referans işler, uygunluk gibi her konu; ücret sorulamaz ve ücret bilgisi verilmez.';
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'message', 'contact' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema (the reference only; the status is never disclosed).
	 *
	 * @return array<string, mixed>
	 */
	public static function output(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'received'  => array( 'type' => 'boolean' ),
				'reference' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'message'   => array( 'type' => 'string' ),
			),
			'required'             => array( 'received', 'reference', 'message' ),
			'additionalProperties' => false,
		);
	}
}
