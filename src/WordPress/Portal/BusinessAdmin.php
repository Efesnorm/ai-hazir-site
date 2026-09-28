<?php
/**
 * İşletmem (business user).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Portal;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\ListingType;
use AIHazirSite\Core\Portal\Business;
use AIHazirSite\WordPress\Catalog\Admin\CatalogAdmin;
use AIHazirSite\WordPress\Catalog\Admin\FormState;
use AIHazirSite\WordPress\Catalog\WpListingRepository;

/**
 * A business user's own listings: list, add, edit, delete. The business always comes from the
 * logged-in user (never from the request), and every write goes through PortalService, which
 * refuses listings of other businesses.
 */
final class BusinessAdmin {

	public const SLUG   = 'aihs-my-business';
	public const SAVE   = 'aihs_business_save_listing';
	public const DELETE = 'aihs_business_delete_listing';
	public const FIELDS = array( 'title', 'category', 'quantity', 'unit', 'price_min', 'price_max', 'currency', 'region', 'lead_time_days', 'valid_until' );

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE, array( $this, 'on_save' ) );
		add_action( 'admin_post_' . self::DELETE, array( $this, 'on_delete' ) );
	}

	/**
	 * Top-level menu for business users.
	 */
	public function add_menu(): void {
		add_menu_page( __( 'İşletmem', 'ai-hazir-site' ), __( 'İşletmem', 'ai-hazir-site' ), Portal::CAPABILITY, self::SLUG, array( $this, 'render' ), 'dashicons-store', 59 );
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
	 * Renders the page.
	 */
	public function render(): void {
		$business = self::current();
		if ( null === $business ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$edit = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		echo self::page( $business, $edit, FormState::take(), $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in page().
	}

	/**
	 * The logged-in user's business when they may manage it, else null.
	 */
	public static function current(): ?Business {
		if ( ! current_user_can( Portal::CAPABILITY ) ) {
			return null;
		}
		return Portal::user_business( get_current_user_id() );
	}

	/**
	 * Page HTML (everything escaped here).
	 *
	 * @param Business                                                                                  $business Business of the user.
	 * @param int                                                                                       $edit     Listing being edited (0 = new).
	 * @param array{errors: array<string, string>, input: array<string, mixed>, warnings: list<string>} $state    State after a failed save.
	 * @param string                                                                                    $message  Message key.
	 */
	public static function page( Business $business, int $edit, array $state, string $message = '' ): string {
		$repository = new WpListingRepository();
		$service    = Portal::service();
		$html       = '<div class="wrap"><h1>' . esc_html( $business->profile->name ) . '</h1><p><a href="' . esc_url( Portal::page_url( $business ) ) . '">' . esc_html( Portal::page_url( $business ) ) . '</a></p>';
		if ( in_array( $message, array( 'saved', 'deleted' ), true ) ) {
			$html .= '<div class="notice notice-success"><p>' . esc_html( 'saved' === $message ? __( 'Kaydedildi.', 'ai-hazir-site' ) : __( 'İlan silindi.', 'ai-hazir-site' ) ) . '</p></div>';
		}
		if ( array() !== $state['errors'] ) {
			$html .= '<div class="notice notice-error"><ul>';
			foreach ( $state['errors'] as $field => $error ) {
				$html .= '<li>' . esc_html( $field . ': ' . $error ) . '</li>';
			}
			$html .= '</ul></div>';
		}

		$labels = CatalogAdmin::type_labels();
		$html  .= '<h2>' . esc_html__( 'İlanlarım', 'ai-hazir-site' ) . '</h2><table id="aihs-my-listings" class="widefat striped"><tbody>';
		foreach ( $repository->listings_of( (int) $business->id ) as $id ) {
			$listing = $repository->find( $id );
			if ( null === $listing ) {
				continue;
			}
			$delete = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::DELETE,
						'id'     => $id,
					),
					admin_url( 'admin-post.php' )
				),
				self::DELETE . '_' . $id
			);
			$html  .= '<tr data-listing="' . esc_attr( (string) $id ) . '"><td>' . esc_html( $listing->title ) . '</td><td>' . esc_html( $labels[ $listing->type ] ?? $listing->type ) . '</td><td><a href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Düzenle', 'ai-hazir-site' ) . '</a> | <a href="' . esc_url( $delete ) . '">' . esc_html__( 'Sil', 'ai-hazir-site' ) . '</a></td></tr>';
		}
		$html .= '</tbody></table>';

		$listing = $edit > 0 && $service->owns( (int) $business->id, $edit ) ? $repository->find( $edit ) : null;
		return $html . self::form( $listing, $state['input'] ) . '</div>';
	}

	/**
	 * Add / edit form.
	 *
	 * @param Listing|null         $listing Listing being edited.
	 * @param array<string, mixed> $input   Values of a failed save.
	 */
	private static function form( ?Listing $listing, array $input ): string {
		$values = null === $listing ? array( 'type' => ListingType::OFFER ) : $listing->to_array();
		$values = array_merge( $values, $input );
		$value  = static fn( string $key ): string => is_scalar( $values[ $key ] ?? null ) ? (string) $values[ $key ] : '';
		$labels = array(
			'title'          => __( 'Başlık', 'ai-hazir-site' ),
			'category'       => __( 'Kategori', 'ai-hazir-site' ),
			'quantity'       => __( 'Miktar', 'ai-hazir-site' ),
			'unit'           => __( 'Birim', 'ai-hazir-site' ),
			'price_min'      => __( 'Fiyat (en az)', 'ai-hazir-site' ),
			'price_max'      => __( 'Fiyat (en çok)', 'ai-hazir-site' ),
			'currency'       => __( 'Para birimi (ör. TRY)', 'ai-hazir-site' ),
			'region'         => __( 'Bölge', 'ai-hazir-site' ),
			'lead_time_days' => __( 'Teslim süresi (gün)', 'ai-hazir-site' ),
			'valid_until'    => __( 'Geçerlilik (YYYY-AA-GG)', 'ai-hazir-site' ),
		);
		$types  = '';
		foreach ( CatalogAdmin::type_labels() as $type => $label ) {
			$types .= '<option value="' . esc_attr( $type ) . '"' . selected( $value( 'type' ), $type, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html = '<h2>' . esc_html( null === $listing ? __( 'Yeni ilan', 'ai-hazir-site' ) : __( 'İlanı düzenle', 'ai-hazir-site' ) ) . '</h2>'
			. '<form id="aihs-my-listing-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) ( $listing->id ?? 0 ) ) . '">' . wp_nonce_field( self::SAVE, '_wpnonce', true, false )
			. '<table class="form-table"><tbody><tr><th>' . esc_html__( 'Tür', 'ai-hazir-site' ) . '</th><td><select name="type"' . ( null === $listing ? '' : ' disabled' ) . '>' . $types . '</select></td></tr>';
		foreach ( $labels as $name => $label ) {
			$html .= '<tr><th><label for="aihs-my-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input id="aihs-my-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text" value="' . esc_attr( $value( $name ) ) . '"></td></tr>';
		}
		$html .= '<tr><th><label for="aihs-my-description">' . esc_html__( 'Açıklama', 'ai-hazir-site' ) . '</label></th><td><textarea id="aihs-my-description" name="description" rows="4" class="large-text">' . esc_textarea( $value( 'description' ) ) . '</textarea></td></tr>';
		return $html . '</tbody></table>' . get_submit_button( __( 'Kaydet', 'ai-hazir-site' ) ) . '</form>';
	}

	/**
	 * `admin_post_aihs_business_save_listing`.
	 */
	public function on_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_save().
		self::redirect( self::handle_save( wp_unslash( $_POST ) ) );
	}

	/**
	 * `admin_post_aihs_business_delete_listing`.
	 */
	public function on_delete(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in handle_delete().
		self::redirect( self::handle_delete( wp_unslash( $_GET ) ) );
	}

	/**
	 * Saves one of the user's listings; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_save( array $post ): string {
		$business = self::authorize( self::SAVE );
		$id       = absint( self::text( $post, 'id' ) );
		$input    = array();
		foreach ( self::FIELDS as $field ) {
			$input[ $field ] = sanitize_text_field( self::text( $post, $field ) );
		}
		$input['description'] = sanitize_textarea_field( self::text( $post, 'description' ) );
		if ( $id > 0 ) {
			$existing      = ( new WpListingRepository() )->find( $id );
			$input['type'] = null === $existing ? '' : $existing->type;
		} else {
			$input['type'] = sanitize_key( self::text( $post, 'type' ) );
		}

		$result = Portal::service()->save_business_listing( (int) $business->id, $input, $id > 0 ? $id : null );
		if ( $result->is_valid() ) {
			return self::url( array( 'message' => 'saved' ) );
		}
		FormState::put( $result->errors, $input );
		return self::url( $id > 0 ? array( 'edit' => $id ) : array() );
	}

	/**
	 * Deletes one of the user's listings; returns where to redirect.
	 *
	 * @param array<mixed> $get Unslashed $_GET.
	 */
	public static function handle_delete( array $get ): string {
		$id       = absint( self::text( $get, 'id' ) );
		$business = self::authorize( self::DELETE . '_' . $id );
		if ( ! Portal::service()->delete_business_listing( (int) $business->id, $id ) ) {
			wp_die( esc_html__( 'Bu ilan işletmenize ait değil.', 'ai-hazir-site' ), 403 );
		}
		return self::url( array( 'message' => 'deleted' ) );
	}

	/**
	 * Capability, business and nonce check; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	private static function authorize( string $action ): Business {
		$business = self::current();
		if ( null === $business ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( $action );
		return $business;
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
