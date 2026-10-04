<?php
/**
 * Booster AI (1.25.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Settings;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Network\NetworkSettings;
use AIHazirSite\Core\Setup\Booster;
use AIHazirSite\WordPress\Compliance\ComplianceModule;
use AIHazirSite\WordPress\I18n\LanguageSource;
use AIHazirSite\WordPress\Measurement\MeasurementModule;
use AIHazirSite\WordPress\Module;
use AIHazirSite\WordPress\Network\NetworkCatalog;
use AIHazirSite\WordPress\Network\NetworkModule;
use AIHazirSite\WordPress\Portal\Portal;
use AIHazirSite\WordPress\Updates\UpdateModule;

/**
 * One button on the settings screen turns on every suitable feature in requirement order (Core\Setup\Booster), with
 * the same per-feature work as the single switches; then sets up automatic updates (our GitHub release manifest and
 * WordPress's own plugin auto-update) and runs the first jobs in the background (AI bot IP lists, compliance scan,
 * network check). The state before is kept, so one click goes back. Booster itself has no feature key: it is a
 * settings tool that only turns keys on (approved exception, see CHANGELOG 1.25.0).
 */
final class BoosterModule implements Module {

	public const RUN      = 'aihs_booster_run';
	public const REVERT   = 'aihs_booster_revert';
	public const HOOK     = 'aihs_booster_first_run';
	public const SNAPSHOT = 'aihs_booster_snapshot';
	public const RESULT   = 'aihs_booster_result_';
	public const MANIFEST = 'https://github.com/Efesnorm/ai-hazir-site/releases/latest/download/ai-hazir-site.json';

	/**
	 * Registers hooks (the first-run job also runs from WP-Cron, outside the admin).
	 */
	public function register(): void {
		add_action( self::HOOK, array( self::class, 'first_run' ) );
		if ( is_admin() ) {
			add_action( 'admin_post_' . self::RUN, array( self::class, 'on_run' ) );
			add_action( 'admin_post_' . self::REVERT, array( self::class, 'on_revert' ) );
		}
	}

	/**
	 * Nothing scheduled to stop (the first-run job is a single event).
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The pre-ticked choice for this site.
	 *
	 * @return list<string>
	 */
	public static function preselected(): array {
		return Booster::preselected( Features::is_enabled( Features::PORTAL_MODE ) || Portal::active(), null !== LanguageSource::plugin(), NetworkSettings::ROLE_NONE !== NetworkModule::settings()->role );
	}

	/**
	 * The Booster box at the top of the settings screen (everything escaped here).
	 */
	public static function box_html(): string {
		$names  = SettingsPage::names();
		$chosen = self::preselected();
		$html   = '<div id="aihs-booster" class="notice notice-info inline" style="padding:12px 16px"><h2 style="margin-top:0">' . esc_html__( 'Booster AI', 'ai-hazir-site' ) . '</h2>'
			. '<p>' . esc_html__( 'Bu siteye uygun bütün özellikleri tek seferde, doğru sırayla açar; otomatik güncellemeyi kurar ve ilk işleri (AI bot IP listeleri, uyum taraması, ağ kontrolü) arka planda başlatır. Hiçbir özelliği kapatmaz; istediğinizde önceki duruma dönülür.', 'ai-hazir-site' ) . '</p>';

		$result = get_transient( self::RESULT . get_current_user_id() );
		delete_transient( self::RESULT . get_current_user_id() );
		if ( is_array( $result ) ) {
			$list  = static fn( array $keys ): string => array() === $keys ? '–' : implode( ', ', array_map( static fn( $k ): string => $names[ (string) $k ] ?? (string) $k, $keys ) );
			$html .= '<div id="aihs-booster-result" class="notice notice-success inline"><p><strong>' . esc_html__( 'Booster AI çalıştı.', 'ai-hazir-site' ) . '</strong></p>'
				. '<p>' . esc_html__( 'Açılanlar:', 'ai-hazir-site' ) . ' ' . esc_html( $list( (array) ( $result['turned'] ?? array() ) ) ) . '</p>'
				. '<p>' . esc_html__( 'Zaten açıktı:', 'ai-hazir-site' ) . ' ' . esc_html( $list( (array) ( $result['have'] ?? array() ) ) ) . '</p>';
			foreach ( (array) ( $result['cannot'] ?? array() ) as $key => $missing ) {
				$html .= '<p>' . esc_html(
					sprintf(
						/* translators: 1: feature, 2: features it needs. */
						__( 'Açılamadı: %1$s (önce gerekli: %2$s)', 'ai-hazir-site' ),
						$names[ (string) $key ] ?? (string) $key,
						$list( (array) $missing )
					)
				) . '</p>';
			}
			$html .= '<p>' . esc_html( ! empty( $result['updates'] ) ? __( 'Otomatik güncelleme açık: yeni sürümler WordPress tarafından kendiliğinden kurulur.', 'ai-hazir-site' ) : __( 'Otomatik güncelleme kurulmadı (Merkezi güncelleme seçilmedi).', 'ai-hazir-site' ) ) . '</p>';
			if ( Features::is_enabled( Features::PORTAL_NETWORK ) && NetworkSettings::ROLE_NONE === NetworkModule::settings()->role ) {
				$html .= '<p>' . esc_html__( 'Portal ağı açık; bu sitenin rolünü (anne / üye) Portal Ağı ekranında seçin.', 'ai-hazir-site' ) . '</p>';
			}
			$html .= '</div>';
		}

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="aihs-booster-form"><input type="hidden" name="action" value="' . esc_attr( self::RUN ) . '">' . wp_nonce_field( self::RUN, '_wpnonce', true, false )
			. '<p><strong>' . esc_html__( 'Açılacak özellikler (işaretini kaldırdığınız açılmaz):', 'ai-hazir-site' ) . '</strong></p><ul style="columns:2">';
		foreach ( Booster::candidates() as $key ) {
			$on   = Features::is_enabled( $key );
			$note = match ( $key ) {
				Features::PORTAL_MODE  => ' ' . __( '(siteyi çok işletmeli portala çevirir; firma sitesinde işaretlemeyin)', 'ai-hazir-site' ),
				Features::MULTILINGUAL => ' ' . __( '(Polylang veya WPML gerekir)', 'ai-hazir-site' ),
				Features::PORTAL_NETWORK, Features::NETWORK_SUGGESTIONS, Features::NETWORK_BLOCK, Features::NETWORK_REFERRALS, Features::NETWORK_REPORT => ' ' . __( '(yalnızca portal ağındaki siteler için)', 'ai-hazir-site' ),
				default                => '',
			};
			$html .= '<li><label><input type="checkbox" name="features[]" value="' . esc_attr( $key ) . '"' . checked( $on || in_array( $key, $chosen, true ), true, false ) . disabled( $on, true, false ) . '> ' . esc_html( ( $names[ $key ] ?? $key ) . $note ) . ( $on ? ' – ' . esc_html__( 'açık', 'ai-hazir-site' ) : '' ) . '</label></li>';
		}
		$html .= '</ul><p class="description">' . esc_html__( 'Booster\'ın açmadıkları: teklif kutusu (kişisel veri toplar; KVKK uyarısıyla kendi ekranından) ve telemetri (veri gönderir; yalnızca ayrı onayla).', 'ai-hazir-site' ) . '</p>'
			. get_submit_button( __( 'Booster AI\'ı çalıştır', 'ai-hazir-site' ), 'primary hero', 'submit', false ) . '</form>';

		if ( is_array( get_option( self::SNAPSHOT ) ) ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px"><input type="hidden" name="action" value="' . esc_attr( self::REVERT ) . '">' . wp_nonce_field( self::REVERT, '_wpnonce', true, false )
				. get_submit_button( __( 'Booster\'dan önceki duruma dön', 'ai-hazir-site' ), 'secondary', 'submit', false ) . '</form>';
		}
		return $html . '</div>';
	}

	/**
	 * Runs Booster for the posted choice; returns where to redirect.
	 *
	 * @param array<mixed> $post Unslashed $_POST.
	 */
	public static function handle_run( array $post ): string {
		self::authorize( self::RUN );
		$chosen = array();
		foreach ( is_array( $post['features'] ?? null ) ? $post['features'] : array() as $key ) {
			$chosen[] = sanitize_key( (string) $key );
		}

		// The state before, for "back to before Booster" (an earlier snapshot is kept: it is the real "before").
		if ( ! is_array( get_option( self::SNAPSHOT ) ) ) {
			$features = array();
			foreach ( Booster::candidates() as $key ) {
				$features[ $key ] = Features::is_enabled( $key );
			}
			$settings = UpdateModule::settings();
			update_option(
				self::SNAPSHOT,
				array(
					'features'    => $features,
					'server'      => $settings['server'],
					'auto_update' => self::auto_update_on(),
				),
				false
			);
		}

		[ $turn, $have, $cannot ] = Booster::plan( $chosen );
		foreach ( $turn as $key ) {
			SettingsPage::set_feature( $key, true );
		}
		$updates = Features::is_enabled( Features::REMOTE_UPDATES ) && self::enable_updates();

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK );
		}
		set_transient(
			self::RESULT . get_current_user_id(),
			array(
				'turned'  => $turn,
				'have'    => $have,
				'cannot'  => $cannot,
				'updates' => $updates,
			),
			300
		);
		return SettingsPage::url( array( 'message' => 'booster' ) ) . '#aihs-booster';
	}

	/**
	 * Back to the state before Booster: features it turned on go off (dependents first), the update settings it changed
	 * are restored. Returns where to redirect.
	 */
	public static function handle_revert(): string {
		self::authorize( self::REVERT );
		$snapshot = get_option( self::SNAPSHOT );
		if ( is_array( $snapshot ) ) {
			$before = is_array( $snapshot['features'] ?? null ) ? $snapshot['features'] : array();
			foreach ( array_reverse( Booster::candidates() ) as $key ) {
				if ( array_key_exists( $key, $before ) && ! $before[ $key ] && Features::is_enabled( $key ) ) {
					SettingsPage::set_feature( $key, false );
				}
			}
			$settings           = UpdateModule::settings();
			$settings['server'] = is_string( $snapshot['server'] ?? null ) ? $snapshot['server'] : '';
			update_option( UpdateModule::SETTINGS, $settings, false );
			if ( empty( $snapshot['auto_update'] ) ) {
				self::set_auto_update( false );
			}
			delete_option( self::SNAPSHOT );
		}
		return SettingsPage::url( array( 'message' => 'booster_reverted' ) );
	}

	/**
	 * Automatic updates: our release manifest (unless the owner set another server) and WordPress's own plugin
	 * auto-update for this plugin.
	 */
	public static function enable_updates(): bool {
		$settings = UpdateModule::settings();
		if ( '' === $settings['server'] ) {
			$settings['server'] = self::MANIFEST;
			update_option( UpdateModule::SETTINGS, $settings, false );
			delete_site_transient( UpdateModule::MANIFEST_CACHE );
		}
		self::set_auto_update( true );
		return true;
	}

	/**
	 * Whether WordPress auto-updates this plugin.
	 */
	public static function auto_update_on(): bool {
		$list = get_site_option( 'auto_update_plugins', array() );
		return is_array( $list ) && in_array( self::basename(), $list, true );
	}

	/**
	 * Turns WordPress's auto-update for this plugin on or off (the "Enable auto-updates" link's own option).
	 *
	 * @param bool $on New state.
	 */
	private static function set_auto_update( bool $on ): void {
		$list = get_site_option( 'auto_update_plugins', array() );
		$list = array_values( array_diff( is_array( $list ) ? $list : array(), array( self::basename() ) ) );
		if ( $on ) {
			$list[] = self::basename();
		}
		update_site_option( 'auto_update_plugins', $list );
	}

	/**
	 * This plugin's basename.
	 */
	private static function basename(): string {
		return defined( 'AIHS_FILE' ) ? plugin_basename( (string) AIHS_FILE ) : 'ai-hazir-site/ai-hazir-site.php';
	}

	/**
	 * Background first run: AI bot IP lists, compliance scan, network check and sibling catalogs, update check.
	 * Each job is independent; one failing never stops the others.
	 */
	public static function first_run(): void {
		$jobs = array(
			static fn() => Features::is_enabled( Features::MEASUREMENT ) ? do_action( MeasurementModule::REFRESH_HOOK ) : null, // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Our hook, by its constant.
			static fn() => Features::is_enabled( Features::COMPLIANCE_SCAN ) ? ComplianceModule::run() : null,
			static fn() => NetworkModule::enabled() && NetworkSettings::ROLE_NONE !== NetworkModule::settings()->role ? NetworkModule::check() : null,
			static fn() => NetworkCatalog::refresh(),
			static fn() => Features::is_enabled( Features::REMOTE_UPDATES ) ? delete_site_transient( 'update_plugins' ) : null,
		);
		foreach ( $jobs as $job ) {
			try {
				$job();
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}

	/**
	 * `admin_post_aihs_booster_run`.
	 */
	public static function on_run(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in handle_run().
		wp_safe_redirect( self::handle_run( wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * `admin_post_aihs_booster_revert`.
	 */
	public static function on_revert(): void {
		wp_safe_redirect( self::handle_revert() );
		exit;
	}

	/**
	 * Capability and nonce; dies on failure.
	 *
	 * @param string $action Nonce action.
	 */
	private static function authorize( string $action ): void {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-hazir-site' ), 403 );
		}
		check_admin_referer( $action );
	}
}
