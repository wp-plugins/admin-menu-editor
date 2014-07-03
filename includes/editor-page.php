<?php
/**
 * @var array $editor_data Various pieces of data passed by the plugin.
 */
$current_user = wp_get_current_user();
$images_url = $editor_data['images_url'];

$icons = array(
	'cut' => '/gnome-icon-theme/edit-cut-blue.png',
	'copy' => '/gion/edit-copy.png',
	'paste' => '/gnome-icon-theme/edit-paste.png',
	'hide'  => '/icon-extension-grey.png',
	'new' => '/page-add.png',
	'delete' => '/page-delete.png',
	'new-separator' => '/separator-add.png',
	'toggle-all' => '/check-all.png',
);
foreach($icons as $name => $url) {
	$icons[$name] = $images_url . $url;
}

//Output the "Upgrade to Pro" message
if ( !apply_filters('admin_menu_editor_is_pro', false) ){
	?>
	<script type="text/javascript">
	(function($){
		$('#screen-meta-links').append(
			'<div id="ws-pro-version-notice" class="custom-screen-meta-link-wrap">' +
				'<a href="http://adminmenueditor.com/upgrade-to-pro/?utm_source=Admin%2BMenu%2BEditor%2Bfree&utm_medium=text_link&utm_content=top_upgrade_link&utm_campaign=Plugins" id="ws-pro-version-notice-link" class="show-settings custom-screen-meta-link" target="_blank" title="View Pro version details">Upgrade to Pro</a>' +
			'</div>'
		);
	})(jQuery);
	</script>
	<?php
}

?>
<div class="wrap">
	<h2>
		<?php echo apply_filters('admin_menu_editor-self_page_title', 'Menu Editor'); ?>
		<a href="<?php echo esc_attr($editor_data['settings_page_url']); ?>" class="add-new-h2" id="ws_plugin_settings_button"
		   title="Configure plugin settings">Settings</a>
	</h2>

<?php
	if ( !empty($_GET['message']) ){
		if ( intval($_GET['message']) == 1 ){
			echo '<div id="message" class="updated fade"><p><strong>Settings saved.</strong></p></div>';
		} elseif ( intval($_GET['message']) == 2 ) {
			echo '<div id="message" class="error"><p><strong>Failed to decode input! The menu wasn\'t modified.</strong></p></div>';
		}
	}
?>

<?php
$hint_id = 'ws_whats_new_120';
$show_whats_new = false && apply_filters('admin_menu_editor_is_pro', false) && !empty($editor_data['show_hints'][$hint_id]);
if ( $show_whats_new ):
    ?>
    <div class="ws_hint" id="<?php echo esc_attr($hint_id); ?>">
        <div class="ws_hint_close" title="Close">x</div>
        <div class="ws_hint_content">
            <strong>What's New In 1.20 and 1.30</strong>
            <ul>
                <li>New menu permissions interface.
                    <a href="http://w-shadow.com/admin-menu-editor-pro/permissions/">Learn more.</a></li>

                <li>You can now use "not:user:username", "capability1,capability2", "capability1+capability2" and other
                    advanced syntax in the capability field. See the link above for details.</li>

                <li>You can drag sub-menu items to the top level and the other way around. To do it,
                    drag the item to the very end of the (sub-)menu and drop it on the yellow rectangle that will appear.</li>

                <li>Added a "Target page" drop-down to simplify setting menu URLs. You can still enter an arbitrary URL
                    by selecting "Custom".</li>

                <li>Miscellaneous bug fixes.</li>

            </ul>
        </div>
    </div>
    <?php
endif;
?>

<?php
include dirname(__FILE__) . '/access-editor-dialog.php';
if ( apply_filters('admin_menu_editor_is_pro', false) ) {
	include dirname(__FILE__) . '/../extras/menu-color-dialog.php';
}
?>

<div id='ws_menu_editor'>
    <div id="ws_actor_selector_container">
        <ul id="ws_actor_selector" class="subsubsub" style="display: none;">
            <!-- Contents will be generated by JS -->
        </ul>
        <div class="clear"></div>
    </div>

    <div>

	<div class='ws_main_container'>
		<div class='ws_toolbar'>
			<div class="ws_button_container">
				<a id='ws_cut_menu' class='ws_button' href='javascript:void(0)' title='Cut'><img src='<?php echo $icons['cut']; ?>' alt="Cut" /></a>
				<a id='ws_copy_menu' class='ws_button' href='javascript:void(0)' title='Copy'><img src='<?php echo $icons['copy']; ?>' alt="Copy" /></a>
				<a id='ws_paste_menu' class='ws_button' href='javascript:void(0)' title='Paste'><img src='<?php echo $icons['paste']; ?>' alt="Paste" /></a>

				<div class="ws_separator">&nbsp;</div>

				<a id='ws_new_menu' class='ws_button' href='javascript:void(0)' title='New menu'><img src='<?php echo $icons['new']; ?>' alt="New menu" /></a>

				<?php if ( $editor_data['show_deprecated_hide_button'] ): ?>
					<a id='ws_hide_menu' class='ws_button' href='javascript:void(0)' title='Show/Hide'><img src='<?php echo $icons['hide']; ?>' alt="Show/Hide" /></a>
				<?php endif; ?>

				<a id='ws_delete_menu' class='ws_button' href='javascript:void(0)' title='Delete menu'><img src='<?php echo $icons['delete']; ?>' alt="Delete menu" /></a>

				<div class="ws_separator">&nbsp;</div>

				<a id='ws_new_separator' class='ws_button' href='javascript:void(0)' title='New separator'><img src='<?php echo $icons['new-separator']; ?>' alt="New separator" /></a>

				<?php  if ( apply_filters('admin_menu_editor_is_pro', false) ): ?>
					<div class="ws_separator">&nbsp;</div>

					<a id='ws_toggle_all_menus' class='ws_button' href='javascript:void(0)'
					   title='Toggle all menus for the selected role'><img src='<?php echo $icons['toggle-all']; ?>' alt="Toggle all" /></a>
				<?php endif; ?>
			</div>
		</div>

		<div id='ws_menu_box' class="ws_box">
		</div>

		<?php do_action('admin_menu_editor-container', 'menu'); ?>
	</div>

	<div class='ws_main_container'>
		<div class='ws_toolbar'>
			<div class="ws_button_container">
				<a id='ws_cut_item' class='ws_button' href='javascript:void(0)' title='Cut'><img src='<?php echo $icons['cut']; ?>' alt="Cut" /></a>
				<a id='ws_copy_item' class='ws_button' href='javascript:void(0)' title='Copy'><img src='<?php echo $icons['copy']; ?>' alt="Copy" /></a>
				<a id='ws_paste_item' class='ws_button' href='javascript:void(0)' title='Paste'><img src='<?php echo $icons['paste']; ?>' alt="Paste" /></a>

				<div class="ws_separator">&nbsp;</div>

				<a id='ws_new_item' class='ws_button' href='javascript:void(0)' title='New menu item'><img src='<?php echo $icons['new']; ?>' alt="New menu item" /></a>
				<?php if ( $editor_data['show_deprecated_hide_button'] ): ?>
					<a id='ws_hide_item' class='ws_button' href='javascript:void(0)' title='Show/Hide'><img src='<?php echo $icons['hide']; ?>' alt="Show/Hide" /></a>
				<?php endif; ?>
				<a id='ws_delete_item' class='ws_button' href='javascript:void(0)' title='Delete menu item'><img src='<?php echo $icons['delete']; ?>' alt="Delete menu item" /></a>

				<div class="ws_separator">&nbsp;</div>

				<?php if ( apply_filters('admin_menu_editor_is_pro', false) ): ?>
					<a id='ws_new_submenu_separator' class='ws_button' href='javascript:void(0)' title='New separator'><img src='<?php echo $icons['new-separator']; ?>' alt="New separator" /></a>
					<div class="ws_separator">&nbsp;</div>
				<?php endif; ?>

				<a id='ws_sort_ascending' class='ws_button' href='javascript:void(0)' title='Sort ascending'>
					<img src='<?php echo $images_url; ?>/sort_ascending.png' alt="Sort ascending" />
				</a>
				<a id='ws_sort_descending' class='ws_button' href='javascript:void(0)' title='Sort descending'>
					<img src='<?php echo $images_url; ?>/sort_descending.png' alt="Sort descending" />
				</a>
			</div>
		</div>

		<div id='ws_submenu_box' class="ws_box">
		</div>

		<?php do_action('admin_menu_editor-container', 'submenu'); ?>
	</div>

	<div class="ws_basic_container">

		<div class="ws_main_container" id="ws_editor_sidebar">
		<form method="post" action="<?php echo admin_url('options-general.php?page=menu_editor&noheader=1'); ?>" id='ws_main_form' name='ws_main_form'>
			<?php wp_nonce_field('menu-editor-form'); ?>
			<input type="hidden" name="action" value="save_menu">
			<input type="hidden" name="data" id="ws_data" value="">
			<input type="hidden" name="data_length" id="ws_data_length" value="">
			<input type="hidden" name="selected_actor" id="ws_selected_actor" value="">
			<input type="button" id='ws_save_menu' class="button-primary ws_main_button" value="Save Changes" />
		</form>

			<input type="button" id='ws_reset_menu' value="Undo changes" class="button ws_main_button" />
			<input type="button" id='ws_load_menu' value="Load default menu" class="button ws_main_button" />

			<?php
				do_action('admin_menu_editor-sidebar');
			?>
		</div>

		<?php
		$hint_id = 'ws_sidebar_pro_ad';
		$show_pro_benefits = !apply_filters('admin_menu_editor_is_pro', false) && (!isset($editor_data['show_hints'][$hint_id]) || $editor_data['show_hints'][$hint_id]);

		if ( $show_pro_benefits ):
			$benefit_variations = array(
				'Simplified, role-based permissions.',
				'Role-based menu permissions.',
				'Simpler, role-based permissions.',
			);
			//Pseudo-randomly select one phrase based on the site URL.
			$variation_index = hexdec( substr(md5(get_site_url()), -1) ) % count($benefit_variations);
			$selected_variation = $benefit_variations[$variation_index];

			$pro_version_link = 'http://adminmenueditor.com/upgrade-to-pro/?utm_source=Admin%2BMenu%2BEditor%2Bfree&utm_medium=text_link&utm_content=sidebar_link_cv' . $variation_index . '&utm_campaign=Plugins';
			?>
			<div class="clear"></div>

			<div class="ws_hint" id="<?php echo esc_attr($hint_id); ?>">
				<div class="ws_hint_close" title="Close">x</div>
				<div class="ws_hint_content">
					<strong>Upgrade to Pro:</strong>
					<ul>
						<li><?php echo $selected_variation; ?></li>
						<li>Drag items between menu levels.</li>
						<li>Menu export &amp; import.</li>
					</ul>
					<a href="<?php echo esc_attr($pro_version_link); ?>" target="_blank">Learn more</a>
					|
					<a href="http://amedemo.com/" target="_blank">Try online demo</a>
				</div>
			</div>
		<?php
		endif;
		?>

	</div> <!-- / .ws_basic_container -->

    </div>

	<div class="clear"></div>

</div> <!-- / .ws_menu_editor -->

</div> <!-- / .wrap -->



<?php
	//Create a pop-up capability selector
	$capSelector = array('<select id="ws_cap_selector" class="ws_dropdown" size="10">');

	$capSelector[] = '<optgroup label="Roles">';
 	foreach($editor_data['all_roles'] as $role_id => $role_name){
 		$capSelector[] = sprintf(
		 	'<option value="%s">%s</option>',
		 	esc_attr($role_id),
		 	$role_name
	 	);
 	}
 	$capSelector[] = '</optgroup>';

 	$capSelector[] = '<optgroup label="Capabilities">';
 	foreach($editor_data['all_capabilities'] as $cap){
 		$capSelector[] = sprintf(
		 	'<option value="%s">%s</option>',
		 	esc_attr($cap),
		 	$cap
	 	);
 	}
 	$capSelector[] = '</optgroup>';
 	$capSelector[] = '</select>';

 	echo implode("\n", $capSelector);
?>

<!-- Menu icon selector widget -->
<?php $iconSelectorClass = $editor_data['show_extra_icons'] ? 'ws_with_more_icons' : ''; ?>
<div id="ws_icon_selector" class="<?php echo $iconSelectorClass; ?>" style="display: none;">
	<?php
	//Let the user select a custom icon via the media uploader.
	//We only support the new WP 3.5+ media API. Hence the function_exists() check.
	if ( function_exists('wp_enqueue_media') ):
	?>
		<input type="button" class="button"
		   id="ws_choose_icon_from_media"
		   title="Upload an image or choose one from your media library"
		   value="Choose Icon">
		<div class="clear"></div>
	<?php
	endif;
	?>

	<?php
	//The old "menu-icon-something" icons are only available in WP 3.8.x and below. Newer versions use Dashicons.
	//Plugins can change $wp_version to something useless for security, so lets check if Dashicons are available
	//before we throw away the old icons.
	$oldMenuIconsAvailable = ( !$editor_data['dashicons_available'] )
		|| version_compare($GLOBALS['wp_version'], '3.9-beta', '<');

	if ($oldMenuIconsAvailable) {
		$defaultWpIcons = array(
			'generic', 'dashboard', 'post', 'media', 'links', 'page', 'comments',
			'appearance', 'plugins', 'users', 'tools', 'settings', 'site',
		);
		foreach($defaultWpIcons as $icon) {
			printf(
				'<div class="ws_icon_option" title="%1$s" data-icon-class="menu-icon-%2$s">
					<div class="ws_icon_image icon16 icon-%2$s"><br></div>
				</div>',
				esc_attr(ucwords($icon)),
				$icon
			);
		}
	}

	//These dashicons are used in the default admin menu.
	$defaultDashicons = array(
		'admin-generic', 'dashboard', 'admin-post', 'admin-media', 'admin-links', 'admin-page', 'admin-comments',
		'admin-appearance', 'admin-plugins', 'admin-users', 'admin-tools', 'admin-settings', 'admin-network',
	);

	//The rest of Dashicons. Some icons were manually removed as they wouldn't look good as menu icons.
	$dashicons = array(
		'admin-site', 'admin-home',
		'align-center', 'align-left', 'align-none', 'align-right', 'analytics', 'art', 'awards', 'backup',
		'book', 'book-alt', 'businessman', 'calendar', 'camera', 'cart', 'category', 'chart-area', 'chart-bar',
		'chart-line', 'chart-pie', 'clock', 'cloud', 'desktop', 'dismiss', 'download', 'edit', 'editor-customchar',
		'editor-distractionfree', 'editor-help', 'editor-insertmore',
		'editor-justify', 'editor-kitchensink', 'editor-ol', 'editor-paste-text',
		'editor-paste-word', 'editor-quote', 'editor-removeformatting', 'editor-rtl', 'editor-spellcheck',
		'editor-ul', 'editor-unlink', 'editor-video',
		'email', 'email-alt', 'exerpt-view', 'facebook', 'facebook-alt', 'feedback', 'flag', 'format-aside',
		'format-audio', 'format-chat', 'format-gallery', 'format-image', 'format-quote', 'format-status',
		'format-video', 'forms', 'googleplus', 'groups', 'hammer', 'id', 'id-alt', 'image-crop',
		'image-flip-horizontal', 'image-flip-vertical', 'image-rotate-left', 'image-rotate-right', 'images-alt',
		'images-alt2', 'info', 'leftright', 'lightbulb', 'list-view', 'location', 'location-alt', 'lock', 'marker',
		'menu', 'migrate', 'minus', 'networking', 'no', 'no-alt', 'performance', 'plus', 'portfolio', 'post-status',
		'pressthis', 'products', 'redo', 'rss', 'screenoptions', 'search', 'share', 'share-alt',
		'share-alt2', 'share1', 'shield', 'shield-alt', 'slides', 'smartphone', 'smiley', 'sort', 'sos', 'star-empty',
		'star-filled', 'star-half', 'tablet', 'tag', 'testimonial', 'translation', 'twitter', 'undo',
		'update', 'upload', 'vault', 'video-alt', 'video-alt2', 'video-alt3', 'visibility', 'welcome-add-page',
		'welcome-comments', 'welcome-learn-more', 'welcome-view-site', 'welcome-widgets-menus', 'welcome-write-blog',
		'wordpress', 'wordpress-alt', 'yes'
	);

	if ($editor_data['dashicons_available']) {
		function ws_ame_print_dashicon_option($icon, $isExtraIcon = false) {
			printf(
				'<div class="ws_icon_option%3$s" title="%1$s" data-icon-url="dashicons-%2$s">
					<div class="ws_icon_image icon16 dashicons dashicons-%2$s"></div>
				</div>',
				esc_attr(ucwords(str_replace('-', ' ', $icon))),
				$icon,
				$isExtraIcon ? ' ws_icon_extra' : ''
			);
		}

		if ( !$oldMenuIconsAvailable ) {
			foreach($defaultDashicons as $icon) {
				ws_ame_print_dashicon_option($icon);
			}
		}
		foreach($dashicons as $icon) {
			ws_ame_print_dashicon_option($icon, true);
		}
	}

	$defaultIconImages = array(
		'images/generic.png',
	);
	foreach($defaultIconImages as $icon) {
		printf(
			'<div class="ws_icon_option" data-icon-url="%1$s">
				<img src="%1$s">
			</div>',
			esc_attr($icon)
		);
	}

	?>
	<div class="ws_icon_option ws_custom_image_icon" title="Custom image" style="display: none;">
		<img src="<?php echo esc_attr(admin_url('images/loading.gif')); ?>">
	</div>


	<?php if ($editor_data['dashicons_available']): ?>
		<!-- Only show this button on recent WP versions where Dashicons are included. -->
		<input type="button" class="button"
		   id="ws_show_more_icons"
		   title="Toggle additional icons"
		   value="<?php echo esc_attr($editor_data['show_extra_icons'] ? 'Less &#x25B2;' : 'More &#x25BC;'); ?>">
	<?php endif; ?>

	<div class="clear"></div>
</div>

<span id="ws-ame-screen-meta-contents" style="display:none;">
	<label for="ws-hide-advanced-settings">
		<input type="checkbox" id="ws-hide-advanced-settings"<?php
			if ( $this->options['hide_advanced_settings'] ){
				echo ' checked="checked"';
			}
		?> /> Hide advanced options
	</label><br>

	<label for="ws-show-extra-icons">
		<input type="checkbox" id="ws-show-extra-icons"<?php
		if ( $this->options['show_extra_icons'] ){
			echo ' checked="checked"';
		}
		?> /> Show extra menu icons
	</label>
</span>


<!-- Confirmation dialog when hiding "Dashboard -> Home" -->
<div id="ws-ame-dashboard-hide-confirmation" style="display: none;">
	<span>
		Hiding <em>Dashboard -> Home</em> may prevent users with the selected role from logging in!
		Are you sure you want to do it?
	</span>

	<h4>Explanation</h4>
	<p>
		WordPress automatically redirects users to the <em>Dashboard -> Home</em> page upon successful login.
		If you hide this page, users will get an "insufficient permissions" error when they log in
		due to being redirected to a hidden page. As a result, it will look like their login failed.
	</p>

	<h4>Recommendations</h4>
	<p>
		You can use a plugin like <a href="http://wordpress.org/plugins/peters-login-redirect/">Peter's Login Redirect</a>
		to redirect specific roles to different pages.
	</p>

	<div class="ws_dialog_buttons">
		<?php
		submit_button('Hide the menu', 'primary', 'ws_confirm_menu_hiding', false);
		submit_button('Leave it visible', 'secondary', 'ws_cancel_menu_hiding', false);
		?>
	</div>

	<label class="ws_dont_show_again">
		<input type="checkbox" id="ws-ame-disable-dashboard-hide-confirmation">
		Don't show this message again
	</label>
</div>

<!-- Confirmation dialog when trying to delete a non-custom item. -->
<div id="ws-ame-menu-deletion-error" title="Error" style="display: none;">
	<div class="ws_dialog_panel">
		Sorry, it's not possible to permanently delete
		<span id="ws-ame-menu-type-desc">{a built-in menu item|an item added by another plugin}</span>.
		You can only hide it.
	</div>

	<div class="ws_dialog_buttons ame-vertical-button-list">
		<?php
		submit_button('Hide it from all users', 'secondary', 'ws_hide_menu_from_everyone', false);
		submit_button(
			sprintf('Hide it from everyone except "%s"', $current_user->get('user_login')),
			'secondary',
			'ws_hide_menu_except_current_user',
			false
		);
		submit_button('Cancel', 'secondary', 'ws_cancel_menu_deletion', false);
		?>
	</div>
</div>


<script type='text/javascript'>
var defaultMenu = <?php echo $editor_data['default_menu_js']; ?>;
var customMenu = <?php echo $editor_data['custom_menu_js']; ?>;
</script>

<?php

//Let the Pro version script output it's extra HTML & scripts.
do_action('admin_menu_editor-footer');