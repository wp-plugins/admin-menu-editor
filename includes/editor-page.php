<?php
/**
 * @var array $editor_data Various pieces of data passed by the plugin.
 */
$images_url = $editor_data['images_url'];

//Output the "Upgrade to Pro" message
if ( !apply_filters('admin_menu_editor_is_pro', false) ){
	?>
	<script type="text/javascript">
	(function($){
		$('#screen-meta-links').append(
			'<div id="ws-pro-version-notice">' +
				'<a href="http://w-shadow.com/AdminMenuEditor/" id="ws-pro-version-notice-link" class="show-settings" target="_blank" title="View Pro version details">Upgrade to Pro</a>' +
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

<?php include dirname(__FILE__) . '/access-editor-dialog.php'; ?>

<div id='ws_menu_editor'>
	<div class='ws_main_container'>
		<div class='ws_toolbar'>
			<div class="ws_button_container">
				<a id='ws_cut_menu' class='ws_button' href='javascript:void(0)' title='Cut'><img src='<?php echo $images_url; ?>/cut.png' alt="Cut" /></a>
				<a id='ws_copy_menu' class='ws_button' href='javascript:void(0)' title='Copy'><img src='<?php echo $images_url; ?>/page_white_copy.png' alt="Copy" /></a>
				<a id='ws_paste_menu' class='ws_button' href='javascript:void(0)' title='Paste'><img src='<?php echo $images_url; ?>/page_white_paste.png' alt="Paste" /></a>

				<div class="ws_separator">&nbsp;</div>

				<a id='ws_new_menu' class='ws_button' href='javascript:void(0)' title='New menu'><img src='<?php echo $images_url; ?>/page_white_add.png' alt="New menu" /></a>
				<a id='ws_hide_menu' class='ws_button' href='javascript:void(0)' title='Show/Hide'><img src='<?php echo $images_url; ?>/plugin_disabled.png' alt="Show/Hide" /></a>
				<a id='ws_delete_menu' class='ws_button' href='javascript:void(0)' title='Delete menu'><img src='<?php echo $images_url; ?>/page_white_delete.png' alt="Delete menu" /></a>

				<div class="ws_separator">&nbsp;</div>

				<a id='ws_new_separator' class='ws_button' href='javascript:void(0)' title='New separator'><img src='<?php echo $images_url; ?>/separator_add.png' alt="New separator" /></a>
			</div>
		</div>

		<div id='ws_menu_box' class="ws_box">
		</div>

		<div id="ws_top_menu_dropzone" class="ws_dropzone">
		</div>
	</div>

	<div class='ws_main_container'>
		<div class='ws_toolbar'>
			<div class="ws_button_container">
				<a id='ws_cut_item' class='ws_button' href='javascript:void(0)' title='Cut'><img src='<?php echo $images_url; ?>/cut.png' alt="Cut" /></a>
				<a id='ws_copy_item' class='ws_button' href='javascript:void(0)' title='Copy'><img src='<?php echo $images_url; ?>/page_white_copy.png' alt="Copy" /></a>
				<a id='ws_paste_item' class='ws_button' href='javascript:void(0)' title='Paste'><img src='<?php echo $images_url; ?>/page_white_paste.png' alt="Paste" /></a>

				<div class="ws_separator">&nbsp;</div>

				<a id='ws_new_item' class='ws_button' href='javascript:void(0)' title='New menu item'><img src='<?php echo $images_url; ?>/page_white_add.png' alt="New menu item" /></a>
				<a id='ws_hide_item' class='ws_button' href='javascript:void(0)' title='Show/Hide'><img src='<?php echo $images_url; ?>/plugin_disabled.png' alt="Show/Hide" /></a>
				<a id='ws_delete_item' class='ws_button' href='javascript:void(0)' title='Delete menu item'><img src='<?php echo $images_url; ?>/page_white_delete.png' alt="Delete menu item" /></a>

				<div class="ws_separator">&nbsp;</div>

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

		<div id="ws_sub_menu_dropzone" class="ws_dropzone">
		</div>
	</div>
</div>

<div class="ws_main_container" id="ws_editor_sidebar">
<form method="post" action="<?php echo admin_url('options-general.php?page=menu_editor&noheader=1'); ?>" id='ws_main_form' name='ws_main_form'>
	<?php wp_nonce_field('menu-editor-form'); ?>
	<input type="hidden" name="data" id="ws_data" value="">
	<input type="button" id='ws_save_menu' class="button-primary ws_main_button" value="Save Changes" />
</form>

	<input type="button" id='ws_reset_menu' value="Undo changes" class="button ws_main_button" />
	<input type="button" id='ws_load_menu' value="Load default menu" class="button ws_main_button" />

	<?php
		do_action('admin_menu_editor_sidebar');
	?>
</div>

</div>

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

<span id="ws-ame-screen-meta-contents" style="display:none;">
<label for="ws-hide-advanced-settings">
	<input type="checkbox" id="ws-hide-advanced-settings"<?php
		if ( $this->options['hide_advanced_settings'] ){
			echo ' checked="checked"';
		}
	?> /> Hide advanced options
</label>
</span>

<script type='text/javascript'>
var defaultMenu = <?php echo $editor_data['default_menu_js']; ?>;
var customMenu = <?php echo $editor_data['custom_menu_js']; ?>;
</script>

<?php

//Let the Pro version script output it's extra HTML & scripts.
do_action('admin_menu_editor_footer');