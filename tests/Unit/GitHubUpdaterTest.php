<?php
/**
 * Tests for the GitHub updater.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Privacy\WP_API_Privacy\GitHubUpdater;

/**
 * Tests how GitHubUpdater finds and offers updates.
 *
 * The updater must only use public, non-API GitHub URLs: the unauthenticated
 * GitHub API allows 60 requests an hour per IP, which sites on shared hosting
 * run out of.
 */
final class GitHubUpdaterTest extends TestCase {

	private const PLUGIN_SLUG = 'wp-api-privacy/wp-api-privacy.php';

	private const MAIN_FILE_URL = 'https://raw.githubusercontent.com/wp-privacy/wp-api-privacy/refs/heads/main/wp-api-privacy.php';

	private const CHANGELOG_URL = 'https://raw.githubusercontent.com/wp-privacy/wp-api-privacy/refs/heads/main/CHANGELOG.md';

	private const ZIP_URL = 'https://github.com/wp-privacy/wp-api-privacy/releases/download/1.2.5/wp-api-privacy.zip';

	/**
	 * Stands in for a WP_Error.
	 */
	private const WP_ERROR = 'wp_error';

	/**
	 * Responses by URL: [ status code, body ], or self::WP_ERROR.
	 *
	 * @var array<string, mixed>
	 */
	private $responses = [];

	/**
	 * Every request made, as "METHOD url".
	 *
	 * @var string[]
	 */
	private $requests = [];

	/**
	 * Stored transients: name => [ value, expiration ].
	 *
	 * @var array<string, array{0: mixed, 1: int}>
	 */
	private $transients = [];

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->responses = [
			self::MAIN_FILE_URL => [ 200, $this->main_file( '1.2.5' ) ],
			self::ZIP_URL       => [ 302, '' ],
			self::CHANGELOG_URL => [ 200, "### Version 1.2.5 - Nov 26th, 2026\n\n- Fixed: Update checks on shared hosting\n" ],
		];

		$request = function ( $method ) {
			return function ( $url ) use ( $method ) {
				$this->requests[] = $method . ' ' . $url;

				return ( $this->responses[ $url ] ?? [ 404, 'Not Found' ] );
			};
		};
		Functions\when( 'wp_remote_get' )->alias( $request( 'GET' ) );
		Functions\when( 'wp_remote_head' )->alias( $request( 'HEAD' ) );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $response ) {
				return self::WP_ERROR === $response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) ? $response[0] : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return is_array( $response ) ? $response[1] : '';
			}
		);

		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return isset( $this->transients[ $name ] ) ? $this->transients[ $name ][0] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $expiration = 0 ) {
				$this->transients[ $name ] = [ $value, $expiration ];

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );

				return true;
			}
		);
	}

	/**
	 * The main plugin file as it would be on GitHub.
	 *
	 * @param string $stable      The Stable version.
	 * @param string $requires_php The Requires PHP version.
	 *
	 * @return string
	 */
	private function main_file( $stable, $requires_php = '7.4' ) {
		return "<?php\n/*\n"
			. "\tPlugin Name: WP API Privacy\n"
			. "\tVersion: {$stable}\n"
			. "\tRequires PHP: {$requires_php}\n"
			. "\tRequires at least: 6.0\n"
			. "\tTested up to: 7.2\n"
			. "\tStable: {$stable}\n"
			. "*/\n";
	}

	/**
	 * Creates the updater, which checks for an update straight away.
	 *
	 * @return GitHubUpdater
	 */
	private function updater() {
		return new GitHubUpdater( self::PLUGIN_SLUG, 'wp-privacy', 'wp-api-privacy', 'main' );
	}

	/**
	 * Runs WordPress's update_plugins transient through the updater.
	 *
	 * @param GitHubUpdater $updater The updater.
	 *
	 * @return object|null The update offered for the plugin, if any.
	 */
	private function offered_update( GitHubUpdater $updater ) {
		$transient          = new \stdClass();
		$transient->checked = [ self::PLUGIN_SLUG => PRIVACY_VERSION ];

		$transient = $updater->handleUpdate( $transient );

		return ( $transient->response[ self::PLUGIN_SLUG ] ?? null );
	}

	public function test_offers_update_from_stable_header_with_release_download_url() {
		$update = $this->offered_update( $this->updater() );

		self::assertNotNull( $update );
		self::assertSame( '1.2.5', $update->new_version );
		self::assertSame( self::ZIP_URL, $update->package );
		self::assertSame( 'wp-api-privacy', $update->slug );
		self::assertSame( '7.2', $update->tested );
	}

	public function test_never_calls_the_github_api() {
		$updater = $this->updater();
		$this->offered_update( $updater );
		$updater->handlePluginInfo( false, 'plugin_information', (object) [ 'slug' => 'wp-api-privacy' ] );

		self::assertNotEmpty( $this->requests );
		foreach ( $this->requests as $request ) {
			self::assertStringNotContainsString( 'api.github.com', $request );
		}
	}

	public function test_no_update_when_already_on_stable_version() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 200, $this->main_file( PRIVACY_VERSION ) ];

		self::assertNull( $this->offered_update( $this->updater() ) );
	}

	public function test_no_update_when_release_zip_does_not_exist_yet() {
		unset( $this->responses[ self::ZIP_URL ] );

		self::assertNull( $this->offered_update( $this->updater() ) );
	}

	public function test_update_offered_when_php_version_equals_requirement() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 200, $this->main_file( '1.2.5', PHP_VERSION ) ];

		self::assertNotNull( $this->offered_update( $this->updater() ) );
	}

	public function test_no_update_when_php_is_too_old() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 200, $this->main_file( '1.2.5', '99.0' ) ];

		self::assertNull( $this->offered_update( $this->updater() ) );
	}

	public function test_no_update_when_github_returns_an_error_status() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 429, "429: Too Many Requests\nStable: 9.9.9\n" ];

		self::assertNull( $this->offered_update( $this->updater() ) );
	}

	public function test_no_update_when_request_fails() {
		$this->responses[ self::MAIN_FILE_URL ] = self::WP_ERROR;

		self::assertNull( $this->offered_update( $this->updater() ) );
	}

	public function test_failed_check_is_not_retried_on_every_page_load() {
		$this->responses[ self::MAIN_FILE_URL ] = self::WP_ERROR;

		$this->updater();
		$this->updater();

		self::assertSame( [ 'GET ' . self::MAIN_FILE_URL ], $this->requests );
	}

	public function test_failed_check_is_retried_sooner_than_a_successful_one() {
		$this->updater();
		$success_expiration = max( array_column( $this->transients, 1 ) );

		$this->transients                       = [];
		$this->responses[ self::MAIN_FILE_URL ] = self::WP_ERROR;
		$this->updater();
		$failure_expiration = max( array_column( $this->transients, 1 ) );

		self::assertGreaterThan( 0, $failure_expiration );
		self::assertLessThan( $success_expiration, $failure_expiration );
	}

	public function test_cached_check_makes_no_requests() {
		$this->updater();
		$this->requests = [];

		$update = $this->offered_update( $this->updater() );

		self::assertSame( [], $this->requests );
		self::assertNotNull( $update );
	}

	public function test_plugin_information_includes_changelog_from_changelog_file() {
		$info = $this->updater()->handlePluginInfo( false, 'plugin_information', (object) [ 'slug' => 'wp-api-privacy' ] );

		self::assertSame( '1.2.5', $info->version );
		self::assertSame( self::ZIP_URL, $info->download_link );
		self::assertStringContainsString( 'Version 1.2.5', $info->sections['changelog'] );
		self::assertStringContainsString( 'Fixed: Update checks on shared hosting', $info->sections['changelog'] );
	}

	public function test_plugin_information_works_without_changelog() {
		unset( $this->responses[ self::CHANGELOG_URL ] );

		$info = $this->updater()->handlePluginInfo( false, 'plugin_information', (object) [ 'slug' => 'wp-api-privacy' ] );

		self::assertSame( '1.2.5', $info->version );
		self::assertArrayNotHasKey( 'changelog', $info->sections );
	}

	public function test_headers_after_the_header_comment_are_ignored() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 200, $this->main_file( '1.2.5' ) . "/*\n\tStable: 9.9.9\n*/\n" ];

		$update = $this->offered_update( $this->updater() );

		self::assertSame( '1.2.5', $update->new_version );
	}

	public function test_header_values_may_contain_a_colon() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 200, str_replace( "*/\n", "\tDescription: Privacy: strips data\n*/\n", $this->main_file( '1.2.5' ) ) ];

		$info = $this->updater()->handlePluginInfo( false, 'plugin_information', (object) [ 'slug' => 'wp-api-privacy' ] );

		self::assertSame( 'Privacy: strips data', $info->sections['description'] );
	}

	public function test_headers_with_windows_line_endings_are_parsed() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 200, str_replace( "\n", "\r\n", $this->main_file( '1.2.5' ) ) ];

		self::assertSame( '1.2.5', $this->offered_update( $this->updater() )->new_version );
	}

	public function test_no_update_when_stable_is_not_a_version() {
		$this->responses[ self::MAIN_FILE_URL ] = [ 200, $this->main_file( '../../evil' ) ];

		self::assertNull( $this->offered_update( $this->updater() ) );
		self::assertSame( [ 'GET ' . self::MAIN_FILE_URL ], $this->requests );
	}

	public function test_no_update_when_release_zip_check_fails_with_server_error() {
		$this->responses[ self::ZIP_URL ] = [ 500, '' ];

		self::assertNull( $this->offered_update( $this->updater() ) );
	}

	public function test_no_update_when_release_zip_check_fails() {
		$this->responses[ self::ZIP_URL ] = self::WP_ERROR;

		self::assertNull( $this->offered_update( $this->updater() ) );
	}

	public function test_missing_release_zip_is_checked_with_head_and_not_retried_on_every_page_load() {
		unset( $this->responses[ self::ZIP_URL ] );

		$this->updater();
		$this->updater();

		self::assertSame( [ 'GET ' . self::MAIN_FILE_URL, 'HEAD ' . self::ZIP_URL ], $this->requests );
	}

	public function test_changelog_html_is_escaped() {
		$this->responses[ self::CHANGELOG_URL ] = [ 200, "### <img src=x onerror=alert(1)>\n\n- <script>alert(1)</script>\n" ];

		$info = $this->updater()->handlePluginInfo( false, 'plugin_information', (object) [ 'slug' => 'wp-api-privacy' ] );

		self::assertStringNotContainsString( '<script', $info->sections['changelog'] );
		self::assertStringNotContainsString( '<img', $info->sections['changelog'] );
	}

	public function test_changelog_headings_and_lists_become_html() {
		$info = $this->updater()->handlePluginInfo( false, 'plugin_information', (object) [ 'slug' => 'wp-api-privacy' ] );

		self::assertSame(
			'<h4>Version 1.2.5 - Nov 26th, 2026</h4><ul><li>Fixed: Update checks on shared hosting</li></ul>',
			$info->sections['changelog']
		);
	}

	public function test_plugin_information_leaves_other_plugins_alone() {
		$info = $this->updater()->handlePluginInfo( false, 'plugin_information', (object) [ 'slug' => 'akismet' ] );

		self::assertFalse( $info );
	}
}
