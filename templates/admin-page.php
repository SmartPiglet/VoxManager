<?php
/**
 * VoxManager Admin Page Template
 *
 * @package VoxManager
 */

namespace Voxel\VoxManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notices = array();

if ( $checked_notice ) {
	$notices[] = __( 'Update check completed.', 'voxmanager' );
}
if ( $saved_notice ) {
	$notices[] = __( 'Settings saved.', 'voxmanager' );
}
if ( $synced_notice ) {
	$notices[] = __( 'GitHub info refreshed.', 'voxmanager' );
}
if ( $installed_notice ) {
	$notices[] = __( 'Plugin installed successfully.', 'voxmanager' );
}
if ( $install_error ) {
	$notices[] = '' !== $install_error_message
		? $install_error_message
		: __( 'Plugin installation failed.', 'voxmanager' );
}

$rate_limit = $this->github->get_rate_limit();
$rate_limit_ok = ! $this->github->is_rate_limit_low( 20 );
if ( is_array( $rate_limit ) && ! $rate_limit_ok ) {
	$notices[] = sprintf(
		/* translators: 1: remaining requests, 2: total limit */
		__( 'GitHub API rate limit low: %1$d/%2$d requests remaining.', 'voxmanager' ),
		(int) $rate_limit['remaining'],
		(int) $rate_limit['limit']
	);
}
?>
<div class="sticky-top">
	<div class="vx-head x-container">
		<h2><?php echo esc_html__( 'VoxManager', 'voxmanager' ); ?></h2>
	</div>
</div>
<div class="ts-spacer"></div>

<?php if ( ! empty( $notices ) ) : ?>
	<div class="x-container">
		<?php foreach ( $notices as $notice ) : ?>
			<div class="vx-info-box wide">
				<p><?php echo esc_html( $notice ); ?></p>
			</div>
			<div class="ts-spacer"></div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<div class="x-container">
	<div class="ts-group">
		<div class="ts-group-head">
			<h3><?php echo esc_html__( 'Update controls', 'voxmanager' ); ?></h3>
		</div>
		<div class="x-row">
			<div class="x-col-12">
				<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'voxmanager_check_updates' ); ?>
					<input type="hidden" name="action" value="voxmanager_check_updates" />
					<button type="submit" class="ts-button ts-save-settings btn-shadow">
						<?php echo esc_html__( 'Check for updates', 'voxmanager' ); ?>
					</button>
				</form>
				<?php if ( $last_checked ) : ?>
					<p class="mt10">
						<?php
						printf(
							/* translators: %s: formatted date/time of last update check */
							esc_html__( 'Last checked: %s', 'voxmanager' ),
							esc_html( $last_checked )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
<div class="ts-spacer"></div>

<div class="x-container">
	<div class="ts-group">
		<div class="ts-group-head">
			<h3><?php echo esc_html__( 'Suite status', 'voxmanager' ); ?></h3>
		</div>
		<div class="vx-panels">
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
						$install_action = '<a class="ts-button ts-outline" href="' . esc_url( $install_url ) . '">' . esc_html__( 'Install', 'voxmanager' ) . '</a>';
					}
				} elseif ( ! empty( $error['message'] ) ) {
					$update_label = esc_html( sprintf( __( 'Update check failed: %s', 'voxmanager' ), $error['message'] ) );
				} elseif ( $update_data['available'] ) {
					$update_label = esc_html( sprintf( __( 'Update available: %s', 'voxmanager' ), $update_data['version'] ) );
					$update_url = wp_nonce_url(
						self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . $plugin_file ),
						'upgrade-plugin_' . $plugin_file
					);
					$update_action = '<a class="ts-button ts-save-settings btn-shadow" href="' . esc_url( $update_url ) . '">' . esc_html__( 'Update now', 'voxmanager' ) . '</a>';
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
				?>
				<div class="vx-panel">
					<div class="panel-info">
						<h3><?php echo esc_html( $label ); ?></h3>
						<ul>
							<li><?php echo esc_html( $plugin_file ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Version: %s', 'voxmanager' ), $version !== '' ? $version : '-' ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Status: %s', 'voxmanager' ), $status ) ); ?></li>
							<li><?php echo esc_html( sprintf( __( 'Update: %s', 'voxmanager' ), $update_label ) ); ?></li>
							<li><?php echo wp_kses_post( sprintf( __( 'Repo: %s', 'voxmanager' ), $repo_link ) ); ?></li>
						</ul>
					</div>
					<?php if ( $install_action ) : ?>
						<?php echo wp_kses_post( $install_action ); ?>
					<?php elseif ( $update_action ) : ?>
						<?php echo wp_kses_post( $update_action ); ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
<div class="ts-spacer"></div>

<div class="x-container">
	<div class="ts-group">
		<div class="ts-group-head">
			<h3><?php echo esc_html__( 'GitHub settings', 'voxmanager' ); ?></h3>
		</div>
		<div class="x-row">
			<div class="x-col-12">
				<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'voxmanager_sync_github' ); ?>
					<input type="hidden" name="action" value="voxmanager_sync_github" />
					<button type="submit" class="ts-button ts-outline">
						<?php echo esc_html__( 'Refresh GitHub info', 'voxmanager' ); ?>
					</button>
				</form>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( self_admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'voxmanager_save_settings' ); ?>
			<input type="hidden" name="action" value="voxmanager_save_settings" />

			<div class="x-row">
				<div class="ts-form-group x-col-12">
					<label for="voxmanager_github_token"><?php echo esc_html__( 'GitHub token', 'voxmanager' ); ?></label>
					<?php
					$token_value = isset( $settings['github_token'] ) ? (string) $settings['github_token'] : '';
					$token_placeholder = $token_value !== '' ? '********' : '';
					?>
					<input type="password" id="voxmanager_github_token" name="voxmanager_github_token" value="" placeholder="<?php echo esc_attr( $token_placeholder ); ?>" />
					<p><?php echo esc_html__( 'Used to access private GitHub repos. Leave blank to keep the existing token.', 'voxmanager' ); ?></p>
					<?php if ( $token_defined ) : ?>
						<p><?php echo esc_html__( 'VOXMANAGER_GITHUB_TOKEN is set in wp-config.php. Saved tokens will be used when the field is not empty.', 'voxmanager' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="ts-form-group x-col-6">
					<label for="voxmanager_release_source"><?php echo esc_html__( 'Release source', 'voxmanager' ); ?></label>
					<?php $release_source = isset( $settings['release_source'] ) ? (string) $settings['release_source'] : 'releases'; ?>
					<select id="voxmanager_release_source" name="voxmanager_release_source">
						<option value="releases" <?php echo selected( $release_source, 'releases', false ); ?>><?php echo esc_html__( 'Latest release', 'voxmanager' ); ?></option>
						<option value="tags" <?php echo selected( $release_source, 'tags', false ); ?>><?php echo esc_html__( 'Latest tag', 'voxmanager' ); ?></option>
					</select>
				</div>
				<div class="ts-form-group x-col-6">
					<label for="voxmanager_package_source"><?php echo esc_html__( 'Package source', 'voxmanager' ); ?></label>
					<?php $package_source = isset( $settings['package_source'] ) ? (string) $settings['package_source'] : 'asset'; ?>
					<select id="voxmanager_package_source" name="voxmanager_package_source">
						<option value="zipball" <?php echo selected( $package_source, 'zipball', false ); ?>><?php echo esc_html__( 'Repo zipball', 'voxmanager' ); ?></option>
						<option value="asset" <?php echo selected( $package_source, 'asset', false ); ?>><?php echo esc_html__( 'Release asset', 'voxmanager' ); ?></option>
					</select>
					<p><?php echo esc_html__( 'Repo zipball pulls directly from the repository. Release assets use uploaded zip files.', 'voxmanager' ); ?></p>
				</div>
			</div>

			<div class="ts-spacer"></div>
			<h3><?php echo esc_html__( 'Suite plugins', 'voxmanager' ); ?></h3>
			<div class="x-row">
				<?php foreach ( $suite_plugins as $plugin_file => $config ) : ?>
					<?php
					$label = $config['label'] !== '' ? $config['label'] : $plugin_file;
					$repo_info = $config['repo'] ? $this->github->get_repo_info( $config['repo'] ) : null;
					$default_branch = is_array( $repo_info ) ? (string) ( $repo_info['default_branch'] ?? '' ) : '';
					$branches = $config['repo'] ? $this->github->get_repo_branches( $config['repo'] ) : array();
					$selected_branch = $config['branch'] !== '' ? $config['branch'] : $default_branch;
					?>
					<div class="ts-form-group x-col-12">
						<label><?php echo esc_html( $label ); ?> <span class="ts-italic ts-gray">(<?php echo esc_html( $plugin_file ); ?>)</span></label>
						<div class="x-row">
							<div class="ts-form-group x-col-4">
								<label><?php echo esc_html__( 'Slug', 'voxmanager' ); ?></label>
								<input type="text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][slug]" value="<?php echo esc_attr( $config['slug'] ); ?>" />
							</div>
							<div class="ts-form-group x-col-4">
								<label><?php echo esc_html__( 'Repository (owner/repo)', 'voxmanager' ); ?></label>
								<input type="text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][repo]" value="<?php echo esc_attr( $config['repo'] ); ?>" />
							</div>
							<div class="ts-form-group x-col-4">
								<label><?php echo esc_html__( 'Branch', 'voxmanager' ); ?></label>
								<?php if ( ! empty( $branches ) ) : ?>
									<select name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][branch]">
										<option value=""><?php echo esc_html__( 'Use releases/tags', 'voxmanager' ); ?></option>
										<?php foreach ( $branches as $branch_name ) : ?>
											<?php $label_suffix = $branch_name === $default_branch ? ' (' . esc_html__( 'default', 'voxmanager' ) . ')' : ''; ?>
											<option value="<?php echo esc_attr( $branch_name ); ?>" <?php echo selected( $selected_branch, $branch_name, false ); ?>><?php echo esc_html( $branch_name . $label_suffix ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="text" name="voxmanager_plugins[<?php echo esc_attr( $plugin_file ); ?>][branch]" value="<?php echo esc_attr( $selected_branch ); ?>" placeholder="<?php echo esc_attr__( 'main', 'voxmanager' ); ?>" />
									<p><?php echo esc_html__( 'GitHub branch list unavailable. Check token or repo permissions.', 'voxmanager' ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<button type="submit" class="ts-button ts-save-settings btn-shadow">
				<?php echo esc_html__( 'Save settings', 'voxmanager' ); ?>
			</button>
		</form>
	</div>
</div>
