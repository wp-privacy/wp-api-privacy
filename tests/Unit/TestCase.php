<?php
/**
 * Base test case.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnit_TestCase;
use ReflectionProperty;
use WP_Privacy\WP_API_Privacy\ApiPrivacy;
use WP_Privacy\WP_API_Privacy\Settings;

/**
 * Sets up Brain Monkey and the WordPress function stubs the plugin needs.
 */
abstract class TestCase extends PHPUnit_TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * The site URL WordPress would put in its User-Agent.
	 */
	protected const SITE_URL = 'https://example.com';

	/**
	 * The WordPress version WordPress would put in its User-Agent.
	 */
	protected const WP_VERSION = '6.8.1';

	/**
	 * The User-Agent WordPress sends by default.
	 */
	protected const DEFAULT_USER_AGENT = 'WordPress/' . self::WP_VERSION . '; ' . self::SITE_URL;

	/**
	 * Sets up the WordPress function stubs.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'plugin_basename' )->justReturn( 'wp-api-privacy/wp-api-privacy.php' );

		// A wrapper around parse_url() in WordPress.
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		// Keeps the GitHub updater inert: it only sets itself up for users who can update plugins.
		Functions\when( 'current_user_can' )->justReturn( false );

		Functions\when( 'get_bloginfo' )->alias(
			static function ( $show = '' ) {
				$info = [
					'version' => self::WP_VERSION,
					'url'     => self::SITE_URL,
				];

				return ( $info[ $show ] ?? '' );
			}
		);

		$this->reset_instance();
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tearDown(): void {
		$this->reset_instance();

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Creates the plugin instance with the given stored settings.
	 *
	 * @param array<string, mixed> $stored Settings as stored in the options table. Missing
	 *                                     settings get their default value, just like in
	 *                                     the plugin. Empty for a fresh install.
	 *
	 * @return ApiPrivacy
	 */
	protected function get_instance( array $stored = [] ) {
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( $stored ) {
				if ( $option === Settings::SETTING_KEY && $stored !== [] ) {
					return (object) $stored;
				}

				return $default_value;
			}
		);

		$this->reset_instance();

		return ApiPrivacy::instance();
	}

	/**
	 * Lets the plugin save its settings without asserting on it.
	 */
	protected function allow_saving_settings() {
		Functions\when( 'update_option' )->justReturn( true );
	}

	/**
	 * Clears the plugin's singleton, so every test starts with fresh settings.
	 */
	private function reset_instance() {
		$property = new ReflectionProperty( ApiPrivacy::class, 'instance' );
		$property->setValue( null, null );
	}
}
