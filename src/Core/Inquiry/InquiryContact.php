<?php
/**
 * Contact details of an inquiry (personal data).
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Inquiry;

/**
 * Personal data, kept only so the company can answer: stored encrypted, deleted when the
 * retention period ends (default 180 days) or on request. Approved exception to "no personal
 * data" (0.12.0).
 */
final class InquiryContact {

	/**
	 * Constructor.
	 *
	 * @param string $name    Contact person.
	 * @param string $company Company.
	 * @param string $email   E-mail.
	 * @param string $phone   Phone.
	 */
	public function __construct(
		public readonly string $name = '',
		public readonly string $company = '',
		public readonly string $email = '',
		public readonly string $phone = ''
	) {
	}

	/**
	 * Plain array.
	 *
	 * @return array{name: string, company: string, email: string, phone: string}
	 */
	public function to_array(): array {
		return array(
			'name'    => $this->name,
			'company' => $this->company,
			'email'   => $this->email,
			'phone'   => $this->phone,
		);
	}

	/**
	 * From a plain array.
	 *
	 * @param mixed $data Data.
	 */
	public static function from_array( mixed $data ): self {
		$data   = is_array( $data ) ? $data : array();
		$string = static fn( string $k ): string => isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ? trim( (string) $data[ $k ] ) : '';
		return new self( $string( 'name' ), $string( 'company' ), $string( 'email' ), $string( 'phone' ) );
	}
}
