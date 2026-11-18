<?php
/**
 * Tests for the settings form handling.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_Privacy\WP_API_Privacy\Settings;

/**
 * Tests what a submitted settings form may and may not change.
 */
final class SettingsFormTest extends TestCase {

	/**
	 * The settings as they are in the options table, false when there are none.
	 *
	 * @var object|false
	 */
	private $stored = false;

	/**
	 * The names of the options that were updated.
	 *
	 * @var string[]
	 */
	private $updated = [];

	/**
	 * Sets up an options table and a user who is allowed to save the form.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->stored  = false;
		$this->updated = [];
		$_POST         = [];

		Functions\when( 'get_option' )->alias(
			function ( $option, $default_value = false ) {
				if ( $option === Settings::SETTING_KEY && $this->stored !== false ) {
					return clone $this->stored;
				}

				return $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $option, $value ) {
				$this->updated[] = $option;
				if ( $option === Settings::SETTING_KEY ) {
					$this->stored = clone $value;
				}

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $option ) {
				if ( $option === Settings::SETTING_KEY ) {
					$this->stored = false;
				}

				return true;
			}
		);

		Functions\when( 'wp_unslash' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return is_scalar( $value ) ? trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) ) : '';
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, $action ) {
				return ( $nonce === 'valid-nonce' && $action === 'wpprivacy' ) ? 1 : false;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static function ( $capability ) {
				return $capability === 'manage_options';
			}
		);
	}

	/**
	 * Resets the submitted form.
	 */
	protected function tearDown(): void {
		$_POST = [];

		parent::tearDown();
	}

	/**
	 * Builds the POST data the settings form sends.
	 *
	 * @param string   $behaviour The selected User-Agent behaviour.
	 * @param string[] $ticked    The names of the ticked checkboxes.
	 *
	 * @return array<string, string>
	 */
	private function form_data( $behaviour, array $ticked ) {
		$checkboxes = [
			'strip_wp_version',
			'strip_plugins',
			'strip_themes',
			'strip_core_data',
			'strip_core_headers',
			'strip_user_logins',
			'disable_https',
			'reset_settings',
		];

		$data = [
			'wp_api_privacy_settings'        => '1',
			'wp_api_privacy_nonce'           => 'valid-nonce',
			'wpsetting_user_agent_behaviour' => $behaviour,
			'submit'                         => 'Save Changes',
		];

		foreach ( $checkboxes as $name ) {
			$data[ 'wpcheckbox_' . $name ] = '1';
			if ( in_array( $name, $ticked, true ) ) {
				$data[ 'wpsetting_' . $name ] = 'on';
			}
		}

		return $data;
	}

	public function test_valid_submit_saves_checkbox_and_select_values() {
		$_POST = $this->form_data( 'modify_all', [ 'strip_plugins', 'strip_user_logins', 'disable_https' ] );

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( [ Settings::UPDATED_KEY, Settings::SETTING_KEY ], $this->updated );

		self::assertSame( 'modify_all', $this->stored->user_agent_behaviour );
		self::assertTrue( $this->stored->strip_plugins );
		self::assertTrue( $this->stored->strip_user_logins );
		self::assertTrue( $this->stored->disable_https );
		self::assertFalse( $this->stored->strip_wp_version );
		self::assertFalse( $this->stored->strip_themes );
		self::assertFalse( $this->stored->strip_core_data );
		self::assertFalse( $this->stored->strip_core_headers );
		self::assertFalse( $this->stored->reset_settings );
		self::assertSame( 0, $this->stored->modification_count );

		self::assertSame( 'modify_all', $settings->getSetting( 'user_agent_behaviour' ) );
		self::assertFalse( $settings->getSetting( 'strip_themes' ) );
	}

	public function test_every_known_select_value_is_accepted() {
		foreach ( [ 'none', 'strip_wp', 'strip_all', 'modify_wp', 'modify_all' ] as $behaviour ) {
			$_POST = $this->form_data( $behaviour, [] );

			$settings = new Settings();
			$settings->processSubmittedSettings();

			self::assertSame( $behaviour, $this->stored->user_agent_behaviour );
		}
	}

	public function test_slashed_select_value_is_unslashed_before_it_is_checked() {
		$_POST = $this->form_data( 'strip\\_all', [] );

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( 'strip_all', $this->stored->user_agent_behaviour );
	}

	/**
	 * An unknown select value keeps the current value, the rest of the form is saved.
	 *
	 * @param mixed $value The submitted value.
	 */
	#[DataProvider( 'invalid_select_values' )]
	public function test_invalid_select_value_is_rejected( $value ) {
		$this->stored = (object) [ 'user_agent_behaviour' => 'modify_wp' ];

		$_POST                                   = $this->form_data( 'none', [ 'strip_plugins' ] );
		$_POST['wpsetting_user_agent_behaviour'] = $value;

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( 'modify_wp', $this->stored->user_agent_behaviour );
		self::assertSame( 'modify_wp', $settings->getSetting( 'user_agent_behaviour' ) );
		self::assertTrue( $this->stored->strip_plugins );
		self::assertFalse( $this->stored->strip_themes );
	}

	/**
	 * Values that are not one of the options of the select.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_select_values() {
		return [
			'unknown value' => [ 'everything' ],
			'markup'        => [ '<script>alert(1)</script>' ],
			'empty'         => [ '' ],
			'wrong case'    => [ 'STRIP_ALL' ],
			'array'         => [ [ 'strip_all' ] ],
		];
	}

	public function test_select_cannot_be_turned_into_a_checkbox() {
		$_POST                                    = $this->form_data( 'strip_all', [] );
		$_POST['wpcheckbox_user_agent_behaviour'] = '1';

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( 'strip_all', $this->stored->user_agent_behaviour );
	}

	public function test_checkbox_value_without_its_marker_is_ignored() {
		$_POST = $this->form_data( 'strip_wp', [ 'strip_plugins' ] );
		unset( $_POST['wpcheckbox_strip_themes'] );
		$_POST['wpsetting_strip_themes'] = 'anything';

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertTrue( $this->stored->strip_themes );
	}

	/**
	 * The modification count is an internal counter, not a form field.
	 *
	 * @param array<string, string> $forged The forged form fields.
	 */
	#[DataProvider( 'forged_modification_counts' )]
	public function test_forged_modification_count_is_ignored( array $forged ) {
		$this->stored = (object) [ 'modification_count' => 5 ];

		$_POST = array_merge( $this->form_data( 'strip_wp', [ 'strip_plugins' ] ), $forged );

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( 5, $this->stored->modification_count );
		self::assertSame( 5, $settings->getSetting( 'modification_count' ) );
	}

	/**
	 * The ways a form could try to set the modification count.
	 *
	 * @return array<string, array{array<string, string>}>
	 */
	public static function forged_modification_counts() {
		return [
			'as a value'              => [ [ 'wpsetting_modification_count' => '999999' ] ],
			'as a string'             => [ [ 'wpsetting_modification_count' => 'not a number' ] ],
			'as a ticked checkbox'    => [
				[
					'wpcheckbox_modification_count' => '1',
					'wpsetting_modification_count'  => 'on',
				],
			],
			'as an unticked checkbox' => [ [ 'wpcheckbox_modification_count' => '1' ] ],
		];
	}

	public function test_bad_nonce_saves_nothing() {
		$_POST                         = $this->form_data( 'none', [] );
		$_POST['wp_api_privacy_nonce'] = 'forged-nonce';

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( [], $this->updated );
		self::assertSame( 'strip_wp', $settings->getSetting( 'user_agent_behaviour' ) );
		self::assertTrue( $settings->getSetting( 'strip_plugins' ) );
	}

	public function test_missing_nonce_saves_nothing() {
		$_POST = $this->form_data( 'none', [] );
		unset( $_POST['wp_api_privacy_nonce'] );

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( [], $this->updated );
		self::assertSame( 'strip_wp', $settings->getSetting( 'user_agent_behaviour' ) );
		self::assertTrue( $settings->getSetting( 'strip_plugins' ) );
	}

	public function test_missing_capability_saves_nothing() {
		Functions\when( 'current_user_can' )->justReturn( false );

		$_POST = $this->form_data( 'none', [] );

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( [], $this->updated );
		self::assertSame( 'strip_wp', $settings->getSetting( 'user_agent_behaviour' ) );
		self::assertTrue( $settings->getSetting( 'strip_plugins' ) );
	}

	public function test_nothing_is_saved_without_a_submitted_form() {
		$_POST = [ 'wpsetting_user_agent_behaviour' => 'none' ];

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertSame( [], $this->updated );
		self::assertSame( 'strip_wp', $settings->getSetting( 'user_agent_behaviour' ) );
	}

	public function test_reset_restores_the_defaults() {
		$this->stored = (object) [
			'user_agent_behaviour' => 'none',
			'strip_plugins'        => false,
			'modification_count'   => 12,
		];

		$_POST = $this->form_data( 'modify_all', [ 'disable_https', 'reset_settings' ] );

		$settings = new Settings();
		$settings->processSubmittedSettings();

		self::assertFalse( $this->stored, 'The stored settings should have been deleted.' );
		self::assertSame( [ Settings::UPDATED_KEY ], $this->updated );

		self::assertEquals( $settings->getDefaultSettings(), (object) $this->settings_of( $settings ) );
	}

	/**
	 * Reads every setting through the public getter.
	 *
	 * @param Settings $settings The settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_of( Settings $settings ) {
		$values = [];
		foreach ( array_keys( get_object_vars( $settings->getDefaultSettings() ) ) as $name ) {
			$values[ $name ] = $settings->getSetting( $name );
		}

		return $values;
	}
}
