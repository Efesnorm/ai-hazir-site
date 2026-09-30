<?php
/**
 * The compliance report as HTML and PDF.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Report;

use AIHazirSite\Adapters\Report\ComplianceReportData;
use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\WordPress\Compliance\Admin\CompliancePage;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Collects the records (scans, A0 report of 28 days, feature keys) once and renders them. The HTML
 * shown in the admin screen and the PDF are the same markup, so they cannot disagree. The PDF is
 * made with Dompdf (LGPL-2.1) using the bundled DejaVu Sans font (full Turkish coverage); remote
 * resources are disabled.
 */
final class ComplianceReportView {

	/**
	 * Report data for this site now.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$features = array();
		foreach ( array_keys( Features::defaults() ) as $key ) {
			$features[ $key ] = Features::is_enabled( $key );
		}
		$store = ComplianceModule::store();
		return ComplianceReportData::build(
			$store->first(),
			$store->latest(),
			ReportPage::report( 28 )->rows(),
			$features,
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			gmdate( 'Y-m-d\TH:i:s\Z' )
		);
	}

	/**
	 * Feature labels.
	 *
	 * @return array<string, string>
	 */
	public static function feature_labels(): array {
		return array(
			Features::MEASUREMENT       => __( 'AI bot ve yönlendirme ölçümü (A0)', 'ai-hazir-site' ),
			Features::COMPLIANCE_SCAN   => __( 'AI uyum taraması (U1)', 'ai-hazir-site' ),
			Features::CATALOG           => __( 'AI Katalog: ilanlar ve firma profili (A1)', 'ai-hazir-site' ),
			Features::BOT_ACCESS        => __( 'AI bot erişim ayarları (U2)', 'ai-hazir-site' ),
			Features::SCHEMA_OUTPUT     => __( 'Schema.org yapılandırılmış veri (A2)', 'ai-hazir-site' ),
			Features::LLMS_TXT          => __( 'llms.txt ve AI katalog sayfası (A3)', 'ai-hazir-site' ),
			Features::TEMPLATES         => __( 'Sektör şablonları (A4)', 'ai-hazir-site' ),
			Features::COMPLIANCE_WIZARD => __( 'AI uyum sihirbazı (U3)', 'ai-hazir-site' ),
			Features::REST_API          => __( 'REST API (A5)', 'ai-hazir-site' ),
			Features::ABILITIES         => __( 'Abilities API (A6)', 'ai-hazir-site' ),
			Features::MCP               => __( 'MCP sunucusu (A6)', 'ai-hazir-site' ),
			Features::INQUIRIES         => __( 'Teklif kutusu (A7)', 'ai-hazir-site' ),
			Features::COMPLIANCE_REPORT => __( 'Uyum raporu ve rozet (U4)', 'ai-hazir-site' ),
		);
	}

	/**
	 * Report HTML (everything escaped here). The same markup goes into the PDF.
	 *
	 * @param array<string, mixed> $data Report data.
	 */
	public static function html( array $data ): string {
		$number = static fn( ?float $value ): string => null === $value ? '–' : rtrim( rtrim( number_format( $value, 2, ',', '' ), '0' ), ',' );
		$date   = static fn( ?string $iso ): string => null === $iso || '' === $iso ? '–' : wp_date( 'd.m.Y H:i', (int) strtotime( $iso ) );
		$labels = CompliancePage::labels();
		$latest = is_array( $data['latest'] ?? null ) ? $data['latest'] : null;
		$first  = is_array( $data['first'] ?? null ) ? $data['first'] : null;

		$html  = '<div class="aihs-report">';
		$html .= '<h1>' . esc_html__( 'AI Uyum Raporu', 'ai-hazir-site' ) . '</h1>';
		$html .= '<p>' . esc_html( (string) $data['site'] ) . ' · ' . esc_html__( 'Oluşturulma', 'ai-hazir-site' ) . ': ' . esc_html( $date( (string) $data['generated_at'] ) ) . '</p>';

		if ( null === $latest ) {
			return $html . '<p id="aihs-report-empty">' . esc_html__( 'Henüz uyum taraması yapılmamış. AI Hazır Site → AI Uyum sayfasından tarama yapın.', 'ai-hazir-site' ) . '</p></div>';
		}

		$score = static fn( ?array $s ): string => null === $s || null === $s['score'] ? '–' : (string) $s['score'];
		$html .= '<table class="widefat aihs-report-summary" id="aihs-report-summary"><tbody>'
			. '<tr><th>' . esc_html__( 'Son tarama puanı', 'ai-hazir-site' ) . '</th><td data-value="latest-score">' . esc_html( $score( $latest ) ) . '</td><td>' . esc_html( $date( $latest['scanned_at'] ) ) . '</td></tr>'
			. '<tr><th>' . esc_html__( 'İlk tarama puanı', 'ai-hazir-site' ) . '</th><td data-value="first-score">' . esc_html( $score( $first ) ) . '</td><td>' . esc_html( $date( $first['scanned_at'] ?? null ) ) . '</td></tr>'
			. '<tr><th>' . esc_html__( 'Değişim', 'ai-hazir-site' ) . '</th><td data-value="score-change">' . esc_html( null === $data['score_change'] ? '–' : sprintf( '%+d', (int) $data['score_change'] ) ) . '</td><td>'
			. ( $data['comparable'] ? '' : esc_html__( 'Puanlama kuralları farklı olduğu için karşılaştırılmadı.', 'ai-hazir-site' ) ) . '</td></tr>'
			. '</tbody></table>';

		$html .= '<h2>' . esc_html__( 'Kontrol bazında durum', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-report-checks"><thead><tr>';
		foreach ( array( __( 'Kriter', 'ai-hazir-site' ), __( 'Ağırlık', 'ai-hazir-site' ), __( 'İlk', 'ai-hazir-site' ), __( 'Son', 'ai-hazir-site' ), __( 'Değişim', 'ai-hazir-site' ), __( 'Eksik puan', 'ai-hazir-site' ) ) as $head ) {
			$html .= '<th>' . esc_html( $head ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( (array) $data['checks'] as $check ) {
			$html .= '<tr data-check="' . esc_attr( $check['id'] ) . '"><td>' . esc_html( $labels[ $check['id'] ] ?? $check['id'] ) . '</td>'
				. '<td data-value="weight">' . (int) $check['weight'] . '</td>'
				. '<td data-value="first">' . esc_html( $number( $check['first_points'] ) ) . '</td>'
				. '<td data-value="points">' . esc_html( null === $check['ratio'] ? __( 'ölçülemedi', 'ai-hazir-site' ) : $number( $check['points'] ) ) . '</td>'
				. '<td data-value="change">' . esc_html( null === $check['change'] ? '–' : ( $check['change'] > 0 ? '+' : '' ) . $number( $check['change'] ) ) . '</td>'
				. '<td data-value="gain">' . esc_html( $number( $check['gain'] ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		$sections = ReportPage::section_labels();
		$html    .= '<h2>' . esc_html__( 'Son 28 gün: AI ölçümü', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-report-measurement"><thead><tr><th>'
			. esc_html__( 'Bölüm', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Kaynak / sayfa', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Doğrulanmış', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Doğrulanmamış', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Toplam', 'ai-hazir-site' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $data['measurement'] as $section => $rows ) {
			foreach ( $rows as $row ) {
				$html .= '<tr data-section="' . esc_attr( (string) $section ) . '"><td>' . esc_html( $sections[ $section ] ?? (string) $section ) . '</td><td>' . esc_html( '' === $row['source'] ? $row['path'] : $row['source'] ) . '</td>'
					. '<td data-value="verified">' . (int) $row['verified'] . '</td><td data-value="unverified">' . (int) $row['unverified'] . '</td><td data-value="total">' . (int) $row['total'] . '</td></tr>';
			}
			$html .= '<tr class="aihs-total" data-section-total="' . esc_attr( (string) $section ) . '"><th colspan="4">' . esc_html( ( $sections[ $section ] ?? (string) $section ) . ' – ' . ( Report::SECTION_PAGES === $section ? __( 'ilk 10 sayfa toplamı', 'ai-hazir-site' ) : __( 'toplam', 'ai-hazir-site' ) ) ) . '</th><td data-value="total">' . (int) ( $data['measurement_totals'][ $section ] ?? 0 ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		$features = self::feature_labels();
		$html    .= '<h2>' . esc_html__( 'Açık yetenekler', 'ai-hazir-site' ) . '</h2><ul id="aihs-report-features">';
		foreach ( (array) $data['features'] as $key ) {
			$html .= '<li data-feature="' . esc_attr( (string) $key ) . '">' . esc_html( $features[ $key ] ?? (string) $key ) . '</li>';
		}
		return $html . '</ul></div>';
	}

	/**
	 * The PDF bytes.
	 *
	 * @param array<string, mixed> $data Report data.
	 */
	public static function pdf( array $data ): string {
		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setDefaultFont( 'DejaVu Sans' );
		$options->setTempDir( get_temp_dir() );
		$options->setFontCache( get_temp_dir() );

		$css = 'body{font-family:"DejaVu Sans",sans-serif;font-size:10pt;color:#1d2327}h1{font-size:18pt}h2{font-size:13pt;margin-top:18pt}'
			. 'table{width:100%;border-collapse:collapse;margin:6pt 0}th,td{border:1px solid #c3c4c7;padding:3pt 5pt;text-align:left}th{background:#f0f0f1}';

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( '<!doctype html><html lang="tr"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . self::html( $data ) . '</body></html>', 'UTF-8' );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();
		return (string) $dompdf->output();
	}
}
