<?php
/**
 * Plugin Name: VoxManager
 * Plugin URI: https://voxeladdons.co.uk/voxmanager
 * Description: Manage VoxPro suite plugin status and update checks. (Personal use)
 * Version: 0.1.0
 * Author: VoxelAddons
 * Author URI: https://voxeladdons.co.uk
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: voxmanager
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_transient' ), 100 );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 20, 3 );
		add_filter( 'http_request_args', array( $this, 'filter_http_request_args' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'filter_upgrader_source_selection' ), 10, 4 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_post_voxmanager_check_updates', array( $this, 'handle_check_updates' ) );
			add_action( 'admin_post_voxmanager_save_settings', array( $this, 'handle_save_settings' ) );
			add_action( 'admin_post_voxmanager_sync_github', array( $this, 'handle_sync_github' ) );
			add_action( 'admin_post_voxmanager_install_plugin', array( $this, 'handle_install_plugin' ) );
		}
	}

	/**
	 * Register the VoxManager admin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$capability = $this->get_required_capability();

		add_menu_page(
			esc_html__( 'VoxManager', 'voxmanager' ),
			esc_html__( 'VoxManager', 'voxmanager' ),
			$capability,
			'voxmanager',
			array( $this, 'render_page' ),
			'dashicons-admin-plugins',
			58
		);
	}

	/**
	 * Render the VoxManager admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'voxmanager' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$settings      = $this->get_settings();
		$suite_plugins = $this->get_suite_plugins();
		$installed     = get_plugins();
		$updates       = get_site_transient( 'update_plugins' );
		$last_checked  = $this->format_last_checked( $updates );
		$token_defined = defined( 'VOXMANAGER_GITHUB_TOKEN' ) && VOXMANAGER_GITHUB_TOKEN;

		$checked_notice = isset( $_GET['voxmanager_checked'] );
		$saved_notice   = isset( $_GET['voxmanager_saved'] );
		$synced_notice  = isset( $_GET['voxmanager_synced'] );
		$installed_notice = isset( $_GET['voxmanager_installed'] );
		$install_error = isset( $_GET['voxmanager_install_error'] );
		$install_error_message = $install_error ? $this->get_install_error_message() : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'VoxManager', 'voxmanager' ) . '</h1>';

		if ( $checked_notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Update check completed.', 'voxmanager' ) . '</p></div>';
		}
		if ( $saved_notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'voxmanager' ) . '</p></div>';
		}
		if ( $synced_notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'GitHub info refreshed.', 'voxmanager' ) . '</p></div>';
		}
		if ( $installed_notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Plugin installed successfully.', 'voxmanager' ) . '</p></div>';
		}
		if ( $install_error ) {
			$message = $install_error_message !== '' ? $install_error_message : esc_html__( 'Plugin installation failed.', 'voxmanager' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Update controls', 'voxmanager' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( self_admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'voxmanager_check_updates' );
		echo '<input type="hidden" name="action" value="voxmanager_check_updates" />';
		submit_button( esc_html__( 'Check for updates', 'voxmanager' ), 'primary', 'voxmanager_check_updates', false );
		echo '</form>';

		if ( $last_checked ) {
			echo '<p>' . esc_html( sprintf( __( 'Last checked: %s', 'voxmanager' ), $last_checked ) ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Suite status', 'voxmanager' ) . '</h2>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Plugin', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Version', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Update', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Repository', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'voxmanager' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $suite_plugins as $plugin_file => $config ) {
			$plugin_data = $installed[ $plugin_file ] ?? null;
			$is_installed = is_array( $plugin_data );
			$is_active = $is_installed && is_plugin_active( $plugin_file );
			$is_network_active = $is_installed && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file );

			$status = esc_html__( 'Missing', 'voxmanager' );
			if ( $is_installed ) {
				if ( $is_network_active ) {
					$status = esc_html__( 'Network Active', 'voxmanager' );
				} elseif ( $is_active ) {
					$status = esc_html__( 'Active', 'voxmanager' );
				} else {
					$status = esc_html__( 'Installed', 'voxmanager' );
				}
			}

			$label = $config['label'];
			if ( '' === $label && $is_installed ) {
				$label = $plugin_data['Name'] ?? '';
			}
			if ( '' === $label ) {
				$label = $plugin_file;
			}

			$version = $is_installed ? (string) ( $plugin_data['Version'] ?? '' ) : '';

			$update_data   = $this->get_update_data( $updates, $plugin_file );
			$update_label  = '';
			$update_action = '';
			$error         = $config['repo'] ? $this->get_release_error( $config['repo'] ) : array();
			$install_action = '';

			if ( ! $is_installed ) {
				$update_label = esc_html__( 'Not installed', 'voxmanager' );
				if ( current_user_can( $this->get_install_capability() ) ) {
					$install_url = wp_nonce_url(
						self_admin_url( 'admin-post.php?action=voxmanager_install_plugin&plugin=' . rawurlencode( $plugin_file ) ),
						'voxmanager_install_plugin_' . $plugin_file
					);
					$install_action = '<a href="' . esc_url( $install_url ) . '">' . esc_html__( 'Install', 'voxmanager' ) . '</a>';
				}
			} elseif ( ! empty( $error['message'] ) ) {
				$update_label = esc_html( sprintf( __( 'Update check failed: %s', 'voxmanager' ), $error['message'] ) );
			} elseif ( $update_data['available'] ) {
				$update_label = esc_html( sprintf( __( 'Update available: %s', 'voxmanager' ), $update_data['version'] ) );
				$update_url   = wp_nonce_url(
					self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . $plugin_file ),
					'upgrade-plugin_' . $plugin_file
				);
				$update_action = '<a href="' . esc_url( $update_url ) . '">' . esc_html__( 'Update now', 'voxmanager' ) . '</a>';
			} elseif ( $last_checked ) {
				$update_label = esc_html__( 'Up to date', 'voxmanager' );
			} else {
				$update_label = esc_html__( 'Not checked yet', 'voxmanager' );
			}

			$repo = $config['repo'] ? esc_html( $config['repo'] ) : '';
			if ( $config['repo'] ) {
				$repo_url = 'https://github.com/' . ltrim( $config['repo'], '/' );
				$repo     = '<a href="' . esc_url( $repo_url ) . '" target="_blank" rel="noopener">' . esc_html( $config['repo'] ) . '</a>';
			}
			$plugin_meta = '<div>' . esc_html( $label ) . '</div>';
			$plugin_meta .= '<div><code>' . esc_html( $plugin_file ) . '</code></div>';

			echo '<tr>';
			echo '<td>' . $plugin_meta . '</td>';
			echo '<td>' . esc_html( $version ?: '-' ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . $update_label . '</td>';
			echo '<td>' . ( $repo ? $repo : '-' ) . '</td>';
			if ( ! $is_installed ) {
				echo '<td>' . ( $install_action ? $install_action : '-' ) . '</td>';
			} else {
				echo '<td>' . ( $update_action ? $update_action : '-' ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'GitHub settings', 'voxmanager' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( self_admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'voxmanager_sync_github' );
		echo '<input type="hidden" name="action" value="voxmanager_sync_github" />';
		submit_button( esc_html__( 'Refresh GitHub info', 'voxmanager' ), 'secondary', 'voxmanager_sync_github', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( self_admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'voxmanager_save_settings' );
		echo '<input type="hidden" name="action" value="voxmanager_save_settings" />';

		echo '<table class="form-table">';
		echo '<tr>';
		echo '<th scope="row"><label for="voxmanager_github_token">' . esc_html__( 'GitHub token', 'voxmanager' ) . '</label></th>';
		echo '<td>';

		if ( $token_defined ) {
			echo '<p>' . esc_html__( 'Token loaded from VOXMANAGER_GITHUB_TOKEN in wp-config.php.', 'voxmanager' ) . '</p>';
		} else {
			$token_value = isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
			$token_placeholder = $token_value !== '' ? '********' : '';
			echo '<input type="password" class="regular-text" id="voxmanager_github_token" name="voxmanager_github_token" value="" placeholder="' . esc_attr( $token_placeholder ) . '" />';
			echo '<p class="description">' . esc_html__( 'Used to access private GitHub repos. Leave blank to keep the existing token.', 'voxmanager' ) . '</p>';
		}
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row"><label for="voxmanager_release_source">' . esc_html__( 'Release source', 'voxmanager' ) . '</label></th>';
		echo '<td>';
		$release_source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases';
		echo '<select id="voxmanager_release_source" name="voxmanager_release_source">';
		echo '<option value="releases"' . selected( $release_source, 'releases', false ) . '>' . esc_html__( 'Latest release', 'voxmanager' ) . '</option>';
		echo '<option value="tags"' . selected( $release_source, 'tags', false ) . '>' . esc_html__( 'Latest tag', 'voxmanager' ) . '</option>';
		echo '</select>';
		echo '</td>';
		echo '</tr>';
		echo '<tr>';
		echo '<th scope="row"><label for="voxmanager_package_source">' . esc_html__( 'Package source', 'voxmanager' ) . '</label></th>';
		echo '<td>';
		$package_source = isset( $settings['package_source'] ) ? (string) $settings['package_source'] : 'asset';
		echo '<select id="voxmanager_package_source" name="voxmanager_package_source">';
		echo '<option value="zipball"' . selected( $package_source, 'zipball', false ) . '>' . esc_html__( 'Repo zipball', 'voxmanager' ) . '</option>';
		echo '<option value="asset"' . selected( $package_source, 'asset', false ) . '>' . esc_html__( 'Release asset', 'voxmanager' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Repo zipball pulls directly from the repository. Release assets use uploaded zip files.', 'voxmanager' ) . '</p>';
		echo '</td>';
		echo '</tr>';
		echo '</table>';

		echo '<h3>' . esc_html__( 'Suite plugins', 'voxmanager' ) . '</h3>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Plugin', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Repository (owner/repo)', 'voxmanager' ) . '</th>';
		echo '<th>' . esc_html__( 'Branch', 'voxmanager' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $suite_plugins as $plugin_file => $config ) {
			$label = $config['label'] !== '' ? $config['label'] : $plugin_file;
			$repo_info = $config['repo'] ? $this->get_repo_info( $config['repo'] ) : null;
			$default_branch = is_array( $repo_info ) ? (string) ( $repo_info['default_branch'] ?? '' ) : '';
			$branches = $config['repo'] ? $this->get_repo_branches( $config['repo'] ) : array();
			$selected_branch = $config['branch'] !== '' ? $config['branch'] : $default_branch;

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '<br><code>' . esc_html( $plugin_file ) . '</code></td>';
			echo '<td><input type="text" class="regular-text" name="voxmanager_plugins[' . esc_attr( $plugin_file ) . '][slug]" value="' . esc_attr( $config['slug'] ) . '" /></td>';
			echo '<td><input type="text" class="regular-text" name="voxmanager_plugins[' . esc_attr( $plugin_file ) . '][repo]" value="' . esc_attr( $config['repo'] ) . '" /></td>';
			echo '<td>';
			if ( ! empty( $branches ) ) {
				echo '<select name="voxmanager_plugins[' . esc_attr( $plugin_file ) . '][branch]">';
				echo '<option value="">' . esc_html__( 'Use releases/tags', 'voxmanager' ) . '</option>';
				foreach ( $branches as $branch_name ) {
					$label_suffix = $branch_name === $default_branch ? ' (' . esc_html__( 'default', 'voxmanager' ) . ')' : '';
					echo '<option value="' . esc_attr( $branch_name ) . '"' . selected( $selected_branch, $branch_name, false ) . '>' . esc_html( $branch_name . $label_suffix ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="text" class="regular-text" name="voxmanager_plugins[' . esc_attr( $plugin_file ) . '][branch]" value="' . esc_attr( $selected_branch ) . '" placeholder="' . esc_attr__( 'main', 'voxmanager' ) . '" />';
				echo '<p class="description">' . esc_html__( 'GitHub branch list unavailable. Check token or repo permissions.', 'voxmanager' ) . '</p>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		submit_button( esc_html__( 'Save settings', 'voxmanager' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handle update check requests.
	 *
	 * @return void
	 */
	public function handle_check_updates(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_check_updates' );

		delete_site_transient( 'update_plugins' );
		$this->clear_release_cache();
		wp_update_plugins();

		$redirect = add_query_arg( 'voxmanager_checked', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle saving settings.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_save_settings' );

		$settings = $this->get_settings();
		$token_defined = defined( 'VOXMANAGER_GITHUB_TOKEN' ) && VOXMANAGER_GITHUB_TOKEN;

		if ( ! $token_defined ) {
			$raw_token = isset( $_POST['voxmanager_github_token'] ) ? (string) wp_unslash( $_POST['voxmanager_github_token'] ) : '';
			if ( $raw_token !== '' ) {
				$settings['github_token'] = sanitize_text_field( $raw_token );
			}
		}

		$release_source = isset( $_POST['voxmanager_release_source'] ) ? (string) wp_unslash( $_POST['voxmanager_release_source'] ) : 'releases';
		$release_source = in_array( $release_source, array( 'releases', 'tags' ), true ) ? $release_source : 'releases';
		$settings['release_source'] = $release_source;

		$package_source = isset( $_POST['voxmanager_package_source'] ) ? (string) wp_unslash( $_POST['voxmanager_package_source'] ) : 'asset';
		$package_source = in_array( $package_source, array( 'zipball', 'asset' ), true ) ? $package_source : 'asset';
		$settings['package_source'] = $package_source;

		$raw_plugins = isset( $_POST['voxmanager_plugins'] ) && is_array( $_POST['voxmanager_plugins'] ) ? $_POST['voxmanager_plugins'] : array();
		$settings['plugins'] = $this->sanitize_plugin_settings( $raw_plugins );

		update_option( 'voxmanager_settings', $settings, false );
		$this->clear_release_cache();

		$redirect = add_query_arg( 'voxmanager_saved', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Refresh cached GitHub data.
	 *
	 * @return void
	 */
	public function handle_sync_github(): void {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_sync_github' );

		$this->clear_release_cache();

		$redirect = add_query_arg( 'voxmanager_synced', '1', self_admin_url( 'admin.php?page=voxmanager' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle initial plugin install.
	 *
	 * @return void
	 */
	public function handle_install_plugin(): void {
		if ( ! current_user_can( $this->get_install_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'voxmanager' ) );
		}

		$plugin_file = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( $_GET['plugin'] ) ) : '';
		if ( $plugin_file === '' ) {
			wp_die( esc_html__( 'Missing plugin identifier.', 'voxmanager' ) );
		}

		check_admin_referer( 'voxmanager_install_plugin_' . $plugin_file );

		$suite_plugins = $this->get_suite_plugins();
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

	/**
	 * Get the configured suite plugins.
	 *
	 * @return array<string,array>
	 */
	private function get_suite_plugins(): array {
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

	/**
	 * Default suite plugin configuration.
	 *
	 * @return array<string,array>
	 */
	private function get_default_suite_plugins(): array {
		return array(
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

	/**
	 * Extract update data for a plugin.
	 *
	 * @param mixed  $updates Update transient.
	 * @param string $plugin_file Plugin file path.
	 * @return array{available: bool, version: string, url: string, package: string}
	 */
	private function get_update_data( $updates, string $plugin_file ): array {
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

	/**
	 * Hook into plugin update checks and inject GitHub release data.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$suite_plugins = $this->get_suite_plugins();
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

			$plugin_data = $installed[ $plugin_file ] ?? null;
			if ( ! is_array( $plugin_data ) ) {
				continue;
			}

			$current_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
			if ( $current_version === '' ) {
				continue;
			}

			$branch = isset( $config['branch'] ) ? (string) $config['branch'] : '';
			if ( $branch !== '' ) {
				$remote_version = $this->get_branch_plugin_version( $config['repo'], $branch, $plugin_file );
				if ( $remote_version === '' ) {
					continue;
				}

				$package = $this->get_branch_package_url( $config['repo'], $branch );
				if ( $package === '' ) {
					continue;
				}

				if ( version_compare( $current_version, $remote_version, '>=' ) ) {
					if ( isset( $transient->response[ $plugin_file ] ) ) {
						unset( $transient->response[ $plugin_file ] );
					}
					$transient->no_update[ $plugin_file ] = (object) array(
						'slug'        => $config['slug'],
						'plugin'      => $plugin_file,
						'new_version' => $remote_version,
						'url'         => 'https://github.com/' . $this->sanitize_repo( $config['repo'] ),
						'package'     => '',
					);
					continue;
				}

				$transient->response[ $plugin_file ] = (object) array(
					'slug'        => $config['slug'],
					'plugin'      => $plugin_file,
					'new_version' => $remote_version,
					'url'         => 'https://github.com/' . $this->sanitize_repo( $config['repo'] ),
					'package'     => $package,
				);
				continue;
			}

			$release = $this->get_latest_release( $config['repo'] );
			if ( ! is_array( $release ) ) {
				continue;
			}

			$remote_version = $this->normalize_version( (string) ( $release['tag_name'] ?? '' ) );
			if ( $remote_version === '' ) {
				continue;
			}

			if ( version_compare( $current_version, $remote_version, '>=' ) ) {
				if ( isset( $transient->response[ $plugin_file ] ) ) {
					unset( $transient->response[ $plugin_file ] );
				}
				$transient->no_update[ $plugin_file ] = (object) array(
					'slug'        => $config['slug'],
					'plugin'      => $plugin_file,
					'new_version' => $remote_version,
					'url'         => $release['html_url'] ?? '',
					'package'     => '',
				);
				continue;
			}

			$package = $this->resolve_release_package( $release, $plugin_file, $config );
			if ( $package === '' ) {
				continue;
			}

			$transient->response[ $plugin_file ] = (object) array(
				'slug'        => $config['slug'],
				'plugin'      => $plugin_file,
				'new_version' => $remote_version,
				'url'         => $release['html_url'] ?? '',
				'package'     => $package,
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the WordPress plugin details modal.
	 *
	 * @param mixed  $res Response.
	 * @param string $action Action name.
	 * @param object $args Request args.
	 * @return mixed
	 */
	public function filter_plugins_api( $res, $action, $args ) {
		if ( $action !== 'plugin_information' || ! is_object( $args ) || empty( $args->slug ) ) {
			return $res;
		}

		$suite_plugins = $this->get_suite_plugins();
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
			$remote_version = $this->get_branch_plugin_version( $match['config']['repo'], $branch, $match['plugin_file'] );
			if ( $remote_version !== '' ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$installed = get_plugins();
				$plugin_data = $installed[ $match['plugin_file'] ] ?? array();
				$branch_note = sprintf( __( 'Updates from branch: %s', 'voxmanager' ), $branch );

				$info = (object) array(
					'name'           => $plugin_data['Name'] ?? $match['config']['slug'],
					'slug'           => $match['config']['slug'],
					'version'        => $remote_version,
					'author'         => $plugin_data['Author'] ?? '',
					'author_profile' => $plugin_data['AuthorURI'] ?? '',
					'homepage'       => $plugin_data['PluginURI'] ?? 'https://github.com/' . $this->sanitize_repo( $match['config']['repo'] ),
					'sections'       => array(
						'description' => wp_kses_post( wpautop( $branch_note ) ),
					),
					'download_link'  => $this->get_branch_package_url( $match['config']['repo'], $branch ),
				);

				return $info;
			}
		}

		$release = $this->get_latest_release( $match['config']['repo'] );
		if ( ! is_array( $release ) ) {
			return $res;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		$plugin_data = $installed[ $match['plugin_file'] ] ?? array();
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
			'download_link' => $this->resolve_release_package( $release, $match['plugin_file'], $match['config'] ),
			'last_updated'  => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
		);

		return $info;
	}

	/**
	 * Add auth headers for GitHub requests.
	 *
	 * @param array  $args Request args.
	 * @param string $url Request URL.
	 * @return array
	 */
	public function filter_http_request_args( array $args, string $url ): array {
		$token = $this->get_github_token();
		if ( $token === '' ) {
			return $args;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return $args;
		}

		$host = strtolower( $host );
		if ( $host !== 'api.github.com' && $host !== 'github.com' && $host !== 'objects.githubusercontent.com' && $host !== 'codeload.github.com' ) {
			return $args;
		}

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$headers = $this->apply_github_auth_headers( $headers );
		$args['headers'] = $headers;

		return $args;
	}

	/**
	 * Retrieve stored settings.
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$defaults = array(
			'github_token'   => '',
			'release_source' => 'releases',
			'package_source' => 'asset',
			'plugins'        => array(),
		);

		$settings = get_option( 'voxmanager_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( $defaults, $settings );
	}

	/**
	 * Sanitize plugin settings input.
	 *
	 * @param array $raw_plugins Raw plugin config.
	 * @return array
	 */
	private function sanitize_plugin_settings( array $raw_plugins ): array {
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

	/**
	 * Normalize GitHub repo string.
	 *
	 * @param string $repo Repo input.
	 * @return string
	 */
	private function sanitize_repo( string $repo ): string {
		$repo = trim( $repo );
		$repo = preg_replace( '#^https?://github.com/#i', '', $repo );
		$repo = preg_replace( '#\\.git$#i', '', $repo );
		$repo = ltrim( (string) $repo, '/' );
		return $repo;
	}

	/**
	 * Normalize branch input.
	 *
	 * @param string $branch Branch input.
	 * @return string
	 */
	private function sanitize_branch( string $branch ): string {
		$branch = trim( $branch );
		$branch = preg_replace( '#[^A-Za-z0-9._\\-/]#', '', $branch );
		return $branch;
	}

	/**
	 * Resolve a package URL for initial installs.
	 *
	 * @param array  $config Plugin config.
	 * @param string $plugin_file Plugin file.
	 * @return string
	 */
	private function get_install_package_url( array $config, string $plugin_file ): string {
		$branch = isset( $config['branch'] ) ? (string) $config['branch'] : '';
		if ( $branch !== '' ) {
			return $this->get_branch_package_url( $config['repo'], $branch );
		}

		$release = $this->get_latest_release( $config['repo'] );
		if ( ! is_array( $release ) ) {
			return '';
		}

		return $this->resolve_release_package( $release, $plugin_file, $config );
	}

	/**
	 * Track the current install context for source renaming.
	 *
	 * @var array|null
	 */
	private $install_context = null;

	/**
	 * Ensure installed plugin folder matches the expected plugin directory.
	 *
	 * @param string $source Source path.
	 * @param string $remote_source Remote source path.
	 * @param object $upgrader Upgrader instance.
	 * @param array  $hook_extra Hook extras.
	 * @return string
	 */
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

	/**
	 * Store install error message for display.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function set_install_error_message( string $message ): void {
		$key = $this->get_install_error_key();
		set_site_transient( $key, $message, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Get install error message.
	 *
	 * @return string
	 */
	private function get_install_error_message(): string {
		$key = $this->get_install_error_key();
		$message = get_site_transient( $key );
		delete_site_transient( $key );

		return is_string( $message ) ? $message : '';
	}

	/**
	 * Build install error cache key.
	 *
	 * @return string
	 */
	private function get_install_error_key(): string {
		$user_id = get_current_user_id();
		return 'voxmanager_install_error_' . $user_id;
	}

	/**
	 * Get the GitHub token from constants or settings.
	 *
	 * @return string
	 */
	private function get_github_token(): string {
		if ( defined( 'VOXMANAGER_GITHUB_TOKEN' ) && VOXMANAGER_GITHUB_TOKEN ) {
			return (string) VOXMANAGER_GITHUB_TOKEN;
		}

		$settings = $this->get_settings();
		return isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
	}

	/**
	 * Apply GitHub auth headers.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	private function apply_github_auth_headers( array $headers ): array {
		$token = $this->get_github_token();
		if ( $token === '' ) {
			return $headers;
		}

		$auth_scheme = strpos( $token, 'github_pat_' ) === 0 ? 'Bearer' : 'token';
		$headers['Authorization'] = $auth_scheme . ' ' . $token;
		$headers['Accept'] = 'application/vnd.github+json';
		$headers['User-Agent'] = 'VoxManager';

		return $headers;
	}

	/**
	 * Fetch repository metadata.
	 *
	 * @param string $repo Repo.
	 * @return array|null
	 */
	private function get_repo_info( string $repo ) {
		$repo = $this->sanitize_repo( $repo );
		if ( $repo === '' ) {
			return null;
		}

		$cache_key = $this->get_repo_cache_key( $repo, 'info' );
		$cached = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s', $repo ),
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$info = array(
			'default_branch' => isset( $data['default_branch'] ) ? (string) $data['default_branch'] : '',
			'private'        => isset( $data['private'] ) ? (bool) $data['private'] : null,
		);

		set_site_transient( $cache_key, $info, 10 * MINUTE_IN_SECONDS );

		return $info;
	}

	/**
	 * Fetch repository branches.
	 *
	 * @param string $repo Repo.
	 * @return array<int,string>
	 */
	private function get_repo_branches( string $repo ): array {
		$repo = $this->sanitize_repo( $repo );
		if ( $repo === '' ) {
			return array();
		}

		$cache_key = $this->get_repo_cache_key( $repo, 'branches' );
		$cached = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/branches?per_page=100', $repo ),
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$branches = array();
		foreach ( $data as $branch ) {
			if ( is_array( $branch ) && isset( $branch['name'] ) ) {
				$branches[] = (string) $branch['name'];
			}
		}

		$branches = array_values( array_unique( array_filter( $branches ) ) );

		$info = $this->get_repo_info( $repo );
		$default_branch = is_array( $info ) ? (string) ( $info['default_branch'] ?? '' ) : '';
		if ( $default_branch !== '' && in_array( $default_branch, $branches, true ) ) {
			$branches = array_merge( array( $default_branch ), array_diff( $branches, array( $default_branch ) ) );
		}

		set_site_transient( $cache_key, $branches, 10 * MINUTE_IN_SECONDS );

		return $branches;
	}

	/**
	 * Get latest release data for a repo.
	 *
	 * @param string $repo Repo.
	 * @return array|null
	 */
	private function get_latest_release( string $repo ) {
		$repo = $this->sanitize_repo( $repo );
		if ( $repo === '' ) {
			return null;
		}

		$settings = $this->get_settings();
		$source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases';
		$source = $source === 'tags' ? 'tags' : 'releases';

		$cache_key = $this->get_release_cache_key( $repo, $source );
		$cached = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$api_url = $source === 'tags'
			? sprintf( 'https://api.github.com/repos/%s/tags?per_page=1', $repo )
			: sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo );

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->set_release_error( $repo, $response->get_error_message() );
			return null;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->set_release_error( $repo, 'HTTP ' . $status_code );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $source === 'tags' ) {
			$tag = is_array( $data ) ? ( $data[0] ?? null ) : null;
			if ( ! is_array( $tag ) ) {
				$this->set_release_error( $repo, 'Missing tag data' );
				return null;
			}

			$data = array(
				'tag_name'     => $tag['name'] ?? '',
				'name'         => $tag['name'] ?? '',
				'zipball_url'  => $tag['zipball_url'] ?? '',
				'html_url'     => 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode( (string) ( $tag['name'] ?? '' ) ),
				'body'         => '',
				'published_at' => '',
				'assets'       => array(),
			);
		}

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			$this->set_release_error( $repo, 'Missing release data' );
			return null;
		}

		$release = array(
			'tag_name'     => (string) ( $data['tag_name'] ?? '' ),
			'name'         => (string) ( $data['name'] ?? '' ),
			'zipball_url'  => (string) ( $data['zipball_url'] ?? '' ),
			'html_url'     => (string) ( $data['html_url'] ?? '' ),
			'body'         => (string) ( $data['body'] ?? '' ),
			'published_at' => (string) ( $data['published_at'] ?? '' ),
			'assets'       => is_array( $data['assets'] ?? null ) ? $data['assets'] : array(),
		);

		set_site_transient( $cache_key, $release, 10 * MINUTE_IN_SECONDS );
		$this->clear_release_error( $repo );

		return $release;
	}

	/**
	 * Resolve a release asset or zipball URL.
	 *
	 * @param array  $release Release data.
	 * @param string $plugin_file Plugin file.
	 * @param array  $config Plugin config.
	 * @return string
	 */
	private function resolve_release_package( array $release, string $plugin_file, array $config ): string {
		$plugin_dir = dirname( $plugin_file );
		$slug = isset( $config['slug'] ) ? (string) $config['slug'] : $plugin_dir;
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : array();
		$settings = $this->get_settings();
		$package_source = isset( $settings['package_source'] ) ? (string) $settings['package_source'] : 'zipball';

		if ( $package_source === 'zipball' ) {
			return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
		}

		$asset_url = $this->resolve_release_asset_url( $assets, $plugin_dir, $slug );
		if ( $asset_url !== '' ) {
			return $asset_url;
		}

		return isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
	}

	/**
	 * Find a matching release asset for a plugin.
	 *
	 * @param array  $assets Assets list.
	 * @param string $plugin_dir Plugin directory.
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	private function resolve_release_asset_url( array $assets, string $plugin_dir, string $slug ): string {
		$first_zip = '';
		$needle_dirs = array_filter( array( strtolower( $plugin_dir ), strtolower( $slug ) ) );

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';

			if ( $name === '' || $url === '' ) {
				continue;
			}

			if ( strtolower( substr( $name, -4 ) ) !== '.zip' ) {
				continue;
			}

			if ( $first_zip === '' ) {
				$first_zip = $url;
			}

			$name_lower = strtolower( $name );
			foreach ( $needle_dirs as $needle ) {
				if ( $needle !== '' && strpos( $name_lower, $needle ) !== false ) {
					return $url;
				}
			}
		}

		return $first_zip;
	}

	/**
	 * Build the zipball URL for a branch.
	 *
	 * @param string $repo Repo.
	 * @param string $branch Branch.
	 * @return string
	 */
	private function get_branch_package_url( string $repo, string $branch ): string {
		$repo = $this->sanitize_repo( $repo );
		$branch = $this->sanitize_branch( $branch );

		if ( $repo === '' || $branch === '' ) {
			return '';
		}

		return sprintf( 'https://api.github.com/repos/%s/zipball/%s', $repo, rawurlencode( $branch ) );
	}

	/**
	 * Fetch plugin version from a repo branch.
	 *
	 * @param string $repo Repo.
	 * @param string $branch Branch.
	 * @param string $plugin_file Plugin file path.
	 * @return string
	 */
	private function get_branch_plugin_version( string $repo, string $branch, string $plugin_file ): string {
		$repo = $this->sanitize_repo( $repo );
		$branch = $this->sanitize_branch( $branch );

		if ( $repo === '' || $branch === '' ) {
			return '';
		}

		$paths = $this->get_repo_file_candidates( $plugin_file );
		$last_error = '';

		foreach ( $paths as $path ) {
			$error = '';
			$contents = $this->get_repo_file_contents( $repo, $path, $branch, $error );
			if ( $contents === '' ) {
				if ( $error !== '' ) {
					$last_error = $error;
				}
				continue;
			}

			$version = $this->parse_plugin_version( $contents );
			if ( $version !== '' ) {
				$this->clear_release_error( $repo );
				return $this->normalize_version( $version );
			}
		}

		if ( $last_error === '' ) {
			$last_error = __( 'Missing plugin version on branch.', 'voxmanager' );
		}

		$this->set_release_error( $repo, $last_error );
		return '';
	}

	/**
	 * Candidate repo paths for the plugin file.
	 *
	 * @param string $plugin_file Plugin file path.
	 * @return array<int,string>
	 */
	private function get_repo_file_candidates( string $plugin_file ): array {
		$plugin_file = ltrim( $plugin_file, '/' );
		$basename = basename( $plugin_file );

		$paths = array( $basename, $plugin_file );
		$lower_plugin = strtolower( $plugin_file );
		$lower_base = strtolower( $basename );

		if ( $lower_plugin !== $plugin_file ) {
			$paths[] = $lower_plugin;
		}

		if ( $lower_base !== $basename ) {
			$paths[] = $lower_base;
		}

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * Fetch repository file contents from GitHub.
	 *
	 * @param string $repo Repo.
	 * @param string $path File path.
	 * @param string $branch Branch.
	 * @param string $error Error message.
	 * @return string
	 */
	private function get_repo_file_contents( string $repo, string $path, string $branch, string &$error ): string {
		$error = '';
		$repo = $this->sanitize_repo( $repo );
		$branch = $this->sanitize_branch( $branch );
		$path = ltrim( $path, '/' );

		if ( $repo === '' || $branch === '' || $path === '' ) {
			$error = __( 'Invalid repo file configuration.', 'voxmanager' );
			return '';
		}

		$cache_key = $this->get_repo_file_cache_key( $repo, $path, $branch );
		$cached = get_site_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$encoded_path = $this->encode_repo_path( $path );
		$api_url = sprintf(
			'https://api.github.com/repos/%s/contents/%s?ref=%s',
			$repo,
			$encoded_path,
			rawurlencode( $branch )
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => $this->apply_github_auth_headers( array() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			return '';
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$error = 'HTTP ' . $status_code;
			return '';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['content'] ) || empty( $data['encoding'] ) ) {
			$error = __( 'Missing file content.', 'voxmanager' );
			return '';
		}

		if ( $data['encoding'] !== 'base64' ) {
			$error = __( 'Unsupported file encoding.', 'voxmanager' );
			return '';
		}

		$decoded = base64_decode( str_replace( "\n", '', (string) $data['content'] ) );
		if ( ! is_string( $decoded ) || $decoded === '' ) {
			$error = __( 'Unable to decode file contents.', 'voxmanager' );
			return '';
		}

		set_site_transient( $cache_key, $decoded, 10 * MINUTE_IN_SECONDS );
		return $decoded;
	}

	/**
	 * Encode a repo path for GitHub API.
	 *
	 * @param string $path Repo path.
	 * @return string
	 */
	private function encode_repo_path( string $path ): string {
		$segments = explode( '/', $path );
		$encoded = array();
		foreach ( $segments as $segment ) {
			$encoded[] = rawurlencode( $segment );
		}

		return implode( '/', $encoded );
	}

	/**
	 * Build cache key for repo file content.
	 *
	 * @param string $repo Repo.
	 * @param string $path Path.
	 * @param string $branch Branch.
	 * @return string
	 */
	private function get_repo_file_cache_key( string $repo, string $path, string $branch ): string {
		return 'voxmanager_repo_file_' . md5( $repo . '|' . $branch . '|' . $path );
	}

	/**
	 * Parse a plugin version from file contents.
	 *
	 * @param string $contents File contents.
	 * @return string
	 */
	private function parse_plugin_version( string $contents ): string {
		if ( preg_match( '/^\\s*Version:\\s*(.+)$/mi', $contents, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Normalize version strings for comparison.
	 *
	 * @param string $raw Version input.
	 * @return string
	 */
	private function normalize_version( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		$raw = ltrim( $raw, "vV" );
		if ( preg_match( '/(\\d+\\.[\\d.]+)/', $raw, $matches ) ) {
			return $matches[1];
		}

		return $raw;
	}

	/**
	 * Build cache key for a repo.
	 *
	 * @param string $repo Repo.
	 * @return string
	 */
	private function get_release_cache_key( string $repo, string $source = '' ): string {
		if ( $source === '' ) {
			$settings = $this->get_settings();
			$source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases';
		}

		return 'voxmanager_release_' . md5( $repo . '|' . $source );
	}

	/**
	 * Build cache key for repo metadata.
	 *
	 * @param string $repo Repo.
	 * @param string $type Cache type.
	 * @return string
	 */
	private function get_repo_cache_key( string $repo, string $type ): string {
		return 'voxmanager_repo_' . $type . '_' . md5( $repo );
	}

	/**
	 * Clear cached release data.
	 *
	 * @return void
	 */
	private function clear_release_cache(): void {
		$suite_plugins = $this->get_suite_plugins();

		foreach ( $suite_plugins as $plugin_file => $config ) {
			if ( empty( $config['repo'] ) ) {
				continue;
			}

			$repo = $this->sanitize_repo( $config['repo'] );
			delete_site_transient( $this->get_release_cache_key( $repo, 'releases' ) );
			delete_site_transient( $this->get_release_cache_key( $repo, 'tags' ) );
			delete_site_transient( $this->get_release_error_key( $repo ) );
			delete_site_transient( $this->get_repo_cache_key( $repo, 'info' ) );
			delete_site_transient( $this->get_repo_cache_key( $repo, 'branches' ) );

			$branch = isset( $config['branch'] ) ? $this->sanitize_branch( (string) $config['branch'] ) : '';
			if ( $branch !== '' ) {
				$paths = $this->get_repo_file_candidates( $plugin_file );
				foreach ( $paths as $path ) {
					delete_site_transient( $this->get_repo_file_cache_key( $repo, $path, $branch ) );
				}
			}
		}
	}

	/**
	 * Store a release error.
	 *
	 * @param string $repo Repo.
	 * @param string $message Error message.
	 * @return void
	 */
	private function set_release_error( string $repo, string $message ): void {
		$message = trim( $message );
		if ( $message === '' ) {
			return;
		}

		$error = array(
			'message' => $message,
			'time'    => time(),
		);

		set_site_transient( $this->get_release_error_key( $repo ), $error, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Clear a release error.
	 *
	 * @param string $repo Repo.
	 * @return void
	 */
	private function clear_release_error( string $repo ): void {
		delete_site_transient( $this->get_release_error_key( $repo ) );
	}

	/**
	 * Get a release error.
	 *
	 * @param string $repo Repo.
	 * @return array
	 */
	private function get_release_error( string $repo ): array {
		$error = get_site_transient( $this->get_release_error_key( $repo ) );
		return is_array( $error ) ? $error : array();
	}

	/**
	 * Build error cache key.
	 *
	 * @param string $repo Repo.
	 * @return string
	 */
	private function get_release_error_key( string $repo ): string {
		return 'voxmanager_release_error_' . md5( $repo );
	}

	/**
	 * Format last update check timestamp.
	 *
	 * @param mixed $updates Update transient.
	 * @return string
	 */
	private function format_last_checked( $updates ): string {
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

	/**
	 * Determine required capability for access.
	 *
	 * @return string
	 */
	private function get_required_capability(): string {
		if ( is_multisite() && is_network_admin() ) {
			return 'manage_network_plugins';
		}

		return 'manage_options';
	}
}

Plugin::instance();
