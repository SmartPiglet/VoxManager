<?php

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'VoxManager', 'voxmanager' ); ?></h1>

	<?php if ( $checked_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Update check completed.', 'voxmanager' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $saved_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'voxmanager' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $synced_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'GitHub info refreshed.', 'voxmanager' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $installed_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Plugin installed successfully.', 'voxmanager' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $install_error ) : ?>
		<?php
		$message = $install_error_message !== ''
			? $install_error_message
			: esc_html__( 'Plugin installation failed.', 'voxmanager' );
		?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
	<?php endif; ?>

	<h2><?php echo esc_html__( 'Update controls', 'voxmanager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'voxmanager_check_updates' ); ?>
		<input type="hidden" name="action" value="voxmanager_check_updates" />
		<?php submit_button( esc_html__( 'Check for updates', 'voxmanager' ), 'primary', 'voxmanager_check_updates', false ); ?>
	</form>

	<?php if ( $last_checked ) : ?>
		<p><?php echo esc_html( sprintf( __( 'Last checked: %s', 'voxmanager' ), $last_checked ) ); ?></p>
	<?php endif; ?>

	<h2><?php echo esc_html__( 'Suite status', 'voxmanager' ); ?></h2>
	<table class="widefat striped">
		<thead>
		<tr>
			<th><?php echo esc_html__( 'Plugin', 'voxmanager' ); ?></th>
			<th><?php echo esc_html__( 'Version', 'voxmanager' ); ?></th>
			<th><?php echo esc_html__( 'Status', 'voxmanager' ); ?></th>
			<th><?php echo esc_html__( 'Update', 'voxmanager' ); ?></th>
			<th><?php echo esc_html__( 'Repository', 'voxmanager' ); ?></th>
			<th><?php echo esc_html__( 'Actions', 'voxmanager' ); ?></th>
		</tr>
		</thead>
		<tbody>
		<?php foreach ( $suite_plugins as $plugin_file => $config ) : ?>
			<?php
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
			$update_data = $this->updater->get_update_data( $updates, $plugin_file );
			$error = $config['repo'] ? $this->github->get_release_error( $config['repo'] ) : array();
			$update_label = '';
			$update_action = '';
			$install_action = '';

			if ( ! $is_installed ) {
				$update_label = esc_html__( 'Not installed', 'voxmanager' );
				if ( current_user_can( $this->settings->get_install_capability() ) ) {
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
				$update_url = wp_nonce_url(
					self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . $plugin_file ),
					'upgrade-plugin_' . $plugin_file
				);
				$update_action = '<a href="' . esc_url( $update_url ) . '">' . esc_html__( 'Update now', 'voxmanager' ) . '</a>';
			} elseif ( $last_checked ) {
				$update_label = esc_html__( 'Up to date', 'voxmanager' );
			} else {
				$update_label = esc_html__( 'Not checked yet', 'voxmanager' );
			}

			$repo_link = '-';
			if ( $config['repo'] ) {
				$repo_url = 'https://github.com/' . ltrim( $config['repo'], '/' );
				$repo_link = '<a href="' . esc_url( $repo_url ) . '" target="_blank" rel="noopener">' . esc_html( $config['repo'] ) . '</a>';
			}

			$plugin_meta = '<div>' . esc_html( $label ) . '</div>';
			$plugin_meta .= '<div><code>' . esc_html( $plugin_file ) . '</code></div>';
			?>
			<tr>
				<td><?php echo $plugin_meta; ?></td>
				<td><?php echo esc_html( $version ?: '-' ); ?></td>
				<td><?php echo esc_html( $status ); ?></td>
				<td><?php echo $update_label; ?></td>
				<td><?php echo $repo_link; ?></td>
				<?php if ( ! $is_installed ) : ?>
					<td><?php echo $install_action ? $install_action : '-'; ?></td>
				<?php else : ?>
					<td><?php echo $update_action ? $update_action : '-'; ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php echo esc_html__( 'GitHub settings', 'voxmanager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'voxmanager_sync_github' ); ?>
		<input type="hidden" name="action" value="voxmanager_sync_github" />
		<?php submit_button( esc_html__( 'Refresh GitHub info', 'voxmanager' ), 'secondary', 'voxmanager_sync_github', false ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'voxmanager_save_settings' ); ?>
		<input type="hidden" name="action" value="voxmanager_save_settings" />

		<table class="form-table">
			<tr>
				<th scope="row"><label for="voxmanager_github_token"><?php echo esc_html__( 'GitHub token', 'voxmanager' ); ?></label></th>
				<td>
					<?php
					$token_value = isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
					$token_placeholder = $token_value !== '' ? '********' : '';
					?>
					<input type="password" class="regular-text" id="voxmanager_github_token" name="voxmanager_github_token" value="" placeholder="<?php echo esc_attr( $token_placeholder ); ?>" />
					<p class="description"><?php echo esc_html__( 'Used to access private GitHub repos. Leave blank to keep the existing token.', 'voxmanager' ); ?></p>
					<?php if ( $token_defined ) : ?>
						<p class="description"><?php echo esc_html__( 'VOXMANAGER_GITHUB_TOKEN is set in wp-config.php. Saved tokens will be used when the field is not empty.', 'voxmanager' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="voxmanager_release_source"><?php echo esc_html__( 'Release source', 'voxmanager' ); ?></label></th>
				<td>
					<?php $release_source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases'; ?>
					<select id="voxmanager_release_source" name="voxmanager_release_source">
						<option value="releases" <?php echo selected( $release_source, 'releases', false ); ?>><?php echo esc_html__( 'Latest release', 'voxmanager' ); ?></option>
						<option value="tags" <?php echo selected( $release_source, 'tags', false ); ?>><?php echo esc_html__( 'Latest tag', 'voxmanager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="voxmanager_package_source"><?php echo esc_html__( 'Package source', 'voxmanager' ); ?></label></th>
				<td>
					<?php $package_source = isset( $settings['package_source'] ) ? (string) $settings['package_source'] : 'asset'; ?>
					<select id="voxmanager_package_source" name="voxmanager_package_source">
						<option value="zipball" <?php echo selected( $package_source, 'zipball', false ); ?>><?php echo esc_html__( 'Repo zipball', 'voxmanager' ); ?></option>
						<option value="asset" <?php echo selected( $package_source, 'asset', false ); ?>><?php echo esc_html__( 'Release asset', 'voxmanager' ); ?></option>
					</select>
					<p class="description"><?php echo esc_html__( 'Repo zipball pulls directly from the repository. Release assets use uploaded zip files.', 'voxmanager' ); ?></p>
				</td>
			</tr>
		</table>

		<h3><?php echo esc_html__( 'Suite plugins', 'voxmanager' ); ?></h3>
		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php echo esc_html__( 'Plugin', 'voxmanager' ); ?></th>
				<th><?php echo esc_html__( 'Slug', 'voxmanager' ); ?></th>
				<th><?php echo esc_html__( 'Repository (owner/repo)', 'voxmanager' ); ?></th>
				<th><?php echo esc_html__( 'Branch', 'voxmanager' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $suite_plugins as $plugin_file => $config ) : ?>
				<?php
				$label = $config['label'] !== '' ? $config['label'] : $plugin_file;
				$repo_info = $config['repo'] ? $this->github->get_repo_info( $config['repo'] ) : null;
				$default_branch = is_array( $repo_info ) ? (string) ( $repo_info['default_branch'] ?? '' ) : '';
				$branches = $config['repo'] ? $this->github->get_repo_branches( $config['repo'] ) : array();
				$selected_branch = $config['branch'] !== '' ? $config['branch'] : $default_branch;
				?>
				<tr>
					<td><?php echo esc_html( $label ); ?><br><code><?php echo esc_html( $plugin_file ); ?></code></td>
					<td><input type="text" class="regular-text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][slug]" value="<?php echo esc_attr( $config['slug'] ); ?>" /></td>
					<td><input type="text" class="regular-text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][repo]" value="<?php echo esc_attr( $config['repo'] ); ?>" /></td>
					<td>
						<?php if ( ! empty( $branches ) ) : ?>
							<select name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][branch]">
								<option value=""><?php echo esc_html__( 'Use releases/tags', 'voxmanager' ); ?></option>
								<?php foreach ( $branches as $branch_name ) : ?>
									<?php $label_suffix = $branch_name === $default_branch ? ' (' . esc_html__( 'default', 'voxmanager' ) . ')' : ''; ?>
									<option value="<?php echo esc_attr( $branch_name ); ?>" <?php echo selected( $selected_branch, $branch_name, false ); ?>><?php echo esc_html( $branch_name . $label_suffix ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="text" class="regular-text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][branch]" value="<?php echo esc_attr( $selected_branch ); ?>" placeholder="<?php echo esc_attr__( 'main', 'voxmanager' ); ?>" />
							<p class="description"><?php echo esc_html__( 'GitHub branch list unavailable. Check token or repo permissions.', 'voxmanager' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php submit_button( esc_html__( 'Save settings', 'voxmanager' ) ); ?>
	</form>
</div>
