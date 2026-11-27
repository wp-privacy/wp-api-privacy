<?php
/**
 * Tests for the "Disable HTTPs for packet sniffing" setting.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

/**
 * Tests what ApiPrivacy::maybeModifyCurl() does to the URL of outbound requests.
 */
final class DisableHttpsTest extends TestCase {

	/**
	 * Runs a request through the cURL hook.
	 *
	 * @param bool   $disable_https Whether the setting is on.
	 * @param string $url           The URL that is requested.
	 *
	 * @return string The URL cURL will request.
	 */
	private function requested_url( $disable_https, $url ) {
		$handle = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- The hook under test gets a cURL handle.

		$this->get_instance( [ 'disable_https' => $disable_https ] )->maybeModifyCurl( $handle, [], $url );

		return curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Reads back the URL the hook set.
	}

	public function test_requests_keep_https_when_setting_is_off() {
		self::assertSame(
			'https://api.wordpress.org/plugins/update-check/1.1/',
			$this->requested_url( false, 'https://api.wordpress.org/plugins/update-check/1.1/' )
		);
	}

	public function test_wordpress_api_requests_are_downgraded_when_setting_is_on() {
		self::assertSame(
			'http://api.wordpress.org/plugins/update-check/1.1/',
			$this->requested_url( true, 'https://api.wordpress.org/plugins/update-check/1.1/' )
		);
	}

	/**
	 * The hosts the updater and the update download use.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function github_urls() {
		return [
			'main plugin file' => [ 'https://raw.githubusercontent.com/wp-privacy/wp-api-privacy/refs/heads/main/wp-api-privacy.php' ],
			'release zip'      => [ 'https://github.com/wp-privacy/wp-api-privacy/releases/download/1.3.0/wp-api-privacy.zip' ],
			'release CDN'      => [ 'https://release-assets.githubusercontent.com/github-production-release-asset/1/2' ],
			'old release CDN'  => [ 'https://objects.githubusercontent.com/github-production-release-asset-2e65be/1/2' ],
		];
	}

	/**
	 * Over plain HTTP anyone on the network could swap the update zip.
	 *
	 * @dataProvider github_urls
	 *
	 * @param string $url The URL.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'github_urls' )]
	public function test_github_requests_keep_https_when_setting_is_on( $url ) {
		self::assertSame( $url, $this->requested_url( true, $url ) );
	}

	public function test_lookalike_github_hosts_are_downgraded() {
		self::assertSame(
			'http://github.com.example.org/file.zip',
			$this->requested_url( true, 'https://github.com.example.org/file.zip' )
		);
	}
}
