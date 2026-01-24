<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	private const OPTION_NAME = 'voxmanager_settings';
	private const OPTION_SECRETS = 'voxmanager_secrets';
	private const SETTINGS_GROUP = 'voxmanager';

	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'object',
				'default'           => $this->get_default_settings(),
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'show_in_rest'      => array(
					'schema' => $this->get_settings_schema(),
				),
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_SECRETS,
			array(
				'type'              => 'object',
				'default'           => $this->get_default_secrets(),
				'sanitize_callback' => array( $this, 'sanitize_secrets' ),
				'show_in_rest'      => false,
			)
		);
	}

	public function get_settings(): array {
		$this->maybe_migrate_legacy_secrets();

		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$merged = array_merge( $this->get_default_settings(), $settings );
		$merged['plugins'] = isset( $merged['plugins'] ) && is_array( $merged['plugins'] ) ? $merged['plugins'] : array();

		return array(
			'release_source' => (string) $merged['release_source'],
			'package_source' => (string) $merged['package_source'],
			'plugins'        => $merged['plugins'],
		);
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

	public function sanitize_settings( $settings ): array {
		$raw_settings = is_array( $settings ) ? $settings : array();

		$release_source = isset( $raw_settings['release_source'] ) ? (string) $raw_settings['release_source'] : 'releases';
		$release_source = in_array( $release_source, array( 'releases', 'tags' ), true ) ? $release_source : 'releases';

		$package_source = isset( $raw_settings['package_source'] ) ? (string) $raw_settings['package_source'] : 'asset';
		$package_source = in_array( $package_source, array( 'zipball', 'asset' ), true ) ? $package_source : 'asset';

		$raw_plugins = isset( $raw_settings['plugins'] ) && is_array( $raw_settings['plugins'] ) ? $raw_settings['plugins'] : array();

		return array(
			'release_source' => $release_source,
			'package_source' => $package_source,
			'plugins'        => $this->sanitize_plugin_settings( $raw_plugins ),
		);
	}

	public function sanitize_secrets( $secrets ): array {
		$raw_secrets = is_array( $secrets ) ? $secrets : array();

		return array(
			'github_token' => $this->encrypt_secret( isset( $raw_secrets['github_token'] ) ? (string) $raw_secrets['github_token'] : '' ),
			'api_key'      => $this->encrypt_secret( isset( $raw_secrets['api_key'] ) ? (string) $raw_secrets['api_key'] : '' ),
		);
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
		if ( defined( 'VOXMANAGER_GITHUB_TOKEN' ) && VOXMANAGER_GITHUB_TOKEN ) {
			return (string) VOXMANAGER_GITHUB_TOKEN;
		}

		$this->maybe_migrate_legacy_secrets();

		$secrets = $this->get_secrets();
		$stored = isset( $secrets['github_token'] ) ? (string) $secrets['github_token'] : '';
		return $this->decrypt_secret( $stored );
	}

	public function has_github_token(): bool {
		return $this->get_github_token() !== '';
	}

	public function set_github_token( string $token ): bool {
		$token = sanitize_text_field( $token );
		$secrets = $this->get_secrets();
		$secrets['github_token'] = $this->encrypt_secret( $token );

		return $this->update_secrets( $secrets );
	}

	public function get_api_key(): string {
		if ( defined( 'VOXMANAGER_API_KEY' ) && VOXMANAGER_API_KEY ) {
			return (string) VOXMANAGER_API_KEY;
		}

		$this->maybe_migrate_legacy_secrets();

		$secrets = $this->get_secrets();
		$stored = isset( $secrets['api_key'] ) ? (string) $secrets['api_key'] : '';
		return $this->decrypt_secret( $stored );
	}

	public function has_api_key(): bool {
		return $this->get_api_key() !== '';
	}

	public function set_api_key( string $api_key ): bool {
		$api_key = sanitize_text_field( $api_key );
		$secrets = $this->get_secrets();
		$secrets['api_key'] = $this->encrypt_secret( $api_key );

		return $this->update_secrets( $secrets );
	}

	private function get_secrets(): array {
		$secrets = get_option( self::OPTION_SECRETS, array() );
		if ( ! is_array( $secrets ) ) {
			return $this->get_default_secrets();
		}

		return array_merge( $this->get_default_secrets(), $secrets );
	}

	private function update_secrets( array $secrets ): bool {
		return update_option( self::OPTION_SECRETS, $secrets, false );
	}

	private function decrypt_secret( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		$decrypted = Encryptor::decrypt( $value );
		if ( false !== $decrypted ) {
			return $decrypted;
		}

		return $value;
	}

	private function encrypt_secret( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}

		$encrypted = Encryptor::encrypt( $value );
		return false === $encrypted ? '' : $encrypted;
	}

	private function maybe_migrate_legacy_secrets(): void {
		$settings = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$legacy_token = isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
		$legacy_api_key = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';

		if ( $legacy_token === '' && $legacy_api_key === '' ) {
			return;
		}

		if ( $legacy_token !== '' ) {
			$this->set_github_token( $legacy_token );
			unset( $settings['github_token'] );
		}

		if ( $legacy_api_key !== '' ) {
			$this->set_api_key( $legacy_api_key );
			unset( $settings['api_key'] );
		}

		update_option( self::OPTION_NAME, $settings, false );
	}

	private function get_default_settings(): array {
		return array(
			'release_source' => 'releases',
			'package_source' => 'asset',
			'plugins'        => array(),
		);
	}

	private function get_default_secrets(): array {
		return array(
			'github_token' => '',
			'api_key'      => '',
		);
	}

	private function get_settings_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'release_source' => array(
					'type' => 'string',
					'enum' => array( 'releases', 'tags' ),
				),
				'package_source' => array(
					'type' => 'string',
					'enum' => array( 'zipball', 'asset' ),
				),
				'plugins'        => array(
					'type' => 'object',
				),
			),
		);
	}
}
