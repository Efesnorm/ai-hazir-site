<?php
/**
 * Clock port.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Contracts;

/**
 * Current time in the site's timezone.
 */
interface Clock {

	/**
	 * Today's date (Y-m-d) in the site's timezone.
	 */
	public function today(): string;

	/**
	 * Current Unix timestamp.
	 */
	public function now(): int;
}
