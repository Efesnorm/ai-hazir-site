<?php
/**
 * Dismissible information notices (1.15.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Admin;

/**
 * An information notice is shown on the plugin's own screens only and each administrator can hide it
 * for good ("Bir daha gösterme": admin-post.php with a nonce; the choice is kept in the user's meta).
 * Error notices, which ask for action, are not handled here.
 */
final class AdminNotices {

	/**
	 * User meta holding the dismissed notice ids.
	 */
	public const META = 'aihs_dismissed_notices';

	/**
	 * Action of the admin-post.php handler.
	 */
	public const DISMISS = 'aihs_dismiss_notice';

	/**
	 * Notice: an SEO plugin already outputs the Organization schema.
	 */
	public const SEO_CONFLICT = 'seo_conflict';

	/**
	 * Notices that can be dismissed.
	 */
	public const IDS = array( self::SEO_CONFLICT );

	/**
	 * Capability needed to dismiss.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Registers the dismiss handler.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::DISMISS, array( self::class, 'on_dismiss' ) );
	}

	/**
	 * Whether a notice is shown to the current user on the current screen.
	 *
	 * @param string $id Notice id.
	 */
	public static function visible( string $id ): bool {
		return AdminMenu::is_own_screen() && ! self::dismissed( $id, get_current_user_id() );
	}

	/**
	 * Whether a user has dismissed a notice.
	 *
	 * @param string $id      Notice id.
	 * @param int    $user_id User id.
	 */
	public static function dismissed( string $id, int $user_id ): bool {
		$ids = get_user_meta( $user_id, self::META, true );
		return is_array( $ids ) && in_array( $id, $ids, true );
	}

	/**
	 * "Bir daha gösterme" link.
	 *
	 * @param string $id Notice id.
	 */
	public static function dismiss_url( string $id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => self::DISMISS, 'notice' => $id ), admin_url( 'admin-post.php' ) ), self::DISMISS . '_' . $id ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Records a dismissal for the current user.
	 *
	 * @param string $id Notice id.
	 * @return bool False for an unknown notice or without the capability.
	 */
	public static function dismiss( string $id ): bool {
		if ( ! in_array( $id, self::IDS, true ) || ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		$ids     = get_user_meta( $user_id, self::META, true );
		$ids     = is_array( $ids ) ? array_values( array_filter( $ids, 'is_string' ) ) : array();
		if ( ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
			update_user_meta( $user_id, self::META, $ids );
		}
		return true;
	}

	/**
	 * Handler of the admin-post.php action: capability and nonce, then back to where the user was.
	 */
	public static function on_dismiss(): void {
		$id = isset( $_GET['notice'] ) && is_string( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified next.
		check_admin_referer( self::DISMISS . '_' . $id );
		if ( ! self::dismiss( $id ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : AdminMenu::url( AdminMenu::PARENT ) );
		exit;
	}
}
