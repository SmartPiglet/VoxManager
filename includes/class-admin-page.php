<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Page {
	private Settings $settings;
	private Github_Client $github;
	private Updater $updater;
	private Installer $installer;
	private string $menu_hook = '';

	public function __construct( Settings $settings, Github_Client $github, Updater $updater, Installer $installer ) {
		$this->settings = $settings;
		$this->github = $github;
		$this->updater = $updater;
		$this->installer = $installer;
	}

	public function register_menu(): void {
		$capability = $this->settings->get_required_capability();

		$this->menu_hook = add_menu_page(
			esc_html__( 'VoxManager', 'voxmanager' ),
			esc_html__( 'VoxManager', 'voxmanager' ),
			$capability,
			'voxmanager',
			array( $this, 'render_page' ),
			'dashicons-admin-plugins',
			58
		);
	}

	public function filter_admin_body_class( string $classes ): string {
		$page = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';
		if ( $page === 'voxmanager' ) {
			$classes .= ' vx-dark-mode ';
		}

		return $classes;
	}

	public function render_page(): void {
		if ( ! current_user_can( $this->settings->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'voxmanager' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$settings      = $this->settings->get_settings();
		$suite_plugins = $this->settings->get_suite_plugins();
		$installed     = get_plugins();
		$installed_map = $this->settings->get_installed_plugin_map( $installed );
		$updates       = get_site_transient( 'update_plugins' );
		$last_checked  = $this->updater->format_last_checked( $updates );
		$token_defined = defined( 'VOXMANAGER_GITHUB_TOKEN' ) && VOXMANAGER_GITHUB_TOKEN;
		$api_key_defined = defined( 'VOXMANAGER_API_KEY' ) && VOXMANAGER_API_KEY;
		$has_github_token = $this->settings->has_github_token();
		$has_api_key = $this->settings->has_api_key();
		$initial_notices = array();

		if ( ! $has_api_key ) {
			$generated_api_key = $this->generate_api_key();
			$this->settings->set_api_key( $generated_api_key );
			$this->set_generated_api_key_notice( $generated_api_key );
			$has_api_key = true;
		}

		$checked_notice = isset( $_GET['voxmanager_checked'] );
		$saved_notice   = isset( $_GET['voxmanager_saved'] );
		$synced_notice  = isset( $_GET['voxmanager_synced'] );
		$installed_notice = isset( $_GET['voxmanager_installed'] );
		$install_error = isset( $_GET['voxmanager_install_error'] );
		$install_error_message = $install_error ? $this->installer->get_install_error_message() : '';
		$generated_api_key = $this->get_generated_api_key_notice();
		if ( $generated_api_key !== '' ) {
			$initial_notices[] = __( 'API key generated. Copy it now; it will only be shown once.', 'voxmanager' );
		}

		$encryption_warning = $this->settings->get_encryption_warning();
		if ( $encryption_warning !== '' ) {
			$initial_notices[] = $encryption_warning;
		}

		foreach ( $suite_plugins as $plugin_file => $config ) {
			if ( empty( $config['repo'] ) ) {
				continue;
			}

			$error = $this->github->get_release_error( $config['repo'] );
			if ( empty( $error['message'] ) ) {
				continue;
			}

			$label = isset( $config['label'] ) && $config['label'] !== '' ? $config['label'] : $plugin_file;
			$initial_notices[] = sprintf(
				/* translators: 1: plugin label, 2: error message */
				__( 'Update check failed for %1$s: %2$s', 'voxmanager' ),
				$label,
				$error['message']
			);
		}

		$view = trailingslashit( dirname( __DIR__ ) ) . 'templates/admin-page.php';
		if ( is_readable( $view ) ) {
			require $view;
			return;
		}

		echo '<div class="wrap">';
		echo '<p>' . esc_html__( 'Admin template missing.', 'voxmanager' ) . '</p>';
		echo '</div>';
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( $hook === '' || $hook !== $this->menu_hook ) {
			return;
		}

		$this->enqueue_voxel_backend_styles();
	}

	public function handle_check_updates(): void {
		if ( ! current_user_can( $this->settings->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_check_updates' );

		delete_site_transient( 'update_plugins' );
		$this->github->clear_release_cache();
		wp_update_plugins();

		$redirect = add_query_arg( 'voxmanager_checked', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_save_settings(): void {
		if ( ! current_user_can( $this->settings->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_save_settings' );

		$settings = $this->settings->get_settings();
		$raw_token = isset( $_POST['voxmanager_github_token'] ) ? (string) wp_unslash( $_POST['voxmanager_github_token'] ) : '';
		if ( $raw_token !== '' ) {
			$this->settings->set_github_token( $raw_token );
		}

		$generate_api_key = isset( $_POST['voxmanager_generate_api_key'] );
		$raw_api_key = isset( $_POST['voxmanager_api_key'] ) ? (string) wp_unslash( $_POST['voxmanager_api_key'] ) : '';
		if ( $generate_api_key ) {
			$generated_api_key = $this->generate_api_key();
			$this->settings->set_api_key( $generated_api_key );
			$this->set_generated_api_key_notice( $generated_api_key );
		} elseif ( $raw_api_key !== '' ) {
			$this->settings->set_api_key( $raw_api_key );
		}

		$release_source = isset( $_POST['voxmanager_release_source'] ) ? (string) wp_unslash( $_POST['voxmanager_release_source'] ) : 'releases';
		$release_source = in_array( $release_source, array( 'releases', 'tags' ), true ) ? $release_source : 'releases';
		$settings['release_source'] = $release_source;

		$package_source = isset( $_POST['voxmanager_package_source'] ) ? (string) wp_unslash( $_POST['voxmanager_package_source'] ) : 'asset';
		$package_source = in_array( $package_source, array( 'zipball', 'asset' ), true ) ? $package_source : 'asset';
		$settings['package_source'] = $package_source;

		$raw_plugins = isset( $_POST['voxmanager_plugins'] ) && is_array( $_POST['voxmanager_plugins'] ) ? $_POST['voxmanager_plugins'] : array();
		$settings['plugins'] = $this->settings->sanitize_plugin_settings( $raw_plugins );

		update_option( 'voxmanager_settings', $settings, false );
		$this->github->clear_release_cache();

		$redirect = add_query_arg(
			array(
				'voxmanager_saved' => '1',
			),
			self_admin_url( 'admin.php?page=voxmanager' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_sync_github(): void {
		if ( ! current_user_can( $this->settings->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_sync_github' );

		$this->github->clear_release_cache();

		$redirect = add_query_arg( 'voxmanager_synced', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	private function enqueue_voxel_backend_styles(): void {
		$theme = wp_get_theme();
		$stylesheet = $theme->get_stylesheet();
		$text_domain = $theme->get( 'TextDomain' );

		if ( $stylesheet !== 'voxel' && $text_domain !== 'voxel' ) {
			return;
		}

		$this->enqueue_voxel_style( 'vx:backend.css', 'backend.css' );
		$this->enqueue_voxel_style( 'vx:commons.css', 'commons.css' );
		if ( wp_style_is( 'fonts:jetbrains-mono', 'registered' ) ) {
			wp_enqueue_style( 'fonts:jetbrains-mono' );
		}
	}

	private function enqueue_voxel_style( string $handle, string $file ): void {
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_enqueue_style( $handle );
			return;
		}

		$stylesheet_dir = get_stylesheet_directory();
		$stylesheet_uri = get_stylesheet_directory_uri();
		$path = trailingslashit( $stylesheet_dir ) . 'assets/dist/' . $file;

		if ( ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_style(
			$handle,
			trailingslashit( $stylesheet_uri ) . 'assets/dist/' . $file,
			array(),
			(string) filemtime( $path )
		);
	}

	private function generate_api_key(): string {
		return wp_generate_password( 48, false, false );
	}

	private function set_generated_api_key_notice( string $api_key ): void {
		$key = $this->get_generated_api_key_key();
		set_site_transient( $key, $api_key, 5 * MINUTE_IN_SECONDS );
	}

	private function get_generated_api_key_notice(): string {
		$key = $this->get_generated_api_key_key();
		$api_key = get_site_transient( $key );
		delete_site_transient( $key );

		return is_string( $api_key ) ? $api_key : '';
	}

	private function get_generated_api_key_key(): string {
		return 'voxmanager_generated_api_key_' . get_current_user_id();
	}
}
