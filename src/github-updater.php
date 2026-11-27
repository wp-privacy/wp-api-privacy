<?php
/*
	Copyright (C) 2024 by Duane Storey - All Rights Reserved
	You may use, distribute and modify this code under the
	terms of the GPLv3 license.
 */

namespace WP_Privacy\WP_API_Privacy;

class GitHubUpdater {
	private const CACHE_TIME = ( 60 * 60 ); // 15 minutes

	protected $pluginSlug    = null;
	protected $githubUser    = null;
	protected $githubProject = null;
	protected $githubBranch  = null;
	protected $gibhubMainPhp = null;
	protected $githubTagApi  = null;

	protected $tagCacheKey    = null;
	protected $headerCacheKey = null;
	protected $cacheModifier  = null;

	protected $updateInfo = null;

	public function __construct( $pluginSlug, $githubUser, $githubProject, $githubBranch = 'main' ) {
		$this->pluginSlug    = $pluginSlug;
		$this->githubUser    = $githubUser;
		$this->githubProject = $githubProject;
		$this->githubBranch  = $githubBranch;

		if ( $this->hasValidInfo() && current_user_can( 'update_plugins' ) ) {
			$this->setupGithubUrls();
			$this->setupTransientKeys();

			// check if the user has manually tried to check all updates at Home/Updates in the WP admin
			if ( is_admin() && strpos( $_SERVER['REQUEST_URI'], 'update-core.php?force-check=1' ) !== false ) {
				// Take this out for now, need to think about API considerations
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
				'last_updated'   => 'updatedAt',
			);

			foreach ( $mappings as $key1 => $key2 ) {
				if ( isset( $this->updateInfo->$key2 ) && ! empty( $this->updateInfo->$key2 ) ) {
					$response->$key1 = $this->updateInfo->$key2;
				}
			}

			$response->sections = [];

			if ( isset( $this->updateInfo->description ) ) {
				$response->sections['description'] = $this->updateInfo->description;
			}

			if ( isset( $this->updateInfo->changeLog ) ) {
				$response->sections['changelog'] = $this->updateInfo->changeLog;
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
				version_compare( $this->updateInfo->requiresPhp, PHP_VERSION, '<' )
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
		$this->cacheModifier = md5( $this->pluginSlug );

		$this->tagCacheKey    = 'wp_priv_tag_' . $this->cacheModifier;
		$this->headerCacheKey = 'wp_priv_hdr_' . $this->cacheModifier;
	}

	private function setupGithubUrls() {
		$this->gibhubMainPhp = 'https://raw.githubusercontent.com/' . $this->githubUser . '/' . $this->githubProject .
			'/refs/heads/' . $this->githubBranch . '/' . basename( $this->pluginSlug );

		$this->githubTagApi = 'https://api.github.com/repos/' . $this->githubUser . '/' . $this->githubProject . '/releases';
	}

	private function generateChangeLog( $releaseInfo ) {
		$changeLog = '';
		foreach ( $releaseInfo as $release ) {
			if ( $release->target_commitish != $this->githubBranch ) {
				continue;
			}

			$changeLog .= '<strong>' . $release->tag_name . ' - ' . $release->name . '</strong>';
			$changeLog .= '<p>' . str_replace( "\r\n", '<br>', $release->body ) . '</p>';
		}

		return $changeLog;
	}

	private function getHeaderData( $headerData, $key, $default = false ) {
		$data = $default;

		if ( isset( $headerData ) && isset( $headerData[ $key ] ) && ! empty( $headerData[ $key ] ) ) {
			return $headerData[ $key ];
		}

		return $data;
	}

	private function checkForUpdate() {
		$headerData  = $this->getHeaderInfo();
		$releaseInfo = $this->getReleaseInfo();

		if ( $headerData && $releaseInfo ) {
			$latestVersion = $headerData['stable'];

			if ( $latestVersion ) {
				foreach ( $releaseInfo as $release ) {
					if ( $release->tag_name == $latestVersion && $release->target_commitish == $this->githubBranch ) {
						// found
						$this->updateInfo = new \stdClass();

						$this->updateInfo->requires    = $this->getHeaderData( $headerData, 'requires at least' );
						$this->updateInfo->testedUpTo  = $this->getHeaderData( $headerData, 'tested up to' );
						$this->updateInfo->requiresPhp = $this->getHeaderData( $headerData, 'requires php' );
						$this->updateInfo->name        = $this->getHeaderData( $headerData, 'plugin name' );
						$this->updateInfo->pluginUri   = $this->getHeaderData( $headerData, 'plugin uri' );
						$this->updateInfo->description = $this->getHeaderData( $headerData, 'description' );
						$this->updateInfo->author      = $this->getHeaderData( $headerData, 'author' );
						$this->updateInfo->authorUri   = $this->getHeaderData( $headerData, 'author uri' );
						$this->updateInfo->banner      = $this->getHeaderData( $headerData, 'banner' );

						$this->updateInfo->updatedAt = date( 'Y-m-d H:i', strtotime( $release->published_at ) );
						$this->updateInfo->changeLog = $this->generateChangeLog( $releaseInfo );

						$this->updateInfo->version = $latestVersion;

						if ( isset( $release->assets ) && isset( $release->assets[0]->browser_download_url ) ) {
							$this->updateInfo->updateUrl = $release->assets[0]->browser_download_url;
						}

						break;
					}
				}
			}
		}
	}

	private function deleteTransients() {
		if ( $this->hasValidInfo() ) {
			delete_transient( $this->tagCacheKey );
			delete_transient( $this->headerCacheKey );
		}
	}

	private function getReleaseInfo() {
		// Use the Github API to obtain release information
		$githubTagData = get_transient( $this->tagCacheKey );
		if ( ! $githubTagData ) {
			$result = wp_remote_get( $this->githubTagApi );
			if ( ! is_wp_error( $result ) ) {
				$githubTagData = json_decode( wp_remote_retrieve_body( $result ) );

				if ( $githubTagData ) {
					set_transient( $this->tagCacheKey, $githubTagData, self::CACHE_TIME );
				}
			}
		}

		return $githubTagData;
	}

	private function getHeaderInfo() {
		// Parse the main header file from the Github repo
		$headerData = get_transient( $this->headerCacheKey );
		if ( ! $headerData ) {
			$result = wp_remote_get( $this->gibhubMainPhp );
			if ( $result ) {
				if ( ! is_wp_error( $result ) ) {
					$body = wp_remote_retrieve_body( $result );
					if ( $body ) {
						if ( preg_match_all( '#[\s]+(.*): (.*)#', $body, $matches ) ) {
							$headers = [];

							for ( $i = 0; $i < count( $matches[0] ); $i++ ) {
								$headers[ strtolower( $matches[1][ $i ] ) ] = $matches[2][ $i ];
							}

							$headerData = $headers;
						}

						set_transient( $this->headerCacheKey, $headerData, self::CACHE_TIME );
						delete_transient( $this->tagCacheKey );
					}
				}
			}
		}

		return $headerData;
	}
}
