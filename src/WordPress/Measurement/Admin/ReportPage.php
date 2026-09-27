<?php
/**
 * Tools → AI Ölçüm admin page.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Measurement\Admin;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\CsvExport;
use AIHazirSite\Core\Measurement\Report;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;
use AIHazirSite\WordPress\Platform\WpClock;

/**
 * Shows the last 7 / 28 days, exports CSV and turns measurement on or off.
 *
 * @phpstan-import-type ReportRow from Report
 */
final class ReportPage {

	public const SLUG          = 'aihs-measurement';
	public const CAPABILITY    = 'manage_options';
	public const EXPORT_ACTION = 'aihs_export_hits';
	public const TOGGLE_ACTION = 'aihs_toggle_measurement';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'toggle' ) );
	}

	/**
	 * Adds the page under Tools.
	 */
	public function add_page(): void {
		add_management_page(
			__( 'AI Ölçüm', 'ai-hazir-site' ),
			__( 'AI Ölçüm', 'ai-hazir-site' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view parameter, whitelisted.
		$days    = Report::sanitize_period( isset( $_GET['days'] ) ? sanitize_text_field( wp_unslash( $_GET['days'] ) ) : 0 );
		$enabled = Features::is_enabled( Features::MEASUREMENT );
		$rows    = self::report( $days )->rows();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI Ölçüm', 'ai-hazir-site' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ayarlar kaydedildi.', 'ai-hazir-site' ) . '</p></div>';
		}

		echo '<div class="notice notice-warning inline"><p>'
			. esc_html__( 'Sayfa önbelleği kullanan sitelerde önbellekten sunulan istekler sayılamaz; sonuçlar alt sınırdır.', 'ai-hazir-site' )
			. '</p></div>';

		if ( ! $enabled ) {
			echo '<div class="notice notice-info inline"><p>'
				. esc_html__( 'Ölçüm kapalı: yeni ziyaretler sayılmıyor.', 'ai-hazir-site' )
				. '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( Report::PERIODS as $period ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::url( array( 'days' => $period ) ) ),
				$period === $days ? ' nav-tab-active' : '',
				/* translators: %d: number of days. */
				esc_html( sprintf( __( 'Son %d gün', 'ai-hazir-site' ), $period ) )
			);
		}
		echo '</nav>';

		echo self::render_tables( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_tables().

		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::EXPORT_ACTION, 'days' => $days ), admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			esc_html__( 'CSV olarak indir', 'ai-hazir-site' )
		);

		echo '<h2>' . esc_html__( 'Ayarlar', 'ai-hazir-site' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::TOGGLE_ACTION ) . '">';
		wp_nonce_field( self::TOGGLE_ACTION );
		echo '<label><input type="checkbox" name="enabled" value="1"' . checked( $enabled, true, false ) . '> '
			. esc_html__( 'AI bot ve yönlendirme ölçümü açık', 'ai-hazir-site' ) . '</label>';
		submit_button( __( 'Kaydet', 'ai-hazir-site' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The report tables. Every text is escaped here.
	 *
	 * @param array[] $rows Report rows.
	 *
	 * @phpstan-param list<ReportRow> $rows
	 */
	public static function render_tables( array $rows ): string {
		$html = '';

		$html .= self::table(
			'bots',
			__( 'Bota göre ziyaret', 'ai-hazir-site' ),
			array( __( 'Bot', 'ai-hazir-site' ), __( 'Doğrulanmış', 'ai-hazir-site' ), __( 'Doğrulanmamış', 'ai-hazir-site' ), __( 'Toplam', 'ai-hazir-site' ) ),
			array_map( static fn( array $r ): array => array( $r['source'], $r['verified'], $r['unverified'], $r['total'] ), Report::section( $rows, Report::SECTION_BOTS ) )
		);

		$html .= self::table(
			'pages',
			/* translators: %d: number of pages. */
			sprintf( __( 'Botların en çok okuduğu %d sayfa', 'ai-hazir-site' ), Report::TOP_PAGES ),
			array( __( 'Sayfa', 'ai-hazir-site' ), __( 'Doğrulanmış', 'ai-hazir-site' ), __( 'Doğrulanmamış', 'ai-hazir-site' ), __( 'Toplam', 'ai-hazir-site' ) ),
			array_map( static fn( array $r ): array => array( $r['path'], $r['verified'], $r['unverified'], $r['total'] ), Report::section( $rows, Report::SECTION_PAGES ) )
		);

		$html .= self::table(
			'referrals',
			__( 'AI platformlarından gelen insan ziyaretleri', 'ai-hazir-site' ),
			array( __( 'Kaynak', 'ai-hazir-site' ), __( 'Ziyaret', 'ai-hazir-site' ) ),
			array_map( static fn( array $r ): array => array( $r['source'], $r['total'] ), Report::section( $rows, Report::SECTION_REFERRALS ) )
		);

		$html .= self::table(
			'ai-files',
			__( 'Botların AI dosyalarını okuması (llms.txt, AI katalog)', 'ai-hazir-site' ),
			array( __( 'Dosya', 'ai-hazir-site' ), __( 'Doğrulanmış', 'ai-hazir-site' ), __( 'Doğrulanmamış', 'ai-hazir-site' ), __( 'Toplam', 'ai-hazir-site' ) ),
			array_map( static fn( array $r ): array => array( $r['path'], $r['verified'], $r['unverified'], $r['total'] ), Report::section( $rows, Report::SECTION_AI_FILES ) )
		);

		$html .= self::table(
			'mcp',
			__( 'MCP çağrıları (AI agentların araç kullanımı)', 'ai-hazir-site' ),
			array( __( 'Araç', 'ai-hazir-site' ), __( 'Çağrı', 'ai-hazir-site' ) ),
			array_map( static fn( array $r ): array => array( $r['source'], $r['total'] ), Report::section( $rows, Report::SECTION_MCP ) )
		);

		return $html;
	}

	/**
	 * `admin_post_aihs_export_hits`: streams the CSV.
	 */
	public function export(): void {
		$this->authorize( self::EXPORT_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in authorize().
		$days = Report::sanitize_period( isset( $_GET['days'] ) ? sanitize_text_field( wp_unslash( $_GET['days'] ) ) : 0 );
		$csv  = CsvExport::to_csv( self::report( $days )->rows(), self::csv_headers(), self::section_labels() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ai-olcum-' . $days . '-gun-' . current_time( 'Y-m-d' ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, not HTML.
		exit;
	}

	/**
	 * `admin_post_aihs_toggle_measurement`: turns measurement on or off.
	 */
	public function toggle(): void {
		$this->authorize( self::TOGGLE_ACTION );

		Features::set( Features::MEASUREMENT, ! empty( $_POST['enabled'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in authorize().

		wp_safe_redirect( self::url( array( 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * Report with the WordPress adapters.
	 *
	 * @param int $days Days.
	 */
	public static function report( int $days ): Report {
		return new Report( new WpdbHitRepository(), new WpClock(), $days );
	}

	/**
	 * Translated CSV column headers.
	 *
	 * @return list<string>
	 */
	public static function csv_headers(): array {
		return array(
			__( 'Bölüm', 'ai-hazir-site' ),
			__( 'Kaynak', 'ai-hazir-site' ),
			__( 'Sayfa', 'ai-hazir-site' ),
			__( 'Doğrulanmış', 'ai-hazir-site' ),
			__( 'Doğrulanmamış', 'ai-hazir-site' ),
			__( 'Toplam', 'ai-hazir-site' ),
		);
	}

	/**
	 * Translated section labels.
	 *
	 * @return array<string, string>
	 */
	public static function section_labels(): array {
		return array(
			Report::SECTION_BOTS      => __( 'Bot', 'ai-hazir-site' ),
			Report::SECTION_PAGES     => __( 'Sayfa', 'ai-hazir-site' ),
			Report::SECTION_REFERRALS => __( 'Yönlendirme', 'ai-hazir-site' ),
			Report::SECTION_AI_FILES  => __( 'AI dosyası', 'ai-hazir-site' ),
			Report::SECTION_MCP       => __( 'MCP', 'ai-hazir-site' ),
		);
	}

	/**
	 * Capability and nonce check; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	public function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * One table.
	 *
	 * @param string   $id      Table id suffix.
	 * @param string   $title   Heading.
	 * @param string[] $headers Column headers.
	 * @param array[]  $cells   Body cells.
	 *
	 * @phpstan-param list<string> $headers
	 * @phpstan-param list<list<string|int>> $cells
	 */
	private static function table( string $id, string $title, array $headers, array $cells ): string {
		$html  = '<h2>' . esc_html( $title ) . '</h2>';
		$html .= '<table class="widefat striped" id="aihs-' . esc_attr( $id ) . '"><thead><tr>';
		foreach ( $headers as $header ) {
			$html .= '<th scope="col">' . esc_html( $header ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		if ( array() === $cells ) {
			$html .= '<tr><td colspan="' . count( $headers ) . '">' . esc_html__( 'Bu dönemde kayıt yok.', 'ai-hazir-site' ) . '</td></tr>';
		}
		foreach ( $cells as $row ) {
			$html .= '<tr>';
			foreach ( $row as $cell ) {
				$html .= '<td>' . esc_html( (string) $cell ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}
}
