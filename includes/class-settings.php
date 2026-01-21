<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	private const OPTION_NAME = 'voxmanager_settings';

	public function get_settings(): array {
		$defaults = array(
			'github_token'   => '',
			'release_source' => 'releases',
			'package_source' => 'asset',
			'plugins'        => array(),
		);

		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( $defaults, $settings );
	}

	public function get_suite_plugins(): array {
		$defaults = $this->get_default_suite_plugins();
		$settings = $this->get_settings();
		$saved    = array();

		if ( isset( $settings['plugins'] ) && is_array( $settings['plugins'] ) ) {
			$saved = $settings['plugins'];
		}

		$plugins = array_merge( $defaults, $saved );
		$plugins = apply_filters( 'voxmanager_suite_plugins', $plugins );

		$normalized = array();
		foreach ( $plugins as $plugin_file => $config ) {
			if ( is_int( $plugin_file ) && is_string( $config ) ) {
				$plugin_file = $config;
				$config = array();
			}

			if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
				continue;
			}

			if ( ! is_array( $config ) ) {
				$config = array();
			}

			$normalized[ $plugin_file ] = array(
				'label' => isset( $config['label'] ) && is_string( $config['label'] ) ? $config['label'] : '',
				'slug'  => isset( $config['slug'] ) && is_string( $config['slug'] ) ? $config['slug'] : sanitize_key( dirname( $plugin_file ) ),
				'repo'  => isset( $config['repo'] ) && is_string( $config['repo'] ) ? $this->sanitize_repo( $config['repo'] ) : '',
				'branch' => isset( $config['branch'] ) && is_string( $config['branch'] ) ? $this->sanitize_branch( $config['branch'] ) : '',
			);
		}

		return $normalized;
	}

	public function sanitize_plugin_settings( array $raw_plugins ): array {
		$defaults = $this->get_default_suite_plugins();
		$sanitized = array();

		foreach ( $raw_plugins as $plugin_file => $data ) {
			$plugin_file = is_string( $plugin_file ) ? $plugin_file : '';
			if ( $plugin_file === '' || ! isset( $defaults[ $plugin_file ] ) ) {
				continue;
			}

			$data = is_array( $data ) ? $data : array();
			$sanitized[ $plugin_file ] = array(
				'label' => $defaults[ $plugin_file ]['label'],
				'slug'  => isset( $data['slug'] ) ? sanitize_key( (string) $data['slug'] ) : $defaults[ $plugin_file ]['slug'],
				'repo'  => isset( $data['repo'] ) ? $this->sanitize_repo( (string) $data['repo'] ) : $defaults[ $plugin_file ]['repo'],
				'branch' => isset( $data['branch'] ) ? $this->sanitize_branch( (string) $data['branch'] ) : $defaults[ $plugin_file ]['branch'],
			);
		}

		return $sanitized;
	}

	public function get_default_suite_plugins(): array {
		return array(
			'VoxManager/voxmanager.php' => array(
				'label' => 'VoxManager',
				'slug'  => 'voxmanager',
				'repo'  => 'SmartPiglet/VoxManager',
				'branch' => 'main',
			),
			'VoxPro/voxpro.php'       => array(
				'label' => 'VoxPro',
				'slug'  => 'voxpro',
				'repo'  => 'SmartPiglet/VoxPro',
				'branch' => '',
			),
			'VoxPoints/voxpoints.php' => array(
				'label' => 'VoxPoints',
				'slug'  => 'voxpoints',
				'repo'  => 'SmartPiglet/VoxPoints',
				'branch' => '',
			),
			'VoxPay/voxpay.php'       => array(
				'label' => 'VoxPay',
				'slug'  => 'voxpay',
				'repo'  => 'SmartPiglet/VoxPay',
				'branch' => '',
			),
			'VoxLab/voxlab.php'       => array(
				'label' => 'VoxLab',
				'slug'  => 'voxlab',
				'repo'  => 'SmartPiglet/VoxLab',
				'branch' => '',
			),
			'VoxPulse/voxpulse.php'   => array(
				'label' => 'VoxPulse',
				'slug'  => 'voxpulse',
				'repo'  => 'SmartPiglet/VoxPulse',
				'branch' => '',
			),
		);
	}

	public function sanitize_repo( string $repo ): string {
		$repo = trim( $repo );
		$repo = preg_replace( '#^https?://github.com/#i', '', $repo );
		$repo = preg_replace( '#\\.git$#i', '', $repo );
		$repo = ltrim( (string) $repo, '/' );
		return $repo;
	}

	public function sanitize_branch( string $branch ): string {
		$branch = trim( $branch );
		$branch = preg_replace( '#[^A-Za-z0-9._\\-/]#', '', $branch );
		return $branch;
	}

	public function get_required_capability(): string {
		if ( is_multisite() && is_network_admin() ) {
			return 'manage_network_plugins';
		}

		return 'manage_options';
	}

	public function get_install_capability(): string {
		if ( is_multisite() && is_network_admin() ) {
			return 'manage_network_plugins';
		}

		return 'install_plugins';
	}

	public function get_github_token(): string {
		$settings = $this->get_settings();
		$token = isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
		if ( $token !== '' ) {
			return $token;
		}

		if ( defined( 'VOXMANAGER_GITHUB_TOKEN' ) && VOXMANAGER_GITHUB_TOKEN ) {
			return (string) VOXMANAGER_GITHUB_TOKEN;
		}

		return '';
	}
}
