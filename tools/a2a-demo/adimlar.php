<?php
/**
 * A2A demosunun adımları. WP-CLI ile çalışır: wp --user=admin eval-file /demo/adimlar.php <adım>
 *
 * Adımlar: fabrika-kur, dagitici-kur, gonder (dağıtıcıda, insan onayı yerine operatör), fabrika-kontrol.
 * "gonder" eklentinin kendi iki adımlı akışını kullanır: A2AAdmin::page() önizlemeyi ve tek kullanımlık belirteci
 * üretir, A2AAdmin::handle_approve() "Onaylıyorum, gönder" düğmesinin yaptığını yapar.
 *
 * @package AIHazirSite
 */

// phpcs:ignoreFile -- Demo betiği; eklentinin parçası değildir.

use AIHazirSite\Core\Features;
use AIHazirSite\WordPress\A2A\A2AAdmin;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Matching\MatchingModule;

$adim = $args[0] ?? '';
$yaz  = static function ( string $baslik, $veri = null ): void {
	echo "\n== {$baslik}\n";
	if ( null !== $veri ) {
		echo is_string( $veri ) ? $veri : wp_json_encode( $veri, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		echo "\n";
	}
};
$ac = static function ( array $anahtarlar ): void {
	foreach ( $anahtarlar as $anahtar ) {
		Features::set( $anahtar, true );
	}
};
$kaydet = static function ( array $ilan ): int {
	$sonuc = CatalogModule::service()->save_listing( $ilan );
	if ( ! $sonuc->is_valid() ) {
		WP_CLI::error( 'İlan kaydedilemedi: ' . implode( ' | ', $sonuc->errors ) );
	}
	return (int) $sonuc->listing()?->id;
};
$gelecek = gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS );

switch ( $adim ) {
	case 'fabrika-kur':
		// Fabrika (F): satıcı. Katalog, teklif kutusu, REST (ortak site okuması için) ve A2A açık.
		$ac( array( Features::CATALOG, Features::SCHEMA_OUTPUT, Features::REST_API, Features::INQUIRIES, Features::A2A ) );
		CatalogModule::service()->save_profile(
			array(
				'name'          => 'Demo Kablo Fabrikası A.Ş.',
				'sector'        => 'Kablo üretimi',
				'country'       => 'TR',
				'contact_email' => 'satis@fabrika.test',
			)
		);
		$id = $kaydet(
			array(
				'type'           => 'offer',
				'title'          => 'NYY 3x2,5 enerji kablosu',
				'category'       => 'Kablo',
				'quantity'       => '5000',
				'unit'           => 'm',
				'price_min'      => '40',
				'price_max'      => '46',
				'currency'       => 'TRY',
				'region'         => 'Türkiye',
				'lead_time_days' => '5',
				'valid_until'    => $gelecek,
			)
		);
		$yaz( 'Fabrika hazır', array( 'ilan' => $id, 'kart' => home_url( '/.well-known/agent-card.json' ) ) );
		break;

	case 'dagitici-kur':
		// Dağıtıcı (D): alıcı. Katalog, eşleştirme ve A2A açık; fabrika ortak site.
		$ac( array( Features::CATALOG, Features::MATCHING, Features::A2A ) );
		CatalogModule::service()->save_profile(
			array(
				'name'          => 'Demo Elektrik Dağıtım Ltd.',
				'sector'        => 'Elektrik malzemesi dağıtımı',
				'country'       => 'TR',
				'contact_email' => 'satinalma@dagitici.test',
				'contact_phone' => '+90 212 555 00 00',
			)
		);
		$id = $kaydet(
			array(
				'type'           => 'demand',
				'title'          => 'NYY 3x2,5 kablo',
				'category'       => 'Kablo',
				'quantity'       => '2000',
				'unit'           => 'm',
				'lead_time_days' => '10',
				'valid_until'    => $gelecek,
			)
		);
		$ayarlar             = get_option( MatchingModule::OPTION, array() );
		$ayarlar             = is_array( $ayarlar ) ? $ayarlar : array();
		$ayarlar['partners'] = array( 'https://fabrika.test/wp-json/aihs/v1/' );
		update_option( MatchingModule::OPTION, $ayarlar );
		$yaz( 'Dağıtıcı hazır', array( 'aranan_ilan' => $id, 'ortak' => MatchingModule::settings()['partners'] ) );
		break;

	case 'gonder':
		// 1. Eşleşme: aranan ilan ve ortak sitedeki aday.
		$ihtiyac = null;
		foreach ( MatchingModule::current( 'demand' ) as $ilan ) {
			$ihtiyac = $ilan;
		}
		if ( null === $ihtiyac ) {
			WP_CLI::error( 'Aranan ilan yok; önce dagitici-kur.' );
		}
		$sonuc = MatchingModule::matches( $ihtiyac );
		$aday  = null;
		foreach ( $sonuc['matches'] as $eslesme ) {
			if ( '' !== $eslesme['candidate']->source ) {
				$aday = $eslesme;
				break;
			}
		}
		$yaz(
			'1. Eşleşme',
			array(
				'aranan'    => $ihtiyac->title,
				'erisilemez' => $sonuc['unreachable'],
				'aday'      => null === $aday ? null : array(
					'baslik' => $aday['candidate']->listing->title,
					'ortak'  => $aday['candidate']->source,
					'puan'   => $aday['score'],
				),
			)
		);
		if ( null === $aday ) {
			WP_CLI::error( 'Ortak siteden eşleşme yok.' );
		}

		// 2. Önizleme: eklenti kartı okur, doğrular ve gönderilecek mesajı gösterir.
		$sayfa = A2AAdmin::page( (int) $ihtiyac->id, $aday['candidate']->source, (int) $aday['candidate']->listing->id );
		if ( ! preg_match( '/name="token" value="([A-Za-z0-9]+)"/', $sayfa, $m ) ) {
			WP_CLI::error( 'Önizleme üretilemedi: ' . wp_strip_all_tags( $sayfa ) );
		}
		preg_match( '/<pre id="aihs-a2a-preview">(.*?)<\/pre>/s', $sayfa, $onizleme );
		preg_match( '/Alıcı: ([^<]+)/u', $sayfa, $alici );
		$yaz( '2. Önizleme (gönderilecek mesaj)', 'Alıcı: ' . html_entity_decode( $alici[1] ?? '' ) . "\n" . html_entity_decode( $onizleme[1] ?? '' ) );

		// 3. Onay: "Onaylıyorum, gönder" düğmesinin çağırdığı işleyici (yetki + nonce + tek kullanımlık belirteç).
		$_REQUEST['_wpnonce'] = wp_create_nonce( A2AAdmin::APPROVE );
		$donus                = A2AAdmin::handle_approve( array( 'token' => $m[1] ) );
		$yanit                = get_transient( A2AAdmin::PENDING . 'result_' . get_current_user_id() );
		$yaz( '3. Onaylandı ve gönderildi', array( 'sonuc' => str_contains( $donus, 'sent=ok' ) ? 'ok' : 'failed', 'karsi_agent' => $yanit ) );

		$yaz( '4. Dağıtıcı denetim kaydı (son)', array_slice( ( new WpAuditRepository() )->latest( 3 ), 0, 1 ) );
		if ( ! str_contains( $donus, 'sent=ok' ) ) {
			WP_CLI::halt( 1 );
		}
		break;

	case 'fabrika-kontrol':
		$talepler = ( new WpInquiryRepository() )->list_inquiries( '', '', 5 );
		$son      = $talepler[0] ?? null;
		$yaz(
			'5. Fabrika teklif kutusu (son talep)',
			null === $son ? 'Talep yok.' : array(
				'kanal'  => $son->channel,
				'kaynak' => $son->source,
				'tur'    => $son->kind,
				'ilan'   => $son->listing_id,
				'konu'   => $son->subject,
				'mesaj'  => $son->message,
				'durum'  => $son->status,
			)
		);
		$yaz( '6. Fabrika denetim kaydı (son)', array_slice( ( new WpAuditRepository() )->latest( 3 ), 0, 2 ) );
		if ( null === $son || 'a2a' !== $son->channel ) {
			WP_CLI::halt( 1 );
		}
		break;

	default:
		WP_CLI::error( 'Adım: fabrika-kur | dagitici-kur | gonder | fabrika-kontrol' );
}
