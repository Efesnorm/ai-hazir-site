<?php
/**
 * Tests for SpamScorer with a sample data set.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Security;

use AIHazirSite\Core\Security\SpamScorer;
use AIHazirSite\Tests\Support\SchemaFixtures as F;
use PHPUnit\Framework\TestCase;

/**
 * Spam samples reach the quarantine threshold, normal samples stay below it.
 *
 * @covers \AIHazirSite\Core\Security\SpamScorer
 */
final class SpamScorerTest extends TestCase {

	/**
	 * Normal inquiries: [subject, message, about the cable offer?, recent].
	 *
	 * @return array<string, array{string, string, bool, int}>
	 */
	public static function normal(): array {
		return array(
			'teklif isteği'     => array( 'NYY kablo teklifi', 'Merhaba, 3x2,5 NYY kablodan 800 metre için fiyat teklifi rica ederiz. Teslim İstanbul.', true, 0 ),
			'miktar sorusu'     => array( '', 'Enerji kablosu için 2000 metrelik sipariş verebilir miyiz? Makara boyu nedir?', true, 0 ),
			'ajan mesajı'       => array( 'Quote request', 'Our client needs 500 m of NYY 3x2,5 energy cable delivered to Izmir within two weeks.', true, 0 ),
			'genel iş birliği'  => array( 'Tedarik', 'Firmanızla uzun vadeli kablo tedariki konusunda görüşmek istiyoruz, uygun bir zaman var mı?', false, 0 ),
			'tek bağlantılı'    => array( 'Şartname', 'Kablo şartnamemiz şu adreste: https://ornek.com/sartname.pdf – bu standarda uygun kablonuz var mı?', true, 0 ),
			'kısa ama anlamlı'  => array( '', 'Kablo stokta var mı acaba?', true, 0 ),
			'ikinci talep'      => array( 'Ek soru', 'Önceki talebimize ek olarak TSE belgesini de gönderebilir misiniz? Kablo için gerekli.', true, 1 ),
			'hukuk yönlendirme' => array( 'Tahkim', 'Uluslararası ticari tahkim konusunda deneyiminiz var mı? Benzer davalarda referans işlerinizi öğrenmek isteriz.', false, 0 ),
			'tur sorusu'        => array( 'Kapadokya', 'Ekim ayındaki balon turunda 4 kişilik yer kaldı mı? Buluşma noktası neresi?', false, 0 ),
			'rakamlı sipariş'   => array( 'Sipariş', 'Kablo: 3x2,5 mm² NYY, 4 makara x 250 m, toplam 1000 m. Teslim: 15.10.2026.', true, 0 ),
		);
	}

	/**
	 * Spam inquiries.
	 *
	 * @return array<string, array{string, string, bool, int}>
	 */
	public static function spam(): array {
		return array(
			'boş'                   => array( '', '..........', false, 0 ),
			'anlamsız'              => array( 'asdf', '!!!!!! ??? ###### $$$$', false, 0 ),
			'bağlantı yığını'       => array( 'Cheap', 'Visit http://a.example http://b.example www.c.example now', false, 0 ),
			'seri gönderim'         => array( 'Kablo', 'Kablo fiyatı nedir, lütfen dönün, acil kablo lazım bize.', true, 4 ),
			'alakasız + bağlantı'   => array( 'SEO hizmeti', 'Sitenizi Google ilk sayfaya taşıyoruz, bilgi için http://seo.example ve http://seo2.example', true, 0 ),
			'tekrar karakter'       => array( 'aaaaaaaaaa', 'aaaaaaaaaaaaaaaa bbbbbbbbbbbb', false, 0 ),
			'sayı yığını'           => array( '', '123 456 789 000 111 222 333', false, 0 ),
			'büyük harf + bağlantı' => array( 'KAZANDINIZ', 'HEMEN TIKLAYIN ÖDÜLÜNÜZÜ ALIN http://odul.example VE http://odul2.example', false, 0 ),
			'kripto'                => array( 'Crypto', 'Earn money fast https://x.example https://y.example https://z.example', false, 0 ),
			'seri + alakasız'       => array( 'Merhaba', 'Web tasarım hizmetimiz hakkında bilgi almak ister misiniz?', true, 3 ),
		);
	}

	/**
	 * Normal samples stay below the threshold.
	 *
	 * @dataProvider normal
	 * @param string $subject Subject.
	 * @param string $message Message.
	 * @param bool   $about   About the cable offer.
	 * @param int    $recent  Recent inquiries of the client.
	 */
	public function test_normal( string $subject, string $message, bool $about, int $recent ): void {
		$result = ( new SpamScorer() )->score( $subject, $message, $about ? F::offer() : null, $recent );
		$this->assertLessThan( SpamScorer::DEFAULT_THRESHOLD, $result['score'], implode( ' | ', $result['reasons'] ) );
	}

	/**
	 * Spam samples reach the threshold, with reasons.
	 *
	 * @dataProvider spam
	 * @param string $subject Subject.
	 * @param string $message Message.
	 * @param bool   $about   About the cable offer.
	 * @param int    $recent  Recent inquiries of the client.
	 */
	public function test_spam( string $subject, string $message, bool $about, int $recent ): void {
		$result = ( new SpamScorer() )->score( $subject, $message, $about ? F::offer() : null, $recent );
		$this->assertGreaterThanOrEqual( SpamScorer::DEFAULT_THRESHOLD, $result['score'], implode( ' | ', $result['reasons'] ) );
		$this->assertNotEmpty( $result['reasons'] );
		$this->assertLessThanOrEqual( 100, $result['score'] );
	}
}
