<?php
/**
 * Plugin Name: AI Hazır Site – yerel e-posta kaydedici (yalnızca geliştirme)
 * Description: wp-env geliştirme ortamında e-posta sunucusu olmadığı için giden e-postaları göndermek yerine wp-content/mails/ klasörüne dosya olarak yazar. Eklenti paketine girmez; yalnızca .wp-env.json geliştirme eşlemesiyle yüklenir.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

// The integration tests also run in the development container: leave e-mail to the test suite's mock mailer there.
if ( defined( 'WP_TESTS_DOMAIN' ) ) {
	return;
}

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, array $atts ) {
		$dir = WP_CONTENT_DIR . '/mails';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$to      = is_array( $atts['to'] ?? null ) ? implode( ', ', $atts['to'] ) : (string) ( $atts['to'] ?? '' );
		$content = 'To: ' . $to . "\nSubject: " . (string) ( $atts['subject'] ?? '' ) . "\nDate: " . gmdate( 'c' ) . "\n\n" . (string) ( $atts['message'] ?? '' ) . "\n";
		file_put_contents( $dir . '/' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.txt', $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Development only.
		return true;
	},
	10,
	2
);
