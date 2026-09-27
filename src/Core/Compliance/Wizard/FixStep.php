<?php
/**
 * One step of the compliance wizard.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Wizard;

/**
 * Either an action the wizard can apply with the plugin's own settings and data
 * (turn a feature on, save the profile, add the first listing, choose a bot preset),
 * or a manual step with instructions (theme, other plugins, server, WordPress settings).
 */
final class FixStep {

	public const SCAN          = 'scan';
	public const PROFILE       = 'profile';
	public const FIRST_LISTING = 'first_listing';
	public const SCHEMA        = 'schema';
	public const LLMS          = 'llms';
	public const BOTS          = 'bots';

	/**
	 * Steps the wizard can apply.
	 */
	public const ACTIONS = array( self::SCAN, self::PROFILE, self::FIRST_LISTING, self::SCHEMA, self::LLMS, self::BOTS );

	/**
	 * Constructor.
	 *
	 * @param string   $id          Step id (self::ACTIONS, or "manual_<check>").
	 * @param string   $title       Title.
	 * @param string   $description What it does and why.
	 * @param float    $gain        Estimated score gain (points).
	 * @param bool     $gain_is_max True when the check was not measured (gain is its whole weight).
	 * @param string[] $requires    Steps that must be applied first.
	 * @param string[] $checks      Check ids this step improves.
	 * @param bool     $manual      True when the wizard cannot apply it (instructions only).
	 *
	 * @phpstan-param list<string> $requires
	 * @phpstan-param list<string> $checks
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $description,
		public readonly float $gain,
		public readonly bool $gain_is_max = false,
		public readonly array $requires = array(),
		public readonly array $checks = array(),
		public readonly bool $manual = false
	) {
	}
}
