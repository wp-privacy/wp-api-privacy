<?php
/**
 * Tests for the data that is stripped from requests and responses.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use Brain\Monkey\Functions;
use stdClass;

/**
 * Tests the stripping of plugins, themes, core data, headers and user logins.
 */
final class RequestDataTest extends TestCase {

	private const PLUGIN_CHECK_URL = 'https://api.wordpress.org/plugins/update-check/1.1/';

	private const THEME_CHECK_URL = 'https://api.wordpress.org/themes/update-check/1.1/';

	private const CORE_CHECK_URL = 'https://api.wordpress.org/core/version-check/1.7/?version=6.8.1';

	/**
	 * Settings that leave the User-Agent alone, to isolate the behaviour under test.
	 */
	private const QUIET = [
		'user_agent_behaviour' => 'none',
		'strip_wp_version'     => false,
	];

	/**
	 * The request arguments WordPress builds for a plugin update check.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_check_params() {
		$plugins = [
			'plugins' => [
				'hosted/hosted.php'         => [
					'Name'      => 'Hosted on WordPress.org',
					'UpdateURI' => '',
				],
				'external/external.php'     => [
					'Name'      => 'Hosted elsewhere',
					'UpdateURI' => 'https://github.com/example/external',
				],
				'hosted-uri/hosted-uri.php' => [
					'Name'      => 'Explicitly on WordPress.org',
					'UpdateURI' => 'https://wordpress.org/plugins/hosted-uri/',
				],
			],
			'active'  => [ 'hosted/hosted.php', 'external/external.php', 'hosted-uri/hosted-uri.php' ],
		];

		return [
			'user-agent' => self::DEFAULT_USER_AGENT,
			'body'       => [ 'plugins' => json_encode( $plugins ) ],
		];
	}

	/**
	 * The request arguments WordPress builds for a theme update check.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_check_params() {
		$themes = [
			'active' => 'hosted',
			'themes' => [
				'hosted'   => [
					'Name'      => 'Hosted on WordPress.org',
					'UpdateURI' => '',
				],
				'external' => [
					'Name'      => 'Hosted elsewhere',
					'UpdateURI' => 'https://example.org/themes/external',
				],
			],
		];

		return [
			'user-agent' => self::DEFAULT_USER_AGENT,
			'body'       => [ 'themes' => json_encode( $themes ) ],
		];
	}

	public function test_externally_hosted_plugins_are_removed_from_update_check() {
		$this->allow_saving_settings();

		$params = $this->get_instance( self::QUIET )->modifyUserAgent( $this->plugin_check_params(), self::PLUGIN_CHECK_URL );
		$sent   = json_decode( $params['body']['plugins'], true );

		self::assertSame( [ 'hosted/hosted.php', 'hosted-uri/hosted-uri.php' ], array_keys( $sent['plugins'] ) );
		self::assertSame( [ 'hosted/hosted.php', 'hosted-uri/hosted-uri.php' ], $sent['active'] );
		self::assertStringNotContainsString( 'external', $params['body']['plugins'] );
	}

	public function test_plugins_are_left_alone_when_plugin_stripping_is_off() {
		Functions\expect( 'update_option' )->never();

		$original = $this->plugin_check_params();
		$params   = $this->get_instance( self::QUIET + [ 'strip_plugins' => false ] )->modifyUserAgent( $original, self::PLUGIN_CHECK_URL );

		self::assertSame( $original, $params );
	}

	public function test_externally_hosted_themes_are_removed_from_update_check() {
		$this->allow_saving_settings();

		$params = $this->get_instance( self::QUIET )->modifyUserAgent( $this->theme_check_params(), self::THEME_CHECK_URL );
		$sent   = json_decode( $params['body']['themes'], true );

		self::assertSame( [ 'hosted' ], array_keys( $sent['themes'] ) );
		self::assertSame( 'hosted', $sent['active'] );
	}

	public function test_themes_are_left_alone_when_theme_stripping_is_off() {
		Functions\expect( 'update_option' )->never();

		$original = $this->theme_check_params();
		$params   = $this->get_instance( self::QUIET + [ 'strip_themes' => false ] )->modifyUserAgent( $original, self::THEME_CHECK_URL );

		self::assertSame( $original, $params );
	}

	public function test_install_headers_are_removed_from_core_version_check() {
		$this->allow_saving_settings();

		$params = [
			'user-agent' => self::DEFAULT_USER_AGENT,
			'headers'    => [
				'wp_install' => 'https://example.com/',
				'wp_blog'    => 'https://example.com/',
				'Accept'     => 'application/json',
			],
		];

		$params = $this->get_instance( self::QUIET )->modifyUserAgent( $params, self::CORE_CHECK_URL );

		self::assertSame( [ 'Accept' => 'application/json' ], $params['headers'] );
	}

	public function test_install_headers_are_kept_when_header_stripping_is_off() {
		Functions\expect( 'update_option' )->never();

		$original = [
			'user-agent' => self::DEFAULT_USER_AGENT,
			'headers'    => [
				'wp_install' => 'https://example.com/',
				'wp_blog'    => 'https://example.com/',
			],
		];

		$params = $this->get_instance( self::QUIET + [ 'strip_core_headers' => false ] )->modifyUserAgent( $original, self::CORE_CHECK_URL );

		self::assertSame( $original, $params );
	}

	public function test_install_headers_are_kept_for_other_requests() {
		$this->allow_saving_settings();

		$original = [
			'user-agent' => self::DEFAULT_USER_AGENT,
			'headers'    => [ 'wp_install' => 'https://example.com/' ],
		];

		$params = $this->get_instance( self::QUIET )->modifyUserAgent( $original, 'https://api.wordpress.org/plugins/info/1.2/' );

		self::assertSame( $original, $params );
	}

	public function test_site_details_are_removed_from_core_version_check_query() {
		$this->allow_saving_settings();

		$args = [
			'version'            => '6.8.1',
			'php'                => '8.4.0',
			'locale'             => 'en_US',
			'mysql'              => '8.0.35',
			'local_package'      => '',
			'blogs'              => 1,
			'users'              => 12,
			'multisite_enabled'  => 0,
			'initial_db_version' => 57155,
			'extensions'         => [ 'curl' => '8.4.0' ],
			'platform_flags'     => [ 'os' => 'Linux' ],
		];

		self::assertSame(
			[
				'version' => '6.8.1',
				'php'     => '8.4.0',
				'locale'  => 'en_US',
			],
			$this->get_instance()->filterCoreArgs( $args )
		);
	}

	public function test_core_version_check_query_is_left_alone_when_core_stripping_is_off() {
		Functions\expect( 'update_option' )->never();

		$args = [
			'version' => '6.8.1',
			'users'   => 12,
		];

		self::assertSame( $args, $this->get_instance( [ 'strip_core_data' => false ] )->filterCoreArgs( $args ) );
	}

	public function test_user_login_is_removed_from_rest_user_response() {
		$response       = new stdClass();
		$response->data = [
			'id'   => 1,
			'name' => 'Jane',
			'slug' => 'jane-login',
		];

		$response = $this->get_instance()->modifyRestUser( $response, null, null );

		self::assertSame(
			[
				'id'   => 1,
				'name' => 'Jane',
			],
			$response->data
		);
	}

	public function test_user_login_is_kept_when_login_stripping_is_off() {
		$response       = new stdClass();
		$response->data = [
			'id'   => 1,
			'slug' => 'jane-login',
		];

		$response = $this->get_instance( [ 'strip_user_logins' => false ] )->modifyRestUser( $response, null, null );

		self::assertSame( 'jane-login', $response->data['slug'] );
	}
}
