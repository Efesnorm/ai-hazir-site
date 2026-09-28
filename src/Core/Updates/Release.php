<?php
/**
 * One published plugin release.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Updates;

/**
 * A release from the update manifest (wp-update-server / Plugin Update Checker field names,
 * plus released_at for the canary delay and db_version for rollbacks).
 */
final class Release {

	/**
	 * Constructor.
	 *
	 * @param string                $version      SemVer, e.g. "1.3.0".
	 * @param string                $download_url Package zip URL (https).
	 * @param string                $released_at  ISO 8601 UTC.
	 * @param int                   $db_version   Latest migration version the release ships with.
	 * @param string                $requires     Minimum WordPress version.
	 * @param string                $requires_php Minimum PHP version.
	 * @param string                $tested       Tested up to WordPress version.
	 * @param array<string, string> $sections     Plugin information sections (HTML), e.g. changelog.
	 */
	public function __construct(
		public readonly string $version,
		public readonly string $download_url,
		public readonly string $released_at,
		public readonly int $db_version,
		public readonly string $requires = '',
		public readonly string $requires_php = '',
		public readonly string $tested = '',
		public readonly array $sections = array()
	) {
	}
}
