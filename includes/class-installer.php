<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {
	private Settings $settings;
	private Github_Client $github;

	/**
	 * @var array|null
	 */
	private $install_context = null;

	public function __construct( Settings $settings, Github_Client $github ) {
		$this->settings = $settings;
		$this->github = $github;
	}

	public function handle_install_plugin(): void {
		if ( ! current_user_can( $this->settings->get_install_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'voxmanager' ) );
		}

		$plugin_file = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		if ( $plugin_file === '' ) {
			wp_die( esc_html__( 'Missing plugin identifier.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_install_plugin_' . $plugin_file );

		$suite_plugins = $this->settings->get_suite_plugins();
		$config = $suite_plugins[ $plugin_file ] ?? null;
		if ( ! is_array( $config ) || empty( $config['repo'] ) ) {
			wp_die( esc_html__( 'Plugin configuration not found.', 'voxmanager' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		WP_Filesystem();

		$package_url = $this->get_install_package_url( $config, $plugin_file );
		if ( $package_url === '' ) {
			$this->set_install_error_message( __( 'Unable to resolve a download package.', 'voxmanager' ) );
			$redirect = add_query_arg( 'voxmanager_install_error', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$this->install_context = array(
			'plugin_file' => $plugin_file,
			'plugin_dir'  => dirname( $plugin_file ),
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
		if ( empty( $this->install_context['plugin_dir'] ) || ! is_dir( $source ) ) {
			return $source;
		}

		$plugin_dir = $this->install_context['plugin_dir'];
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
