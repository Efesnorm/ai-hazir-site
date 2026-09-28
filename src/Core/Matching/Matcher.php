<?php
/**
 * Need ↔ offer/supply matching.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Matching;

use AIHazirSite\Core\Catalog\Listing;
use AIHazirSite\Core\Catalog\Query\ListingSearch;

/**
 * Scores candidates that pass HardFilter: S = Σ weight × value (0–100), with the value and the
 * points of every criterion kept as the explanation (the points always add up to the score).
 * Matches are suggestions only; nothing is sent to anyone.
 *
 * Values (0–1): attributes = share of the need's attributes (other than standards) the candidate
 * has with the same value; quantity = candidate ÷ needed (capped at 1, same unit, else 0);
 * lead time = 1 when within the limit; price = 1 when the price range meets the budget, falling
 * with the gap relative to the budget. Unknown data on either side counts 0.5 (1 when the need
 * does not ask for it).
 */
final class Matcher {

	public const NEUTRAL = 0.5;

	/**
	 * Constructor.
	 *
	 * @param MatchWeights $weights Weights.
	 */
	public function __construct( private readonly MatchWeights $weights = new MatchWeights() ) {
	}

	/**
	 * Matches for a need, best first (equal scores: by candidate key).
	 *
	 * @param Listing     $need       Demand listing.
	 * @param Candidate[] $candidates Candidates.
	 * @return array{matches: list<array{candidate: Candidate, score: float, breakdown: list<array{criterion: string, weight: float, value: float, points: float}>}>, excluded: array<string, string>}
	 *
	 * @phpstan-param list<Candidate> $candidates
	 */
	public function match( Listing $need, array $candidates ): array {
		$matches  = array();
		$excluded = array();
		foreach ( $candidates as $candidate ) {
			$reason = HardFilter::reason( $need, $candidate->listing );
			if ( null !== $reason ) {
				$excluded[ $candidate->key() ] = $reason;
				continue;
			}
			$breakdown = array();
			$score     = 0.0;
			foreach ( $this->values( $need, $candidate->listing ) as $criterion => $value ) {
				$weight      = $this->weights->of( $criterion );
				$points      = round( 100 * $weight * $value, 2 );
				$score      += $points;
				$breakdown[] = array(
					'criterion' => $criterion,
					'weight'    => round( $weight, 4 ),
					'value'     => round( $value, 4 ),
					'points'    => $points,
				);
			}
			$matches[] = array(
				'candidate' => $candidate,
				'score'     => round( $score, 2 ),
				'breakdown' => $breakdown,
			);
		}
		usort( $matches, static fn( array $a, array $b ): int => array( $b['score'], $a['candidate']->key() ) <=> array( $a['score'], $b['candidate']->key() ) );
		return array(
			'matches'  => $matches,
			'excluded' => $excluded,
		);
	}

	/**
	 * Criterion values (0–1).
	 *
	 * @param Listing $need      Need.
	 * @param Listing $candidate Candidate.
	 * @return array<string, float>
	 */
	public function values( Listing $need, Listing $candidate ): array {
		return array(
			'attributes' => self::attributes( $need, $candidate ),
			'quantity'   => self::quantity( $need, $candidate ),
			'lead_time'  => null === $need->lead_time_days ? 1.0 : ( null === $candidate->lead_time_days ? self::NEUTRAL : 1.0 ),
			'price'      => self::price( $need, $candidate ),
		);
	}

	/**
	 * Share of the need's attributes the candidate matches.
	 *
	 * @param Listing $need      Need.
	 * @param Listing $candidate Candidate.
	 */
	private static function attributes( Listing $need, Listing $candidate ): float {
		$wanted = array_diff_key( $need->attributes, array( HardFilter::STANDARD => true ) );
		if ( array() === $wanted ) {
			return 1.0;
		}
		$met = 0;
		foreach ( $wanted as $key => $value ) {
			$have = $candidate->attributes[ $key ] ?? null;
			if ( null !== $have && ( ListingSearch::fold( $have ) === ListingSearch::fold( $value ) || ( is_numeric( str_replace( ',', '.', $have ) ) && is_numeric( str_replace( ',', '.', $value ) ) && (float) str_replace( ',', '.', $have ) === (float) str_replace( ',', '.', $value ) ) ) ) {
				++$met;
			}
		}
		return $met / count( $wanted );
	}

	/**
	 * Quantity coverage.
	 *
	 * @param Listing $need      Need.
	 * @param Listing $candidate Candidate.
	 */
	private static function quantity( Listing $need, Listing $candidate ): float {
		if ( null === $need->quantity || (float) $need->quantity <= 0.0 ) {
			return 1.0;
		}
		if ( null === $candidate->quantity ) {
			return self::NEUTRAL;
		}
		if ( ListingSearch::fold( $need->unit ) !== ListingSearch::fold( $candidate->unit ) ) {
			return 0.0;
		}
		return min( 1.0, (float) $candidate->quantity / (float) $need->quantity );
	}

	/**
	 * Price overlap with the budget.
	 *
	 * @param Listing $need      Need (budget).
	 * @param Listing $candidate Candidate (price).
	 */
	private static function price( Listing $need, Listing $candidate ): float {
		if ( null === $need->price_min && null === $need->price_max ) {
			return 1.0;
		}
		if ( ( null === $candidate->price_min && null === $candidate->price_max ) || $need->currency !== $candidate->currency ) {
			return self::NEUTRAL;
		}
		$budget_min = (float) ( $need->price_min ?? $need->price_max );
		$budget_max = (float) ( $need->price_max ?? $need->price_min );
		$price_min  = (float) ( $candidate->price_min ?? $candidate->price_max );
		$price_max  = (float) ( $candidate->price_max ?? $candidate->price_min );
		if ( $price_min <= $budget_max && $price_max >= $budget_min ) {
			return 1.0;
		}
		$gap = $price_min > $budget_max ? $price_min - $budget_max : $budget_min - $price_max;
		return $budget_max > 0.0 ? max( 0.0, 1.0 - $gap / $budget_max ) : 0.0;
	}
}
