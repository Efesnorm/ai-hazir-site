<?php
/**
 * Security software that can slow down or block AI bots and the portal network (1.21.0).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\WordPress\Integrations;

use AIHazirSite\Core\Features;
use AIHazirSite\Core\Measurement\Registry;
use AIHazirSite\Core\Security\WpConfigBlock;
use AIHazirSite\WordPress\Network\NetworkModule;

/**
 * Detection and the few documented actions a site owner may take:
 * - Imunify Security (plugin `imunify-wp-security`): AI Bot Management preset through the documented wp-config.php
 *   constant IMUNIFY_AI_BOT_PROTECTION_PRESET; we only ever write "balanced" (never "monitor" or off). Server-level
 *   Imunify360 lists are root-only: a ready text for the host is offered instead.
 * - Wordfence: the public `wordfence::whitelistIP()` for the verified network sites' observed server IPs (never for AI
 *   bot ranges: an allow-listed IP skips every firewall rule). Wordfence has no public removal function, so what we added
 *   is listed for manual removal.
 * - Cloudflare and other security plugins: detection and guidance only.
 */
final class SecuritySoftware {

	public const OPTION         = 'aihs_security';
	public const IMUNIFY_PLUGIN = 'imunify-wp-security';
	public const IMUNIFY_PRESET = 'IMUNIFY_AI_BOT_PROTECTION_PRESET';
	public const IMUNIFY_SWITCH = 'IMUNIFY_AI_BOT_PROTECTION';
	public const LOCKED_PRESET  = 'balanced';

	/**
	 * Wordfence's public allow-list function (wordfence 9.x: `wordfence::whitelistIP( string $ip )`).
	 */
	public const WORDFENCE_ALLOW = array( 'wordfence', 'whitelistIP' );

	/**
	 * Imunify presets → requests per minute (verified search engines, verified AI crawlers, unknown automated, unverified
	 * bots); null = not enforced. From the Imunify Security site-owner guide.
	 */
	public const PRESETS = array(
		'balanced' => array( 300, 10, 5, 2 ),
		'strict'   => array( 300, 3, 2, 1 ),
		'monitor'  => null,
	);

	/**
	 * Other security plugins (directory → name), detection and guidance only.
	 */
	public const OTHERS = array(
		'better-wp-security'                  => 'Solid Security',
		'all-in-one-wp-security-and-firewall' => 'All-In-One Security',
		'ninjafirewall'                       => 'NinjaFirewall',
		'sucuri-scanner'                      => 'Sucuri Security',
	);

	/**
	 * What is present on this site.
	 *
	 * @return array{imunify: bool, wordfence: bool, cloudflare: bool, others: list<string>}
	 */
	public static function detect(): array {
		$others = array();
		foreach ( self::OTHERS as $dir => $name ) {
			if ( self::active( $dir ) ) {
				$others[] = $name;
			}
		}
		return array(
			'imunify'    => self::active( self::IMUNIFY_PLUGIN ) || defined( self::IMUNIFY_PRESET ) || defined( self::IMUNIFY_SWITCH ),
			'wordfence'  => is_callable( self::WORDFENCE_ALLOW ),
			'cloudflare' => isset( $_SERVER['HTTP_CF_RAY'] ) || isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ),
			'others'     => $others,
		);
	}

	/**
	 * Whether a plugin directory is active (site-wide on multisite too).
	 *
	 * @param string $dir Plugin directory.
	 */
	public static function active( string $dir ): bool {
		$plugins = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && str_starts_with( $plugin, $dir . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The preset fixed in wp-config.php ('' when the widget decides).
	 */
	public static function imunify_preset(): string {
		return defined( self::IMUNIFY_PRESET ) ? (string) constant( self::IMUNIFY_PRESET ) : '';
	}

	/**
	 * Stored state: whether our wp-config block is in place, the last write problem, IPs we added to Wordfence.
	 *
	 * @return array{imunify_locked: bool, imunify_error: string, wordfence: list<string>}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'imunify_locked' => true === ( $state['imunify_locked'] ?? false ),
			'imunify_error'  => is_string( $state['imunify_error'] ?? null ) ? $state['imunify_error'] : '',
			'wordfence'      => array_values( array_filter( (array) ( $state['wordfence'] ?? array() ), 'is_string' ) ),
		);
	}

	/**
	 * The line we put into wp-config.php.
	 */
	public static function imunify_line(): string {
		return "define( '" . self::IMUNIFY_PRESET . "', '" . self::LOCKED_PRESET . "' );";
	}

	/**
	 * Fixes Imunify AI Bot Management at "balanced" (true) or removes our line (false).
	 *
	 * @param bool $on New state.
	 * @return string '' on success, else the reason (the screen then shows the line to add by hand).
	 */
	public static function set_imunify_lock( bool $on ): string {
		$error                   = self::rewrite_config(
			static function ( string $source ) use ( $on ): ?string {
				if ( ! $on ) {
					return WpConfigBlock::remove( $source );
				}
				if ( WpConfigBlock::defined_outside( $source, self::IMUNIFY_PRESET ) || WpConfigBlock::defined_outside( $source, self::IMUNIFY_SWITCH ) ) {
					return null;
				}
				return WpConfigBlock::with( $source, array( self::imunify_line() . ' // Imunify AI Bot Management; AI Hazir Site > Entegrasyonlar.' ) );
			}
		);
		$state                   = self::state();
		$state['imunify_locked'] = '' === $error ? $on : $state['imunify_locked'];
		$state['imunify_error']  = $error;
		update_option( self::OPTION, $state, false );
		return $error;
	}

	/**
	 * Reads wp-config.php, applies the transform and writes it back atomically (temporary file + move) with WordPress's
	 * own filesystem API. Only when the filesystem is directly writable; never leaves a copy of the file behind.
	 *
	 * @param callable $transform fn( string ): ?string – null means "do not write".
	 * @return string '' on success or when nothing changes, else the reason.
	 *
	 * @phpstan-param callable(string): ?string $transform
	 */
	private static function rewrite_config( callable $transform ): string {
		$path = self::config_path();
		if ( null === $path ) {
			return __( 'wp-config.php bulunamadı.', 'ai-hazir-site' );
		}
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method( array(), dirname( $path ) ) || ! WP_Filesystem() ) {
			return __( 'Dosya sistemi doğrudan yazılamıyor.', 'ai-hazir-site' );
		}
		global $wp_filesystem;
		$source = $wp_filesystem->get_contents( $path );
		if ( ! is_string( $source ) || '' === $source ) {
			return __( 'wp-config.php okunamadı.', 'ai-hazir-site' );
		}
		$result = $transform( $source );
		if ( null === $result ) {
			return __( 'Sabit zaten sitede tanımlı ya da dosya beklenen biçimde değil; dosyaya dokunulmadı.', 'ai-hazir-site' );
		}
		if ( $result === $source ) {
			return '';
		}
		$temp = dirname( $path ) . '/wp-config-aihs-' . wp_generate_password( 12, false ) . '.php';
		$mode = fileperms( $path );
		if ( ! $wp_filesystem->put_contents( $temp, $result, false === $mode ? FS_CHMOD_FILE : ( $mode & 0777 ) ) ) {
			$wp_filesystem->delete( $temp );
			return __( 'Geçici dosya yazılamadı; wp-config.php değişmedi.', 'ai-hazir-site' );
		}
		if ( ! $wp_filesystem->move( $temp, $path, true ) ) {
			$wp_filesystem->delete( $temp );
			return __( 'wp-config.php değiştirilemedi; dosya olduğu gibi duruyor.', 'ai-hazir-site' );
		}
		return '';
	}

	/**
	 * Path of wp-config.php the way WordPress finds it (site root, or one level up), or null.
	 */
	public static function config_path(): ?string {
		$path = (string) apply_filters( 'aihs_wp_config_path', '' );
		if ( '' !== $path ) {
			return is_file( $path ) ? $path : null;
		}
		if ( is_file( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return is_file( $up ) && ! is_file( dirname( ABSPATH ) . '/wp-settings.php' ) ? $up : null;
	}

	/**
	 * Observed server IPs of the verified network sites (URL → IP), from the portal network.
	 *
	 * @return array<string, string>
	 */
	public static function network_ips(): array {
		if ( ! NetworkModule::enabled() ) {
			return array();
		}
		$view    = NetworkModule::view();
		$allowed = array_merge( array( $view->mother() ), array_column( $view->siblings(), 'url' ) );
		$seen    = NetworkModule::state()['seen'] ?? array();
		$ips     = array();
		foreach ( is_array( $seen ) ? $seen : array() as $url => $ip ) {
			if ( in_array( $url, $allowed, true ) && is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$ips[ (string) $url ] = $ip;
			}
		}
		return $ips;
	}

	/**
	 * Adds the network sites' IPs to Wordfence's allow-list with its public function; returns the IPs added now.
	 *
	 * @return list<string>
	 */
	public static function allow_network_in_wordfence(): array {
		if ( ! self::detect()['wordfence'] ) {
			return array();
		}
		$state = self::state();
		$added = array();
		foreach ( self::network_ips() as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				continue; // Wordfence's function takes dotted-quad IPv4.
			}
			try {
				if ( false !== call_user_func( self::WORDFENCE_ALLOW, $ip ) ) {
					$added[] = $ip;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! in_array( $ip, $state['wordfence'], true ) ) {
				$state['wordfence'][] = $ip;
			}
		}
		update_option( self::OPTION, $state, false );
		return $added;
	}

	/**
	 * Official AI bot IP list addresses (from the bot list the measurement already uses).
	 *
	 * @return list<string>
	 */
	public static function ai_ip_sources(): array {
		$urls = array();
		foreach ( Registry::bots() as $bot ) {
			if ( 'ip_ranges' === $bot->verify && str_starts_with( $bot->verify_source, 'https://' ) && ! in_array( $bot->verify_source, $urls, true ) ) {
				$urls[] = $bot->verify_source;
			}
		}
		return $urls;
	}

	/**
	 * Text for the hosting company (server-level WAF and IP lists are theirs).
	 */
	public static function host_text(): string {
		$lines = array(
			__( 'Merhaba, sitemiz için AI tarayıcılarının ve kendi sitelerimiz arasındaki isteklerin sunucu güvenlik katmanında (Imunify360 / WAF / hız sınırı) engellenmemesini ya da yavaşlatılmamasını rica ediyoruz.', 'ai-hazir-site' ),
			'',
			__( '1. AI tarayıcılarının resmi IP listeleri (sağlayıcıların yayımladığı; düzenli güncellenir):', 'ai-hazir-site' ),
		);
		foreach ( self::ai_ip_sources() as $url ) {
			$lines[] = '- ' . $url;
		}
		$ips = self::network_ips();
		if ( array() !== $ips ) {
			$lines[] = '';
			$lines[] = __( '2. Kendi sitelerimizin sunucu adresleri (portal ağı; birbirlerinin herkese açık AI Katalog API\'sini saatte bir okurlar):', 'ai-hazir-site' );
			foreach ( $ips as $url => $ip ) {
				$lines[] = '- ' . $ip . ' (' . $url . ')';
			}
		}
		$lines[] = '';
		$lines[] = __( 'Bu adresleri kötü niyetli bot sınıfında değil, doğrulanmış tarayıcı olarak değerlendirmenizi; 429 yanıtlarına uzun önbellek süresi vermemenizi rica ederiz.', 'ai-hazir-site' );
		return implode( "\n", $lines );
	}

	/**
	 * Feature off or uninstall: our wp-config block goes (Wordfence entries cannot be removed by a public function).
	 */
	public static function revert(): void {
		$path = self::config_path();
		if ( null !== $path && is_readable( $path ) && WpConfigBlock::present( (string) file_get_contents( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, read only.
			self::set_imunify_lock( false );
		}
	}

	/**
	 * Turns the feature on or off (off reverts our wp-config block).
	 *
	 * @param bool $on New state.
	 */
	public static function enable( bool $on ): void {
		Features::set( Features::SECURITY_INTEGRATIONS, $on );
		if ( ! $on ) {
			self::revert();
		}
	}
}
