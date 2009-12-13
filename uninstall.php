<?php

/**
 * @author W-Shadow 
 * @copyright 2009
 *
 * The uninstallation script.
 */

if( defined( 'ABSPATH') && defined('WP_UNINSTALL_PLUGIN') ) {

	//Remove the plugin's settings
	delete_option('ws_menu_editor');

}

?>