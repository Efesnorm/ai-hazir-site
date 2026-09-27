<?php
/**
 * What the wizard needs to know about the site right now.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Wizard;

/**
 * Filled by the platform adapter; the planner decides from it which steps are still open
 * and which gaps can only be explained.
 */
final class SiteState {

	/**
	 * Constructor.
	 *
	 * @param array<string, bool> $features         Feature key → enabled.
	 * @param bool                $has_profile      Whether a company profile is stored.
	 * @param int                 $listings         Number of stored listings.
	 * @param bool                $physical_robots  Whether a physical robots.txt hides the virtual one.
	 * @param bool                $physical_llms    Whether a physical llms.txt hides the virtual one.
	 * @param bool                $search_visible   WordPress "discourage search engines" is off.
	 * @param bool                $robots_blocks_ai Whether robots.txt currently blocks any known AI bot.
	 * @param bool                $robots_fixable   Whether the AI bot access setting ("allow all") would unblock them
	 *                                              (not when another plugin or the theme blocks a bot by name).
	 */
	public function __construct(
		public readonly array $features = array(),
		public readonly bool $has_profile = false,
		public readonly int $listings = 0,
		public readonly bool $physical_robots = false,
		public readonly bool $physical_llms = false,
		public readonly bool $search_visible = true,
		public readonly bool $robots_blocks_ai = false,
		public readonly bool $robots_fixable = true
	) {
	}

	/**
	 * Whether a feature is on.
	 *
	 * @param string $key Feature key.
	 */
	public function on( string $key ): bool {
		return $this->features[ $key ] ?? false;
	}
}
