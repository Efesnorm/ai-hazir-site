<?php
/**
 * AI Hazır Site → Portal Ağı (1.20.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Network;

use AIHazirSite\Core\Network\NetworkCheck;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\WordPress\Admin\AdminMenu;

/**
 * Role (mother / member / none), the network name and members (mother) or the mother's address (member), each site's
 * status and a "check now" button. Every form has a nonce and needs manage_options.
 */
final class NetworkPage {

	public const SLUG       = 'aihs-network';
	public const SAVE       = 'aihs_network_save';
	public const CHECK      = 'aihs_network_check_now';
	public const CAPABILITY = 'manage_options';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_post_' . self::SAVE, array( self::class, 'on_save' ) );
		add_action( 'admin_post_' . self::CHECK, array( self::class, 'on_check' ) );
	}

	/**
	 * Adds the screen.
	 */
	public function add_page(): void {
		AdminMenu::add( __( 'Portal Ağı', 'ai-hazir-site' ), self::CAPABILITY, self::SLUG, array( self::class, 'render' ) );
	}

	/**
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			NetworkCheck::VERIFIED     => __( 'doğrulandı', 'ai-hazir-site' ),
			NetworkCheck::NOT_DECLARED => __( 'bu siteyi anne olarak göstermiyor', 'ai-hazir-site' ),
			NetworkCheck::OTHER_MOTHER => __( 'anne başka', 'ai-hazir-site' ),
			NetworkCheck::NOT_LISTED   => __( 'annede kayıtlı değil (henüz)', 'ai-hazir-site' ),
			NetworkCheck::NOT_MOTHER   => __( 'girilen adres anne site değil', 'ai-hazir-site' ),
			NetworkCheck::UNREACHABLE  => __( 'erişilemedi / henüz kontrol edilmedi', 'ai-hazir-site' ),
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		echo '<div class="wrap">' . self::render_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_html().
	}

	/**
	 * Screen HTML (everything escaped here).
	 */
	public static function render_html(): string {
		$settings = NetworkModule::settings();
		$state    = NetworkModule::state();
		$view     = NetworkModule::view();
		$labels   = self::status_labels();
		$errors   = get_transient( 'aihs_network_errors_' . get_current_user_id() );
		delete_transient( 'aihs_network_errors_' . get_current_user_id() );

		$html = '<h1>' . esc_html__( 'Portal Ağı', 'ai-hazir-site' ) . '</h1>'
			. '<p>' . esc_html__( 'Aynı sahibin siteleri bir ağ olur: anne site ağın adını ve üyelerini yönetir, üyeler yalnızca annelerinin adresini girer. Bir site ancak iki taraf da onaylamışsa (anne listeler, üye o anneyi gösterir) ağda görünür. Doğrulanan siteler AI\'lara ağ olarak duyurulur (Schema.org, llms.txt).', 'ai-hazir-site' ) . '</p>';

		if ( is_array( $errors ) && array() !== $errors ) {
			$html .= '<div class="notice notice-error inline"><ul>' . implode( '', array_map( static fn( $e ): string => '<li>' . esc_html( (string) $e ) . '</li>', $errors ) ) . '</ul></div>';
		}

		$role = static fn( string $value, string $label ) => '<label style="display:block"><input type="radio" name="role" value="' . esc_attr( $value ) . '"' . checked( $settings->role, $value, false ) . '> ' . esc_html( $label ) . '</label>';
		// 1.23.1: only the chosen role's fields (CSS :has(); without support every field stays visible, as before).
		$html  .= '<style>#aihs-network-form:has(input[name="role"]:not([value="mother"]):checked) .aihs-for-mother,#aihs-network-form:has(input[name="role"]:not([value="member"]):checked) .aihs-for-member{display:none}</style>';
		$joined = NetworkSettings::ROLE_MEMBER === $settings->role ? $view->network_name() : '';
		$html  .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-network-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '">' . wp_nonce_field( self::SAVE, '_wpnonce', true, false )
			. '<h2>' . esc_html__( 'Bu sitenin rolü', 'ai-hazir-site' ) . '</h2>'
			. $role( NetworkSettings::ROLE_NONE, __( 'Ağda değil', 'ai-hazir-site' ) )
			. $role( NetworkSettings::ROLE_MOTHER, __( 'Anne site (ağı buradan yönetirim, veriyi buradan izlerim)', 'ai-hazir-site' ) )
			. $role( NetworkSettings::ROLE_MEMBER, __( 'Üye (bir anne siteye bağlıyım)', 'ai-hazir-site' ) )
			. '<table class="form-table"><tbody>'
			. '<tr class="aihs-for-mother"><th><label for="aihs-network-name">' . esc_html__( 'Ağ adı (anne)', 'ai-hazir-site' ) . '</label></th><td><input type="text" class="regular-text" id="aihs-network-name" name="name" maxlength="' . (int) NetworkSettings::NAME_MAX . '" value="' . esc_attr( $settings->name ) . '"></td></tr>'
			. '<tr class="aihs-for-mother"><th><label for="aihs-network-members">' . esc_html__( 'Üye siteler (anne)', 'ai-hazir-site' ) . '</label></th><td><textarea class="large-text code" rows="8" id="aihs-network-members" name="members" placeholder="https://www.ornek.org.tr/">' . esc_textarea( implode( "\n", $settings->members ) ) . '</textarea>'
			. '<p class="description">' . esc_html( sprintf( /* translators: %d: maximum members. */ __( 'Her satıra bir site adresi (https). En çok %d üye.', 'ai-hazir-site' ), NetworkSettings::MAX_MEMBERS ) ) . '</p></td></tr>'
			. ( '' === $joined ? '' : '<tr class="aihs-for-member" id="aihs-network-joined"><th>' . esc_html__( 'Ağ adı', 'ai-hazir-site' ) . '</th><td><strong>' . esc_html( $joined ) . '</strong> <span class="description">' . esc_html__( '(anne siteden)', 'ai-hazir-site' ) . '</span></td></tr>' )
			. '<tr class="aihs-for-member"><th><label for="aihs-network-mother">' . esc_html__( 'Anne site adresi (üye)', 'ai-hazir-site' ) . '</label></th><td><input type="url" class="regular-text" id="aihs-network-mother" name="mother" value="' . esc_attr( NetworkSettings::ROLE_MEMBER === $settings->role ? $settings->mother : '' ) . '" placeholder="https://"></td></tr>'
			. '</tbody></table>'
			. get_submit_button( __( 'Kaydet', 'ai-hazir-site' ) )
			. '</form>';

		if ( NetworkSettings::ROLE_NONE === $settings->role ) {
			return $html;
		}

		$html .= '<h2>' . esc_html__( 'Durum', 'ai-hazir-site' ) . '</h2><table class="widefat striped" id="aihs-network-status"><thead><tr><th>' . esc_html__( 'Site', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Durum', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Son kontrol', 'ai-hazir-site' ) . '</th></tr></thead><tbody>';
		$rows  = array();
		if ( NetworkSettings::ROLE_MOTHER === $settings->role ) {
			foreach ( $settings->members as $url ) {
				$row    = is_array( $state['members'][ $url ] ?? null ) ? $state['members'][ $url ] : array();
				$status = $view->member_status( $url );
				$note   = NetworkCheck::VERIFIED === $status && '' === (string) ( $row['country'] ?? '' ) ? ' (' . __( 'profilde ülke yok', 'ai-hazir-site' ) . ')' : '';
				$rows[] = array( $url . ( '' !== (string) ( $row['name'] ?? '' ) ? ' – ' . $row['name'] : '' ) . $note, $status, (int) ( $row['checked_at'] ?? 0 ) );
			}
		} else {
			$row    = is_array( $state['self'] ?? null ) ? $state['self'] : array();
			$rows[] = array( __( 'Bu site, annede:', 'ai-hazir-site' ) . ' ' . $settings->mother, $view->self_status(), (int) ( $row['checked_at'] ?? 0 ) );
			foreach ( $view->siblings() as $site ) {
				$rows[] = array( $site['url'] . ( '' !== $site['name'] ? ' – ' . $site['name'] : '' ), NetworkCheck::VERIFIED, (int) ( $row['checked_at'] ?? 0 ) );
			}
		}
		foreach ( $rows as [ $site, $status, $checked ] ) {
			$html .= '<tr data-status="' . esc_attr( $status ) . '"><td>' . esc_html( $site ) . '</td><td>' . esc_html( $labels[ $status ] ?? $status ) . '</td><td>' . esc_html( 0 === $checked ? '–' : wp_date( 'd.m.Y H:i', $checked ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		return $html . '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::CHECK ) . '">' . wp_nonce_field( self::CHECK, '_wpnonce', true, false ) . get_submit_button( __( 'Şimdi kontrol et', 'ai-hazir-site' ), 'secondary' ) . '</form>'
			. '<p class="description">' . esc_html__( 'Kontrol saatte bir kendiliğinden yapılır. Bir site eklendikten sonra iki tarafta da kaydedip "Şimdi kontrol et"e basın (önce üyede, sonra annede, sonra yeniden üyede).', 'ai-hazir-site' ) . '</p>';
	}

	/**
	 * Saves the settings; returns the redirect URL.
	 *
	 * @param array<string, mixed> $input Posted data.
	 */
	public static function handle_save( array $input ): string {
		self::authorize( self::SAVE );
		[ $settings, $errors ] = NetworkSettings::from_input( $input, NetworkModule::self_url() );
		update_option( NetworkModule::OPTION, $settings->to_array(), false );
		if ( array() !== $errors ) {
			set_transient( 'aihs_network_errors_' . get_current_user_id(), $errors, 300 );
		}
		NetworkModule::check();
		return AdminMenu::url( self::SLUG, array( 'saved' => 1 ) );
	}

	/**
	 * Runs the check now; returns the redirect URL.
	 */
	public static function handle_check(): string {
		self::authorize( self::CHECK );
		NetworkModule::check();
		return AdminMenu::url( self::SLUG, array( 'checked' => 1 ) );
	}

	/**
	 * `admin_post_aihs_network_save`.
	 */
	public static function on_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save().
		$input = wp_unslash( $_POST );
		wp_safe_redirect(
			self::handle_save(
				array(
					'role'    => sanitize_key( (string) ( $input['role'] ?? '' ) ),
					'name'    => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
					'mother'  => esc_url_raw( (string) ( $input['mother'] ?? '' ), array( 'https' ) ),
					'members' => sanitize_textarea_field( (string) ( $input['members'] ?? '' ) ),
				)
			)
		);
		exit;
	}

	/**
	 * `admin_post_aihs_network_check_now`.
	 */
	public static function on_check(): void {
		wp_safe_redirect( self::handle_check() );
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
