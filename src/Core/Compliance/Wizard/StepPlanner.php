<?php
/**
 * Scan findings → wizard steps.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Wizard;

use AIHazirSite\Core\Compliance\ScoreReport;
use AIHazirSite\Core\Features;

/**
 * Maps every check with missing points to the step that closes it: an action the wizard can
 * apply, or instructions when the fix is outside the plugin (theme, server, other plugins,
 * WordPress settings, modules not released yet). Estimated gain = the points the check is
 * missing in the last scan (its whole weight, marked "at most", when it was not measured).
 *
 * Order: actions by effective gain (a prerequisite counts with the largest gain it unlocks and
 * comes before its dependents); manual steps separately by gain. Nothing to gain → no step.
 */
final class StepPlanner {

	/**
	 * Steps for the current scan and site.
	 *
	 * @param ScoreReport|null $report Last scan (null: none yet).
	 * @param SiteState        $state  Site state.
	 * @return array{actions: list<FixStep>, manual: list<FixStep>}
	 */
	public function plan( ?ScoreReport $report, SiteState $state ): array {
		if ( null === $report || ! $state->on( Features::COMPLIANCE_SCAN ) ) {
			return array(
				'actions' => array( new FixStep( FixStep::SCAN, 'Uyum taramasını başlatın', 'AI uyum taramasını açar ve sitenin şu anki puanını ölçer; sonraki adımlar bu taramaya göre önerilir.', 0.0 ) ),
				'manual'  => array(),
			);
		}

		$actions         = array();
		$manual          = array();
		[ $sd, $sd_max ] = self::gain( $report, 'structured_data' );
		[ $fr, $fr_max ] = self::gain( $report, 'freshness' );

		if ( $sd + $fr > 0 ) {
			if ( ! $state->on( Features::SCHEMA_OUTPUT ) ) {
				$requires = array();
				if ( ! $state->has_profile ) {
					$requires[] = FixStep::PROFILE;
					$actions[]  = new FixStep( FixStep::PROFILE, 'Firma profilini doldurun', 'AI katalogunu açar ve firma adı, sektör, ülke ve kurumsal e-postayı kaydeder. Yapılandırılmış veri bu bilgilerden üretilir.', 0.0 );
				}
				if ( 0 === $state->listings ) {
					$requires[] = FixStep::FIRST_LISTING;
					$actions[]  = new FixStep( FixStep::FIRST_LISTING, 'İlk ilanınızı ekleyin', 'Sattığınız, aradığınız veya tedarik edebildiğiniz bir ürünü ya da hizmeti ekler. AI katalog sayfası ve tarih bilgisi ilanlardan gelir.', 0.0, false, $state->has_profile ? array() : array( FixStep::PROFILE ) );
				}
				$actions[] = new FixStep( FixStep::SCHEMA, 'Schema.org yapılandırılmış veriyi açın', 'Ana sayfaya firma bilgisini, /ai-katalog/ sayfasına ilanları makine okunur biçimde (JSON-LD) ekler; tarih bilgisi (dateModified) da buradan gelir.', $sd + $fr, $sd_max || $fr_max, $requires, array( 'structured_data', 'freshness' ) );
			} else {
				if ( $sd > 0 ) {
					$manual[] = new FixStep( 'manual_structured_data', 'Diğer sayfalarda yapılandırılmış veri', 'AI Hazır Site ana sayfaya ve /ai-katalog/ sayfasına şema ekliyor. Taranan diğer sayfalar (ör. ürün veya hizmet sayfaları) için temanızın ya da SEO eklentinizin şema ayarlarını açın.', $sd, $sd_max, array(), array( 'structured_data' ), true );
				}
				if ( $fr > 0 ) {
					$manual[] = new FixStep( 'manual_freshness', 'Diğer sayfalarda tarih bilgisi', 'Taranan diğer sayfaların yapılandırılmış verisine dateModified tarihi ekleyin (tema veya SEO eklentisi ayarı); ilanlarınızı güncel tutun.', $fr, $fr_max, array(), array( 'freshness' ), true );
				}
			}
		}

		[ $llms, $llms_max ] = self::gain( $report, 'llms_txt' );
		if ( $llms > 0 ) {
			if ( ! $state->on( Features::LLMS_TXT ) && ! $state->physical_llms ) {
				$actions[] = new FixStep( FixStep::LLMS, 'llms.txt dosyasını yayınlayın', 'Firmanızı ve ilanlarınızı AI\'lara sade metinle anlatan /llms.txt adresini ve AI katalog sayfasını açar.', $llms, $llms_max, array(), array( 'llms_txt' ) );
			} elseif ( $state->physical_llms ) {
				$manual[] = new FixStep( 'manual_llms_txt', 'Elle yazılmış llms.txt dosyası', 'Sitenin kök dizininde bir llms.txt dosyası var; sunucu onu gösterdiği için AI Hazır Site dosyaya dokunmaz. Dosyayı "# Firma adı" başlığıyla başlatın ya da kaldırıp AI Hazır Site\'nin llms.txt çıktısını kullanın.', $llms, $llms_max, array(), array( 'llms_txt' ), true );
			} else {
				$manual[] = new FixStep( 'manual_llms_txt', 'llms.txt adresine ulaşılamıyor', 'llms.txt açık ama tarama ona ulaşamadı. Sunucunun var olmayan dosya isteklerini WordPress\'e ilettiğini (Apache .htaccess veya nginx try_files) ve önbelleğin temizlendiğini kontrol edin.', $llms, $llms_max, array(), array( 'llms_txt' ), true );
			}
		}

		[ $bots, $bots_max ] = self::gain( $report, 'bot_access' );
		if ( $bots > 0 ) {
			if ( $state->robots_blocks_ai && $state->robots_fixable && ! $state->physical_robots ) {
				$actions[] = new FixStep( FixStep::BOTS, 'AI botlarına erişim izni verin', 'robots.txt\'ye AI botları için işaretli bir izin bloğu ekler (AI Bot Erişimi ayarı). Mevcut satırlara dokunulmaz; engellemek istediğiniz botları sonradan tek tek seçebilirsiniz.', $bots, $bots_max, array(), array( 'bot_access' ) );
			} elseif ( $state->physical_robots ) {
				$manual[] = new FixStep( 'manual_bot_access', 'Elle yazılmış robots.txt dosyası', 'Sitenin kök dizinindeki robots.txt dosyası AI botlarını engelliyor ve AI Hazır Site bu dosyaya dokunmaz. Dosyaya AI Bot Erişimi sayfasında gösterilen izin bloğunu ekleyin.', $bots, $bots_max, array(), array( 'bot_access' ), true );
			} elseif ( $state->robots_blocks_ai ) {
				$manual[] = new FixStep( 'manual_bot_access', 'AI botları adıyla engelleniyor', 'Başka bir eklenti veya tema robots.txt\'ye AI botlarını adıyla engelleyen satırlar ekliyor; AI Bot Erişimi ayarı bunları kaldıramaz. robots.txt\'yi açıp bu satırları ekleyen eklentinin ayarından kaldırın.', $bots, $bots_max, array(), array( 'bot_access' ), true );
			} elseif ( ! $state->search_visible ) {
				$manual[] = new FixStep( 'manual_bot_access', 'Arama motorları engelleniyor', 'Ayarlar → Okuma\'daki "Arama motorlarının bu siteyi dizine eklemesini engelle" seçeneği açık. Site yayındaysa bu seçeneği kapatın (WordPress ayarıdır; sihirbaz değiştirmez).', $bots, $bots_max, array(), array( 'bot_access' ), true );
			} else {
				$manual[] = new FixStep( 'manual_bot_access', 'AI botları sayfalara erişemiyor', 'Sayfalar AI bot kimliğine hata yanıtı veriyor. Güvenlik eklentinizin veya CDN\'inizin (ör. Cloudflare) bot kurallarında AI botlarına izin verin.', $bots, $bots_max, array(), array( 'bot_access' ), true );
			}
		}

		$help = array(
			'readability'       => array( 'İçerik okunabilirliği', 'Sayfalarda tek H1 başlık, sıralı alt başlıklar, görsellerde alternatif metin ve JavaScript olmadan okunabilen ana içerik kullanın. Bu tema ve içerik işidir; sihirbaz değiştirmez.' ),
			'machine_interface' => array( 'REST API ve MCP', 'WordPress REST API\'sinin bir güvenlik eklentisiyle kapatılmadığından emin olun. AI Hazır Site\'nin REST ve MCP kanalları sonraki sürümlerde (A5, A6) gelecek.' ),
			'advanced'          => array( 'İleri standartlar (A2A)', 'A2A agent kartviziti AI Hazır Site\'nin sonraki bir sürümünde (A12) gelecek; şimdilik yapılacak bir şey yok.' ),
		);
		foreach ( $help as $check => [ $title, $text ] ) {
			[ $gain, $max ] = self::gain( $report, $check );
			if ( $gain > 0 ) {
				$manual[] = new FixStep( 'manual_' . $check, $title, $text, $gain, $max, array(), array( $check ), true );
			}
		}

		return array(
			'actions' => self::order( $actions ),
			'manual'  => self::by_gain( $manual ),
		);
	}

	/**
	 * Missing points of a check and whether that is an upper bound (not measured).
	 *
	 * @param ScoreReport $report Report.
	 * @param string      $check  Check id.
	 * @return array{0: float, 1: bool}
	 */
	private static function gain( ScoreReport $report, string $check ): array {
		$row = $report->result( $check );
		if ( null === $row ) {
			return array( 0.0, false );
		}
		if ( null === $row['ratio'] ) {
			return array( (float) $row['weight'], true );
		}
		return array( round( (float) $row['gain'], 2 ), false );
	}

	/**
	 * Actions by effective gain; prerequisites before their dependents.
	 *
	 * @param FixStep[] $steps Steps.
	 * @return list<FixStep>
	 *
	 * @phpstan-param list<FixStep> $steps
	 */
	private static function order( array $steps ): array {
		$effective = array();
		foreach ( $steps as $step ) {
			$effective[ $step->id ] = $step->gain;
		}
		// Twice is enough for a chain of two prerequisites (profile → first listing → schema).
		for ( $pass = 0; $pass < 2; $pass++ ) {
			foreach ( $steps as $step ) {
				foreach ( $step->requires as $required ) {
					$effective[ $required ] = max( $effective[ $required ] ?? 0.0, $effective[ $step->id ] );
				}
			}
		}

		usort(
			$steps,
			static function ( FixStep $a, FixStep $b ) use ( $effective ): int {
				if ( $effective[ $a->id ] !== $effective[ $b->id ] ) {
					return $effective[ $b->id ] <=> $effective[ $a->id ];
				}
				if ( in_array( $a->id, $b->requires, true ) ) {
					return -1;
				}
				if ( in_array( $b->id, $a->requires, true ) ) {
					return 1;
				}
				return array( $b->gain, $a->id ) <=> array( $a->gain, $b->id );
			}
		);
		return $steps;
	}

	/**
	 * Largest gain first.
	 *
	 * @param FixStep[] $steps Steps.
	 * @return list<FixStep>
	 *
	 * @phpstan-param list<FixStep> $steps
	 */
	private static function by_gain( array $steps ): array {
		usort( $steps, static fn( FixStep $a, FixStep $b ): int => array( $b->gain, $a->id ) <=> array( $a->gain, $b->id ) );
		return $steps;
	}
}
