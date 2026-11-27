<?php
/**
 * Tests for blocking Browse Happy requests.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use WP_Error;

/**
 * Tests ApiPrivacy::maybeBlockRequest(), which stops WordPress sending the admin's
 * browser User-Agent to the Browse Happy API.
 */
final class BrowseHappyTest extends TestCase {

	private const BROWSE_HAPPY_URL = 'https://api.wordpress.org/core/browse-happy/1.1/';

	/**
	 * Runs a request through the pre_http_request filter.
	 *
	 * @param array<string, mixed> $stored  Stored settings.
	 * @param string               $url     The URL that is requested.
	 * @param mixed                $preempt What earlier filters returned.
	 *
	 * @return mixed
	 */
	private function filter_request( array $stored, $url, $preempt = false ) {
		$this->allow_saving_settings();

		return $this->get_instance( $stored )->maybeBlockRequest( $preempt, [], $url );
	}

	public function test_hook_is_registered() {
		$privacy = $this->get_instance();

		self::assertSame( 10, has_filter( 'pre_http_request', [ $privacy, 'maybeBlockRequest' ] ) );
	}

	public function test_browse_happy_is_blocked_by_default() {
		self::assertInstanceOf( WP_Error::class, $this->filter_request( [], self::BROWSE_HAPPY_URL ) );
	}

	public function test_browse_happy_over_http_is_blocked() {
		self::assertInstanceOf( WP_Error::class, $this->filter_request( [], 'http://api.wordpress.org/core/browse-happy/1.1/' ) );
	}

	public function test_browse_happy_is_sent_when_setting_is_off() {
		self::assertFalse( $this->filter_request( [ 'block_browse_happy' => false ], self::BROWSE_HAPPY_URL ) );
	}

	public function test_serve_happy_is_not_blocked() {
		self::assertFalse( $this->filter_request( [], 'https://api.wordpress.org/core/serve-happy/1.0/' ) );
	}

	public function test_other_requests_are_not_blocked() {
		self::assertFalse( $this->filter_request( [], 'https://api.wordpress.org/core/version-check/1.7/' ) );
		self::assertFalse( $this->filter_request( [], 'https://example.org/core/browse-happy/1.1/' ) );
	}

	public function test_response_from_an_earlier_filter_is_kept() {
		$response = [ 'response' => [ 'code' => 200 ] ];

		self::assertSame( $response, $this->filter_request( [], self::BROWSE_HAPPY_URL, $response ) );
	}
}
