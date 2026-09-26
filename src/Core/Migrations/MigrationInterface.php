<?php
/**
 * Migration contract.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Migrations;

/**
 * A versioned, reversible schema change. Changes must be additive only
 * (new table or new column); `down()` undoes exactly what `up()` did.
 */
interface MigrationInterface {

	/**
	 * Unique, positive version number. Migrations run in ascending order.
	 */
	public function version(): int;

	/**
	 * Applies the change.
	 */
	public function up(): void;

	/**
	 * Reverts the change.
	 */
	public function down(): void;
}
