<?php
/**
 * Weights of the match score.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Core\Matching;

/**
 * S = w1·attributes + w2·quantity + w3·lead time + w4·price, weights summing to 1
 * (equal by default). Negative or invalid weights count as 0; all zero falls back to equal.
 */
final class MatchWeights {

	public const CRITERIA = array( 'attributes', 'quantity', 'lead_time', 'price' );

	/**
	 * Normalised weights by criterion.
	 *
	 * @var array<string, float>
	 */
	public readonly array $weights;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $weights Criterion → weight (any scale).
	 */
	public function __construct( array $weights = array() ) {
		$raw = array();
		foreach ( self::CRITERIA as $criterion ) {
			$value             = $weights[ $criterion ] ?? 1;
			$raw[ $criterion ] = is_numeric( $value ) ? max( 0.0, (float) $value ) : 0.0;
		}
		$sum = array_sum( $raw );
		if ( $sum <= 0.0 ) {
			$raw = array_fill_keys( self::CRITERIA, 1.0 );
			$sum = (float) count( self::CRITERIA );
		}
		$this->weights = array_map( static fn( float $w ): float => $w / $sum, $raw );
	}

	/**
	 * Weight of a criterion.
	 *
	 * @param string $criterion Criterion.
	 */
	public function of( string $criterion ): float {
		return $this->weights[ $criterion ] ?? 0.0;
	}
}
