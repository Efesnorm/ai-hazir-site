<?php
/**
 * Stored bot access policy.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Access;

use AIHazirSite\Core\Contracts\Settings;
use AIHazirSite\Core\Measurement\Bot;

/**
 * The only writer of the `aihs_bot_policy` setting. Unknown bot ids are dropped on save.
 */
final class PolicyStore {

	public const OPTION = 'aihs_bot_policy';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Storage.
	 */
	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * Stored policy.
	 */
	public function get(): BotPolicy {
		return BotPolicy::from_array( $this->settings->get( self::OPTION, array() ) );
	}

	/**
	 * Saves a policy, keeping only known bots.
	 *
	 * @param BotPolicy $policy Policy.
	 * @param Bot[]     $bots   Known bots.
	 */
	public function save( BotPolicy $policy, array $bots ): BotPolicy {
		$known = array_map( static fn( Bot $bot ): string => $bot->id, $bots );
		$clean = new BotPolicy( array_intersect_key( $policy->to_array(), array_flip( $known ) ) );
		$this->settings->set( self::OPTION, $clean->to_array(), false );
		return $clean;
	}
}
