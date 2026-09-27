<?php
/**
 * AI Katalog → Teklif Kutusu.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Inquiry\Admin;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Inquiry\Inquiry;
use AIHazirSite\Core\Inquiry\InquirySettings;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Inquiry\InquiryChannels;
use AIHazirSite\WordPress\Inquiry\InquiryModule;
use AIHazirSite\WordPress\Inquiry\WpAuditRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Platform\WpSettings;

/**
 * The only place where an inquiry's status is changed (approve, reject, quarantine, back to new),
 * always by a person (manage_options + nonce). Also: switching the box on (after a warning) or off,
 * deleting an inquiry with its contact data, the settings and the KVKK notice placeholder.
 */
final class InquiryAdmin {

	public const SLUG       = 'aihs-inquiries';
	public const CAPABILITY = 'manage_options';
	public const ENABLE     = 'aihs_inquiry_enable';
	public const DISABLE    = 'aihs_inquiry_disable';
	public const STATUS     = 'aihs_inquiry_status';
	public const DELETE     = 'aihs_inquiry_delete';
	public const SETTINGS   = 'aihs_inquiry_settings';

	/**
	 * Registers the page and handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		foreach ( array( self::ENABLE, self::DISABLE, self::STATUS, self::DELETE, self::SETTINGS ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'on_' . substr( $action, strlen( 'aihs_inquiry_' ) ) ) );
		}
	}

	/**
	 * Under AI Katalog when the catalog menu exists, else under Tools.
	 */
	public function add_page(): void {
		$title = __( 'Teklif Kutusu', 'ai-hazir-site' );
		if ( Features::is_enabled( Features::CATALOG ) ) {
			add_submenu_page( CatalogAdmin::SLUGS['offer'], $title, $title, self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
			return;
		}
		add_management_page( $title, $title, self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			Inquiry::STATUS_NEW        => __( 'Yeni', 'ai-hazir-site' ),
			Inquiry::STATUS_APPROVED   => __( 'Onaylandı', 'ai-hazir-site' ),
			Inquiry::STATUS_REJECTED   => __( 'Reddedildi', 'ai-hazir-site' ),
			Inquiry::STATUS_QUARANTINE => __( 'Karantina', 'ai-hazir-site' ),
		);
	}

	/**
	 * Kind labels.
	 *
	 * @return array<string, string>
	 */
	public static function kind_labels(): array {
		return array(
			Inquiry::KIND_QUOTE_REQUEST => __( 'Teklif isteği', 'ai-hazir-site' ),
			Inquiry::KIND_OFFER         => __( 'Teklif', 'ai-hazir-site' ),
			Inquiry::KIND_REFERRAL      => __( 'Yönlendirme talebi', 'ai-hazir-site' ),
		);
	}

	/**
	 * Source labels.
	 *
	 * @return array<string, string>
	 */
	public static function source_labels(): array {
		return array(
			Inquiry::SOURCE_AI      => __( 'AI agent', 'ai-hazir-site' ),
			Inquiry::SOURCE_HUMAN   => __( 'İnsan', 'ai-hazir-site' ),
			Inquiry::SOURCE_UNKNOWN => __( 'Bilinmiyor', 'ai-hazir-site' ),
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		echo '<div class="wrap">' . self::render_html( $status, $source ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param string $status Status filter.
	 * @param string $source Source filter.
	 */
	public static function render_html( string $status = '', string $source = '' ): string {
		$html = '<h1>' . esc_html__( 'Teklif Kutusu', 'ai-hazir-site' ) . '</h1>';
		if ( ! Features::is_enabled( Features::INQUIRIES ) ) {
			return $html . self::enable_form();
		}

		$status = isset( self::status_labels()[ $status ] ) ? $status : '';
		$source = isset( self::source_labels()[ $source ] ) ? $source : '';
		$html  .= '<p>' . esc_html__( 'AI agentların ve ziyaretçilerin bıraktığı talepler. Hiçbir talep otomatik onaylanmaz ve talep sahibine otomatik yanıt gitmez; aşağıdan inceleyip kendiniz dönün.', 'ai-hazir-site' ) . '</p>';
		if ( InquiryChannels::referral_only() ) {
			$html .= '<div class="notice notice-info inline"><p>' . esc_html__( 'Profil şablonunuz ücret bilgisine izin vermediği için yalnızca yönlendirme talepleri alınıyor; ücret soran talepler kabul edilmiyor.', 'ai-hazir-site' ) . '</p></div>';
		}

		$filters = array( '' => __( 'Tümü', 'ai-hazir-site' ) ) + self::status_labels();
		$links   = array();
		foreach ( $filters as $key => $label ) {
			$links[] = $key === $status ? '<strong>' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( self::url( '' === $key ? array() : array( 'status' => $key ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$html .= '<p class="subsubsub">' . implode( ' | ', $links ) . '</p><br class="clear">';

		$inquiries = ( new WpInquiryRepository() )->list_inquiries( $status, $source, 100 );
		$html     .= '<table class="widefat striped" id="aihs-inquiries"><thead><tr>';
		foreach ( array( __( 'Tarih', 'ai-hazir-site' ), __( 'Tür / kaynak', 'ai-hazir-site' ), __( 'İlan', 'ai-hazir-site' ), __( 'Talep', 'ai-hazir-site' ), __( 'İletişim', 'ai-hazir-site' ), __( 'Spam', 'ai-hazir-site' ), __( 'Durum', 'ai-hazir-site' ), __( 'İşlem', 'ai-hazir-site' ) ) as $head ) {
			$html .= '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		if ( array() === $inquiries ) {
			$html .= '<tr><td colspan="8">' . esc_html__( 'Talep yok.', 'ai-hazir-site' ) . '</td></tr>';
		}
		foreach ( $inquiries as $inquiry ) {
			$html .= self::row( $inquiry );
		}
		$html .= '</tbody></table>';

		return $html . self::settings_form() . self::kvkk() . self::audit() . self::disable_form();
	}

	/**
	 * One inquiry row.
	 *
	 * @param Inquiry $inquiry Inquiry.
	 */
	private static function row( Inquiry $inquiry ): string {
		$listing = null === $inquiry->listing_id ? null : ( new WpListingRepository() )->find( $inquiry->listing_id );
		$contact = array_filter( $inquiry->contact->to_array() );
		$actions = '';
		foreach ( self::status_labels() as $target => $label ) {
			if ( $target === $inquiry->status ) {
				continue;
			}
			$actions .= self::button( self::STATUS, (int) $inquiry->id, array( 'status' => $target ), self::action_label( $target ) );
		}
		$actions .= self::button( self::DELETE, (int) $inquiry->id, array(), __( 'Sil', 'ai-hazir-site' ), __( 'Talep ve iletişim bilgileri kalıcı olarak silinecek. Emin misiniz?', 'ai-hazir-site' ) );

		return '<tr id="aihs-inquiry-' . (int) $inquiry->id . '">'
			. '<td>' . esc_html( wp_date( 'Y-m-d H:i', (int) strtotime( $inquiry->created_at ) ) ) . '</td>'
			. '<td>' . esc_html( ( self::kind_labels()[ $inquiry->kind ] ?? $inquiry->kind ) . ' / ' . ( self::source_labels()[ $inquiry->source ] ?? $inquiry->source ) . ' (' . $inquiry->channel . ')' ) . '</td>'
			. '<td>' . esc_html( null === $listing ? '–' : $listing->title ) . '</td>'
			. '<td>' . ( '' === $inquiry->subject ? '' : '<strong>' . esc_html( $inquiry->subject ) . '</strong><br>' ) . nl2br( esc_html( $inquiry->message ) ) . '</td>'
			. '<td>' . ( array() === $contact ? esc_html__( '(çözülemedi)', 'ai-hazir-site' ) : implode( '<br>', array_map( 'esc_html', $contact ) ) ) . '</td>'
			. '<td>' . (int) $inquiry->spam_score . ( array() === $inquiry->spam_reasons ? '' : '<br><small>' . esc_html( implode( ' ', $inquiry->spam_reasons ) ) . '</small>' ) . '</td>'
			. '<td class="aihs-status">' . esc_html( self::status_labels()[ $inquiry->status ] ?? $inquiry->status ) . '</td>'
			. '<td>' . $actions . '</td></tr>';
	}

	/**
	 * Button label for a target status.
	 *
	 * @param string $status Target status.
	 */
	private static function action_label( string $status ): string {
		return match ( $status ) {
			Inquiry::STATUS_APPROVED   => __( 'Onayla', 'ai-hazir-site' ),
			Inquiry::STATUS_REJECTED   => __( 'Reddet', 'ai-hazir-site' ),
			Inquiry::STATUS_QUARANTINE => __( 'Karantinaya al', 'ai-hazir-site' ),
			default                    => __( 'Yeni olarak işaretle', 'ai-hazir-site' ),
		};
	}

	/**
	 * A small form button for an action on one inquiry.
	 *
	 * @param string                $action  Action.
	 * @param int                   $id      Inquiry.
	 * @param array<string, string> $fields  Extra fields.
	 * @param string                $label   Label.
	 * @param string                $confirm Confirmation question ('' for none).
	 */
	private static function button( string $action, int $id, array $fields, string $label, string $confirm = '' ): string {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( '' === $confirm ? '' : ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');"' ) . '>'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="id" value="' . $id . '">';
		foreach ( $fields as $name => $value ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		return $html . wp_nonce_field( $action . '_' . $id, '_wpnonce', false, false ) . '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * Warning and switch shown while the box is off.
	 */
	private static function enable_form(): string {
		$settings = InquiryModule::settings();
		$points   = array(
			__( 'Talep bırakanların iletişim bilgileri (ad, firma, e-posta, telefon) kişisel veridir. Veritabanında şifreli saklanır ve yalnızca bu ekranda görünür.', 'ai-hazir-site' ),
			/* translators: %d: days. */
			sprintf( __( 'Talepler %d gün sonra iletişim bilgileriyle birlikte otomatik silinir (ayarlanabilir); tek tek de silebilirsiniz.', 'ai-hazir-site' ), $settings->retention_days ),
			__( 'KVKK kapsamında aydınlatma metninizi hazırlayıp yayınlamanız gerekir; bu ekranda yeri gösterilir. Hukuki metni eklenti sağlamaz.', 'ai-hazir-site' ),
			__( 'Hiçbir talep otomatik onaylanmaz; talep sahibine otomatik yanıt gitmez. Yeni talepleri yönetici e-posta adresinize bildiririz.', 'ai-hazir-site' ),
			__( 'Kötüye kullanıma karşı istemci başına hız sınırı ve spam kuralları uygulanır; şüpheli talepler karantinaya düşer.', 'ai-hazir-site' ),
		);
		return '<div class="notice notice-warning inline" id="aihs-inquiry-warning"><p><strong>' . esc_html__( 'Teklif kutusunu açmadan önce', 'ai-hazir-site' ) . '</strong></p><ul style="list-style:disc;padding-left:20px">'
			. implode( '', array_map( static fn( string $p ): string => '<li>' . esc_html( $p ) . '</li>', $points ) ) . '</ul></div>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ENABLE ) . '">' . wp_nonce_field( self::ENABLE, '_wpnonce', false, false )
			. '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Yukarıdakileri okudum; kişisel verilerin işlenmesinden sitenin sahibi olarak sorumlu olduğumu biliyorum.', 'ai-hazir-site' ) . '</label></p>'
			. get_submit_button( __( 'Teklif kutusunu aç', 'ai-hazir-site' ), 'primary', 'submit', false ) . '</form>';
	}

	/**
	 * Settings form.
	 */
	private static function settings_form(): string {
		$settings = InquiryModule::settings();
		$fields   = array(
			'retention_days' => array( __( 'Saklama süresi (gün)', 'ai-hazir-site' ), $settings->retention_days ),
			'per_minute'     => array( __( 'İstemci başına dakikalık talep hakkı', 'ai-hazir-site' ), $settings->per_minute ),
			'per_day'        => array( __( 'İstemci başına günlük talep hakkı', 'ai-hazir-site' ), $settings->per_day ),
			'spam_threshold' => array( __( 'Karantina eşiği (spam skoru, 1–100)', 'ai-hazir-site' ), $settings->spam_threshold ),
		);
		$html     = '<h2>' . esc_html__( 'Ayarlar', 'ai-hazir-site' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-inquiry-settings">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SETTINGS ) . '">' . wp_nonce_field( self::SETTINGS, '_wpnonce', false, false ) . '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $name => [ $label, $value ] ) {
			$html .= '<tr><th scope="row"><label for="aihs-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" min="1" id="aihs-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . (int) $value . '"></td></tr>';
		}
		return $html . '</tbody></table>' . get_submit_button( __( 'Ayarları kaydet', 'ai-hazir-site' ), 'secondary', 'submit', false ) . '</form>';
	}

	/**
	 * KVKK notice placeholder.
	 */
	private static function kvkk(): string {
		return '<h2>' . esc_html__( 'KVKK aydınlatma metni', 'ai-hazir-site' ) . '</h2><div class="card" id="aihs-kvkk">'
			. '<p>' . esc_html__( '[Yer tutucu] Talep bırakanlara, kişisel verilerinin hangi amaçla işlendiğini, ne kadar saklandığını ve haklarını anlatan aydınlatma metninizi burada ve sitenizde yayınlayın. Metni hukuk danışmanınızla hazırlayın.', 'ai-hazir-site' ) . '</p>'
			/* translators: %d: days. */
			. '<p>' . esc_html( sprintf( __( 'Eklentinin yaptığı: iletişim bilgilerini şifreli saklar, %d gün sonra siler, talep üzerine tek tek siler; IP adresini saklamaz (yalnızca tuzlu özet).', 'ai-hazir-site' ), InquiryModule::settings()->retention_days ) ) . '</p></div>';
	}

	/**
	 * Latest audit entries.
	 */
	private static function audit(): string {
		$html = '<h2>' . esc_html__( 'Son kayıtlar (denetim)', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-audit"><tbody>';
		foreach ( ( new WpAuditRepository() )->latest( 20 ) as $entry ) {
			$html .= '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) strtotime( $entry['at'] ) ) ) . '</td><td>' . esc_html( $entry['channel'] ) . '</td><td>' . esc_html( $entry['action'] ) . '</td><td>' . esc_html( $entry['outcome'] ) . '</td><td>' . esc_html( null === $entry['inquiry_id'] ? '' : '#' . $entry['inquiry_id'] ) . '</td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Switch-off form.
	 */
	private static function disable_form(): string {
		return '<h2>' . esc_html__( 'Teklif kutusunu kapat', 'ai-hazir-site' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::DISABLE ) . '">' . wp_nonce_field( self::DISABLE, '_wpnonce', false, false )
			. '<p class="description">' . esc_html__( 'Yeni talep alınmaz; kayıtlı talepler saklama süresi dolunca silinmeye devam eder.', 'ai-hazir-site' ) . '</p>'
			. get_submit_button( __( 'Kapat', 'ai-hazir-site' ), 'secondary', 'submit', false ) . '</form>';
	}

	/**
	 * `admin_post_aihs_inquiry_enable`.
	 */
	public function on_enable(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_enable().
		self::redirect( $this->handle_enable( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_inquiry_disable`.
	 */
	public function on_disable(): void {
		self::redirect( $this->handle_disable() );
	}

	/**
	 * `admin_post_aihs_inquiry_status`.
	 */
	public function on_status(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_status().
		self::redirect( $this->handle_status( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_inquiry_delete`.
	 */
	public function on_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_delete().
		self::redirect( $this->handle_delete( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_inquiry_settings`.
	 */
	public function on_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_settings().
		self::redirect( $this->handle_settings( wp_unslash( $_POST ) ) );
	}

	/**
	 * Switches the box on after the warning was confirmed.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_enable( array $post ): string {
		self::authorize( self::ENABLE );
		if ( '1' !== ( $post['confirm'] ?? '' ) ) {
			return self::url( array( 'message' => 'confirm' ) );
		}
		Features::set( Features::INQUIRIES, true );
		InquiryModule::schedule();
		return self::url( array( 'message' => 'enabled' ) );
	}

	/**
	 * Switches the box off (stored inquiries stay until their retention ends).
	 */
	public function handle_disable(): string {
		self::authorize( self::DISABLE );
		Features::set( Features::INQUIRIES, false );
		return self::url( array( 'message' => 'disabled' ) );
	}

	/**
	 * Changes one inquiry's status (the only way to approve).
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_status( array $post ): string {
		$id = isset( $post['id'] ) && is_scalar( $post['id'] ) ? absint( $post['id'] ) : 0;
		self::authorize( self::STATUS . '_' . $id );
		$status = isset( $post['status'] ) && is_string( $post['status'] ) ? sanitize_key( $post['status'] ) : '';
		$done   = InquiryModule::service()->set_status( $id, $status );
		return self::url( array( 'message' => $done ? 'status' : 'failed' ) );
	}

	/**
	 * Deletes one inquiry with its contact data.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_delete( array $post ): string {
		$id = isset( $post['id'] ) && is_scalar( $post['id'] ) ? absint( $post['id'] ) : 0;
		self::authorize( self::DELETE . '_' . $id );
		$done = InquiryModule::service()->delete( $id );
		return self::url( array( 'message' => $done ? 'deleted' : 'failed' ) );
	}

	/**
	 * Saves the settings (clamped to safe bounds).
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_settings( array $post ): string {
		self::authorize( self::SETTINGS );
		$values = array();
		foreach ( array( 'retention_days', 'per_minute', 'per_day', 'spam_threshold' ) as $field ) {
			$values[ $field ] = isset( $post[ $field ] ) && is_scalar( $post[ $field ] ) ? absint( $post[ $field ] ) : null;
		}
		( new WpSettings() )->set( InquirySettings::OPTION, InquirySettings::from_array( array_filter( $values, static fn( $v ): bool => null !== $v ) )->to_array(), false );
		return self::url( array( 'message' => 'saved' ) );
	}

	/**
	 * Capability and nonce check; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	public static function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects and stops.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
