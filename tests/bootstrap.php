<?php
/**
 * PHPUnit bootstrap file.
 *
 * The suite is made of Brain Monkey unit tests: WordPress itself is not loaded,
 * so the constants the plugin's main file would normally define are set here.
 *
 * @package WP_Privacy\WP_API_Privacy
 */

// The source files bail out unless WordPress is loaded.
define( 'WPINC', 'wp-includes' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Mirrors of the constants defined in wp-api-privacy.php.
define( 'PRIVACY_VERSION', '0.0.0-test' );
define( 'PRIVACY_PATH', dirname( __DIR__ ) );
define( 'PRIVACY_MAIN_FILE', dirname( __DIR__ ) . '/wp-api-privacy.php' );
define( 'PRIVACY_PATH_SRC', dirname( __DIR__ ) . '/src' );

// Loads the updater and settings classes as well.
require_once PRIVACY_PATH_SRC . '/api-privacy.php';
