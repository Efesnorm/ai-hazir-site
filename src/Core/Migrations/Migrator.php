<?php
/**
 * Migration runner.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Migrations;

use InvalidArgumentException;

/**
 * Applies migrations in version order and remembers the last applied version
 * in the `aihs_db_version` option.
 */
final class Migrator {

	/**
	 * Option name that stores the last applied migration version.
	 */
	public const OPTION = 'aihs_db_version';

	/**
	 * Migrations sorted by ascending version.
	 *
	 * @var list<MigrationInterface>
	 */
	private array $migrations;

	/**
	 * Constructor.
	 *
	 * @param MigrationInterface[] $migrations Migrations, in any order.
	 * @throws InvalidArgumentException When a version is not positive or is duplicated.
	 */
	public function __construct( array $migrations ) {
		$seen = array();
		foreach ( $migrations as $migration ) {
			$version = $migration->version();
			if ( $version < 1 ) {
				throw new InvalidArgumentException( sprintf( 'Migration version must be positive, got %d.', $version ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message, integer only.
			}
			if ( isset( $seen[ $version ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Duplicate migration version %d.', $version ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message, integer only.
			}
			$seen[ $version ] = true;
		}

		usort(
			$migrations,
			static fn( MigrationInterface $a, MigrationInterface $b ): int => $a->version() <=> $b->version()
		);
		$this->migrations = $migrations;
	}

	/**
	 * Last applied migration version (0 when none).
	 */
	public function current_version(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * Applies every migration newer than the current version.
	 *
	 * The version is saved after each migration, so a failure leaves the
	 * already-applied ones recorded.
	 *
	 * @return list<int> Versions applied during this call.
	 */
	public function migrate(): array {
		$current = $this->current_version();
		$applied = array();

		foreach ( $this->migrations as $migration ) {
			$version = $migration->version();
			if ( $version <= $current ) {
				continue;
			}

			$migration->up();
			update_option( self::OPTION, $version, false );
			$applied[] = $version;
		}

		return $applied;
	}

	/**
	 * Reverts applied migrations newer than `$target`, newest first.
	 *
	 * @param int $target Version to roll back to (0 reverts everything).
	 * @return list<int> Versions reverted during this call.
	 */
	public function rollback( int $target = 0 ): array {
		$current  = $this->current_version();
		$reverted = array();

		for ( $i = count( $this->migrations ) - 1; $i >= 0; $i-- ) {
			$version = $this->migrations[ $i ]->version();
			if ( $version > $current ) {
				continue;
			}
			if ( $version <= $target ) {
				break;
			}

			$this->migrations[ $i ]->down();
			$previous = $i > 0 ? $this->migrations[ $i - 1 ]->version() : 0;
			update_option( self::OPTION, $previous, false );
			$reverted[] = $version;
		}

		return $reverted;
	}
}
