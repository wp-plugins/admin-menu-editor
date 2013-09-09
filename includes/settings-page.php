<?php
/**
 * This is the HTML template for the plugin settings page.
 *
 * These variables are provided by the plugin:
 * @var array $settings Plugin settings.
 * @var string $editor_page_url A fully qualified URL of the admin menu editor page.
 * @var string $settings_page_url
 */

$currentUser = wp_get_current_user();
$isMultisite = is_multisite();
$formActionUrl = add_query_arg('noheader', 1, $settings_page_url);
?>

<div class="wrap">
	<?php screen_icon(); ?>
	<h2>
		<?php echo apply_filters('admin_menu_editor-self_page_title', 'Menu Editor'); ?> Settings
		<a href="<?php echo esc_attr($editor_page_url); ?>" class="add-new-h2"
		   title="Back to Admin Menu Editor">Editor</a>
	</h2>

	<form method="post" action="<?php echo esc_attr($formActionUrl); ?>">

		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row">
					Who can access this plugin
				</th>
				<td>
					<fieldset>
						<p>
							<label>
								<input type="radio" name="plugin_access" value="super_admin"
									<?php checked('super_admin', $settings['plugin_access']); ?>
									<?php disabled( !is_super_admin() ); ?>>
								Super Admin

								<?php if ( !$isMultisite ) : ?>
									<br><span class="description">
										On a single site installation this is usually
										the same as the Administrator role.
									</span>
								<?php endif; ?>
							</label>
						</p>

						<p>
							<label>
								<input type="radio" name="plugin_access" value="manage_options"
									<?php checked('manage_options', $settings['plugin_access']); ?>
									<?php disabled( !current_user_can('manage_options') ); ?>>
								Anyone with the "manage_options" capability

								<br><span class="description">
									By default only Administrators have this capability.
								</span>
							</label>
						</p>

						<p>
							<label>
								<input type="radio" name="plugin_access" value="specific_user"
									<?php checked('specific_user', $settings['plugin_access']); ?>
									<?php disabled( is_multisite() && !is_super_admin() ); ?>>
								Only the current user

								<br>
								<span class="description">
									Login: <?php echo $currentUser->user_login; ?>,
								 	user ID: <?php echo get_current_user_id(); ?>
								</span>
							</label>
						</p>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row">
					Multisite settings
				</th>
				<td>
					<fieldset id="ame-menu-scope-settings">
						<p>
							<label>
								<input type="radio" name="menu_config_scope" value="global"
								       id="ame-menu-config-scope-global"
									<?php checked('global', $settings['menu_config_scope']); ?>
									<?php disabled(!$isMultisite); ?>>
								Global &mdash;
								Use the same admin menu settings for all network sites.
							</label><br>
						</p>


						<label>
							<input type="radio" name="menu_config_scope" value="site"
								<?php checked('site', $settings['menu_config_scope']); ?>
								<?php disabled(!$isMultisite); ?>>
							Per-site &mdash;
							Use different admin menu settings for each site.
						</label>
					</fieldset>
				</td>
			</tr>
			</tbody>
		</table>

		<input type="hidden" name="action" value="save_settings">
		<?php
		wp_nonce_field('save_settings');
		submit_button();
		?>
	</form>

</div>