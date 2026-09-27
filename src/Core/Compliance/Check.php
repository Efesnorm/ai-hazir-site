<?php
/**
 * One compliance criterion.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

/**
 * A self-contained check. Checks never call each other; adding one only means
 * adding it to the list given to {@see Scanner}.
 */
interface Check {

	/**
	 * Stable identifier, e.g. "llms_txt".
	 */
	public function id(): string;

	/**
	 * Share of the 100-point score.
	 */
	public function weight(): int;

	/**
	 * Evaluates the site.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult;
}
