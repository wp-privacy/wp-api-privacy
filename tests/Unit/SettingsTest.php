<?php
/**
 * Tests for the settings.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use Brain\Monkey\Functions;
use WP_Privacy\WP_API_Privacy\Settings;

/**
 * Tests the defaults and the loading of stored settings.
 */
final class SettingsTest extends TestCase {

	public function test_fresh_install_has_every_privacy_option_on() {
		Functions\when( 'get_option' )->justReturn( false );

		$settings = new Settings();

		self::assertSame( 'strip_wp', $settings->getSetting( 'user_agent_behaviour' ) );

		foreach ( [ 'strip_wp_version', 'strip_plugins', 'strip_themes', 'strip_core_data', 'strip_core_headers', 'strip_user_logins' ] as $name ) {
			self::assertTrue( $settings->getSetting( $name ), $name );
		}

		self::assertFalse( $settings->getSetting( 'disable_https' ) );
		self::assertSame( 0, $settings->getSetting( 'modification_count' ) );
	}

	public function test_stored_settings_get_new_defaults_and_lose_removed_settings() {
		Functions\when( 'get_option' )->justReturn(
			(object) [
				'strip_plugins'   => false,
				'removed_setting' => true,
			]
		);

		$settings = new Settings();
		$stored   = null;

		Functions\expect( 'update_option' )
			->once()
			->andReturnUsing(
				static function ( $option, $value ) use ( &$stored ) {
					$stored = $value;

					return true;
				}
			);

		$settings->saveSettings();

		self::assertFalse( $stored->strip_plugins );
		self::assertTrue( $stored->strip_themes );
		self::assertFalse( isset( $stored->removed_setting ) );
	}

	public function test_uninstall_deletes_both_options() {
		Functions\expect( 'delete_option' )->once()->with( Settings::SETTING_KEY );
		Functions\expect( 'delete_option' )->once()->with( Settings::UPDATED_KEY );

		Settings::deleteAllOptions();
	}
}
