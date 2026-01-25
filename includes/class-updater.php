<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {
	private Settings $settings;
	private Github_Client $github;

	public function __construct( Settings $settings, Github_Client $github ) {
		$this->settings = $settings;
		$this->github = $github;
	}

	public function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		// Use real plugin file keys (case-insensitive match) for WordPress update APIs.
		$installed_map = $this->settings->get_installed_plugin_map( $installed );
		$suite_plugins = $this->settings->get_suite_plugins();
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		foreach ( $suite_plugins as $plugin_file => $config ) {
			if ( empty( $config['repo'] ) ) {
				continue;
			}

			$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $plugin_file, $installed_map );
			$plugin_data = $installed[ $resolved_plugin_file ] ?? null;
			if ( ! is_array( $plugin_data ) ) {
				continue;
			}

			$current_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
			if ( $current_version === '' ) {
				continue;
			}

			$branch = isset( $config['branch'] ) ? (string) $config['branch'] : '';
			if ( $branch !== '' ) {
				$remote_version = $this->github->get_branch_plugin_version( $config['repo'], $branch, $plugin_file );
				if ( $remote_version === '' ) {
					continue;
				}

				$package = $this->github->get_branch_package_url( $config['repo'], $branch );
				if ( $package === '' ) {
					continue;
				}

				if ( version_compare( $current_version, $remote_version, '>=' ) ) {
					if ( isset( $transient->response[ $resolved_plugin_file ] ) ) {
						unset( $transient->response[ $resolved_plugin_file ] );
					}
					$transient->no_update[ $resolved_plugin_file ] = (object) array(
						'slug'        => $config['slug'],
						'plugin'      => $resolved_plugin_file,
						'new_version' => $remote_version,
						'url'         => 'https://github.com/' . $this->settings->sanitize_repo( $config['repo'] ),
						'package'     => '',
					);
					continue;
				}

				$transient->response[ $resolved_plugin_file ] = (object) array(
					'slug'        => $config['slug'],
					'plugin'      => $resolved_plugin_file,
					'new_version' => $remote_version,
					'url'         => 'https://github.com/' . $this->settings->sanitize_repo( $config['repo'] ),
					'package'     => $package,
					);
				continue;
			}

			$release = $this->github->get_latest_release( $config['repo'] );
			if ( ! is_array( $release ) ) {
				continue;
			}

			$remote_version = $this->github->normalize_version( (string) ( $release['tag_name'] ?? '' ) );
			if ( $remote_version === '' ) {
				continue;
			}

			if ( version_compare( $current_version, $remote_version, '>=' ) ) {
				if ( isset( $transient->response[ $resolved_plugin_file ] ) ) {
					unset( $transient->response[ $resolved_plugin_file ] );
				}
				$transient->no_update[ $resolved_plugin_file ] = (object) array(
					'slug'        => $config['slug'],
					'plugin'      => $resolved_plugin_file,
					'new_version' => $remote_version,
					'url'         => $release['html_url'] ?? '',
					'package'     => '',
				);
				continue;
			}

			$package = $this->github->resolve_release_package( $release, $resolved_plugin_file, $config );
			if ( $package === '' ) {
				continue;
			}

			$transient->response[ $resolved_plugin_file ] = (object) array(
				'slug'        => $config['slug'],
				'plugin'      => $resolved_plugin_file,
				'new_version' => $remote_version,
				'url'         => $release['html_url'] ?? '',
				'package'     => $package,
			);
		}

		return $transient;
	}

	public function filter_plugins_api( $res, $action, $args ) {
		if ( $action !== 'plugin_information' || ! is_object( $args ) || empty( $args->slug ) ) {
			return $res;
		}

		$suite_plugins = $this->settings->get_suite_plugins();
		$match = null;
		foreach ( $suite_plugins as $plugin_file => $config ) {
			if ( isset( $config['slug'] ) && $config['slug'] === $args->slug ) {
				$match = array(
					'plugin_file' => $plugin_file,
					'config'      => $config,
				);
				break;
			}
		}

		if ( ! $match || empty( $match['config']['repo'] ) ) {
			return $res;
		}

		$branch = isset( $match['config']['branch'] ) ? (string) $match['config']['branch'] : '';
		if ( $branch !== '' ) {
			$remote_version = $this->github->get_branch_plugin_version( $match['config']['repo'], $branch, $match['plugin_file'] );
			if ( $remote_version !== '' ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$installed = get_plugins();
				$installed_map = $this->settings->get_installed_plugin_map( $installed );
				$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $match['plugin_file'], $installed_map );
				$plugin_data = $installed[ $resolved_plugin_file ] ?? array();
				$branch_note = sprintf( __( 'Updates from branch: %s', 'voxmanager' ), $branch );

				$info = (object) array(
					'name'           => $plugin_data['Name'] ?? $match['config']['slug'],
					'slug'           => $match['config']['slug'],
					'version'        => $remote_version,
					'author'         => $plugin_data['Author'] ?? '',
					'author_profile' => $plugin_data['AuthorURI'] ?? '',
					'homepage'       => $plugin_data['PluginURI'] ?? 'https://github.com/' . $this->settings->sanitize_repo( $match['config']['repo'] ),
					'sections'       => array(
						'description' => wp_kses_post( wpautop( $branch_note ) ),
					),
					'download_link'  => $this->github->get_branch_package_url( $match['config']['repo'], $branch ),
				);

				return $info;
			}
		}

		$release = $this->github->get_latest_release( $match['config']['repo'] );
		if ( ! is_array( $release ) ) {
			return $res;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$installed_map = $this->settings->get_installed_plugin_map( $installed );
		$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $match['plugin_file'], $installed_map );
		$plugin_data = $installed[ $resolved_plugin_file ] ?? array();
		$description = isset( $release['body'] ) ? (string) $release['body'] : '';

		$info = (object) array(
			'name'          => $plugin_data['Name'] ?? $match['config']['slug'],
			'slug'          => $match['config']['slug'],
			'version'       => $this->normalize_version( (string) ( $release['tag_name'] ?? '' ) ),
			'author'        => $plugin_data['Author'] ?? '',
			'author_profile' => $plugin_data['AuthorURI'] ?? '',
			'homepage'      => $plugin_data['PluginURI'] ?? ( $release['html_url'] ?? '' ),
			'sections'      => array(
				'description' => $description !== '' ? wp_kses_post( wpautop( $description ) ) : '',
				'changelog'   => $description !== '' ? wp_kses_post( wpautop( $description ) ) : '',
			),
			'download_link' => $this->github->resolve_release_package( $release, $match['plugin_file'], $match['config'] ),
			'last_updated'  => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
		);

		return $info;
	}

	public function get_update_data( $updates, string $plugin_file ): array {
		$payload = array(
			'available' => false,
			'version'   => '',
			'url'       => '',
			'package'   => '',
		);

		if ( ! is_object( $updates ) || empty( $updates->response ) || ! is_array( $updates->response ) ) {
			return $payload;
		}

		$update = $updates->response[ $plugin_file ] ?? null;
		if ( ! is_object( $update ) ) {
			return $payload;
		}

		$payload['available'] = true;
		$payload['version'] = isset( $update->new_version ) ? (string) $update->new_version : '';
		$payload['url'] = isset( $update->url ) ? (string) $update->url : '';
		$payload['package'] = isset( $update->package ) ? (string) $update->package : '';

		return $payload;
	}

	public function format_last_checked( $updates ): string {
		if ( ! is_object( $updates ) || empty( $updates->last_checked ) ) {
			return '';
		}

		$timestamp = (int) $updates->last_checked;
		if ( $timestamp <= 0 ) {
			return '';
		}

		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );

		return date_i18n( trim( $date_format . ' ' . $time_format ), $timestamp );
	}

}
