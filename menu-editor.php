<?php
/*
Plugin Name: Admin Menu Editor
Plugin URI: http://w-shadow.com/blog/2008/12/20/admin-menu-editor-for-wordpress/
Description: Lets you directly edit the WordPress admin menu. You can re-order, hide or rename existing menus, add custom menus and more. 
Version: 1.3-alpha
Author: Janis Elsts
Author URI: http://w-shadow.com/blog/
*/

//It's not possible to have two versions of this plugin active at the same time. Abort plugin load
//and display an error if we detect that another version has already been loaded.
if ( class_exists('WPMenuEditor') ) {

	function ws_ame_activation_conflict() {
		if ( !current_user_can('activate_plugins') ) {
			return; //The current user can't do anything about the problem.
		}
		?>
		<div class="error fade">
			<p>
				<strong>Error: Another version of Admin Menu Editor is already active.</strong><br>
				Please deactivate the older version. It is not possible to run two different versions
				of this plugin at the same time.
			</p>
		</div>
	<?php
	}

	add_action('admin_notices', 'ws_ame_activation_conflict');
	return;
}

//Are we running in the Dashboard?
if ( is_admin() ) {

    //Load the plugin
    require 'includes/menu-editor-core.php';
    $wp_menu_editor = new WPMenuEditor(__FILE__, 'ws_menu_editor');

}//is_admin()