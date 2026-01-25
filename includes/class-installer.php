<?php
/**
 * Plugin Installer for VoxManager
 *
 * Handles installation of VoxPro suite plugins from GitHub.
 *
 * @package VoxManager
 */

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin installer class.
 *
 * Manages the installation of plugins from GitHub repositories
 * using WordPress Plugin Upgrader.
 */
final class Installer {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * GitHub client instance.
	 *
	 * @var Github_Client
	 */
	private Github_Client $github;

	/**
	 * Current installation context for source selection filter.
	 *
	 * @var array|null
	 */
	private $install_context = null;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings instance.
	 * @param Github_Client $github   GitHub client instance.
	 */
	public function __construct( Settings $settings, Github_Client $github ) {
		$this->settings = $settings;
		$this->github   = $github;
	}

	/**
	 * Install a suite plugin programmatically.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return bool|\WP_Error
	 */
	public function install_plugin( string $plugin_file ) {
		$plugin_file = trim( $plugin_file );
		if ( $plugin_file === '' ) {
			return new \WP_Error( 'voxmanager_missing_plugin', __( 'Missing plugin identifier.', 'voxmanager' ), array( 'status' => 400 ) );
		}

		$suite_plugins = $this->settings->get_suite_plugins();
		// Resolve suite keys case-insensitively to avoid mismatches on Linux hosts.
		$resolved_key = $this->settings->resolve_suite_plugin_key( $plugin_file, $suite_plugins );
		if ( $resolved_key === '' ) {
			return new \WP_Error( 'voxmanager_invalid_plugin', __( 'Plugin configuration not found.', 'voxmanager' ), array( 'status' => 404 ) );
		}

		$plugin_file = $resolved_key;
		$config = $suite_plugins[ $plugin_file ] ?? null;
		if ( ! is_array( $config ) || empty( $config['repo'] ) ) {
			return new \WP_Error( 'voxmanager_invalid_plugin', __( 'Plugin configuration not found.', 'voxmanager' ), array( 'status' => 404 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		WP_Filesystem();

		$installed_map = $this->settings->get_installed_plugin_map( get_plugins() );
		$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $plugin_file, $installed_map );

		$package_url = $this->get_install_package_url( $config, $resolved_plugin_file );
		if ( $package_url === '' ) {
			return new \WP_Error( 'voxmanager_package_missing', __( 'Unable to resolve a download package.', 'voxmanager' ), array( 'status' => 500 ) );
		}

		$this->install_context = array(
			'plugin_file' => $resolved_plugin_file,
			'plugin_dir'  => dirname( $resolved_plugin_file ),
		);

		$skin = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result = $upgrader->install( $package_url );

		$this->install_context = null;

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new \WP_Error( 'voxmanager_install_failed', __( 'Plugin installation failed.', 'voxmanager' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Upgrade a suite plugin programmatically.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return bool|\WP_Error
	 */
	public function upgrade_plugin( string $plugin_file ) {
		$plugin_file = trim( $plugin_file );
		if ( $plugin_file === '' ) {
			return new \WP_Error( 'voxmanager_missing_plugin', __( 'Missing plugin identifier.', 'voxmanager' ), array( 'status' => 400 ) );
		}

		$suite_plugins = $this->settings->get_suite_plugins();
		$resolved_key = $this->settings->resolve_suite_plugin_key( $plugin_file, $suite_plugins );
		if ( $resolved_key === '' ) {
			return new \WP_Error( 'voxmanager_invalid_plugin', __( 'Plugin configuration not found.', 'voxmanager' ), array( 'status' => 404 ) );
		}

		$plugin_file = $resolved_key;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		WP_Filesystem();

		$installed_map = $this->settings->get_installed_plugin_map( get_plugins() );
		$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $plugin_file, $installed_map );

		$this->install_context = array(
			'plugin_file' => $resolved_plugin_file,
			'plugin_dir'  => dirname( $resolved_plugin_file ),
		);

		$skin = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result = $upgrader->upgrade( $resolved_plugin_file );

		$this->install_context = null;

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new \WP_Error( 'voxmanager_update_failed', __( 'Plugin update failed.', 'voxmanager' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Handle plugin installation request.
	 *
	 * @return void
	 */
	public function handle_install_plugin(): void {
		if ( ! current_user_can( $this->settings->get_install_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'voxmanager' ) );
		}

		$plugin_file = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		if ( '' === $plugin_file ) {
			wp_die( esc_html__( 'Missing plugin identifier.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_install_plugin_' . $plugin_file );

		$suite_plugins = $this->settings->get_suite_plugins();
		$resolved_key = $this->settings->resolve_suite_plugin_key( $plugin_file, $suite_plugins );
		if ( $resolved_key === '' ) {
			wp_die( esc_html__( 'Plugin configuration not found.', 'voxmanager' ) );
		}

		$plugin_file = $resolved_key;
		$config = $suite_plugins[ $plugin_file ] ?? null;
		if ( ! is_array( $config ) || empty( $config['repo'] ) ) {
			wp_die( esc_html__( 'Plugin configuration not found.', 'voxmanager' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		WP_Filesystem();

		$installed_map = $this->settings->get_installed_plugin_map( get_plugins() );
		$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $plugin_file, $installed_map );

		$package_url = $this->get_install_package_url( $config, $resolved_plugin_file );
		if ( $package_url === '' ) {
			$this->set_install_error_message( __( 'Unable to resolve a download package.', 'voxmanager' ) );
			$redirect = add_query_arg( 'voxmanager_install_error', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$this->install_context = array(
			'plugin_file' => $resolved_plugin_file,
			'plugin_dir'  => dirname( $resolved_plugin_file ),
		);

		$skin = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result = $upgrader->install( $package_url );

		$this->install_context = null;

		if ( is_wp_error( $result ) ) {
			$this->set_install_error_message( $result->get_error_message() );
			$redirect = add_query_arg( 'voxmanager_install_error', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! $result ) {
			$this->set_install_error_message( __( 'Plugin installation failed.', 'voxmanager' ) );
			$redirect = add_query_arg( 'voxmanager_install_error', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$redirect = add_query_arg( 'voxmanager_installed', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function filter_upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		$plugin_dir = $this->install_context['plugin_dir'] ?? '';
		if ( $plugin_dir === '' && isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$suite_plugins = $this->settings->get_suite_plugins();
			$plugin_file = $hook_extra['plugin'];
			$resolved_key = $this->settings->resolve_suite_plugin_key( $plugin_file, $suite_plugins );
			if ( $resolved_key !== '' ) {
				$installed_map = array();
				if ( function_exists( 'get_plugins' ) ) {
					$installed_map = $this->settings->get_installed_plugin_map( get_plugins() );
				}
				$resolved_plugin_file = $this->settings->resolve_installed_plugin_file( $resolved_key, $installed_map );
				$plugin_dir = dirname( $resolved_plugin_file );
			}
		}

		if ( $plugin_dir === '' || ! is_dir( $source ) ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . $plugin_dir;

		if ( basename( $source ) === $plugin_dir ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
			return $target;
		}

		// Fallback: Direct rename when WP_Filesystem fails (e.g., on some local environments).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Fallback with graceful failure.
		if ( @rename( $source, $target ) ) {
			return $target;
		}

		return $source;
	}

	public function get_install_error_message(): string {
		$key = $this->get_install_error_key();
		$message = get_site_transient( $key );
		delete_site_transient( $key );

		return is_string( $message ) ? $message : '';
	}

	private function get_install_package_url( array $config, string $plugin_file ): string {
		$branch = isset( $config['branch'] ) ? (string) $config['branch'] : '';
		if ( $branch !== '' ) {
			return $this->github->get_branch_package_url( $config['repo'], $branch );
		}

		$release = $this->github->get_latest_release( $config['repo'] );
		if ( ! is_array( $release ) ) {
			return '';
		}

		return $this->github->resolve_release_package( $release, $plugin_file, $config );
	}

	private function set_install_error_message( string $message ): void {
		$key = $this->get_install_error_key();
		set_site_transient( $key, $message, 5 * MINUTE_IN_SECONDS );
	}

	private function get_install_error_key(): string {
		$user_id = get_current_user_id();
		return 'voxmanager_install_error_' . $user_id;
	}
}
