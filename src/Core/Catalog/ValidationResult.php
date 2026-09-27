<?php
/**
 * Outcome of validating (and possibly saving) catalog input.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Catalog;

/**
 * Field errors block saving; warnings do not.
 */
final class ValidationResult {

	/**
	 * Constructor.
	 *
	 * @param Listing|CompanyProfile|null $value    Valid value (null when there are errors).
	 * @param array<string, string>       $errors   Field → message.
	 * @param string[]                    $warnings Non-blocking notes.
	 *
	 * @phpstan-param list<string> $warnings
	 */
	public function __construct(
		public readonly Listing|CompanyProfile|null $value,
		public readonly array $errors = array(),
		public readonly array $warnings = array()
	) {
	}

	/**
	 * Whether there are no errors.
	 */
	public function is_valid(): bool {
		return array() === $this->errors && null !== $this->value;
	}

	/**
	 * The listing, when valid.
	 */
	public function listing(): ?Listing {
		return $this->value instanceof Listing ? $this->value : null;
	}

	/**
	 * The profile, when valid.
	 */
	public function profile(): ?CompanyProfile {
		return $this->value instanceof CompanyProfile ? $this->value : null;
	}

	/**
	 * Same result with another value (e.g. after storage assigned an id).
	 *
	 * @param Listing|CompanyProfile $value Value.
	 */
	public function with_value( Listing|CompanyProfile $value ): self {
		return new self( $value, $this->errors, $this->warnings );
	}
}
