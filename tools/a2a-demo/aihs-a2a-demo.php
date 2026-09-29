<?php
/**
 * Plugin Name: AI Hazır Site – A2A demo ortamı
 * Description: Yalnızca tools/a2a-demo içindir. *.test adlarını dış adres sayar ve yerel CA sertifikasını doğrulamaz.
 *
 * WordPress'in güvenli HTTP işlevleri (wp_safe_remote_*) özel ağ adreslerine istek atmaz ve Caddy'nin yerel
 * sertifika yetkilisi kapsayıcılarda tanınmaz. Demo iki siteyi gerçek HTTPS ile konuşturabilsin diye, yalnızca
 * WP_ENVIRONMENT_TYPE "local" iken ve yalnızca .test alan adları için bu iki kural gevşetilir. Eklenti kodu değişmez.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

if ( 'local' === wp_get_environment_type() ) {
	$aihs_demo_host = static fn( string $url ): string => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

	add_filter(
		'http_request_host_is_external',
		static fn( bool $external, string $host ): bool => $external || str_ends_with( strtolower( $host ), '.test' ),
		10,
		2
	);

	add_filter(
		'http_request_args',
		static function ( array $args, string $url ) use ( $aihs_demo_host ): array {
			if ( str_ends_with( $aihs_demo_host( $url ), '.test' ) ) {
				$args['sslverify'] = false;
			}
			return $args;
		},
		10,
		2
	);
}
