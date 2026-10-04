<?php
/**
 * AI Hazır Site → Ağ Raporu (1.24.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Network;

use AIHazirSite\Core\Network\NetworkLink;
use AIHazirSite\Core\Network\NetworkReport;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\Core\Network\NetworkStats;
use AIHazirSite\WordPress\Admin\AdminMenu;

/**
 * Member: create / revoke the report key (shown once). Mother: the members' keys, the summary, the network referral
 * matrix, period, refresh and CSV. Every form has a nonce and needs manage_options.
 */
final class NetworkReportPage {

	public const SLUG       = 'aihs-network-report';
	public const CREATE     = 'aihs_network_report_key';
	public const REVOKE     = 'aihs_network_report_revoke';
	public const CREDS      = 'aihs_network_report_creds';
	public const REFRESH    = 'aihs_network_report_refresh';
	public const CSV        = 'aihs_network_report_csv';
	public const CAPABILITY = 'manage_options';
	public const NEW_KEY    = 'aihs_network_report_new_';
	public const STALE      = 2 * DAY_IN_SECONDS;

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		foreach ( array( self::CREATE, self::REVOKE, self::CREDS, self::REFRESH ) as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'on_post' ) );
		}
		add_action( 'admin_post_' . self::CSV, array( self::class, 'on_csv' ) );
	}

	/**
	 * Adds the screen.
	 */
	public function add_page(): void {
		AdminMenu::add( __( 'Ağ Raporu', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			NetworkReport::OK           => __( 'güncel', 'ai-hazir-site' ),
			NetworkReport::SELF         => __( 'bu site', 'ai-hazir-site' ),
			NetworkReport::NO_KEY       => __( 'anahtar girilmedi', 'ai-hazir-site' ),
			NetworkReport::UNAUTHORIZED => __( 'anahtar geçersiz veya iptal edilmiş', 'ai-hazir-site' ),
			NetworkReport::LIMITED      => __( 'hız sınırı; sonra yeniden denenecek', 'ai-hazir-site' ),
			NetworkReport::UNREACHABLE  => __( 'erişilemedi', 'ai-hazir-site' ),
		);
	}

	/**
	 * Summary column key → header.
	 *
	 * @return array<string, string>
	 */
	public static function columns(): array {
		return array(
			'site'       => __( 'Site', 'ai-hazir-site' ),
			'version'    => __( 'Sürüm', 'ai-hazir-site' ),
			'score'      => __( 'Uyum puanı', 'ai-hazir-site' ),
			'bots'       => __( 'AI bot ziyareti', 'ai-hazir-site' ),
			'verified'   => __( 'Doğrulanmış', 'ai-hazir-site' ),
			'referrals'  => __( 'AI yönlendirmesi', 'ai-hazir-site' ),
			'network'    => __( 'Ağ yönlendirmesi (gelen)', 'ai-hazir-site' ),
			'mcp'        => __( 'MCP çağrısı', 'ai-hazir-site' ),
			'inquiries'  => __( 'Talep', 'ai-hazir-site' ),
			'listings'   => __( 'Geçerli ilan', 'ai-hazir-site' ),
			'status'     => __( 'Durum', 'ai-hazir-site' ),
			'fetched_at' => __( 'Son okuma', 'ai-hazir-site' ),
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only period choice.
		$days = NetworkStats::period( isset( $_GET['days'] ) ? sanitize_key( wp_unslash( (string) $_GET['days'] ) ) : 28 );
		echo '<div class="wrap">' . self::render_html( $days ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
	}

	/**
	 * Screen HTML (everything escaped here).
	 *
	 * @param int $days Period.
	 */
	public static function render_html( int $days = 28 ): string {
		$role = NetworkModule::settings()->role;
		$html = '<h1>' . esc_html__( 'Ağ Raporu', 'ai-hazir-site' ) . '</h1>'
			. '<p>' . esc_html__( 'Anne site, ağdaki her sitenin toplam sayılarını tek ekranda gösterir: AI bot ziyaretleri, AI ve ağ yönlendirmeleri, MCP çağrıları, talep ve ilan sayısı, uyum puanı. Kişisel veri, talep metni veya ham ölçüm satırı paylaşılmaz. Erişim, üyenin oluşturduğu WordPress uygulama parolasıyladır.', 'ai-hazir-site' ) . '</p>';

		if ( NetworkSettings::ROLE_MEMBER === $role ) {
			return $html . self::member_html();
		}
		if ( NetworkSettings::ROLE_MOTHER === $role ) {
			return $html . self::mother_html( $days );
		}
		return $html . '<p>' . esc_html__( 'Bu site bir portal ağında değil. Önce Portal Ağı ekranında rolünü seçin.', 'ai-hazir-site' ) . ' <a href="' . esc_url( AdminMenu::url( NetworkPage::SLUG ) ) . '">' . esc_html__( 'Portal Ağı', 'ai-hazir-site' ) . '</a></p>';
	}

	/**
	 * Member part: the report key.
	 */
	private static function member_html(): string {
		$mother = NetworkModule::settings()->mother;
		$html   = '<h2>' . esc_html__( 'Anne site için rapor anahtarı', 'ai-hazir-site' ) . '</h2>';
		if ( ! wp_is_application_passwords_available() ) {
			$html .= '<div class="notice notice-error inline" id="aihs-report-app-passwords-off"><p>' . esc_html__( 'Bu sitede WordPress uygulama parolaları kapalı. Wordfence kullanıyorsanız: Wordfence → Login Security → Settings → "Disable WordPress application passwords" seçeneğini kaldırın; başka bir güvenlik eklentisi de kapatmış olabilir.', 'ai-hazir-site' ) . '</p></div>';
		}

		$error = get_transient( self::NEW_KEY . 'error_' . get_current_user_id() );
		delete_transient( self::NEW_KEY . 'error_' . get_current_user_id() );
		if ( is_string( $error ) && '' !== $error ) {
			$html .= '<div class="notice notice-error inline" id="aihs-report-key-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$new = get_transient( self::NEW_KEY . get_current_user_id() );
		delete_transient( self::NEW_KEY . get_current_user_id() );
		if ( is_array( $new ) ) {
			$test  = array(
				'ok'      => __( 'Deneme başarılı: anahtar bu sitede çalışıyor.', 'ai-hazir-site' ),
				'blocked' => __( 'Deneme başarısız (401/403): sunucu "Authorization" başlığını WordPress\'e iletmiyor olabilir. Barındırma firmanıza şunu iletin: "Apache/FastCGI, HTTP Authorization başlığını PHP\'ye geçirsin (ör. .htaccess: SetEnvIf Authorization (.*) HTTP_AUTHORIZATION=$1)."', 'ai-hazir-site' ),
				'unknown' => __( 'Site kendini çağıramadığı için deneme yapılamadı; anne sitede "Şimdi yenile" ile görülecek.', 'ai-hazir-site' ),
			);
			$html .= '<div class="notice notice-success inline" id="aihs-report-new-key"><p><strong>' . esc_html__( 'Anahtar oluşturuldu. Parola yalnızca şimdi gösterilir; anne sitenin Ağ Raporu ekranına girin:', 'ai-hazir-site' ) . '</strong></p>'
				. '<p>' . esc_html__( 'Kullanıcı adı', 'ai-hazir-site' ) . ': <code>' . esc_html( (string) ( $new['login'] ?? '' ) ) . '</code><br>'
				. esc_html__( 'Uygulama parolası', 'ai-hazir-site' ) . ': <code>' . esc_html( (string) ( $new['password'] ?? '' ) ) . '</code><br>'
				. esc_html__( 'Anne site', 'ai-hazir-site' ) . ': ' . esc_html( $mother ) . '</p>'
				. '<p>' . esc_html( $test[ (string) ( $new['test'] ?? 'unknown' ) ] ?? $test['unknown'] ) . '</p></div>';
		}

		$key = NetworkReportModule::key();
		if ( null === $key ) {
			$html .= '<p>' . esc_html__( 'Henüz anahtar yok. Anahtar, yalnızca bu raporu okuyabilen ve yönetim paneline giremeyen ayrı bir kullanıcıya bağlı bir uygulama parolasıdır.', 'ai-hazir-site' ) . '</p>'
				. self::button( self::CREATE, __( 'Anne site için rapor anahtarı oluştur', 'ai-hazir-site' ), 'primary' );
		} else {
			$html .= '<p id="aihs-report-key">' . esc_html(
				sprintf(
					/* translators: %s: date. */
					__( 'Anahtar oluşturuldu: %s. Anne site bu anahtarla günde bir toplamları okur.', 'ai-hazir-site' ),
					wp_date( 'd.m.Y H:i', $key['created_at'] )
				)
			) . '</p>'
				. self::button( self::CREATE, __( 'Yeni anahtar oluştur (eskisi iptal edilir)', 'ai-hazir-site' ), 'secondary' )
				. self::button( self::REVOKE, __( 'Anahtarı iptal et', 'ai-hazir-site' ), 'delete' );
		}
		return $html;
	}

	/**
	 * Mother part: keys, summary, matrix.
	 *
	 * @param int $days Period.
	 */
	private static function mother_html( int $days ): string {
		$sites  = NetworkReportModule::sites( $days );
		$labels = self::status_labels();
		$html   = '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label for="aihs-report-days">' . esc_html__( 'Dönem', 'ai-hazir-site' ) . '</label> <select id="aihs-report-days" name="days">';
		foreach ( NetworkStats::PERIODS as $period ) {
			/* translators: %d: number of days. */
			$html .= '<option value="' . (int) $period . '"' . selected( $days, $period, false ) . '>' . esc_html( sprintf( __( 'Son %d gün', 'ai-hazir-site' ), $period ) ) . '</option>';
		}
		$html .= '</select> ' . get_submit_button( __( 'Göster', 'ai-hazir-site' ), 'secondary', '', false ) . '</form>';

		// Summary.
		$html .= '<h2>' . esc_html__( 'Özet', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-network-report-summary"><thead><tr>';
		foreach ( self::columns() as $header ) {
			$html .= '<th>' . esc_html( $header ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( NetworkReport::summary( $sites ) as $row ) {
			$total = '' === $row['site'];
			$stale = ! $total && NetworkReport::SELF !== $row['status'] && $row['fetched_at'] > 0 && time() - $row['fetched_at'] > self::STALE;
			$html .= '<tr' . ( $total ? ' class="aihs-total"' : '' ) . '>';
			foreach ( array_keys( self::columns() ) as $key ) {
				$value = match ( $key ) {
					'site'       => $total ? __( 'Ağ toplamı', 'ai-hazir-site' ) : $row['site'],
					'status'     => $total ? '' : ( $labels[ $row['status'] ] ?? $row['status'] ) . ( $stale ? ' ' . __( '(eski veri)', 'ai-hazir-site' ) : '' ),
					'fetched_at' => $total || 0 === $row['fetched_at'] ? '–' : wp_date( 'd.m.Y H:i', $row['fetched_at'] ),
					'score'      => null === $row['score'] ? '–' : (string) $row['score'],
					default      => (string) $row[ $key ],
				};
				$html .= $total ? '<th>' . esc_html( (string) $value ) . '</th>' : '<td>' . esc_html( (string) $value ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		// Network referral matrix.
		[ $hosts, $cells ] = NetworkReport::matrix( $sites );
		$html             .= '<h2>' . esc_html__( 'Ağ yönlendirmeleri: kim kime ziyaretçi gönderdi', 'ai-hazir-site' ) . '</h2><p class="description">' . esc_html__( 'Satır: ziyaretçiyi alan site. Sütun: gönderen site. Üyelerde "Ağ yönlendirmeleri" anahtarı açık olmalıdır.', 'ai-hazir-site' ) . '</p>'
			. '<table class="widefat striped" id="aihs-network-report-matrix"><thead><tr><th>' . esc_html__( 'Alan ↓ / Gönderen →', 'ai-hazir-site' ) . '</th>';
		foreach ( $hosts as $host ) {
			$html .= '<th>' . esc_html( $host ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $hosts as $to ) {
			$html .= '<tr><th>' . esc_html( $to ) . '</th>';
			foreach ( $hosts as $from ) {
				$html .= '<td>' . ( $from === $to ? '–' : esc_html( (string) ( $cells[ $to ][ $from ] ?? 0 ) ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		$html .= self::button( self::REFRESH, __( 'Şimdi yenile', 'ai-hazir-site' ), 'secondary', array( 'days' => (string) $days ) )
			. '<p><a class="button" id="aihs-network-report-csv" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => self::CSV, 'days' => $days ), admin_url( 'admin-post.php' ) ), self::CSV ) ) . '">' . esc_html__( 'CSV indir', 'ai-hazir-site' ) . '</a></p>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// Members' keys.
		$creds = NetworkReportModule::credentials();
		$html .= '<h2>' . esc_html__( 'Üyelerin rapor anahtarları', 'ai-hazir-site' ) . '</h2><p class="description">' . esc_html__( 'Her üyenin Ağ Raporu ekranında "Anne site için rapor anahtarı oluştur"a basın; çıkan kullanıcı adını ve parolayı buraya girin. Parolalar şifreli saklanır; boş bırakılan parola değişmez, kullanıcı adı silinirse anahtar kaldırılır.', 'ai-hazir-site' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-network-report-creds"><input type="hidden" name="action" value="' . esc_attr( self::CREDS ) . '">' . wp_nonce_field( self::CREDS, '_wpnonce', true, false )
			. '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Üye', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Kullanıcı adı', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Uygulama parolası', 'ai-hazir-site' ) . '</th></tr></thead><tbody>';
		foreach ( NetworkModule::view()->siblings() as $i => $site ) {
			$has   = isset( $creds[ $site['url'] ] );
			$html .= '<tr><td>' . esc_html( ( '' !== $site['name'] ? $site['name'] . ' – ' : '' ) . NetworkLink::host( $site['url'] ) ) . '<input type="hidden" name="url[' . (int) $i . ']" value="' . esc_attr( $site['url'] ) . '"></td>'
				. '<td><input type="text" name="user[' . (int) $i . ']" value="' . esc_attr( $has ? $creds[ $site['url'] ]['user'] : '' ) . '" autocomplete="off"></td>'
				. '<td><input type="password" name="password[' . (int) $i . ']" value="" autocomplete="new-password" placeholder="' . esc_attr( $has ? __( 'kayıtlı (değiştirmek için yazın)', 'ai-hazir-site' ) : '' ) . '"></td></tr>';
		}
		return $html . '</tbody></table>' . get_submit_button( __( 'Anahtarları kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * A one-button form.
	 *
	 * @param string                $action Action.
	 * @param string                $label  Label.
	 * @param string                $type   Button type.
	 * @param array<string, string> $fields Hidden fields.
	 */
	private static function button( string $action, string $label, string $type, array $fields = array() ): string {
		$hidden = '';
		foreach ( $fields as $name => $value ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">' . $hidden . wp_nonce_field( $action, '_wpnonce', true, false ) . get_submit_button( $label, $type, '', false ) . '</form>';
	}

	/**
	 * Handles a posted action; returns the redirect URL.
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $input  Posted data (unslashed).
	 */
	public static function handle( string $action, array $input ): string {
		self::authorize( $action );
		$args = array();
		switch ( $action ) {
			case self::CREATE:
				$created = NetworkReportModule::create_key();
				if ( is_wp_error( $created ) ) {
					$args['error'] = 1;
					set_transient( self::NEW_KEY . 'error_' . get_current_user_id(), $created->get_error_message(), 300 );
					break;
				}
				set_transient(
					self::NEW_KEY . get_current_user_id(),
					array(
						'login'    => $created[0],
						'password' => $created[1],
						'test'     => NetworkReportModule::self_test( $created[0], $created[1] ),
					),
					300
				);
				break;
			case self::REVOKE:
				NetworkReportModule::revoke_key();
				break;
			case self::CREDS:
				$urls      = is_array( $input['url'] ?? null ) ? $input['url'] : array();
				$users     = is_array( $input['user'] ?? null ) ? $input['user'] : array();
				$passwords = is_array( $input['password'] ?? null ) ? $input['password'] : array();
				foreach ( $urls as $i => $url ) {
					NetworkReportModule::save_credentials(
						esc_url_raw( (string) $url, array( 'https' ) ),
						sanitize_user( (string) ( $users[ $i ] ?? '' ), true ),
						// Application passwords are 24 letters and digits, shown in groups of four (spaces are ignored).
						(string) preg_replace( '/[^A-Za-z0-9 ]/', '', (string) ( $passwords[ $i ] ?? '' ) )
					);
				}
				NetworkReportModule::fetch_all();
				break;
			case self::REFRESH:
				NetworkReportModule::fetch_all();
				$args['days'] = NetworkStats::period( $input['days'] ?? 28 );
				break;
		}
		return AdminMenu::url( self::SLUG, $args + array( 'done' => 1 ) );
	}

	/**
	 * `admin_post_*` for the forms.
	 */
	public static function on_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$input  = wp_unslash( $_POST );
		$action = sanitize_key( (string) ( $input['action'] ?? '' ) );
		wp_safe_redirect( self::handle( $action, $input ) );
		exit;
	}

	/**
	 * The CSV (period from the link).
	 *
	 * @param int $days Period.
	 */
	public static function csv( int $days ): string {
		return NetworkReport::csv( NetworkReportModule::sites( $days ), self::columns(), self::status_labels(), __( 'Ağ toplamı', 'ai-hazir-site' ) );
	}

	/**
	 * `admin_post_aihs_network_report_csv`: streams the CSV.
	 */
	public static function on_csv(): void {
		self::authorize( self::CSV );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in authorize().
		$days = NetworkStats::period( isset( $_GET['days'] ) ? sanitize_key( wp_unslash( (string) $_GET['days'] ) ) : 28 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ag-raporu-' . gmdate( 'Y-m-d' ) . '-' . $days . 'gun.csv"' );
		echo self::csv( $days ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells escaped against formulas.
		exit;
	}

	/**
	 * Capability and nonce; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	private static function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( $action );
	}
}
