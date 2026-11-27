<?php
/*
	Copyright (C) 2024 by Duane Storey - All Rights Reserved
	You may use, distribute and modify this code under the
	terms of the GPLv3 license.
 */

namespace WP_Privacy\WP_API_Privacy;

/**
 * Offers plugin updates from GitHub.
 *
 * Only public URLs are used, never the GitHub API: unauthenticated API requests
 * are limited to 60 an hour per IP, which many sites on one shared host run out of.
 *
 * - The latest version is the Stable header of the main plugin file on the branch.
 * - The update is the release asset {project}.zip of the release tagged with that version.
 * - The changelog is CHANGELOG.md on the branch.
 */
class GitHubUpdater {
	// How long a successful check is cached. A forced check at Dashboard > Updates ignores it.
	private const CACHE_TIME = ( 15 * 60 );

	// How long a failed check is cached, so a GitHub outage isn't retried on every page load.
	private const RETRY_TIME = ( 5 * 60 );

	// Cached in place of a check that failed.
	private const FAILED = 'failed';

	protected $pluginSlug    = null;
	protected $githubUser    = null;
	protected $githubProject = null;
	protected $githubBranch  = null;

	/**
	 * Transient key of the cached update check.
	 *
	 * @var string|null
	 */
	protected $checkCacheKey = null;

	/**
	 * Transient key of the cached changelog.
	 *
	 * @var string|null
	 */
	protected $changeLogCacheKey = null;

	protected $updateInfo = null;

	public function __construct( $pluginSlug, $githubUser, $githubProject, $githubBranch = 'main' ) {
		$this->pluginSlug    = $pluginSlug;
		$this->githubUser    = $githubUser;
		$this->githubProject = $githubProject;
		$this->githubBranch  = $githubBranch;

		if ( $this->hasValidInfo() && current_user_can( 'update_plugins' ) ) {
			$this->setupTransientKeys();

			// check if the user has manually tried to check all updates at Home/Updates in the WP admin
			if ( is_admin() && isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'update-core.php?force-check=1' ) !== false ) {
				$this->deleteTransients();
			}

			$this->checkForUpdate();

			add_filter( 'plugins_api', [ $this, 'handlePluginInfo' ], 20, 3 );
			add_filter( 'site_transient_update_plugins', [ $this, 'handleUpdate' ] );
		}
	}

	public function handlePluginInfo( $response, $action, $params ) {
		if ( 'plugin_information' !== $action ) {
			return $response;
		}

		if ( empty( $params->slug ) || basename( $this->pluginSlug, '.php' ) !== $params->slug ) {
			return $response;
		}

		if ( $this->updateInfo ) {
			$response       = new \stdClass();
			$response->slug = basename( $this->pluginSlug, '.php' );

			$mappings = array(
				'name'           => 'name',
				'version'        => 'version',
				'compatible'     => 'testedUpTo',
				'requires'       => 'requires',
				'author'         => 'author',
				'author_profile' => 'authorUri',
				'homepage'       => 'pluginUri',
				'download_link'  => 'updateUrl',
				'requires_php'   => 'requiresPhp',
			);

			foreach ( $mappings as $key1 => $key2 ) {
				if ( isset( $this->updateInfo->$key2 ) && ! empty( $this->updateInfo->$key2 ) ) {
					$response->$key1 = $this->updateInfo->$key2;
				}
			}

			$response->sections = [];

			if ( ! empty( $this->updateInfo->description ) ) {
				$response->sections['description'] = $this->updateInfo->description;
			}

			$changeLog = $this->getChangeLog();
			if ( $changeLog ) {
				$response->sections['changelog'] = $changeLog;
			}

			if ( $this->updateInfo->banner ) {
				$response->banners = [
					'high' => $this->updateInfo->banner,
				];
			}
		}
		return $response;
	}

	public function handleUpdate( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		if ( $this->updateInfo ) {
			if ( version_compare( PRIVACY_VERSION, $this->updateInfo->version, '<' ) &&
				version_compare( $this->updateInfo->requires, get_bloginfo( 'version' ), '<=' ) &&
				version_compare( $this->updateInfo->requiresPhp, PHP_VERSION, '<=' )
			) {
				$response = new \stdClass();

				$response->slug        = basename( $this->pluginSlug, '.php' );
				$response->plugin      = $this->pluginSlug;
				$response->new_version = $this->updateInfo->version;
				$response->tested      = $this->updateInfo->testedUpTo;
				$response->package     = $this->updateInfo->updateUrl;

				$transient->response[ $response->plugin ] = $response;
			}
		}

		return $transient;
	}

	protected function hasValidInfo() {
		return ( $this->pluginSlug && $this->githubUser && $this->githubProject && $this->githubBranch );
	}

	protected function setupTransientKeys() {
		$cacheModifier = md5( $this->pluginSlug );

		$this->checkCacheKey     = 'wp_priv_check_' . $cacheModifier;
		$this->changeLogCacheKey = 'wp_priv_log_' . $cacheModifier;
	}

	/**
	 * The public URL of a file on the branch.
	 *
	 * @param string $file The file path in the repository.
	 *
	 * @return string
	 */
	private function getRawUrl( $file ) {
		return 'https://raw.githubusercontent.com/' . $this->githubUser . '/' . $this->githubProject .
			'/refs/heads/' . $this->githubBranch . '/' . $file;
	}

	/**
	 * The public download URL of the release zip for a version.
	 *
	 * @param string $version The version, which is also the release tag.
	 *
	 * @return string
	 */
	private function getPackageUrl( $version ) {
		return 'https://github.com/' . $this->githubUser . '/' . $this->githubProject .
			'/releases/download/' . rawurlencode( $version ) . '/' . $this->githubProject . '.zip';
	}

	private function getHeaderData( $headerData, $key, $default = false ) {
		$data = $default;

		if ( isset( $headerData ) && isset( $headerData[ $key ] ) && ! empty( $headerData[ $key ] ) ) {
			return $headerData[ $key ];
		}

		return $data;
	}

	private function checkForUpdate() {
		$headerData = $this->getUpdateCheck();
		if ( ! $headerData ) {
			return;
		}

		$this->updateInfo = new \stdClass();

		$this->updateInfo->requires    = $this->getHeaderData( $headerData, 'requires at least', '0' );
		$this->updateInfo->testedUpTo  = $this->getHeaderData( $headerData, 'tested up to' );
		$this->updateInfo->requiresPhp = $this->getHeaderData( $headerData, 'requires php', '0' );
		$this->updateInfo->name        = $this->getHeaderData( $headerData, 'plugin name' );
		$this->updateInfo->pluginUri   = $this->getHeaderData( $headerData, 'plugin uri' );
		$this->updateInfo->description = $this->getHeaderData( $headerData, 'description' );
		$this->updateInfo->author      = $this->getHeaderData( $headerData, 'author' );
		$this->updateInfo->authorUri   = $this->getHeaderData( $headerData, 'author uri' );
		$this->updateInfo->banner      = $this->getHeaderData( $headerData, 'banner' );

		$this->updateInfo->version   = $headerData['stable'];
		$this->updateInfo->updateUrl = $this->getPackageUrl( $headerData['stable'] );
	}

	/**
	 * The headers of the main plugin file on the branch, if its Stable version has a release zip.
	 *
	 * @return array<string, string>|false
	 */
	private function getUpdateCheck() {
		$cached = get_transient( $this->checkCacheKey );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : false;
		}

		$headerData = $this->getHeaderInfo();

		// The Stable header can be pushed before the release zip is uploaded, so the zip has to exist
		if ( ! $headerData || ! $this->isVersion( $headerData['stable'] ?? '' ) || ! $this->packageExists( $this->getPackageUrl( $headerData['stable'] ) ) ) {
			set_transient( $this->checkCacheKey, self::FAILED, self::RETRY_TIME );
			return false;
		}

		set_transient( $this->checkCacheKey, $headerData, self::CACHE_TIME );

		return $headerData;
	}

	/**
	 * Whether a value looks like a version number, such as 1.3.0 or 1.3.0-beta1.
	 *
	 * @param string $version The value.
	 *
	 * @return bool
	 */
	private function isVersion( $version ) {
		return (bool) preg_match( '#^\d+(\.\d+)*([-+][0-9A-Za-z.]+)?$#', $version );
	}

	private function deleteTransients() {
		if ( $this->hasValidInfo() ) {
			delete_transient( $this->checkCacheKey );
			delete_transient( $this->changeLogCacheKey );
		}
	}

	/**
	 * Fetches a public URL.
	 *
	 * @param string $url The URL.
	 *
	 * @return string|false The body, or false unless the response was a 200.
	 */
	private function getRemoteBody( $url ) {
		$result = wp_remote_get( $url );
		if ( is_wp_error( $result ) || 200 !== (int) wp_remote_retrieve_response_code( $result ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $result );
	}

	/**
	 * Whether a release zip can be downloaded. GitHub redirects existing release assets to its CDN.
	 *
	 * @param string $url The release zip URL.
	 *
	 * @return bool
	 */
	private function packageExists( $url ) {
		$result = wp_remote_head( $url );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $result );

		return ( $code >= 200 && $code < 400 );
	}

	/**
	 * Parses the headers of the main plugin file on the branch.
	 *
	 * @return array<string, string>|false
	 */
	private function getHeaderInfo() {
		$body = $this->getRemoteBody( $this->getRawUrl( basename( $this->pluginSlug ) ) );

		// Only the first comment block holds the plugin headers
		if ( ! $body || ! preg_match( '#/\*(.*?)\*/#s', $body, $comment ) ) {
			return false;
		}

		if ( ! preg_match_all( '#^[ \t*]*([A-Za-z][A-Za-z ]*?):[ \t]*(.*?)[ \t]*\r?$#m', $comment[1], $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		$headers = [];
		foreach ( $matches as $match ) {
			$key = strtolower( $match[1] );
			if ( ! isset( $headers[ $key ] ) ) {
				$headers[ $key ] = $match[2];
			}
		}

		return $headers;
	}

	/**
	 * The changelog as HTML, from CHANGELOG.md on the branch. Only fetched when the plugin details are viewed.
	 *
	 * @return string Empty if it can't be fetched.
	 */
	private function getChangeLog() {
		$cached = get_transient( $this->changeLogCacheKey );
		if ( false !== $cached ) {
			return $cached;
		}

		$markdown = $this->getRemoteBody( $this->getRawUrl( 'CHANGELOG.md' ) );
		if ( ! $markdown ) {
			set_transient( $this->changeLogCacheKey, '', self::RETRY_TIME );
			return '';
		}

		$changeLog = $this->formatChangeLog( $markdown );
		set_transient( $this->changeLogCacheKey, $changeLog, self::CACHE_TIME );

		return $changeLog;
	}

	/**
	 * Turns the headings, list items and paragraphs of CHANGELOG.md into HTML.
	 *
	 * @param string $markdown The changelog.
	 *
	 * @return string
	 */
	private function formatChangeLog( $markdown ) {
		$html   = '';
		$inList = false;

		foreach ( preg_split( '/\R/', $markdown ) as $line ) {
			$line = trim( $line );

			if ( preg_match( '#^[-*]\s+(.*)$#', $line, $match ) ) {
				if ( ! $inList ) {
					$html  .= '<ul>';
					$inList = true;
				}
				$html .= '<li>' . esc_html( $match[1] ) . '</li>';
				continue;
			}

			if ( $inList ) {
				$html  .= '</ul>';
				$inList = false;
			}

			if ( preg_match( '#^\#+\s+(.*)$#', $line, $match ) ) {
				$html .= '<h4>' . esc_html( $match[1] ) . '</h4>';
			} elseif ( '' !== $line ) {
				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
		}

		if ( $inList ) {
			$html .= '</ul>';
		}

		return $html;
	}
}
