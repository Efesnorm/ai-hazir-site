<?php
/**
 * Runs the compliance checks and computes the score.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Compliance;

use AIHazirSite\Core\Access\BotPolicy;
use AIHazirSite\Core\Compliance\Checks\AdvancedCheck;
use AIHazirSite\Core\Compliance\Checks\BotAccessCheck;
use AIHazirSite\Core\Compliance\Checks\FreshnessCheck;
use AIHazirSite\Core\Compliance\Checks\LlmsTxtCheck;
use AIHazirSite\Core\Compliance\Checks\MachineInterfaceCheck;
use AIHazirSite\Core\Compliance\Checks\ReadabilityCheck;
use AIHazirSite\Core\Compliance\Checks\StructuredDataCheck;
use AIHazirSite\Core\Contracts\Clock;
use InvalidArgumentException;
use Throwable;

/**
 * Score = 100 × Σ(weight × ratio) / Σ(weight of measured checks).
 * A check that throws or cannot measure is reported as unmeasured and left out.
 */
final class Scanner {

	/**
	 * Version of the scoring rules; bump when checks, weights or scoring change.
	 *
	 * 1: 0.3.0 – seven checks.
	 * 2: 0.5.0 – bots blocked on purpose with the AI bot access setting no longer lower bot_access.
	 * 3: 1.4.0 – bot list 12 → 18 (Mistral AI, DuckAssistBot, Meta fetcher/indexer): bot_access ratios change.
	 * 4: 1.13.0 – machine_interface: full REST credit only with a public data API, MCP behind authentication 0.1.
	 */
	public const SCORE_VERSION = 4;

	/**
	 * Checks in display order.
	 *
	 * @var list<Check>
	 */
	private array $checks;

	/**
	 * Constructor.
	 *
	 * @param Check[] $checks Checks.
	 * @param Clock   $clock  Timestamps the report.
	 * @throws InvalidArgumentException On duplicate ids or non-positive weights.
	 *
	 * @phpstan-param list<Check> $checks
	 */
	public function __construct( array $checks, private readonly Clock $clock ) {
		$ids = array();
		foreach ( $checks as $check ) {
			if ( isset( $ids[ $check->id() ] ) ) {
				throw new InvalidArgumentException( 'Duplicate check id: ' . $check->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing.
			}
			if ( $check->weight() < 1 ) {
				throw new InvalidArgumentException( 'Check weight must be positive: ' . $check->id() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing.
			}
			$ids[ $check->id() ] = true;
		}
		$this->checks = $checks;
	}

	/**
	 * The seven U1 checks with the PRD weights (20+20+20+15+10+10+5 = 100).
	 * A new check is added here only; existing checks are not touched.
	 *
	 * @param BotPolicy|null $policy The site's AI bot access policy, when that setting is on.
	 * @return list<Check>
	 */
	public static function default_checks( ?BotPolicy $policy = null ): array {
		return array(
			new StructuredDataCheck(),
			new ReadabilityCheck(),
			new MachineInterfaceCheck(),
			new BotAccessCheck( $policy ),
			new LlmsTxtCheck(),
			new FreshnessCheck(),
			new AdvancedCheck(),
		);
	}

	/**
	 * Checks in display order.
	 *
	 * @return list<Check>
	 */
	public function checks(): array {
		return $this->checks;
	}

	/**
	 * Scans the site.
	 *
	 * @param Site $site Site.
	 */
	public function scan( Site $site ): ScoreReport {
		$rows = array();
		foreach ( $this->checks as $check ) {
			try {
				$result = $check->run( $site );
			} catch ( Throwable $e ) {
				$result = CheckResult::unmeasured( 'Kontrol çalışırken hata oluştu: ' . $e->getMessage() );
			}

			$weight = $check->weight();
			$points = null === $result->ratio ? 0.0 : round( $weight * $result->ratio, 2 );
			$rows[] = array(
				'id'       => $check->id(),
				'weight'   => $weight,
				'ratio'    => $result->ratio,
				'points'   => $points,
				'gain'     => null === $result->ratio ? 0.0 : round( $weight - $points, 2 ),
				'level'    => $result->level,
				'findings' => $result->findings,
				'fix'      => $result->fix,
			);
		}

		// 1.19.0: semantic structure advice from the pages already fetched; not part of the score.
		try {
			$advice = SemanticStructure::site( $site );
		} catch ( Throwable $e ) {
			$advice = array();
		}

		return new ScoreReport( gmdate( 'Y-m-d\TH:i:s\Z', $this->clock->now() ), self::SCORE_VERSION, $rows, $site->elapsed_ms(), $advice );
	}
}
