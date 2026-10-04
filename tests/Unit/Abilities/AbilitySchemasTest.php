<?php
/**
 * Tests for AbilitySchemas.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Abilities;

use AIHazirSite\Adapters\Abilities\AbilitySchemas;
use AIHazirSite\Adapters\Rest\RestSchemas;
use PHPUnit\Framework\TestCase;

/**
 * Names, closed inputs and outputs equal to the REST contract.
 *
 * @covers \AIHazirSite\Adapters\Abilities\AbilitySchemas
 */
final class AbilitySchemasTest extends TestCase {

	/**
	 * The four abilities with valid WordPress names.
	 */
	public function test_names(): void {
		$names = array_keys( AbilitySchemas::all() );
		$this->assertSame( array( 'aihs/get-profile', 'aihs/search-listings', 'aihs/get-listing', 'aihs/check-availability' ), $names );
		foreach ( $names as $name ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
		}
	}

	/**
	 * Inputs are closed objects; ids are required where a listing is addressed.
	 */
	public function test_inputs(): void {
		$all = AbilitySchemas::all();
		foreach ( $all as $name => $schemas ) {
			$this->assertSame( array( 'object', false ), array( $schemas['input']['type'], $schemas['input']['additionalProperties'] ), $name );
		}
		$this->assertArrayNotHasKey( 'properties', $all['aihs/get-profile']['input'], 'Empty properties would encode as [].' );
		$this->assertSame( array( 'id' ), $all['aihs/get-listing']['input']['required'] );
		$this->assertSame( array( 'id' ), $all['aihs/check-availability']['input']['required'] );
		$this->assertSame( array( 'type', 'category', 'region', 'keyword', 'attributes', 'page', 'per_page', 'sector' ), array_keys( $all['aihs/search-listings']['input']['properties'] ) );
		$this->assertSame( 'network', array_key_last( AbilitySchemas::with_suggestions( $all )['aihs/search-listings']['input']['properties'] ) );
	}

	/**
	 * Outputs are the REST bodies' schemas (same answer on both channels).
	 */
	public function test_outputs_equal_rest(): void {
		$all   = AbilitySchemas::all();
		$strip = static function ( array $schema ): array {
			unset( $schema['$schema'], $schema['title'] );
			return $schema;
		};
		$this->assertSame( $strip( RestSchemas::profile() ), $all['aihs/get-profile']['output'] );
		$this->assertSame( $strip( RestSchemas::listings() ), $all['aihs/search-listings']['output'] );
		$this->assertSame( RestSchemas::listing(), $all['aihs/get-listing']['output'] );
		$this->assertSame( array( 'yes', 'no', 'unknown' ), $all['aihs/check-availability']['output']['properties']['answer']['enum'] );
	}
}
