<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	private const OPTION_NAME = 'voxmanager_settings';
	private const OPTION_SECRETS = 'voxmanager_secrets';
	private const SETTINGS_GROUP = 'voxmanager';
	private const ENCRYPTION_WARNING_KEY = 'voxmanager_encryption_warning_';

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
			'dev_mode'       => ! empty( $merged['dev_mode'] ),
		);
	}

	public function get_suite_plugins(): array {
		$defaults = $this->get_default_suite_plugins();
		$settings = $this->get_settings();
		$saved    = array();

		if ( isset( $settings['plugins'] ) && is_array( $settings['plugins'] ) ) {
			foreach ( $settings['plugins'] as $plugin_file => $config ) {
				$resolved_key = $this->resolve_suite_plugin_key( (string) $plugin_file, $defaults );
				if ( '' === $resolved_key ) {
					continue;
				}

				$saved[ $resolved_key ] = $config;
			}
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
			$resolved_key = $this->resolve_suite_plugin_key( $plugin_file, $defaults );
			if ( $resolved_key === '' || ! isset( $defaults[ $resolved_key ] ) ) {
				continue;
			}

			$data = is_array( $data ) ? $data : array();
			$sanitized[ $resolved_key ] = array(
				'label' => $defaults[ $resolved_key ]['label'],
				'slug'  => isset( $data['slug'] ) ? sanitize_key( (string) $data['slug'] ) : $defaults[ $resolved_key ]['slug'],
				'repo'  => isset( $data['repo'] ) ? $this->sanitize_repo( (string) $data['repo'] ) : $defaults[ $resolved_key ]['repo'],
				'branch' => isset( $data['branch'] ) ? $this->sanitize_branch( (string) $data['branch'] ) : $defaults[ $resolved_key ]['branch'],
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
		$dev_mode = ! empty( $raw_settings['dev_mode'] );

		return array(
			'release_source' => $release_source,
			'package_source' => $package_source,
			'plugins'        => $this->sanitize_plugin_settings( $raw_plugins ),
			'dev_mode'       => $dev_mode,
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
		if ( false === $encrypted ) {
			// Fall back to storing the raw value so secrets do not vanish on hosts without OpenSSL ciphers.
			$this->set_encryption_warning( Encryptor::get_last_error() );
			return $value;
		}

		return $encrypted;
	}

	public function get_encryption_warning(): string {
		$key = self::ENCRYPTION_WARNING_KEY . get_current_user_id();
		$message = get_site_transient( $key );
		delete_site_transient( $key );

		return is_string( $message ) ? $message : '';
	}

	public function get_installed_plugin_map( array $installed ): array {
		$map = array();

		foreach ( $installed as $plugin_file => $_data ) {
			if ( is_string( $plugin_file ) && $plugin_file !== '' ) {
				// Normalize keys for case-insensitive lookup on Linux hosts.
				$map[ strtolower( $plugin_file ) ] = $plugin_file;
			}
		}

		return $map;
	}

	public function resolve_installed_plugin_file( string $plugin_file, array $installed_map = array() ): string {
		$plugin_file = trim( $plugin_file );
		if ( $plugin_file === '' ) {
			return '';
		}

		if ( empty( $installed_map ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$installed_map = $this->get_installed_plugin_map( get_plugins() );
		}

		$lower = strtolower( $plugin_file );
		return $installed_map[ $lower ] ?? $plugin_file;
	}

	public function resolve_suite_plugin_key( string $plugin_file, array $suite_plugins = array() ): string {
		$plugin_file = trim( $plugin_file );
		if ( $plugin_file === '' ) {
			return '';
		}

		if ( empty( $suite_plugins ) ) {
			$suite_plugins = $this->get_suite_plugins();
		}

		if ( isset( $suite_plugins[ $plugin_file ] ) ) {
			return $plugin_file;
		}

		$lower = strtolower( $plugin_file );
		foreach ( $suite_plugins as $key => $_config ) {
			if ( strtolower( (string) $key ) === $lower ) {
				return (string) $key;
			}
		}

		return '';
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
			'dev_mode'       => false,
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
				'dev_mode'       => array(
					'type' => 'boolean',
				),
			),
		);
	}

	public function is_dev_mode_enabled(): bool {
		if ( defined( 'VOXMANAGER_DEV_MODE' ) ) {
			return (bool) VOXMANAGER_DEV_MODE;
		}

		$settings = $this->get_settings();
		return ! empty( $settings['dev_mode'] );
	}

	public function seed_licenses(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$products = array(
			900 => 'VoxPro',
			901 => 'VoxPulse',
			902 => 'VoxPoints',
			903 => 'VoxLab',
			904 => 'VoxPay',
		);

		$existing = get_option( 'voxpro_shared_licenses', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$updated = false;
		foreach ( $products as $id => $name ) {
			$found = false;
			foreach ( $existing as $license ) {
				if ( isset( $license['product_id'] ) && (int) $license['product_id'] === $id ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$key = sprintf( 'VX-%d-%d-%s', $user_id, $id, strtoupper( wp_generate_password( 8, false ) ) );
				$existing[ $key ] = array(
					'key'              => $key,
					'user_id'          => $user_id,
					'product_id'       => $id,
					'status'           => 'active',
					'activation_limit' => 999,
					'expires_at'       => null,
					'activations'      => array( home_url() ),
				);
				$updated = true;
			}
		}

		if ( $updated ) {
			update_option( 'voxpro_shared_licenses', $existing );
		}
	}

	private function set_encryption_warning( string $reason ): void {
		$reason = trim( $reason );
		if ( $reason === '' ) {
			$reason = __( 'Encryption unavailable; token stored without encryption.', 'voxmanager' );
		}

		set_site_transient(
			self::ENCRYPTION_WARNING_KEY . get_current_user_id(),
			$reason,
			10 * MINUTE_IN_SECONDS
		);
	}
}
