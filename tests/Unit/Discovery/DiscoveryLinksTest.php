<?php
/**
 * Tests for DiscoveryLinks.
 *
 * @package AIHazirSite
 */

declare(strict_types=1);

namespace AIHazirSite\Tests\Unit\Discovery;

use AIHazirSite\Adapters\Discovery\DiscoveryLinks;
use PHPUnit\Framework\TestCase;

/**
 * Registered relations (llms.txt proposal, RFC 8631), HTML and RFC 8288 header forms.
 *
 * @covers \AIHazirSite\Adapters\Discovery\DiscoveryLinks
 */
final class DiscoveryLinksTest extends TestCase {

	private const LLMS = 'https://ornek.com/llms.txt';
	private const API  = 'https://ornek.com/wp-json/aihs/v1/openapi.json';

	/**
	 * Only the resources that are on.
	 */
	public function test_links_follow_the_features(): void {
		$this->assertSame( array( 'describedby', 'service-desc' ), array_column( DiscoveryLinks::links( self::LLMS, self::API ), 'rel' ) );
		$this->assertSame( array( 'service-desc' ), array_column( DiscoveryLinks::links( '', self::API ), 'rel' ) );
		$this->assertSame( array( 'describedby' ), array_column( DiscoveryLinks::links( self::LLMS, '' ), 'rel' ) );
		$this->assertSame( array(), DiscoveryLinks::links( '', '' ) );
		$this->assertSame( '', DiscoveryLinks::html( array() ) );
		$this->assertSame( '', DiscoveryLinks::header( array() ) );
	}

	/**
	 * HTML <link> elements with type and title.
	 */
	public function test_html(): void {
		$this->assertSame(
			'<link rel="describedby" type="text/markdown" href="https://ornek.com/llms.txt" title="llms.txt">' . "\n"
			. '<link rel="service-desc" type="application/vnd.oai.openapi+json" href="https://ornek.com/wp-json/aihs/v1/openapi.json" title="AI Katalog API">' . "\n",
			DiscoveryLinks::html( DiscoveryLinks::links( self::LLMS, self::API ) )
		);
	}

	/**
	 * One Link header value, comma-separated link-values (RFC 8288 §3).
	 */
	public function test_header(): void {
		$this->assertSame(
			'<https://ornek.com/llms.txt>; rel="describedby"; type="text/markdown", <https://ornek.com/wp-json/aihs/v1/openapi.json>; rel="service-desc"; type="application/vnd.oai.openapi+json"',
			DiscoveryLinks::header( DiscoveryLinks::links( self::LLMS, self::API ) )
		);
	}

	/**
	 * Attribute escaping; URIs that would break the header are left out of it.
	 */
	public function test_escaping(): void {
		$bad = 'https://ornek.com/"><script>x</script>';
		$this->assertStringNotContainsString( '<script>', DiscoveryLinks::html( DiscoveryLinks::links( $bad, '' ) ) );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', DiscoveryLinks::html( DiscoveryLinks::links( $bad, '' ) ) );
		$this->assertSame( '', DiscoveryLinks::header( DiscoveryLinks::links( $bad, '' ) ) );
		$this->assertSame( '', DiscoveryLinks::header( DiscoveryLinks::links( "https://ornek.com/a\r\nX: y", '' ) ) );
	}
}
