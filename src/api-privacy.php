<?php
/*
	Copyright (C) 2024 by Duane Storey - All Rights Reserved
	You may use, distribute and modify this code under the
	terms of the GPLv3 license.
 */

namespace WP_Privacy\WP_API_Privacy;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once PRIVACY_PATH_SRC . '/github-updater.php';
require_once PRIVACY_PATH_SRC . '/settings.php';

class ApiPrivacy extends GithubUpdater {
	private const USER_AGENT = 'WordPress/Private';

	private static $instance = null;

	protected $settings = null;

	protected function __construct() {
		$this->settings = new Settings();

		// set up our user-agent filter
		add_filter( 'http_request_args', array( $this, 'modifyUserAgent' ), 0, 2 );
		add_filter( 'rest_prepare_user', array( $this, 'modifyRestUser' ), 10, 3 );
		add_action( 'http_api_curl', array( $this, 'maybeModifyCurl' ), 10, 3 );
		add_filter( 'core_version_check_query_args', array( $this, 'filterCoreArgs' ) );

		// Plugin action links
		add_filter( 'plugin_action_links_' . plugin_basename( PRIVACY_MAIN_FILE ), array( $this, 'addActionLinks' ) );

		// initialize the updater
		parent::__construct(
			'wp-api-privacy/wp-api-privacy.php',
			'wp-privacy',
			'wp-api-privacy',
			'main'
		);
	}

	public function init() {
		$this->settings->init();
	}

	public function getSetting( $name ) {
		return $this->settings->getSetting( $name );
	}

	public function addActionLinks( $actions ) {
		$links = array(
			'<a href="' . admin_url( 'options-general.php?page=api-privacy' ) . '">' . esc_html__( 'Settings', 'wp-api-privacy' ) . '</a>',
		);

		return array_merge( $links, $actions );
	}

	public function filterCoreArgs( $args ) {
		if ( $this->getSetting( 'strip_core_data' ) ) {
			$this->updateApiModificationCount();

			$removeTags = [ 'blogs', 'users', 'mysql', 'multisite_enabled', 'initial_db_version', 'local_package', 'extensions', 'platform_flags' ];
			foreach ( $removeTags as $tag ) {
				if ( isset( $args[ $tag ] ) ) {
					unset( $args[ $tag ] );
				}
			}
		}

		return $args;
	}

	public function maybeModifyCurl( $handle, $params, $url ) {
		if ( $handle ) {
			// Requests to GitHub stay on HTTPS: they fetch this plugin's updates, which could be swapped over plain HTTP
			if ( $this->getSetting( 'disable_https' ) && ! $this->isGithubUrl( $url ) ) {
				$url = str_replace( 'https://', 'http://', $url );

				curl_setopt( $handle, CURLOPT_URL, $url );
			}
		}

		return $handle;
	}

	/**
	 * Whether a URL is on github.com or githubusercontent.com, or a subdomain of them.
	 *
	 * @param string $url The URL.
	 *
	 * @return bool
	 */
	private function isGithubUrl( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return (bool) preg_match( '#(^|\.)(github\.com|githubusercontent\.com)$#', $host );
	}

	public function modifyRestUser( $response, $user, $request ) {
		if ( $this->getSetting( 'strip_user_logins' ) && isset( $response->data ) && isset( $response->data['slug'] ) ) {
			unset( $response->data['slug'] );
		}

		return $response;
	}

	public function updateApiModificationCount() {
		$count = $this->settings->getSetting( 'modification_count' );
		if ( is_int( $count ) ) {
			++$count;
			$this->settings->setSetting( 'modification_count', $count );
			$this->settings->saveSettings();
		}
	}

	public function getUniqueSiteHash() {
		if ( defined( 'NONCE_KEY' ) ) {
			return 'https://' . md5( NONCE_KEY . get_bloginfo( 'url' ) ) . '.com';
		} else {
			return 'https://' . md5( get_bloginfo( 'url' ) ) . '.com';
		}
	}

	private function removeUrlFromUserAgent( $str ) {
		if ( strpos( $str, ';' ) !== false ) {
			$split = explode( ';', $str );

			return $split[0];
		}

		return $str;
	}

	public function modifyUserAgent( $params, $url ) {
		$wasModified = false;

		$wordPressJetpackUrls = [
			'api.wordpress.org',
			// 'jetpack.wordpress.com',
			// 'public-api.wordpress.com'
		];

		$wordPressJetpackUrls = apply_filters( 'api_privacy_wp_urls', $wordPressJetpackUrls );

		$isWordPressAPI = false;
		foreach ( $wordPressJetpackUrls as $wpUrl ) {
			if ( strpos( $url, $wpUrl ) !== false ) {
				$isWordPressAPI = true;
				break;
			}
		}

		if ( $this->getSetting( 'strip_wp_version' ) && ! empty( $params['user-agent'] ) ) {
			// remove the version
			$params['user-agent'] = str_replace( get_bloginfo( 'version' ), 'Private', $params['user-agent'] );
			$wasModified          = true;
		}

		$behaviour = $this->getSetting( 'user_agent_behaviour' );
		if ( $behaviour != 'none' && ! empty( $params['user-agent'] ) ) {
			switch ( $behaviour ) {
				case 'strip_wp':
					if ( $isWordPressAPI ) {
						$params['user-agent'] = $this->removeUrlFromUserAgent( $params['user-agent'] );
						$wasModified          = true;
					}
					break;
				case 'strip_all':
					// no URL provided at all
					$params['user-agent'] = $this->removeUrlFromUserAgent( $params['user-agent'] );
					$wasModified          = true;
					break;
				case 'modify_wp':
					if ( $isWordPressAPI ) {
						$params['user-agent'] = $this->removeUrlFromUserAgent( $params['user-agent'] ) . '; ' . $this->getUniqueSiteHash();
						$wasModified          = true;
					}
					break;
				case 'modify_all':
					// Modify it always
					$params['user-agent'] = $this->removeUrlFromUserAgent( $params['user-agent'] ) . '; ' . $this->getUniqueSiteHash();
					$wasModified          = true;
					break;
				default:
					break;
			}
		}

		// Remove plugins hosted off-site, nobody needs to know these - for now this just uses the 'Update URI' parameter
		if ( $this->getSetting( 'strip_plugins' ) && strpos( $url, 'wordpress.org/plugins/update-check/' ) !== false ) {
			$decodedJson = json_decode( $params['body']['plugins'] );
			if ( $decodedJson ) {
				// check for plugin info
				if ( $decodedJson->plugins ) {
					$toRemove = [];
					$toKeep   = [];
					foreach ( $decodedJson->plugins as $name => $plugin ) {
						if ( isset( $plugin->UpdateURI ) && ! empty( $plugin->UpdateURI ) ) {
							// don't remove ones hosted on wordpress.org
							if ( strpos( $plugin->UpdateURI, 'wordpress.org' ) === false ) {
								$toRemove[] = $name;
								continue;
							}
						}

						$toKeep[] = $name;
					}

					foreach ( $toRemove as $remove ) {
						unset( $decodedJson->plugins->$remove );
					}

					$decodedJson->active = $toKeep;
				}
				$params['body']['plugins'] = json_encode( $decodedJson );
				$wasModified               = true;
			}
		} elseif ( $this->getSetting( 'strip_themes' ) && strpos( $url, 'wordpress.org/themes/update-check/' ) !== false ) {
			$decodedJson = json_decode( $params['body']['themes'] );
			if ( $decodedJson ) {
				// check for theme info
				if ( $decodedJson->themes ) {
					$toRemove = [];
					foreach ( $decodedJson->themes as $name => $theme ) {
						if ( isset( $theme->UpdateURI ) && ! empty( $theme->UpdateURI ) ) {
							// don't remove ones hosted on wordpress.org
							if ( strpos( $theme->UpdateURI, 'wordpress.org' ) === false ) {
								$toRemove[] = $name;
							}
						}
					}

					foreach ( $toRemove as $remove ) {
						unset( $decodedJson->themes->$remove );
					}
				}
				$params['body']['themes'] = json_encode( $decodedJson );
				$wasModified              = true;
			}
		} if ( $this->getSetting( 'strip_core_headers' ) && strpos( $url, 'api.wordpress.org/core/version-check' ) !== false ) {
			if ( isset( $params['headers'] ) ) {
				if ( isset( $params['headers']['wp_install'] ) ) {
					unset( $params['headers']['wp_install'] );
				}

				if ( isset( $params['headers']['wp_blog'] ) ) {
					unset( $params['headers']['wp_blog'] );
				}
			}
			$wasModified = true;
		}

		if ( $wasModified ) {
			$this->updateApiModificationCount();
		}

		return $params;
	}

	static function instance() {
		if ( self::$instance == null ) {
			self::$instance = new ApiPrivacy();
		}

		return self::$instance;
	}
}
