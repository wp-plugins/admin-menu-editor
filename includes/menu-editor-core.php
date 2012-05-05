<?php

//Can't have two different versions of the plugin active at the same time. It would be incredibly buggy.
if (class_exists('WPMenuEditor')){
	trigger_error(
		'Another version of Admin Menu Editor is already active. Please deactivate it before activating this one.', 
		E_USER_ERROR
	);
}

$thisDirectory = dirname(__FILE__);
require $thisDirectory . '/shadow_plugin_framework.php';
require $thisDirectory . '/menu-item.php';
require $thisDirectory . '/menu.php';

if ( !class_exists('WPMenuEditor') ) :

class WPMenuEditor extends MenuEd_ShadowPluginFramework {

	protected $default_wp_menu;           //Holds the default WP menu for later use in the editor
	protected $default_wp_submenu;        //Holds the default WP menu for later use
	private $filtered_wp_menu = null;     //The final, ready-for-display top-level menu and sub-menu.
	private $filtered_wp_submenu = null;

	protected $title_lookups = array(); //A list of page titles indexed by $item['file']. Used to
	                                    //fix the titles of moved plugin pages.
	private $custom_menu = null;        //The current custom menu with defaults merged in
	private $item_templates = array();

	//Our personal copy of the request vars, without any "magic quotes".
	private $post = array();
	private $get = array();
    
	function init(){
		//Determine if the plugin is active network-wide (i.e. either installed in 
		//the /mu-plugins/ directory or activated "network wide" by the super admin.
		if ( $this->is_super_plugin() ){
			$this->sitewide_options = true;
		}
		
		//Set some plugin-specific options
		if ( empty($this->option_name) ){
			$this->option_name = 'ws_menu_editor';
		}
		$this->defaults = array(
			'hide_advanced_settings' => true,
			'menu_format_version' => 0,
			'custom_menu' => null,
		);
		$this->serialize_with_json = false; //(Don't) store the options in JSON format

		$this->settings_link = 'options-general.php?page=menu_editor';
		
		$this->magic_hooks = true;
		$this->magic_hook_priority = 99999;
		
		//AJAXify screen options
		add_action('wp_ajax_ws_ame_save_screen_options', array(&$this,'ajax_save_screen_options'));

		//Activate the 'menu_order' filter. See self::hook_menu_order().
		add_filter('custom_menu_order', '__return_true');

		//Make sure we have access to the original, un-mangled request data.
		//This is necessary because WordPress will stupidly apply "magic quotes"
		//to the request vars even if this PHP misfeature is disabled.
		add_action('plugins_loaded', array($this, 'capture_request_vars'));
	}

  /**
   * Activation hook
   * 
   * @return void
   */
	function activate(){
		//If we have no stored settings for this version of the plugin, try importing them
		//from other versions (i.e. the free or the Pro version).
		if ( !$this->load_options() ){
			$this->import_settings();
		}
		parent::activate();
	}
	
  /**
   * Import settings from a different version of the plugin.
   * 
   * @return bool True if settings were imported successfully, False otherwise
   */
	function import_settings(){
		$possible_names = array('ws_menu_editor', 'ws_menu_editor_pro');
		foreach($possible_names as $option_name){
			if ( $this->load_options($option_name) ){
				return true;
			}
		}
		return false;
	}

  /**
   * Create a configuration page and load the custom menu
   *
   * @return void
   */
	function hook_admin_menu(){
		global $menu, $submenu;
		
		//Menu reset (for emergencies). Executed by accessing http://example.com/wp-admin/?reset_admin_menu=1 
		$reset_requested = isset($this->get['reset_admin_menu']) && $this->get['reset_admin_menu'];
		if ( $reset_requested && $this->current_user_can_edit_menu() ){
			$this->options['custom_menu'] = null;
			$this->save_options();
		}
		
		//The menu editor is only visible to users with the manage_options privilege.
		//Or, if the plugin is installed in mu-plugins, only to the site administrator(s). 
		if ( $this->current_user_can_edit_menu() ){
			$page = add_options_page(
				apply_filters('admin_menu_editor-self_page_title', 'Menu Editor'), 
				apply_filters('admin_menu_editor-self_menu_title', 'Menu Editor'), 
				'manage_options', 
				'menu_editor', 
				array(&$this, 'page_menu_editor')
			);
			//Output our JS & CSS on that page only
			add_action("admin_print_scripts-$page", array(&$this, 'enqueue_scripts'));
			add_action("admin_print_styles-$page", array(&$this, 'enqueue_styles'));
			
			//Make a placeholder for our screen options (hacky)
			add_meta_box("ws-ame-screen-options", "You should never see this", '__return_false', $page);
		}
		
		//Store the "original" menus for later use in the editor
		$this->default_wp_menu = $menu;
		$this->default_wp_submenu = $submenu;

		//Is there a custom menu to use?
		if ( !empty($this->options['custom_menu']) ){
			$custom_menu = ameMenu::load_array($this->options['custom_menu']);

			//Generate item templates from the default menu.
			$this->item_templates = $this->build_templates($this->default_wp_menu, $this->default_wp_submenu);

			//Merge in data from the default menu
			$custom_menu['tree'] = $this->menu_merge($custom_menu['tree'], $this->item_templates);
			//Save for later - the editor page will need it
			$this->custom_menu = $custom_menu;
			//Apply the custom menu
			list($menu, $submenu, $this->title_lookups) = $this->tree2wp($this->custom_menu['tree']);

			//Re-filter the menu (silly WP should do that itself, oh well)
			$this->filter_menu();
			$this->filtered_wp_menu = $menu;
			$this->filtered_wp_submenu = $submenu;
		}
	}

	/**
	  * Add the JS required by the editor to the page header
	  *
	  * @return void
	  */
	function enqueue_scripts(){
		//jQuery JSON plugin
		wp_enqueue_script('jquery-json', $this->plugin_dir_url.'/js/jquery.json-1.3.js', array('jquery'), '1.3');
		//jQuery sort plugin
		wp_enqueue_script('jquery-sort', $this->plugin_dir_url.'/js/jquery.sort.js', array('jquery'));
		//jQuery UI Droppable
		wp_enqueue_script('jquery-ui-droppable');

		//Editor's scipts
		wp_enqueue_script(
			'menu-editor',
			$this->plugin_dir_url.'/js/menu-editor.js',
			array('jquery', 'jquery-ui-sortable', 'jquery-ui-dialog', 'jquery-form'),
			'20120426'
		);

		//The editor will need access to some of the plugin data.
		wp_localize_script(
			'menu-editor',
			'wsEditorData',
			array(
				'imagesUrl' => $this->plugin_dir_url . '/images',
				'adminAjaxUrl' => admin_url('admin-ajax.php'),
				'hideAdvancedSettings' => (boolean)$this->options['hide_advanced_settings'],
				'hideAdvancedSettingsNonce' => wp_create_nonce('ws_ame_save_screen_options'),
				'captionShowAdvanced' => 'Show advanced options',
				'captionHideAdvanced' => 'Hide advanced options',
				'wsMenuEditorPro' => false, //Will be overwritten if extras are loaded
				'menuFormatName' => ameMenu::format_name,
				'menuFormatVersion' => ameMenu::format_version,
				'blankMenuItem' => ameMenuItem::blank_menu(),
				'itemTemplates' => $this->item_templates,
			)
		);
	}

	 /**
	  * Add the editor's CSS file to the page header
	  *
	  * @return void
	  */
	function enqueue_styles(){
		wp_enqueue_style('menu-editor-style', $this->plugin_dir_url . '/css/menu-editor.css', array(), '1.1');
	}

	/**
	 * Override the order of the top-level menu entries.
	 *
	 * @param array $menu_order
	 * @return array
	 */
	function hook_menu_order($menu_order){
		if (empty($this->custom_menu)){
			return $menu_order;
		}
		$custom_menu_order = array();
		foreach($this->filtered_wp_menu as $topmenu){
			$filename = $topmenu[2];
			if ( in_array($filename, $menu_order) ){
				$custom_menu_order[] = $filename;
			}
		}
		return $custom_menu_order;
	}
	
	/**
	 * Determine if the current user may use the menu editor.
	 * 
	 * @return bool
	 */
	function current_user_can_edit_menu(){
		if ( $this->is_super_plugin() ){
			return is_super_admin();
		} else {
			return current_user_can('manage_options');
		}
	}
	
	/**
	 * Fix the page title for moved plugin pages.
	 * The 'admin_title' filter is only available in WP 3.1+
	 * 
	 * @param string $admin_title The current admin title (full).
	 * @param string $title The current page title. 
	 * @return string New admin title.
	 */
	function hook_admin_title($admin_title, $title){
		if ( empty($title) ){
			$admin_title = $this->get_real_page_title() . $admin_title;
		}
		return $admin_title;
	}
	
	/**
	 * Get the correct page title for a plugin page that's been moved to a different menu.
	 *  
	 * @return string
	 */
	function get_real_page_title(){
		global $title;
		global $pagenow;
		global $plugin_page;

		$real_title = $title;
		if ( empty($title) && !empty($plugin_page) && !empty($pagenow) ){
			$file = sprintf('%s?page=%s', $pagenow, $plugin_page);
			if ( isset($this->title_lookups[$file]) ){
				$real_title = esc_html( strip_tags( $this->title_lookups[$file] ) );
			}
		}
		
		return $real_title;
	}	
	

  /**
   * Loop over the Dashboard submenus and remove pages for which the current user does not have privs.
   *
   * @return void
   */
	function filter_menu(){
		global $submenu, $_wp_submenu_nopriv;
		
		foreach ($submenu as $parent => $items) {
			foreach ($items as $index => $data) {
				if ( ! current_user_can($data[1]) ) {
					unset($submenu[$parent][$index]);
					$_wp_submenu_nopriv[$parent][$data[2]] = true;
				}
			}

			if ( empty($submenu[$parent]) ) {
				unset($submenu[$parent]);
			}
		}
	}

  /**
   * Populate a lookup array with default values from $menu and $submenu. Used later to merge
   * a custom menu with the native WordPress menu structure somewhat gracefully.
   *
   * @param array $menu
   * @param array $submenu
   * @return array An array of menu templates and their default values.
   */
	function build_templates($menu, $submenu){
		$templates = array();

		$name_lookup = array();
		foreach($menu as $pos => $item){
			$item = ameMenuItem::fromWpItem($item, $pos);
			if ($item['separator']) {
				continue;
			}

			$name = $this->sanitize_menu_title($item['menu_title']);
			$name_lookup[$item['file']] = $name;

			$templates[ameMenuItem::template_id($item)] = array(
				'name' => $name,
				'used' => false,
				'defaults' => $item
			);
		}

		foreach($submenu as $parent => $items){
			foreach($items as $pos => $item){
				$item = ameMenuItem::fromWpItem($item, $pos, $parent);
				$templates[ameMenuItem::template_id($item)] = array(
					'name' => $name_lookup[$parent] . ' -> ' . $this->sanitize_menu_title($item['menu_title']),
					'used' => false,
					'defaults' => $item
				);
			}
		}

		return $templates;
	}

	/**
	 * Sanitize a menu title for display.
	 * Removes HTML tags and update notification bubbles.
	 *
	 * @param string $title
	 * @return string
	 */
	private function sanitize_menu_title($title) {
		return strip_tags( preg_replace('@<span[^>]*>.*</span>@i', '', $title) );
	}

  /**
   * Merge $menu and $submenu into the $tree. Adds/replaces defaults, inserts new items
   * and marks missing items as such.
   *
   * @param array $tree A menu in plugin's internal form
   * @param array $itemTemplates A lookup list of default menu items, used as templates for the custom menu.
   * @return array Updated menu tree
   */
	function menu_merge($tree, $itemTemplates){
		//Iterate over all menus and submenus and look up default values
		foreach ($tree as &$topmenu){

			if ( !ameMenuItem::get($topmenu, 'custom') ) {
				$template_id = ameMenuItem::template_id($topmenu);
				//Is this menu present in the default WP menu?
				if (isset($itemTemplates[$template_id])){
					//Yes, load defaults from that item
					$topmenu['defaults'] = $itemTemplates[$template_id]['defaults'];
					//Note that the original item was used
					$itemTemplates[$template_id]['used'] = true;
				} else {
					//Record the menu as missing, unless it's a menu separator
					if ( empty($topmenu['separator']) ){
						$topmenu['missing'] = true;
					}
				}
			}

			if (is_array($topmenu['items'])) {
				//Iterate over submenu items
				foreach ($topmenu['items'] as &$item){
					if ( !ameMenuItem::get($item, 'custom') ) {
						$template_id = ameMenuItem::template_id($item);

						//Is this item present in the default WP menu?
						if (isset($itemTemplates[$template_id])){
							//Yes, load defaults from that item
							$item['defaults'] = $itemTemplates[$template_id]['defaults'];
							$itemTemplates[$template_id]['used'] = true;
						} else {
							//Record as missing
							$item['missing'] = true;
						}
					}
				}
			}
		}

		//If we don't unset these they will fuck up the next two loops where the same names are used.
		unset($topmenu);
		unset($item);

		//Now we have some items marked as missing, and some items in lookup arrays
		//that are not marked as used. Lets remove the missing items from the tree and
		//merge in the unused items.
		$filteredTree = array();
		foreach($tree as $file => $topmenu) {
			if ( $topmenu['missing'] ) {
				continue;
			}
			$filteredSubmenu = array();
			if (is_array($topmenu['items'])) {
				foreach($topmenu['items'] as $index => $item) {
					if ( !$item['missing'] ) {
						$filteredSubmenu[$index] = $item;
					}
				}

			}
			$topmenu['items'] = $filteredSubmenu;
			$filteredTree[$file] = $topmenu;
		}

		$tree = $filteredTree;

		//Find and merge unused menus
		foreach ($itemTemplates as $template_id => $template){
			//Skip used menus and separators
			if ( !empty($template['used']) || !empty($template['defaults']['separator'])) {
				continue;
			}

			//Found an unused item. Build the tree entry.
			$entry = ameMenuItem::blank_menu();
			$entry['template_id'] = $template_id;
			$entry['defaults'] = $template['defaults'];
			$entry['unused'] = true; //Note that this item is unused

			//Add the new entry to the menu tree
			if ( !empty($template['defaults']['parent']) ) {
				if (isset($tree[$template['defaults']['parent']])) {
					//Okay, insert the item.
					$tree[$template['defaults']['parent']]['items'][$template['defaults']['file']] = $entry;
				} else {
					//Ooops? This should never happen. Some kind of inconsistency?
				}
			} else {
				$tree[$template['defaults']['file']] = $entry;
			}
		}

		//Resort the tree to ensure the found items are in the right spots
		$tree = $this->sort_menu_tree($tree);

		return $tree;
	}

  /**
   * Convert the WP menu structure to the internal representation. All properties set as defaults.
   *
   * @param array $menu
   * @param array $submenu
   * @return array Menu in the internal tree format.
   */
	function wp2tree($menu, $submenu){
		$tree = array();
		foreach ($menu as $pos => $item){
			
			$tree_item = ameMenuItem::blank_menu();
			$tree_item['defaults'] = ameMenuItem::fromWpItem($item, $pos);
			$tree_item['separator'] = $tree_item['defaults']['separator'];
			
			//Attach sub-menu items
			$parent = $tree_item['defaults']['file'];
			if ( isset($submenu[$parent]) ){
				foreach($submenu[$parent] as $position => $subitem){
					$tree_item['items'][$subitem[2]] = array_merge(
						ameMenuItem::blank_menu(),
						array('defaults' => ameMenuItem::fromWpItem($subitem, $position, $parent))
					);
				}				
			}
			
			$tree[$parent] = $tree_item;
		}

		$tree = $this->sort_menu_tree($tree);

		return $tree;
	}

  /**
   * Sort the menus and menu items of a given menu according to their positions 
   *
   * @param array $tree A menu structure in the internal format
   * @return array Sorted menu in the internal format
   */
	function sort_menu_tree($tree){
		//Resort the tree to ensure the found items are in the right spots
		uasort($tree, 'ameMenuItem::compare_position');
		//Resort all submenus as well
		foreach ($tree as &$topmenu){
			if (!empty($topmenu['items'])){
				uasort($topmenu['items'], 'ameMenuItem::compare_position');
			}
		}
		
		return $tree;
	}

  /**
   * Convert internal menu representation to the form used by WP.
   * 
   * Note : While this function doesn't cause any side effects of its own, 
   * it executes several filters that may modify global state. Specifically,
   * IFrame-handling callbacks in 'extras.php' may insert items into the 
   * global $menu and $submenu arrays.
   *
   * @param array $tree
   * @return array $menu and $submenu
   */
	function tree2wp($tree){
		$menu = array();
		$submenu = array();
		$title_lookup = array();
		
		//Sort the menu by position
		uasort($tree, 'ameMenuItem::compare_position');

		//Prepare the top menu
		$first_nonseparator_found = false;
		foreach ($tree as $topmenu){
			
			//Skip missing menus.
			if ( !empty($topmenu['missing']) ) {
				continue;
			}
			
			//Skip leading menu separators. Fixes a superfluous separator showing up
			//in WP 3.0 (multisite mode) when there's a custom menu and the current user
			//can't access its first item ("Super Admin").
			if ( !empty($topmenu['separator']) && !$first_nonseparator_found ) continue;
			
			$first_nonseparator_found = true;
			
			//Apply defaults & filters
			$topmenu = ameMenuItem::apply_defaults($topmenu);
			$topmenu = ameMenuItem::apply_filters($topmenu, 'menu');
			
			//Skip hidden entries
			if (!empty($topmenu['hidden'])) continue;
			
			//Build the menu structure that WP expects
			$menu[] = array(
					$topmenu['menu_title'],
					$topmenu['access_level'],
					$topmenu['file'],
					$topmenu['page_title'],
					$topmenu['css_class'],
					$topmenu['hookname'], //ID
					$topmenu['icon_url']
				);
				
			//Prepare the submenu of this menu
			if( !empty($topmenu['items']) ){
				$items = $topmenu['items'];
				//Sort by position
				uasort($items, 'ameMenuItem::compare_position');
				
				foreach ($items as $item) {
					
					//Skip missing items
					if ( !empty($item['missing']) ) {
						continue;
					}
					
					//Special case : plugin pages that have been moved to a different menu.
					//If the file field hasn't already been modified, we'll need to adjust it
					//to point to the old parent. This is required because WP identifies 
					//plugin pages using *both* the plugin file and the parent file.
					if ( ameMenuItem::get($item, 'is_plugin_page') && ($item['file'] === null) ){
						$default_parent = '';
						if ( isset($item['defaults']) && isset($item['defaults']['parent'])){
							$default_parent = $item['defaults']['parent'];
						}
						if ( $topmenu['file'] != $default_parent ){
							$item['file'] = $default_parent . '?page=' . $item['defaults']['file'];
						}
					}
					
					$item = ameMenuItem::apply_defaults($item);
					$item = ameMenuItem::apply_filters($item, 'submenu', $topmenu['file']);
					
					//Skip hidden items
					if (!empty($item['hidden'])) {
						continue;
					}
					
					$submenu[$topmenu['file']][] = array(
						$item['menu_title'],
						$item['access_level'],
						$item['file'],
						$item['page_title'],
					);
					 
					//Make a note of the page's correct title so we can fix it later
					//if necessary.
					$title_lookup[$item['file']] = $item['menu_title']; 
				}
			}
		}
		return array($menu, $submenu, $title_lookup);
	}
	
  /**
   * Output the menu editor page
   *
   * @return void
   */
	function page_menu_editor(){
		if ( !$this->current_user_can_edit_menu() ){
			wp_die("Access denied.");
		}
		
		$action = isset($this->post['action']) ? $this->post['action'] : (isset($this->get['action']) ? $this->get['action'] : '');
		do_action('admin_menu_editor_header', $action);
		
		$this->handle_form_submission($this->post);
		$this->display_editor_ui();
	}

	private function handle_form_submission($post) {
		if (isset($post['data'])){
			check_admin_referer('menu-editor-form');

			//Try to decode a menu tree encoded as JSON
			$url = remove_query_arg('noheader');
			try {
				$menu = ameMenu::load_json($post['data'], true);
			} catch (InvalidMenuException $ex) {
				//Or redirect & display the error message
				wp_redirect( add_query_arg('message', 2, $url) );
				die();
			}

			//Ensure the user doesn't change the required capability to something they themselves don't have.
			if ( isset($menu['tree']['options-general.php']['items']['menu_editor']) ){
				$item = $menu['tree']['options-general.php']['items']['menu_editor'];
				if ( !empty($item['access_level']) && !current_user_can($item['access_level']) ){
					$item['access_level'] = null;
					$menu['tree']['options-general.php']['items']['menu_editor'] = $item;
				}
			}

			//Save the custom menu
			$this->options['custom_menu'] = $menu;
			$this->save_options();
			//Redirect back to the editor and display the success message
			wp_redirect( add_query_arg('message', 1, $url) );
			die();
		}
	}

	private function display_editor_ui() {
		//Prepare a bunch of parameters for the editor.
		$editor_data = array(
			'message' => isset($this->get['message']) ? intval($this->get['message']) : null,
			'images_url' => $this->plugin_dir_url . '/images',
			'hide_advanced_settings' => $this->options['hide_advanced_settings'],
		);

		//Build a tree struct. for the default menu
		$default_tree = $this->wp2tree($this->default_wp_menu, $this->default_wp_submenu);
		$default_menu = ameMenu::load_array($default_tree);

		//Is there a custom menu?
		if (!empty($this->custom_menu)){
			$custom_menu = $this->custom_menu;
		} else {
			//Start out with the default menu if there is no user-created one
			$custom_menu = $default_menu;
		}

		//Encode both menus as JSON
		$editor_data['default_menu_js'] = ameMenu::to_json($default_menu);
		$editor_data['custom_menu_js'] = ameMenu::to_json($custom_menu);

		//Create a list of all known capabilities and roles. Used for the dropdown list on the access field.
		$all_capabilities = $this->get_all_capabilities();
		//"level_X" capabilities are deprecated so we don't want people using them.
		//This would look better with array_filter() and an anonymous function as a callback.
		for($level = 0; $level <= 10; $level++){
			$cap = 'level_' . $level;
			if ( isset($all_capabilities[$cap]) ){
				unset($all_capabilities[$cap]);
			}
		}
		$all_capabilities = array_keys($all_capabilities);
		natcasesort($all_capabilities);
		$editor_data['all_capabilities'] = $all_capabilities;

		//Create a list of all roles, too.
		$all_roles = $this->get_all_roles();
		if ( is_multisite() ){ //Multi-site installs also get the virtual "Super Admin" role
			$all_roles['super_admin'] = 'Super Admin';
		}
		asort($all_roles);
		$editor_data['all_roles'] = $all_roles;

		//Create a list of known admin pages for yet another selector.
		$known_pages = array();
	    foreach($default_menu['tree'] as $toplevel){
	        if ( $toplevel['separator'] ) continue;

	        $top_title = strip_tags( preg_replace('@<span[^>]*>.*</span>@i', '', ameMenuItem::get($toplevel, 'menu_title')) );
	        if ( empty($toplevel['items'])) {
	            //This menu has no items, so it can only link to itself
		        $known_pages[ameMenuItem::get($toplevel, 'file')] = array($top_title, $top_title);
			} else {
				//When a menu has some items, it's own URL is ignored by WP and the first item is used instead.
			    foreach($toplevel['items'] as $subitem){
			        $sub_title = strip_tags( preg_replace('@<span[^>]*>.*</span>@i', '', ameMenuItem::get($subitem, 'menu_title')) );
				    $known_pages[ameMenuItem::get($subitem, 'file')] = array($top_title, $sub_title);
			    }
		    }
	    }
		$editor_data['known_pages'] = $known_pages;

		require dirname(__FILE__) . '/editor-page.php';
	}
	
  /**
   * Retrieve a list of all known capabilities of all roles
   *
   * @return array Associative array with capability names as keys
   */
	function get_all_capabilities(){
		/** @var WP_Roles $wp_roles */
		global $wp_roles;
		
		$capabilities = array();
		
		if ( !isset($wp_roles) || !isset($wp_roles->roles) ){
			return $capabilities;
		}
		
		//Iterate over all known roles and collect their capabilities
		foreach($wp_roles->roles as $role){
			if ( !empty($role['capabilities']) && is_array($role['capabilities']) ){ //Being defensive here
				$capabilities = array_merge($capabilities, $role['capabilities']);
			}
		}
		
		//Add multisite-specific capabilities (not listed in any roles in WP 3.0)
		$multisite_caps = array(
			'manage_sites' => 1,  
			'manage_network' => 1, 
			'manage_network_users' => 1, 
			'manage_network_themes' => 1, 
			'manage_network_options' => 1, 
			'manage_network_plugins' => 1, 
		);
		$capabilities = array_merge($capabilities, $multisite_caps);
		
		return $capabilities;
	}
	
  /**
   * Retrieve a list of all known roles
   *
   * @return array Associative array with role IDs as keys and role display names as values
   */
	function get_all_roles(){
		/** @var WP_Roles $wp_roles */
		global $wp_roles;
		$roles = array();
		
		if ( !isset($wp_roles) || !isset($wp_roles->roles) ){
			return $roles;
		}
		
		foreach($wp_roles->roles as $role_id => $role){
			$roles[$role_id] = $role['name'];
		}
		
		return $roles;
	}

	/**
	 * Create a virtual 'super_admin' capability that only super admins have.
	 * This function accomplishes that by by filtering 'user_has_cap' calls.
	 * 
	 * @param array $allcaps All capabilities belonging to the current user, cap => true/false.
	 * @param array $required_caps The required capabilities.
	 * @param array $args The capability passed to current_user_can, the current user's ID, and other args.
	 * @return array Filtered version of $allcaps
	 */
	function hook_user_has_cap($allcaps, $required_caps, $args){
		//Be careful not to overwrite a super_admin cap added by other plugins 
		//For example, Advanced Access Manager also adds this capability. 
		if ( in_array('super_admin', $required_caps) && !isset($allcaps['super_admin']) ){
			$allcaps['super_admin'] = is_multisite() && is_super_admin($args[1]);
		}
		return $allcaps;
	}

	/**
	 * AJAX callback for saving screen options (whether to show or to hide advanced menu options).
	 * 
	 * Handles the 'ws_ame_save_screen_options' action. The new option value 
	 * is read from $_POST['hide_advanced_settings'].
	 * 
	 * @return void
	 */
	function ajax_save_screen_options(){
		if (!current_user_can('manage_options') || !check_ajax_referer('ws_ame_save_screen_options', false, false)){
			die( $this->json_encode( array(
				'error' => "You're not allowed to do that!" 
			 )));
		}
		
		$this->options['hide_advanced_settings'] = !empty($this->post['hide_advanced_settings']);
		$this->save_options();
		die('1');
	}

	/**
	 * Capture $_GET and $_POST in $this->get and $this->post.
	 * Slashes added by "magic quotes" will be stripped.
	 */
	function capture_request_vars(){
		$this->post = $_POST;
		$this->get = $_GET;

		if ( function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc() ) {
			$this->post = stripslashes_deep($this->post);
			$this->get = stripslashes_deep($this->get);
		}
	}

} //class

endif;