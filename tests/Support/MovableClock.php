<?php
/**
 * A clock the test moves.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Support;

use AIHazirSite\Core\Contracts\Clock;

/**
 * Starts at 2026-09-27 12:00:00 UTC; `advance()` moves it forward.
 */
final class MovableClock implements Clock {

	/**
	 * Unix time.
	 *
	 * @var int
	 */
	public int $time;

	/**
	 * Constructor.
	 *
	 * @param string $at ISO 8601 UTC start time.
	 */
	public function __construct( string $at = '2026-09-27T12:00:00Z' ) {
		$this->time = (int) strtotime( $at );
	}

	/**
	 * Moves the clock forward.
	 *
	 * @param int $seconds Seconds.
	 */
	public function advance( int $seconds ): void {
		$this->time += $seconds;
	}

	/**
	 * Day.
	 */
	public function today(): string {
		return gmdate( 'Y-m-d', $this->time );
	}

	/**
	 * Time.
	 */
	public function now(): int {
		return $this->time;
	}
}
