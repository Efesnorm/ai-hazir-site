<?php
/**
 * The A2A skills this plugin offers.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Adapters\A2A;

/**
 * AgentSkill definitions (A2A 1.0: id, name, description, tags required; examples, inputModes,
 * outputModes optional). They are the same operations as the A6/A7 abilities.
 */
final class A2ASkills {

	public const AVAILABILITY = 'musaitlik-sor';
	public const QUOTE        = 'teklif-iste';

	/**
	 * Skill definitions by id.
	 *
	 * @param string[] $ids Skills offered by this site.
	 * @return list<array{id: string, name: string, description: string, tags: list<string>, examples: list<string>, inputModes: list<string>, outputModes: list<string>}>
	 */
	public static function definitions( array $ids ): array {
		$all = array(
			self::AVAILABILITY => array(
				'id'          => self::AVAILABILITY,
				'name'        => 'Müsaitlik sor',
				'description' => 'Bir ilan için istenen miktarın istenen sürede karşılanıp karşılanamayacağını söyler (yes / no / unknown ve gerekçeler). Girdi: DataPart {"skill": "musaitlik-sor", "listing_id": 12, "quantity": 1500, "within_days": 10}.',
				'tags'        => array( 'availability', 'stock', 'lead-time' ),
				'examples'    => array( '{"skill": "musaitlik-sor", "listing_id": 12, "quantity": 1500, "within_days": 10}' ),
				'inputModes'  => array( 'application/json' ),
				'outputModes' => array( 'application/json' ),
			),
			self::QUOTE        => array(
				'id'          => self::QUOTE,
				'name'        => 'Teklif iste',
				'description' => 'Firmaya teklif isteği (veya bu sitede geçerliyse yönlendirme talebi) bırakır. Talep otomatik onaylanmaz ve otomatik yanıt gönderilmez; firma inceleyip iletişim bilgisinden döner. Girdi: DataPart {"skill": "teklif-iste", "kind", "listing_id", "subject", "message", "contact": {"name", "company", "email", "phone"}}.',
				'tags'        => array( 'quote', 'inquiry', 'b2b' ),
				'examples'    => array( '{"skill": "teklif-iste", "kind": "quote_request", "listing_id": 12, "message": "2000 m NYY 3x2,5 için teklif rica ederiz.", "contact": {"name": "Satın alma", "company": "Dağıtıcı A.Ş.", "email": "satinalma@dagitici.example"}}' ),
				'inputModes'  => array( 'application/json' ),
				'outputModes' => array( 'application/json' ),
			),
		);
		return array_values( array_intersect_key( $all, array_flip( $ids ) ) );
	}
}
