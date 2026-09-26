<?php
/**
 * Fixed Clock for tests.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\Clock;

/**
 * A clock that always returns the given day.
 */
final class FixedClock implements Clock {

	/**
	 * Constructor.
	 *
	 * @param string $day Y-m-d.
	 */
	public function __construct( public string $day = '2026-09-27' ) {
	}

	/**
	 * The fixed day.
	 */
	public function today(): string {
		return $this->day;
	}

	/**
	 * Noon UTC of the fixed day.
	 */
	public function now(): int {
		return (int) strtotime( $this->day . ' 12:00:00 UTC' );
	}
}
