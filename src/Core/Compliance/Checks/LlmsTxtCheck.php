<?php
/**
 * Check for the llms.txt file.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance\Checks;

use AIHazirSite\Core\Compliance\Check;
use AIHazirSite\Core\Compliance\CheckResult;
use AIHazirSite\Core\Compliance\Site;

/**
 * /llms.txt exists, is not empty, and starts with the H1 the format requires
 * ("An H1 with the name of the project or site. This is the only required section", llmstxt.org).
 */
final class LlmsTxtCheck implements Check {

	/**
	 * Id.
	 */
	public function id(): string {
		return 'llms_txt';
	}

	/**
	 * Weight.
	 */
	public function weight(): int {
		return 10;
	}

	/**
	 * 0.5 for a non-empty file, +0.5 when its first line is an H1.
	 *
	 * @param Site $site Site.
	 */
	public function run( Site $site ): CheckResult {
		$response = $site->fetch( 'llms.txt' );
		if ( ! $response->reached() ) {
			return CheckResult::unmeasured( '/llms.txt adresine erişilemedi.' );
		}

		$fix  = 'Sitenin kök dizininde "# Firma adı" başlığıyla başlayan bir /llms.txt yayınlayın (AI Hazır Site llms.txt modülü).';
		$body = trim( $response->ok() ? $response->body : '' );
		if ( ! $response->ok() ) {
			return CheckResult::measured( 0.0, array( sprintf( '/llms.txt yok (%d).', $response->status ) ), $fix );
		}
		if ( '' === $body ) {
			return CheckResult::measured( 0.0, array( '/llms.txt boş.' ), $fix );
		}

		$first = strtok( str_replace( "\xEF\xBB\xBF", '', $body ), "\n" );
		if ( false !== $first && 1 === preg_match( '/^#\s+\S/', trim( $first ) ) ) {
			return CheckResult::measured( 1.0, array( '/llms.txt var ve H1 başlığıyla başlıyor.' ) );
		}

		return CheckResult::measured( 0.5, array( '/llms.txt var ama zorunlu H1 başlığıyla başlamıyor.' ), $fix );
	}
}
