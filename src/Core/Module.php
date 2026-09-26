<?php
/**
 * Module contract.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core;

/**
 * A self-contained part of the plugin (core service or adapter) registered by {@see Plugin}.
 */
interface Module {

	/**
	 * Registers the module's hooks.
	 */
	public function register(): void;
}
