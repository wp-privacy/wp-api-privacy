<?php
/**
 * Tests for the User-Agent handling.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use WP_Privacy\WP_API_Privacy\Settings;

/**
 * Tests what ApiPrivacy::modifyUserAgent() does to the User-Agent of outbound requests.
 */
final class UserAgentTest extends TestCase {

	private const WP_API_URL = 'https://api.wordpress.org/plugins/info/1.2/';

	private const OTHER_URL = 'https://example.org/api/';

	/**
	 * Runs a request with the default WordPress User-Agent through the filter.
	 *
	 * @param array<string, mixed> $stored Stored settings.
	 * @param string               $url    The URL that is requested.
	 *
	 * @return string The resulting User-Agent.
	 */
	private function filter_user_agent( array $stored, $url ) {
		$params = $this->get_instance( $stored )->modifyUserAgent( [ 'user-agent' => self::DEFAULT_USER_AGENT ], $url );

		return $params['user-agent'];
	}

	/**
	 * The hash that replaces the site URL in the "modify" modes.
	 *
	 * @return string
	 */
	private function site_hash() {
		return 'https://' . md5( self::SITE_URL ) . '.com';
	}

	public function test_hooks_are_registered() {
		$privacy = $this->get_instance();

		self::assertSame( 0, has_filter( 'http_request_args', [ $privacy, 'modifyUserAgent' ] ) );
		self::assertSame( 10, has_filter( 'rest_prepare_user', [ $privacy, 'modifyRestUser' ] ) );
		self::assertSame( 10, has_filter( 'core_version_check_query_args', [ $privacy, 'filterCoreArgs' ] ) );
	}

	public function test_defaults_strip_site_url_and_version_from_wp_api_requests() {
		$this->allow_saving_settings();

		self::assertSame( 'WordPress/Private', $this->filter_user_agent( [], self::WP_API_URL ) );
	}

	public function test_defaults_keep_site_url_for_other_hosts() {
		$this->allow_saving_settings();

		self::assertSame( 'WordPress/Private; ' . self::SITE_URL, $this->filter_user_agent( [], self::OTHER_URL ) );
	}

	public function test_version_is_kept_when_version_stripping_is_off() {
		$this->allow_saving_settings();

		self::assertSame(
			'WordPress/' . self::WP_VERSION,
			$this->filter_user_agent( [ 'strip_wp_version' => false ], self::WP_API_URL )
		);
	}

	public function test_strip_all_strips_site_url_for_every_host() {
		$this->allow_saving_settings();

		self::assertSame(
			'WordPress/Private',
			$this->filter_user_agent( [ 'user_agent_behaviour' => 'strip_all' ], self::OTHER_URL )
		);
	}

	public function test_modify_wp_replaces_site_url_with_hash_for_wp_api_requests_only() {
		$this->allow_saving_settings();

		$stored = [ 'user_agent_behaviour' => 'modify_wp' ];

		self::assertSame( 'WordPress/Private; ' . $this->site_hash(), $this->filter_user_agent( $stored, self::WP_API_URL ) );
		self::assertSame( 'WordPress/Private; ' . self::SITE_URL, $this->filter_user_agent( $stored, self::OTHER_URL ) );
	}

	public function test_modify_all_replaces_site_url_with_hash_for_every_host() {
		$this->allow_saving_settings();

		$user_agent = $this->filter_user_agent( [ 'user_agent_behaviour' => 'modify_all' ], self::OTHER_URL );

		self::assertSame( 'WordPress/Private; ' . $this->site_hash(), $user_agent );
		self::assertStringNotContainsString( 'example.com', $user_agent );
	}

	public function test_nothing_changes_or_is_counted_when_everything_is_off() {
		Functions\expect( 'update_option' )->never();

		$stored = [
			'user_agent_behaviour' => 'none',
			'strip_wp_version'     => false,
		];

		self::assertSame( self::DEFAULT_USER_AGENT, $this->filter_user_agent( $stored, self::WP_API_URL ) );
	}

	public function test_filter_can_add_hosts_that_count_as_wp_api() {
		$this->allow_saving_settings();

		Filters\expectApplied( 'api_privacy_wp_urls' )
			->once()
			->with( [ 'api.wordpress.org' ] )
			->andReturn( [ 'api.wordpress.org', 'example.org' ] );

		self::assertSame( 'WordPress/Private', $this->filter_user_agent( [], self::OTHER_URL ) );
	}

	public function test_modified_request_is_counted_once() {
		Functions\expect( 'update_option' )
			->once()
			->with(
				Settings::SETTING_KEY,
				Mockery::on(
					static function ( $settings ) {
						return $settings->modification_count === 1;
					}
				),
				false
			);

		$this->filter_user_agent( [], self::WP_API_URL );
	}
}
