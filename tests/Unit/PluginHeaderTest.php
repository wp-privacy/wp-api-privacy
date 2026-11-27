<?php
/**
 * Tests for the headers of the main plugin file, which the updater reads from GitHub.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

namespace WP_Privacy\WP_API_Privacy\Tests\Unit;

use PHPUnit\Framework\TestCase as PHPUnit_TestCase;

/**
 * Tests the headers in wp-api-privacy.php.
 */
final class PluginHeaderTest extends PHPUnit_TestCase {

	/**
	 * A header of the main plugin file.
	 *
	 * @param string $name The header name.
	 *
	 * @return string
	 */
	private function header( $name ) {
		$file = (string) file_get_contents( dirname( __DIR__, 2 ) . '/wp-api-privacy.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		self::assertSame( 1, preg_match( '#^\s*' . preg_quote( $name, '#' ) . ':\s*(.+?)\s*$#m', $file, $match ) );

		return $match[1];
	}

	/**
	 * On multisite, updates only appear in Network Admin, which only loads the updater
	 * when the plugin is network-activated (or active on the main site).
	 */
	public function test_plugin_can_only_be_network_activated() {
		self::assertSame( 'true', $this->header( 'Network' ) );
	}

	public function test_banner_is_a_file_in_the_repository() {
		$prefix = 'https://raw.githubusercontent.com/wp-privacy/wp-api-privacy/main/';
		$banner = $this->header( 'Banner' );

		self::assertStringStartsWith( $prefix, $banner );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/' . substr( $banner, strlen( $prefix ) ) );
	}
}
