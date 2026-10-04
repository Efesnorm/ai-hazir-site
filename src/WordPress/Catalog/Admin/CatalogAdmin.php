<?php
/**
 * "AI Katalog" admin menu: listings of three types and the company profile.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Catalog\Admin;

use AIHazirSite\WordPress\Admin\AdminMenu;
use AIHazirSite\Core\Catalog\CompanyProfile;
use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Catalog\Nace;
use AIHazirSite\Core\Catalog\ProfileValidator;
use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateField;
use AIHazirSite\WordPress\Catalog\CatalogModule;
use AIHazirSite\WordPress\Catalog\PostType;
use AIHazirSite\WordPress\Catalog\WpListingRepository;
use AIHazirSite\WordPress\Catalog\WpProfileRepository;
use AIHazirSite\WordPress\Discovery\DiscoveryModule;
use AIHazirSite\WordPress\Templates\TemplatesModule;
use AIHazirSite\WordPress\Platform\WpClock;

/**
 * Screens post to admin-post.php; every write goes through CatalogService.
 * Handlers return the redirect URL so they can be tested without exiting.
 */
final class CatalogAdmin {

	public const CAPABILITY     = PostType::CAPABILITY;
	public const SAVE_LISTING   = 'aihs_save_listing';
	public const DELETE_LISTING = 'aihs_delete_listing';
	public const SAVE_PROFILE   = 'aihs_save_profile';
	public const PROFILE_SLUG   = 'aihs-catalog-profile';

	/**
	 * Admin page slug per listing type.
	 */
	public const SLUGS = array(
		ListingType::OFFER  => 'aihs-catalog',
		ListingType::DEMAND => 'aihs-catalog-demand',
		ListingType::SUPPLY => 'aihs-catalog-supply',
	);

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_post_' . self::SAVE_LISTING, array( $this, 'on_save_listing' ) );
		add_action( 'admin_post_' . self::DELETE_LISTING, array( $this, 'on_delete_listing' ) );
		add_action( 'admin_post_' . self::SAVE_PROFILE, array( $this, 'on_save_profile' ) );
	}

	/**
	 * Type labels (plural).
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			ListingType::OFFER  => __( 'Satılanlar', 'ai-hazir-site' ),
			ListingType::DEMAND => __( 'Arananlar', 'ai-hazir-site' ),
			ListingType::SUPPLY => __( 'Tedarik Edilebilenler', 'ai-hazir-site' ),
		);
	}

	/**
	 * Adds the menu and its sub-pages.
	 */
	public function add_menus(): void {
		$labels = self::type_labels();
		AdminMenu::add( $labels[ ListingType::OFFER ], self::CAPABILITY, self::SLUGS[ ListingType::OFFER ], array( $this, 'render_offer' ) );
		AdminMenu::add( $labels[ ListingType::DEMAND ], self::CAPABILITY, self::SLUGS[ ListingType::DEMAND ], array( $this, 'render_demand' ) );
		AdminMenu::add( $labels[ ListingType::SUPPLY ], self::CAPABILITY, self::SLUGS[ ListingType::SUPPLY ], array( $this, 'render_supply' ) );
		AdminMenu::add( __( 'Firma Profili', 'ai-hazir-site' ), self::CAPABILITY, self::PROFILE_SLUG, array( $this, 'render_profile' ) );
	}

	/**
	 * Satılanlar page.
	 */
	public function render_offer(): void {
		$this->render_type( ListingType::OFFER );
	}

	/**
	 * Arananlar page.
	 */
	public function render_demand(): void {
		$this->render_type( ListingType::DEMAND );
	}

	/**
	 * Tedarik Edilebilenler page.
	 */
	public function render_supply(): void {
		$this->render_type( ListingType::SUPPLY );
	}

	/**
	 * List or form of one type.
	 *
	 * @param string $type Listing type.
	 */
	public function render_type( string $type ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- View selection only; writes are nonce-checked in the handlers.
		$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$new  = isset( $_GET['new'] );
		// phpcs:enable

		echo '<div class="wrap">';
		if ( $new || $edit > 0 ) {
			echo self::render_form( $type, $edit > 0 ? ( new WpListingRepository() )->find( $edit ) : null, FormState::take() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_form().
		} else {
			echo self::render_list( $type, ( new WpListingRepository() )->all( $type ), ( new WpClock() )->today() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_list().
		}
		echo '</div>';
	}

	/**
	 * Listing table with type, validity and last update columns.
	 *
	 * @param string    $type     Type.
	 * @param Listing[] $listings Listings.
	 * @param string    $today    Y-m-d.
	 */
	public static function render_list( string $type, array $listings, string $today ): string {
		$labels = self::type_labels();
		$html   = '<h1 class="wp-heading-inline">' . esc_html( $labels[ $type ] ) . '</h1> '
			. '<a class="page-title-action" href="' . esc_url( self::page_url( $type, array( 'new' => 1 ) ) ) . '">' . esc_html__( 'Yeni ekle', 'ai-hazir-site' ) . '</a>'
			. '<hr class="wp-header-end">' . self::message_notice();

		$html .= '<table class="widefat striped" id="aihs-listings"><thead><tr>'
			. '<th>' . esc_html__( 'Başlık', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Tür', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Kategori', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Fiyat', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Geçerlilik', 'ai-hazir-site' ) . '</th>'
			. '<th>' . esc_html__( 'Son güncelleme', 'ai-hazir-site' ) . '</th>'
			. '</tr></thead><tbody>';

		if ( array() === $listings ) {
			$html .= '<tr><td colspan="6">' . esc_html__( 'Henüz kayıt yok.', 'ai-hazir-site' ) . '</td></tr>';
		}

		foreach ( $listings as $listing ) {
			$delete = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::DELETE_LISTING,
						'id'     => $listing->id,
						'type'   => $type,
					),
					admin_url( 'admin-post.php' )
				),
				self::DELETE_LISTING . '_' . $listing->id
			);

			$validity = null === $listing->valid_until ? '–' : esc_html( $listing->valid_until );
			if ( $listing->is_expired( $today ) ) {
				$validity .= ' <span class="aihs-expired" style="color:#b32d2e;font-weight:600">' . esc_html__( 'süresi doldu', 'ai-hazir-site' ) . '</span>';
			}

			$html .= '<tr data-id="' . esc_attr( (string) $listing->id ) . '">'
				. '<td><strong><a href="' . esc_url( self::page_url( $type, array( 'edit' => (int) $listing->id ) ) ) . '">' . esc_html( $listing->title ) . '</a></strong>'
				. '<div class="row-actions"><a href="' . esc_url( self::page_url( $type, array( 'edit' => (int) $listing->id ) ) ) . '">' . esc_html__( 'Düzenle', 'ai-hazir-site' ) . '</a> | '
				. '<a class="submitdelete" href="' . esc_url( $delete ) . '" onclick="return confirm(\'' . esc_js( __( 'Bu ilan kalıcı olarak silinecek. Emin misiniz?', 'ai-hazir-site' ) ) . '\')">' . esc_html__( 'Sil', 'ai-hazir-site' ) . '</a></div></td>'
				. '<td>' . esc_html( $labels[ $listing->type ] ?? $listing->type ) . '</td>'
				. '<td>' . esc_html( $listing->category ) . '</td>'
				. '<td>' . esc_html( self::price( $listing ) ) . '</td>'
				. '<td>' . $validity . '</td>'
				. '<td>' . esc_html( null === $listing->updated_at ? '–' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $listing->updated_at ) ) ) . '</td>'
				. '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * Add/edit form.
	 *
	 * @param string                                                                                    $type     Type.
	 * @param Listing|null                                                                              $listing  Listing being edited.
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state Carried-over state.
	 */
	public static function render_form( string $type, ?Listing $listing, array $state ): string {
		$labels   = self::type_labels();
		$registry = TemplatesModule::registry();
		$template = self::listing_template( $listing, $state['input'] );
		$values   = null === $listing ? array() : $listing->to_array();
		if ( null !== $listing ) {
			$values['attributes'] = self::attributes_text( array_diff_key( $listing->attributes, self::field_names( $template ) ) );
			$values['attr']       = array_intersect_key( $listing->attributes, self::field_names( $template ) );
		}
		$values = array_merge( $values, $state['input'] );
		$errors = $state['errors'];

		$html  = '<h1>' . esc_html( ( null === $listing ? __( 'Yeni ilan', 'ai-hazir-site' ) : __( 'İlanı düzenle', 'ai-hazir-site' ) ) . ' – ' . $labels[ $type ] ) . '</h1>';
		$html .= self::errors_notice( $errors );
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-listing-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_LISTING ) . '">'
			. '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">'
			. '<input type="hidden" name="id" value="' . esc_attr( null === $listing ? '' : (string) $listing->id ) . '">'
			. ( null !== $registry && null === $listing ? '<input type="hidden" name="template" value="' . esc_attr( $template->id ) . '">' : '' )
			. wp_nonce_field( self::SAVE_LISTING, '_wpnonce', true, false )
			. '<table class="form-table" role="presentation"><tbody>';

		if ( null !== $registry ) {
			$html .= '<tr><th scope="row">' . esc_html__( 'Sektör şablonu', 'ai-hazir-site' ) . '</th><td><strong id="aihs-template-name">' . esc_html( $template->name ) . '</strong>'
				. ( '' !== $template->description ? '<p class="description">' . esc_html( $template->description ) . '</p>' : '' )
				. ( null === $listing ? '<p class="description">' . esc_html__( 'Yeni ilanlar Firma Profili\'nde seçilen şablonu kullanır; ilanın şablonu sonradan değiştirilemez.', 'ai-hazir-site' ) . '</p>' : '' )
				. ( '' !== ( $errors['template'] ?? '' ) ? '<p class="aihs-field-error" style="color:#b32d2e">' . esc_html( $errors['template'] ) . '</p>' : '' )
				. '</td></tr>';
		}

		$fields = array(
			'title'          => array( __( 'Başlık', 'ai-hazir-site' ), 'text', __( 'Zorunlu.', 'ai-hazir-site' ) ),
			'description'    => array( __( 'Açıklama', 'ai-hazir-site' ), 'textarea', '' ),
			'category'       => array( __( 'Kategori', 'ai-hazir-site' ), 'text', '' ),
			'quantity'       => array( __( 'Miktar', 'ai-hazir-site' ), 'text', '' ),
			'unit'           => array( __( 'Birim', 'ai-hazir-site' ), 'text', __( 'Örn. m, kg, adet.', 'ai-hazir-site' ) ),
			'price_min'      => array( __( 'En düşük fiyat', 'ai-hazir-site' ), 'text', '' ),
			'price_max'      => array( __( 'En yüksek fiyat', 'ai-hazir-site' ), 'text', '' ),
			'currency'       => array( __( 'Para birimi', 'ai-hazir-site' ), 'text', __( 'Üç harfli kod: TRY, USD, EUR.', 'ai-hazir-site' ) ),
			'region'         => array( __( 'Bölge', 'ai-hazir-site' ), 'text', '' ),
			'lead_time_days' => array( __( 'Teslim süresi (gün)', 'ai-hazir-site' ), 'number', '' ),
			'valid_until'    => array( __( 'Geçerlilik tarihi', 'ai-hazir-site' ), 'date', '' ),
			'attributes'     => array( array() === $template->fields ? __( 'Özellikler', 'ai-hazir-site' ) : __( 'Ek özellikler', 'ai-hazir-site' ), 'textarea', __( 'Her satıra bir "anahtar: değer" (anahtar: küçük harf, rakam, alt çizgi).', 'ai-hazir-site' ) ),
		);
		if ( ! $template->price ) {
			unset( $fields['price_min'], $fields['price_max'], $fields['currency'] );
		}
		foreach ( $fields as $name => [ $label, $kind, $help ] ) {
			if ( 'attributes' === $name ) {
				$attr = is_array( $values['attr'] ?? null ) ? $values['attr'] : array();
				foreach ( $template->fields as $field ) {
					$html .= self::template_row( $field, $attr[ $field->name ] ?? '', $errors[ 'attributes.' . $field->name ] ?? '' );
				}
			}
			$html .= self::field_row( $name, $label, $kind, $values[ $name ] ?? '', $help, $errors[ $name ] ?? '' );
		}

		$html .= '</tbody></table>'
			. get_submit_button( __( 'Kaydet', 'ai-hazir-site' ) )
			. '<a href="' . esc_url( self::page_url( $type ) ) . '">' . esc_html__( 'Listeye dön', 'ai-hazir-site' ) . '</a>'
			. '</form>';

		return $html;
	}

	/**
	 * Company profile page.
	 */
	public function render_profile(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		echo '<div class="wrap">' . self::render_profile_form( ( new WpProfileRepository() )->get(), FormState::take() ) . DiscoveryModule::settings_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_profile_form() and settings_html().
	}

	/**
	 * Profile form; shows the personal e-mail warning when the saved address looks personal.
	 *
	 * @param CompanyProfile                                                                            $profile Stored profile.
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state Carried-over state.
	 */
	public static function render_profile_form( CompanyProfile $profile, array $state ): string {
		$values                   = $profile->to_array();
		$values['languages']      = implode( ', ', $profile->languages );
		$values['certifications'] = implode( "\n", $profile->certifications );
		$values                   = array_merge( $values, $state['input'] );

		$warnings = $state['warnings'];
		$domain   = substr( (string) strrchr( strtolower( (string) $values['contact_email'] ), '@' ), 1 );
		if ( in_array( $domain, ProfileValidator::PERSONAL_EMAIL_DOMAINS, true ) && ! in_array( ProfileValidator::PERSONAL_EMAIL_WARNING, $warnings, true ) ) {
			$warnings[] = ProfileValidator::PERSONAL_EMAIL_WARNING;
		}

		$html = '<h1>' . esc_html__( 'Firma Profili', 'ai-hazir-site' ) . '</h1>' . self::message_notice() . self::errors_notice( $state['errors'] );
		foreach ( $warnings as $warning ) {
			$html .= '<div class="notice notice-warning inline aihs-warning"><p>' . esc_html( $warning ) . '</p></div>';
		}

		$html .= '<p class="description">' . esc_html__( 'Bu bilgiler AI agentlarına yayınlanır. Kişisel bilgi (kişi adı, kişisel e-posta veya telefon) girmeyin; kurumsal iletişim bilgileri kullanın.', 'ai-hazir-site' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-profile-form">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_PROFILE ) . '">'
			. wp_nonce_field( self::SAVE_PROFILE, '_wpnonce', true, false )
			. '<table class="form-table" role="presentation"><tbody>';

		$fields = array(
			'name'           => array( __( 'Firma adı', 'ai-hazir-site' ), 'text', __( 'Zorunlu.', 'ai-hazir-site' ) ),
			'sector'         => array( __( 'Sektör', 'ai-hazir-site' ), 'text', '' ),
			'country'        => array( __( 'Ülke', 'ai-hazir-site' ), 'text', __( 'İki harfli kod, ör. TR.', 'ai-hazir-site' ) ),
			'languages'      => array( __( 'Diller', 'ai-hazir-site' ), 'text', __( 'Virgülle ayrılmış iki harfli kodlar, ör. tr, en.', 'ai-hazir-site' ) ),
			'contact_email'  => array( __( 'Kurumsal e-posta', 'ai-hazir-site' ), 'email', __( 'Ör. satis@firmaniz.com. Kişisel adres kullanmayın.', 'ai-hazir-site' ) ),
			'contact_phone'  => array( __( 'Kurumsal telefon', 'ai-hazir-site' ), 'text', '' ),
			'certifications' => array( __( 'Sertifikalar', 'ai-hazir-site' ), 'textarea', __( 'Her satıra bir sertifika.', 'ai-hazir-site' ) ),
		);
		foreach ( $fields as $name => [ $label, $kind, $help ] ) {
			$html .= self::field_row( $name, $label, $kind, $values[ $name ] ?? '', $help, $state['errors'][ $name ] ?? '' );
		}
		$html .= self::nace_row( (string) ( $values['nace'] ?? '' ), $state['errors']['nace'] ?? '' );

		$registry = TemplatesModule::registry();
		if ( null !== $registry ) {
			$options = '';
			foreach ( $registry->all() as $template ) {
				$options .= '<option value="' . esc_attr( $template->id ) . '"' . selected( (string) $values['template'], $template->id, false ) . '>' . esc_html( $template->name ) . '</option>';
			}
			$error = $state['errors']['template'] ?? '';
			$html .= '<tr><th scope="row"><label for="aihs-template">' . esc_html__( 'Sektör şablonu', 'ai-hazir-site' ) . '</label></th><td>'
				. '<select id="aihs-template" name="template">' . $options . '</select>'
				. ( '' !== $error ? '<p class="aihs-field-error" style="color:#b32d2e">' . esc_html( $error ) . '</p>' : '' )
				. '<p class="description">' . esc_html__( 'Yeni ilan formları bu şablonun alanlarını gösterir. Mevcut ilanlar kendi şablonunu korur.', 'ai-hazir-site' ) . '</p></td></tr>';
		}

		return $html . '</tbody></table>' . get_submit_button( __( 'Kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * `admin_post_aihs_save_listing`.
	 */
	public function on_save_listing(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save_listing().
		self::redirect( $this->handle_save_listing( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_delete_listing`.
	 */
	public function on_delete_listing(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in handle_delete_listing().
		self::redirect( $this->handle_delete_listing( wp_unslash( $_GET ) ) );
	}

	/**
	 * `admin_post_aihs_save_profile`.
	 */
	public function on_save_profile(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save_profile().
		self::redirect( $this->handle_save_profile( wp_unslash( $_POST ) ) );
	}

	/**
	 * Saves a listing; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_save_listing( array $post ): string {
		self::authorize( self::SAVE_LISTING );

		$type = self::text( $post, 'type' );
		if ( ! ListingType::is_valid( $type ) ) {
			wp_die( esc_html__( 'Geçersiz ilan türü.', 'ai-hazir-site' ), 400 );
		}
		$id    = absint( self::text( $post, 'id' ) );
		$input = array( 'type' => $type );
		foreach ( array( 'title', 'category', 'quantity', 'unit', 'price_min', 'price_max', 'currency', 'region', 'lead_time_days', 'valid_until' ) as $field ) {
			$input[ $field ] = sanitize_text_field( self::text( $post, $field ) );
		}
		$input['description'] = sanitize_textarea_field( self::text( $post, 'description' ) );
		$input['attributes']  = sanitize_textarea_field( self::text( $post, 'attributes' ) );
		$input['attr']        = array();
		foreach ( isset( $post['attr'] ) && is_array( $post['attr'] ) ? $post['attr'] : array() as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$input['attr'][ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		if ( null !== TemplatesModule::registry() && $id <= 0 ) {
			$input['template'] = sanitize_key( self::text( $post, 'template' ) );
		}

		// Template fields come last so they win over a same-named line in the free text.
		$save               = $input;
		$save['attributes'] = $input['attributes'];
		foreach ( $input['attr'] as $key => $value ) {
			if ( '' !== $value ) {
				$save['attributes'] .= "\n" . $key . ': ' . $value;
			}
		}
		unset( $save['attr'] );

		$result = CatalogModule::service()->save_listing( $save, $id > 0 ? $id : null );
		if ( $result->is_valid() ) {
			return self::page_url( $type, array( 'message' => 'saved' ) );
		}

		FormState::put( $result->errors, $input );
		return self::page_url( $type, $id > 0 ? array( 'edit' => $id ) : array( 'new' => 1 ) );
	}

	/**
	 * Deletes a listing; returns where to redirect.
	 *
	 * @param array<mixed> $get Unslashed $_GET.
	 */
	public function handle_delete_listing( array $get ): string {
		$id   = absint( self::text( $get, 'id' ) );
		$type = self::text( $get, 'type' );
		self::authorize( self::DELETE_LISTING . '_' . $id );

		if ( ! ListingType::is_valid( $type ) ) {
			wp_die( esc_html__( 'Geçersiz ilan türü.', 'ai-hazir-site' ), 400 );
		}
		$deleted = CatalogModule::service()->delete_listing( $id, $type );
		return self::page_url( $type, array( 'message' => $deleted ? 'deleted' : 'not_found' ) );
	}

	/**
	 * Saves the profile; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public function handle_save_profile( array $post ): string {
		self::authorize( self::SAVE_PROFILE );

		$input = array();
		foreach ( array( 'name', 'sector', 'country', 'languages', 'contact_phone' ) as $field ) {
			$input[ $field ] = sanitize_text_field( self::text( $post, $field ) );
		}
		$input['contact_email']  = sanitize_email( self::text( $post, 'contact_email' ) );
		$input['certifications'] = sanitize_textarea_field( self::text( $post, 'certifications' ) );
		$input['nace']           = strtoupper( sanitize_key( self::text( $post, 'nace' ) ) );
		if ( null !== TemplatesModule::registry() ) {
			$input['template'] = sanitize_key( self::text( $post, 'template' ) );
		}

		$result = CatalogModule::service()->save_profile( $input );
		if ( $result->is_valid() ) {
			FormState::put( array(), array(), $result->warnings );
			return self::profile_url( array( 'message' => 'saved' ) );
		}

		FormState::put( $result->errors, $input, $result->warnings );
		return self::profile_url();
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
	 * Page URL of a type.
	 *
	 * @param string                    $type Type.
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function page_url( string $type, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUGS[ $type ] ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Profile page URL.
	 *
	 * @param array<string, string|int> $args Extra query args.
	 */
	public static function profile_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PROFILE_SLUG ), $args ), admin_url( 'admin.php' ) );
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
	 * NACE Rev. 2.1 section select (1.22.0; also used by the portal business form).
	 *
	 * @param string $value Current section letter.
	 * @param string $error Error message.
	 */
	public static function nace_row( string $value, string $error ): string {
		$options = '<option value="">' . esc_html__( '— seçilmedi —', 'ai-hazir-site' ) . '</option>';
		foreach ( Nace::SECTIONS as $letter => $titles ) {
			$options .= '<option value="' . esc_attr( $letter ) . '"' . selected( $value, $letter, false ) . '>' . esc_html( Nace::label( $letter ) ) . '</option>';
		}
		return '<tr><th scope="row"><label for="aihs-nace">' . esc_html__( 'Faaliyet alanı (NACE Rev. 2.1)', 'ai-hazir-site' ) . '</label></th><td>'
			. '<select id="aihs-nace" name="nace">' . $options . '</select>'
			. ( '' !== $error ? '<p class="aihs-field-error" style="color:#b32d2e">' . esc_html( $error ) . '</p>' : '' )
			. '<p class="description">' . esc_html__( 'AB\'nin ortak faaliyet sınıflandırması: farklı dillerde ve ülkelerde aynı sektörü aynı harfle anlatır (ör. nakliye = H). İsteğe bağlı.', 'ai-hazir-site' ) . '</p></td></tr>';
	}

	/**
	 * One form row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $kind  text | textarea | number | date | email.
	 * @param mixed  $value Value.
	 * @param string $help  Help text.
	 * @param string $error Error message.
	 */
	private static function field_row( string $name, string $label, string $kind, mixed $value, string $help, string $error ): string {
		$id    = 'aihs-' . str_replace( '_', '-', $name );
		$value = is_scalar( $value ) ? (string) $value : '';
		$attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( '' !== $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '' );

		$input = 'textarea' === $kind
			? '<textarea class="large-text" rows="4"' . $attrs . '>' . esc_textarea( $value ) . '</textarea>'
			: '<input type="' . esc_attr( $kind ) . '" class="regular-text"' . $attrs . ' value="' . esc_attr( $value ) . '">';

		return '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . $input
			. ( '' !== $error ? '<p class="aihs-field-error" id="' . esc_attr( $id ) . '-error" style="color:#b32d2e">' . esc_html( $error ) . '</p>' : '' )
			. ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' )
			. '</td></tr>';
	}

	/**
	 * The template a listing form shows: the listing's own, else the requested one, else the profile's.
	 *
	 * @param Listing|null         $listing Listing being edited.
	 * @param array<string, mixed> $input   Carried-over input.
	 */
	public static function listing_template( ?Listing $listing, array $input = array() ): Template {
		$registry = TemplatesModule::registry();
		if ( null === $registry ) {
			return Template::general();
		}
		if ( null !== $listing ) {
			return $registry->get( $listing->template );
		}
		$requested = isset( $input['template'] ) && is_string( $input['template'] ) ? $input['template'] : '';
		return $registry->get( '' !== $requested ? $requested : ( new WpProfileRepository() )->get()->template );
	}

	/**
	 * Field names of a template as keys.
	 *
	 * @param Template $template Template.
	 * @return array<string, true>
	 */
	private static function field_names( Template $template ): array {
		return array_fill_keys( array_map( static fn( TemplateField $f ): string => $f->name, $template->fields ), true );
	}

	/**
	 * Form row of a template field (input named attr[<field>]).
	 *
	 * @param TemplateField $field Field.
	 * @param mixed         $value Value.
	 * @param string        $error Error message.
	 */
	private static function template_row( TemplateField $field, mixed $value, string $error ): string {
		$id    = 'aihs-attr-' . str_replace( '_', '-', $field->name );
		$value = is_scalar( $value ) ? (string) $value : '';
		$attrs = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( 'attr[' . $field->name . ']' ) . '"'
			. ( $field->required ? ' required' : '' )
			. ( '' !== $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '' );

		if ( 'enum' === $field->type ) {
			$options = '<option value="">' . esc_html__( '— Seçin —', 'ai-hazir-site' ) . '</option>';
			foreach ( $field->allowed as $allowed ) {
				$options .= '<option value="' . esc_attr( $allowed ) . '"' . selected( $value, $allowed, false ) . '>' . esc_html( $allowed ) . '</option>';
			}
			$input = '<select' . $attrs . '>' . $options . '</select>';
		} else {
			$kind  = 'date' === $field->type ? 'date' : 'text';
			$mode  = in_array( $field->type, array( 'integer', 'decimal' ), true ) ? ' inputmode="' . ( 'integer' === $field->type ? 'numeric' : 'decimal' ) . '"' : '';
			$input = '<input type="' . $kind . '" class="regular-text"' . $attrs . $mode . ' value="' . esc_attr( $value ) . '">';
		}

		$help = $field->help;
		if ( 'list' === $field->type ) {
			$help = trim( $help . ' ' . ( array() === $field->allowed ? __( 'Virgülle ayırın.', 'ai-hazir-site' ) : sprintf( /* translators: %s: allowed values. */ __( 'Virgülle ayırın; izin verilenler: %s.', 'ai-hazir-site' ), implode( ', ', $field->allowed ) ) ) );
		}
		if ( null !== $field->fresh_hours ) {
			$help = trim( $help . ' ' . sprintf( /* translators: %d: hours. */ __( 'Kaydettikten %d saat sonra AI çıktılarında "doğrulanmadı" diye işaretlenir; güncel tutmak için ilanı yeniden kaydedin.', 'ai-hazir-site' ), $field->fresh_hours ) );
		}
		$label = $field->label . ( '' !== $field->unit ? ' (' . $field->unit . ')' : '' ) . ( $field->required ? ' *' : '' );

		return '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . $input
			. ( '' !== $error ? '<p class="aihs-field-error" id="' . esc_attr( $id ) . '-error" style="color:#b32d2e">' . esc_html( $error ) . '</p>' : '' )
			. ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' )
			. '</td></tr>';
	}

	/**
	 * Summary of field errors.
	 *
	 * @param array<string, string> $errors Errors.
	 */
	private static function errors_notice( array $errors ): string {
		if ( array() === $errors ) {
			return '';
		}
		$items = implode( '', array_map( static fn( string $e ): string => '<li>' . esc_html( $e ) . '</li>', $errors ) );
		return '<div class="notice notice-error inline" id="aihs-errors"><p>' . esc_html__( 'Kaydedilmedi. Lütfen şunları düzeltin:', 'ai-hazir-site' ) . '</p><ul>' . $items . '</ul></div>';
	}

	/**
	 * Success/failure notice after a redirect.
	 */
	private static function message_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$texts   = array(
			'saved'     => array( 'success', __( 'Kaydedildi.', 'ai-hazir-site' ) ),
			'deleted'   => array( 'success', __( 'İlan silindi.', 'ai-hazir-site' ) ),
			'not_found' => array( 'error', __( 'İlan bulunamadı.', 'ai-hazir-site' ) ),
		);
		if ( ! isset( $texts[ $message ] ) ) {
			return '';
		}
		return '<div class="notice notice-' . esc_attr( $texts[ $message ][0] ) . ' is-dismissible"><p>' . esc_html( $texts[ $message ][1] ) . '</p></div>';
	}

	/**
	 * Price range as text.
	 *
	 * @param Listing $listing Listing.
	 */
	private static function price( Listing $listing ): string {
		if ( null === $listing->price_min && null === $listing->price_max ) {
			return '–';
		}
		$range = null !== $listing->price_min && null !== $listing->price_max && $listing->price_min !== $listing->price_max
			? $listing->price_min . ' – ' . $listing->price_max
			: (string) ( $listing->price_min ?? $listing->price_max );
		return trim( $range . ' ' . $listing->currency );
	}

	/**
	 * Attributes as "key: value" lines.
	 *
	 * @param array<string, string> $attributes Attributes.
	 */
	private static function attributes_text( array $attributes ): string {
		$lines = array();
		foreach ( $attributes as $key => $value ) {
			$lines[] = $key . ': ' . $value;
		}
		return implode( "\n", $lines );
	}
}
