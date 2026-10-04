<?php
/**
 * Company profile validation.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

use AIHazirSite\Core\Templates\Template;
use AIHazirSite\Core\Templates\TemplateRegistry;

/**
 * Validates the profile and warns when the contact e-mail looks personal
 * (a free mailbox provider) instead of corporate.
 */
final class ProfileValidator {

	/**
	 * Free mailbox providers; an address on these domains is usually personal.
	 */
	public const PERSONAL_EMAIL_DOMAINS = array( 'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.com.tr', 'outlook.com', 'outlook.com.tr', 'live.com', 'msn.com', 'yahoo.com', 'yahoo.com.tr', 'ymail.com', 'yandex.com', 'yandex.com.tr', 'yandex.ru', 'icloud.com', 'me.com', 'mail.com', 'mail.ru', 'aol.com', 'gmx.com', 'gmx.de', 'proton.me', 'protonmail.com', 'zoho.com' );

	public const PERSONAL_EMAIL_WARNING = 'İletişim e-postası kişisel bir adres gibi görünüyor. AI agentlarına yayınlanacağı için kurumsal bir adres (ör. satis@firmaniz.com) kullanın.';

	/**
	 * Constructor.
	 *
	 * @param TemplateRegistry|null $templates Known sector templates; null when templates are off
	 *                                         (the id is then only format-checked, so a stored choice survives).
	 */
	public function __construct( private readonly ?TemplateRegistry $templates = null ) {
	}

	/**
	 * Validates input.
	 *
	 * @param array<string, mixed> $input Raw input; lists as arrays or comma/line separated strings.
	 */
	public function validate( array $input ): ValidationResult {
		$text     = static fn( string $k ): string => isset( $input[ $k ] ) && is_scalar( $input[ $k ] ) ? trim( (string) $input[ $k ] ) : '';
		$errors   = array();
		$warnings = array();

		$name = $text( 'name' );
		if ( '' === $name ) {
			$errors['name'] = 'Firma adı boş olamaz.';
		} elseif ( mb_strlen( $name ) > 200 ) {
			$errors['name'] = 'Firma adı en fazla 200 karakter olabilir.';
		}

		$country = strtoupper( $text( 'country' ) );
		if ( '' !== $country && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$errors['country'] = 'Ülke iki harfli ISO 3166-1 kodu olmalı (ör. TR).';
		}

		$languages = array_map( 'strtolower', self::list( $input['languages'] ?? array() ) );
		foreach ( $languages as $language ) {
			if ( ! preg_match( '/^[a-z]{2}$/', $language ) ) {
				$errors['languages'] = 'Diller iki harfli ISO 639-1 kodları olmalı (ör. tr, en).';
			}
		}

		$email = strtolower( $text( 'contact_email' ) );
		if ( '' !== $email ) {
			if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$errors['contact_email'] = 'Geçerli bir e-posta adresi girin.';
			} elseif ( in_array( substr( (string) strrchr( $email, '@' ), 1 ), self::PERSONAL_EMAIL_DOMAINS, true ) ) {
				$warnings[] = self::PERSONAL_EMAIL_WARNING;
			}
		}

		$phone = $text( 'contact_phone' );
		if ( '' !== $phone && ( ! preg_match( '/^\+?[\d\s().\/-]+$/', $phone ) || strlen( (string) preg_replace( '/\D/', '', $phone ) ) < 7 ) ) {
			$errors['contact_phone'] = 'Telefon yalnızca rakam, boşluk ve + ( ) - içerebilir; en az 7 rakam.';
		}

		$certifications = array_map( static fn( string $c ): string => mb_substr( $c, 0, 100 ), self::list( $input['certifications'] ?? array(), "\n" ) );
		if ( count( $certifications ) > 20 ) {
			$errors['certifications'] = 'En fazla 20 sertifika girilebilir.';
		}

		$template = '' === $text( 'template' ) ? Template::GENERAL : $text( 'template' );
		if ( null === $this->templates ) {
			$template = preg_match( Template::ID, $template ) ? $template : Template::GENERAL;
		} elseif ( ! $this->templates->has( $template ) ) {
			$errors['template'] = 'Bilinmeyen sektör şablonu.';
		}

		// 1.22.0: optional NACE Rev. 2.1 section.
		$nace = Nace::section( $input['nace'] ?? '' );
		if ( '' === $nace && '' !== $text( 'nace' ) ) {
			$errors['nace'] = 'Bilinmeyen faaliyet alanı (NACE Rev. 2.1 bölüm harfi A–V).';
		}

		if ( array() !== $errors ) {
			return new ValidationResult( null, $errors, $warnings );
		}

		return new ValidationResult(
			new CompanyProfile( $name, $text( 'sector' ), $country, array_values( array_unique( $languages ) ), $email, $phone, $certifications, $template, $nace ),
			array(),
			$warnings
		);
	}

	/**
	 * Non-empty trimmed items from an array or a separated string.
	 *
	 * @param mixed  $raw       Raw value.
	 * @param string $separator Extra separator besides new lines (',' by default).
	 * @return list<string>
	 */
	private static function list( mixed $raw, string $separator = ',' ): array {
		$items = is_array( $raw ) ? $raw : explode( "\n", str_replace( $separator, "\n", is_scalar( $raw ) ? (string) $raw : '' ) );
		$clean = array();
		foreach ( $items as $item ) {
			$item = is_scalar( $item ) ? trim( (string) $item ) : '';
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}
		return $clean;
	}
}
