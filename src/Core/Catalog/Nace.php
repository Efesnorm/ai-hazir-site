<?php
/**
 * NACE Rev. 2.1 sections (1.22.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

/**
 * The 22 sections of NACE Rev. 2.1, the EU statistical classification of economic activities (Commission Delegated
 * Regulation (EU) 2023/137; used by European statistics since 1 January 2025). English titles as in the official manual
 * ("NACE Rev. 2.1 – Statistical classification of economic activities in the European Union", Eurostat). Turkish titles
 * follow TÜİK's NACE Rev. 2 wording; sections J and K (new in Rev. 2.1) are our translation.
 * A shared section lets sites in different countries compare sectors ("Nakliye", "Lojistik", "Transport" are all H).
 */
final class Nace {

	/**
	 * Letter → [English title, Turkish title].
	 */
	public const SECTIONS = array(
		'A' => array( 'Agriculture, forestry and fishing', 'Tarım, ormancılık ve balıkçılık' ),
		'B' => array( 'Mining and quarrying', 'Madencilik ve taş ocakçılığı' ),
		'C' => array( 'Manufacturing', 'İmalat' ),
		'D' => array( 'Electricity, gas, steam and air conditioning supply', 'Elektrik, gaz, buhar ve iklimlendirme üretimi ve dağıtımı' ),
		'E' => array( 'Water supply; sewerage, waste management and remediation activities', 'Su temini; kanalizasyon, atık yönetimi ve iyileştirme faaliyetleri' ),
		'F' => array( 'Construction', 'İnşaat' ),
		'G' => array( 'Wholesale and retail trade', 'Toptan ve perakende ticaret' ),
		'H' => array( 'Transportation and storage', 'Ulaştırma ve depolama' ),
		'I' => array( 'Accommodation and food service activities', 'Konaklama ve yiyecek hizmeti faaliyetleri' ),
		'J' => array( 'Publishing, broadcasting, and content production and distribution activities', 'Yayıncılık, yayın ve içerik üretimi ve dağıtımı faaliyetleri' ),
		'K' => array( 'Telecommunication, computer programming, consulting, computing infrastructure and other information service activities', 'Telekomünikasyon, bilgisayar programcılığı, danışmanlık, bilişim altyapısı ve diğer bilgi hizmeti faaliyetleri' ),
		'L' => array( 'Financial and insurance activities', 'Finans ve sigorta faaliyetleri' ),
		'M' => array( 'Real estate activities', 'Gayrimenkul faaliyetleri' ),
		'N' => array( 'Professional, scientific and technical activities', 'Mesleki, bilimsel ve teknik faaliyetler' ),
		'O' => array( 'Administrative and support service activities', 'İdari ve destek hizmet faaliyetleri' ),
		'P' => array( 'Public administration and defence; compulsory social security', 'Kamu yönetimi ve savunma; zorunlu sosyal güvenlik' ),
		'Q' => array( 'Education', 'Eğitim' ),
		'R' => array( 'Human health and social work activities', 'İnsan sağlığı ve sosyal hizmet faaliyetleri' ),
		'S' => array( 'Arts, sports and recreation', 'Sanat, spor ve eğlence' ),
		'T' => array( 'Other service activities', 'Diğer hizmet faaliyetleri' ),
		'U' => array( 'Activities of households as employers and undifferentiated goods- and service-producing activities of households for own use', 'Hanehalklarının işveren olarak faaliyetleri ve kendi kullanımları için ayrım yapılmamış mal ve hizmet üretim faaliyetleri' ),
		'V' => array( 'Activities of extraterritorial organisations and bodies', 'Ülke dışı örgüt ve temsilciliklerin faaliyetleri' ),
	);

	/**
	 * Section letter from input ('' when empty or unknown).
	 *
	 * @param mixed $value Input.
	 */
	public static function section( mixed $value ): string {
		$letter = strtoupper( trim( is_scalar( $value ) ? (string) $value : '' ) );
		return isset( self::SECTIONS[ $letter ] ) ? $letter : '';
	}

	/**
	 * "H – Ulaştırma ve depolama" ('' for no section).
	 *
	 * @param string $letter Section letter.
	 */
	public static function label( string $letter ): string {
		return isset( self::SECTIONS[ $letter ] ) ? $letter . ' – ' . self::SECTIONS[ $letter ][1] : '';
	}
}
