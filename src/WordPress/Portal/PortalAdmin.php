<?php
/**
 * AI Hazır Site → İşletmeler (portal administrator).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Portal;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Measurement\Hit;
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\Core\Portal\PortalReport;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Inquiry\WpInquiryRepository;
use AIHazirSite\WordPress\Measurement\Admin\ReportPage;
use AIHazirSite\Core\Contracts\HitRepository;
use AIHazirSite\WordPress\Storage\WpdbHitRepository;

/**
 * Businesses, their users, listing assignment and the per-business report.
 */
final class PortalAdmin {

	public const SLUG            = 'aihs-portal';
	public const SAVE_BUSINESS   = 'aihs_save_business';
	public const DELETE_BUSINESS = 'aihs_delete_business';
	public const LINK_USER       = 'aihs_link_business_user';
	public const ASSIGN          = 'aihs_assign_listings';
	public const REPORT_DAYS     = 28;

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		foreach ( array( self::SAVE_BUSINESS, self::DELETE_BUSINESS, self::LINK_USER, self::ASSIGN ) as $action ) {
			add_action( 'admin_post_' . $action, array( $this, 'on_' . $action ) );
		}
	}

	/**
	 * Submenu under AI Katalog.
	 */
	public function add_menu(): void {
		AdminMenu::add( __( 'İşletmeler', 'ai-hazir-site' ), CatalogAdmin::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function url( array $args = array() ): string {
		return AdminMenu::url( self::SLUG, $args );
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CatalogAdmin::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$edit = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		echo self::page( $edit, FormState::take(), $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page().
	}

	/**
	 * The per-business report of the last REPORT_DAYS days.
	 *
	 * @return array{rows: array<int, array{name: string, slug: string, page_hits: int, listings: int, inquiries: int}>, totals: array{page_hits: int, listings: int, inquiries: int}}
	 */
	public static function report(): array {
		$since = ReportPage::report( self::REPORT_DAYS )->since();
		$hits  = array();
		foreach ( ( new WpdbHitRepository() )->totals( Hit::KIND_BOT, $since, HitRepository::GROUP_PATH ) as $row ) {
			$hits[ $row['key'] ] = $row['total'];
		}
		$repository = new WpListingRepository();
		$map        = array();
		foreach ( $repository->ids() as $id ) {
			$map[ $id ] = $repository->business_of( $id );
		}
		$inquiries = array_values( array_filter( ( new WpInquiryRepository() )->list_inquiries( '', '', 100000 ), static fn( $i ): bool => substr( $i->created_at, 0, 10 ) >= $since ) );
		return PortalReport::build( Portal::businesses()->businesses(), $map, $hits, $inquiries, array( Portal::class, 'page_path' ) );
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param int                                                                                       $edit    Business being edited (0 = new).
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state   State after a failed save.
	 * @param string                                                                                    $message Message key.
	 */
	public static function page( int $edit, array $state, string $message = '' ): string {
		$businesses = Portal::businesses()->businesses();
		$html       = '<div class="wrap"><h1>' . esc_html__( 'İşletmeler', 'ai-hazir-site' ) . '</h1>';
		$messages   = array(
			'saved'    => __( 'Kaydedildi.', 'ai-hazir-site' ),
			'deleted'  => __( 'İşletme silindi.', 'ai-hazir-site' ),
			'linked'   => __( 'Kullanıcı bağlandı.', 'ai-hazir-site' ),
			'unlinked' => __( 'Kullanıcının bağlantısı kaldırıldı.', 'ai-hazir-site' ),
			'assigned' => __( 'İlanlar güncellendi.', 'ai-hazir-site' ),
		);
		if ( isset( $messages[ $message ] ) ) {
			$html .= '<div class="notice notice-success"><p>' . esc_html( $messages[ $message ] ) . '</p></div>';
		}
		if ( array() !== $state['errors'] ) {
			$html .= '<div class="notice notice-error"><ul>';
			foreach ( $state['errors'] as $field => $error ) {
				$html .= '<li>' . esc_html( $field . ': ' . $error ) . '</li>';
			}
			$html .= '</ul></div>';
		}

		$html .= self::report_table( self::report() );
		$html .= self::business_form( $edit > 0 ? Portal::businesses()->business( $edit ) : null, $state['input'] );
		if ( array() !== $businesses ) {
			$html .= self::users_section( $businesses ) . self::assign_section( $businesses );
		}
		return $html . '</div>';
	}

	/**
	 * Report table.
	 *
	 * @param array{rows: array<int, array{name: string, slug: string, page_hits: int, listings: int, inquiries: int}>, totals: array{page_hits: int, listings: int, inquiries: int}} $report Report.
	 */
	private static function report_table( array $report ): string {
		/* translators: %d: days. */
		$html = '<h2>' . esc_html( sprintf( __( 'İşletme bazında AI görünürlüğü (son %d gün)', 'ai-hazir-site' ), self::REPORT_DAYS ) ) . '</h2>'
			. '<table id="aihs-portal-report" class="widefat striped"><thead><tr><th>' . esc_html__( 'İşletme', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'AI bot okuması (işletme sayfası)', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'İlan', 'ai-hazir-site' ) . '</th><th>' . esc_html__( 'Talep', 'ai-hazir-site' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $report['rows'] as $id => $row ) {
			$name  = 0 === $id ? __( 'Portalın kendi ilanları', 'ai-hazir-site' ) : $row['name'];
			$html .= '<tr data-business="' . esc_attr( (string) $id ) . '"><td>' . esc_html( $name ) . '</td><td data-value="page_hits">' . esc_html( (string) $row['page_hits'] ) . '</td><td data-value="listings">' . esc_html( (string) $row['listings'] ) . '</td><td data-value="inquiries">' . esc_html( (string) $row['inquiries'] ) . '</td><td>'
				. ( 0 === $id ? '' : '<a href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Düzenle', 'ai-hazir-site' ) . '</a>' ) . '</td></tr>';
		}
		return $html . '<tr data-business="total"><th>' . esc_html__( 'Toplam', 'ai-hazir-site' ) . '</th><th data-value="page_hits">' . esc_html( (string) $report['totals']['page_hits'] ) . '</th><th data-value="listings">' . esc_html( (string) $report['totals']['listings'] ) . '</th><th data-value="inquiries">' . esc_html( (string) $report['totals']['inquiries'] ) . '</th><th></th></tr></tbody></table>';
	}

	/**
	 * Add / edit form.
	 *
	 * @param Business|null        $business Business being edited.
	 * @param array<string, mixed> $input    Values of a failed save.
	 */
	private static function business_form( ?Business $business, array $input ): string {
		$values = null === $business ? array() : array_merge( $business->profile->to_array(), array( 'slug' => $business->slug ) );
		$values = array_merge( $values, $input );
		$value  = static function ( string $key ) use ( $values ): string {
			$v = $values[ $key ] ?? '';
			return is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : ( is_scalar( $v ) ? (string) $v : '' );
		};
		$fields = array(
			'name'           => __( 'İşletme adı', 'ai-hazir-site' ),
			'slug'           => __( 'Adres kısaltması (boş: addan)', 'ai-hazir-site' ),
			'sector'         => __( 'Sektör', 'ai-hazir-site' ),
			'country'        => __( 'Ülke (ISO, ör. TR)', 'ai-hazir-site' ),
			'languages'      => __( 'Diller (virgülle)', 'ai-hazir-site' ),
			'contact_email'  => __( 'Kurumsal e-posta', 'ai-hazir-site' ),
			'contact_phone'  => __( 'Kurumsal telefon', 'ai-hazir-site' ),
			'certifications' => __( 'Sertifikalar (virgülle)', 'ai-hazir-site' ),
		);
		$html   = '<h2>' . esc_html( null === $business ? __( 'İşletme ekle', 'ai-hazir-site' ) : __( 'İşletmeyi düzenle', 'ai-hazir-site' ) ) . '</h2>'
			. '<form id="aihs-business-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::SAVE_BUSINESS ) . '">'
			. '<input type="hidden" name="id" value="' . esc_attr( (string) ( $business->id ?? 0 ) ) . '">' . wp_nonce_field( self::SAVE_BUSINESS, '_wpnonce', true, false ) . '<table class="form-table"><tbody>';
		foreach ( $fields as $name => $label ) {
			$html .= '<tr><th><label for="aihs-business-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="aihs-business-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text" value="' . esc_attr( $value( $name ) ) . '"></td></tr>';
		}
		$html .= '</tbody></table>' . get_submit_button( __( 'İşletmeyi kaydet', 'ai-hazir-site' ) ) . '</form>';
		if ( null !== $business ) {
			$html .= '<p><a href="' . esc_url( Portal::page_url( $business ) ) . '">' . esc_html( Portal::page_url( $business ) ) . '</a></p>'
				. '<p><a class="button button-link-delete" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action' => self::DELETE_BUSINESS,
								'id'     => (int) $business->id,
							),
							admin_url( 'admin-post.php' )
						),
						self::DELETE_BUSINESS . '_' . (int) $business->id
					)
				) . '">' . esc_html__( 'İşletmeyi sil', 'ai-hazir-site' ) . '</a></p>';
		}
		return $html;
	}

	/**
	 * Users linked to each business and the link form.
	 *
	 * @param Business[] $businesses Businesses.
	 */
	private static function users_section( array $businesses ): string {
		$html = '<h2>' . esc_html__( 'İşletme yetkilileri', 'ai-hazir-site' ) . '</h2><p>' . esc_html__( 'Yetkili, yalnızca kendi işletmesinin ilanlarını "İşletmem" ekranından görür ve düzenler. Kullanıcıyı önce Kullanıcılar ekranında (ör. Abone rolüyle) oluşturun.', 'ai-hazir-site' ) . '</p><ul id="aihs-business-users">';
		foreach ( $businesses as $business ) {
			$names = array_map( static fn( \WP_User $u ): string => $u->user_login, Portal::users_of( (int) $business->id ) );
			$html .= '<li data-business="' . esc_attr( (string) $business->id ) . '">' . esc_html( $business->profile->name . ': ' . ( array() === $names ? '—' : implode( ', ', $names ) ) ) . '</li>';
		}
		$options = '<option value="0">' . esc_html__( '(bağlantıyı kaldır)', 'ai-hazir-site' ) . '</option>';
		foreach ( $businesses as $business ) {
			$options .= '<option value="' . esc_attr( (string) $business->id ) . '">' . esc_html( $business->profile->name ) . '</option>';
		}
		return $html . '</ul><form id="aihs-link-user-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::LINK_USER ) . '">' . wp_nonce_field( self::LINK_USER, '_wpnonce', true, false )
			. '<label>' . esc_html__( 'Kullanıcı adı veya e-posta', 'ai-hazir-site' ) . ' <input name="user" class="regular-text"></label> <select name="business">' . $options . '</select> ' . get_submit_button( __( 'Bağla', 'ai-hazir-site' ), 'secondary', 'submit', false ) . '</form>';
	}

	/**
	 * Listing → business assignment.
	 *
	 * @param Business[] $businesses Businesses.
	 */
	private static function assign_section( array $businesses ): string {
		$repository = new WpListingRepository();
		$html       = '<h2>' . esc_html__( 'İlanların işletmeleri', 'ai-hazir-site' ) . '</h2><form id="aihs-assign-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ASSIGN ) . '">' . wp_nonce_field( self::ASSIGN, '_wpnonce', true, false ) . '<table class="widefat striped"><tbody>';
		foreach ( ListingType::ALL as $type ) {
			foreach ( $repository->all( $type ) as $listing ) {
				$current = $repository->business_of( (int) $listing->id );
				$select  = '<select name="assign[' . esc_attr( (string) $listing->id ) . ']"><option value="0">' . esc_html__( 'Portal', 'ai-hazir-site' ) . '</option>';
				foreach ( $businesses as $business ) {
					$select .= '<option value="' . esc_attr( (string) $business->id ) . '"' . selected( $current, $business->id, false ) . '>' . esc_html( $business->profile->name ) . '</option>';
				}
				$html .= '<tr data-listing="' . esc_attr( (string) $listing->id ) . '"><td>' . esc_html( $listing->title ) . '</td><td>' . $select . '</select></td></tr>';
			}
		}
		return $html . '</tbody></table>' . get_submit_button( __( 'İlanları kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * `admin_post_aihs_save_business`.
	 */
	public function on_aihs_save_business(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the handler.
		self::redirect( self::handle_save_business( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_delete_business`.
	 */
	public function on_aihs_delete_business(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in the handler.
		self::redirect( self::handle_delete_business( wp_unslash( $_GET ) ) );
	}

	/**
	 * `admin_post_aihs_link_business_user`.
	 */
	public function on_aihs_link_business_user(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the handler.
		self::redirect( self::handle_link_user( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_assign_listings`.
	 */
	public function on_aihs_assign_listings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the handler.
		self::redirect( self::handle_assign( wp_unslash( $_POST ) ) );
	}

	/**
	 * Saves a business; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save_business( array $post ): string {
		CatalogAdmin::authorize( self::SAVE_BUSINESS );
		$input = array();
		foreach ( array( 'name', 'slug', 'sector', 'country', 'languages', 'contact_phone', 'certifications' ) as $field ) {
			$input[ $field ] = sanitize_text_field( self::text( $post, $field ) );
		}
		$input['slug']          = sanitize_title( $input['slug'] );
		$input['contact_email'] = sanitize_email( self::text( $post, 'contact_email' ) );
		$id                     = absint( self::text( $post, 'id' ) );
		$saved                  = Portal::service()->save_business( $input, $id > 0 ? $id : null );
		if ( null === $saved['business'] ) {
			FormState::put( $saved['errors'], $input );
			return self::url( $id > 0 ? array( 'edit' => $id ) : array() );
		}
		FormState::put( array(), array(), $saved['warnings'] );
		return self::url(
			array(
				'message' => 'saved',
				'edit'    => (int) $saved['business']->id,
			)
		);
	}

	/**
	 * Deletes a business; returns where to redirect.
	 *
	 * @param array<mixed> $get Unslashed $_GET.
	 */
	public static function handle_delete_business( array $get ): string {
		$id = absint( self::text( $get, 'id' ) );
		CatalogAdmin::authorize( self::DELETE_BUSINESS . '_' . $id );
		$reason = Portal::service()->delete_business( $id );
		if ( '' !== $reason ) {
			FormState::put( array( 'business' => $reason ) );
			return self::url( array( 'edit' => $id ) );
		}
		foreach ( Portal::users_of( $id ) as $user ) {
			delete_user_meta( $user->ID, Portal::USER_META );
		}
		return self::url( array( 'message' => 'deleted' ) );
	}

	/**
	 * Links a user to a business (0 = unlink); returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_link_user( array $post ): string {
		CatalogAdmin::authorize( self::LINK_USER );
		$login    = sanitize_text_field( self::text( $post, 'user' ) );
		$user     = get_user_by( 'login', $login );
		$user     = false === $user ? get_user_by( 'email', $login ) : $user;
		$business = absint( self::text( $post, 'business' ) );
		if ( false === $user ) {
			FormState::put( array( 'user' => __( 'Kullanıcı bulunamadı.', 'ai-hazir-site' ) ) );
			return self::url();
		}
		if ( user_can( $user, CatalogAdmin::CAPABILITY ) ) {
			FormState::put( array( 'user' => __( 'Yöneticiler bir işletmeye bağlanmaz; tüm işletmeleri zaten yönetir.', 'ai-hazir-site' ) ) );
			return self::url();
		}
		if ( 0 === $business ) {
			delete_user_meta( $user->ID, Portal::USER_META );
			return self::url( array( 'message' => 'unlinked' ) );
		}
		if ( null === Portal::businesses()->business( $business ) ) {
			FormState::put( array( 'business' => __( 'İşletme bulunamadı.', 'ai-hazir-site' ) ) );
			return self::url();
		}
		update_user_meta( $user->ID, Portal::USER_META, $business );
		return self::url( array( 'message' => 'linked' ) );
	}

	/**
	 * Saves the listing → business assignment; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_assign( array $post ): string {
		CatalogAdmin::authorize( self::ASSIGN );
		$errors = array();
		foreach ( is_array( $post['assign'] ?? null ) ? $post['assign'] : array() as $listing => $business ) {
			$business = is_scalar( $business ) ? absint( (string) $business ) : 0;
			if ( ! Portal::service()->assign_listing( absint( (string) $listing ), $business > 0 ? $business : null ) ) {
				$errors[ 'listing ' . absint( (string) $listing ) ] = __( 'Atanamadı.', 'ai-hazir-site' );
			}
		}
		FormState::put( $errors );
		return self::url( array() === $errors ? array( 'message' => 'assigned' ) : array() );
	}

	/**
	 * String value from an array.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $key  Key.
	 */
	private static function text( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
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
