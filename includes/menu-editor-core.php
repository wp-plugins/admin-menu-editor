<?php

//Can't have two different versions of the plugin active at the same time. It would be incredibly buggy.
if (class_exists('WPMenuEditor')){
	trigger_error(
		'Another version of Admin Menu Editor is already active. Please deactivate it before activating this one.', 
		E_USER_ERROR
	);
}

//Load the "framework"
require 'shadow_plugin_framework.php';

if ( !class_exists('WPMenuEditor') ) :

class WPMenuEditor extends MenuEd_ShadowPluginFramework {

	protected $default_wp_menu;           //Holds the default WP menu for later use in the editor
	protected $default_wp_submenu;        //Holds the default WP menu for later use
	private $filtered_wp_menu = null;     //The final, ready-for-display top-level menu and sub-menu.
	private $filtered_wp_submenu = null;

	protected $title_lookups = array(); //A list of page titles indexed by $item['file']. Used to
	                                    //fix the titles of moved plugin pages.
	private $custom_menu = null;        //The current custom menu with defaults merged in
	public $menu_format_version = 4;
    
    //Template arrays for various menu structures. See the constructor for details.
	private $basic_defaults;
	private $blank_menu;

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
		);
		$this->serialize_with_json = false; //(Don't) store the options in JSON format

		$this->settings_link = 'options-general.php?page=menu_editor';
		
		$this->magic_hooks = true;
		$this->magic_hook_priority = 99999;
		
        //Build some template arrays
        $this->basic_defaults = array(
	        //Fields that apply to all menu items.
            'page_title' => '',
			'menu_title' => '',
			'access_level' => 'read',  
			'file' => '',
	        'position' => 0,
	        'parent' => '',

	        //Fields that apply only to top level menus.
	        'css_class' => '',
	        'hookname' => '',
	        'icon_url' => '',
	        'separator' => false,

	        //Internal fields that may not map directly to WP menu structures.
	        'menu_id' => '',
	        'url' => '',
	        'is_plugin_page' => false,
	        'custom' => false,
	        'open_in' => 'same_window', //'new_window', 'iframe' or 'same_window' (the default)
        );

		//Template for a basic menu item.
		$blank_menu = array_fill_keys(array_keys($this->basic_defaults), null);
		$blank_menu['items'] = array();
		$blank_menu['defaults'] = $this->basic_defaults;
		$this->blank_menu = $blank_menu;

		//AJAXify screen options
		add_action( 'wp_ajax_ws_ame_save_screen_options', array(&$this,'ajax_save_screen_options') );

		//Activate the 'menu_order' filter. See self::hook_menu_order().
		add_filter('custom_menu_order', '__return_true');
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
		$reset_requested = isset($_GET['reset_admin_menu']) && $_GET['reset_admin_menu'];
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
			//Check if we need to upgrade the menu structure
			if ( empty($this->options['menu_format_version']) || ($this->options['menu_format_version'] < $this->menu_format_version) ){
				$this->options['custom_menu'] = $this->upgrade_menu_structure($this->options['custom_menu']);
				$this->options['menu_format_version'] = $this->menu_format_version;
				$this->save_options();
			}
			//Merge in data from the default menu
			$tree = $this->menu_merge($this->options['custom_menu'], $menu, $submenu);
			//Save for later - the editor page will need it
			$this->custom_menu = $tree;
			//Apply the custom menu
			list($menu, $submenu, $this->title_lookups) = $this->tree2wp($tree);
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
			'1.1'
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
	 * Convert a WP menu structure to an associative array.
	 *
	 * @param array $item An menu item.
	 * @param int $position The position (index) of the the menu item.
	 * @param string $parent The slug of the parent menu that owns this item. Blank for top level menus.
	 * @return array
	 */
	function menu2assoc($item, $position = 0, $parent = '') {
		static $separator_count = 0;
		$item = array(
			'menu_title'   => $item[0],
			'access_level' => $item[1],
			'file'         => $item[2],
			'page_title'   => (isset($item[3]) ? $item[3] : ''),
			'css_class'    => (isset($item[4]) ? $item[4] : ''),
			'hookname'     => (isset($item[5]) ? $item[5] : ''), //Used as the ID attr. of the generated HTML tag.
			'icon_url'     => (isset($item[6]) ? $item[6] : ''),
			'position'     => $position,
			'parent'       => $parent,
		);

		if ( empty($parent) ) {
			$item['separator'] = empty($item['file']) || empty($item['menu_title']) || (strpos($item['css_class'], 'wp-menu-separator') !== false);
			//WP 3.0 in multisite mode has two separators with the same filename. Fix by reindexing separators.
			if ( $item['separator'] ) {
				$item['file'] = 'separator_' . ($separator_count++);
			}
		} else {
			//Submenus can't contain separators.
			$item['separator'] = false;
		}

		//Flag plugin pages
		$item['is_plugin_page'] = (get_plugin_page_hook($item['file'], $parent) != null);

		//URL generation is not used yet.
		if ( !$item['separator'] ) {
			//$item['url'] = $this->get_menu_url($item['file'], $parent);
			$item['url'] = 'error-url-generation-disabled';
		}

		$item['menu_id'] = $this->unique_menu_id($item);

		return array_merge($this->basic_defaults, $item);
	}

	/**
	 * Generate a URL for a menu item.
	 *
	 * @param string $item_slug
	 * @param string $parent_slug
	 * @return string An URL relative to the /wp-admin/ directory.
	 */
	private function get_menu_url($item_slug, $parent_slug = '') {
		$menu_url = is_array($item_slug) ? $this->get_menu_field($item_slug, 'file') : $item_slug;
		$parent_url = !empty($parent_slug) ? $parent_slug : 'admin.php';

		if ( $this->is_hook_or_plugin_page($menu_url, $parent_url) ) {
			$base_file = $this->is_hook_or_plugin_page($parent_url) ? 'admin.php' : $parent_url;
			$url = add_query_arg(array('page' => $menu_url), $base_file);
		} else {
			$url = $menu_url;
		}
		return $url;
	}

	private function is_hook_or_plugin_page($page_url, $parent_page_url = '') {
		if ( empty($parent_page_url) ) {
			$parent_page_url = 'admin.php';
		}
		$pageFile = $this->remove_query_from($page_url);

		$hasHook = (get_plugin_page_hook($page_url, $parent_page_url) !== null);
		$adminFileExists = is_file(ABSPATH . '/wp-admin/' . $pageFile);
		$pluginFileExists = ($page_url != 'index.php') && is_file(WP_PLUGIN_DIR . '/' . $pageFile);

		return !$adminFileExists && ($hasHook || $pluginFileExists);
	}

	private function remove_query_from($url) {
		$pos = strpos($url, '?');
		if ( $pos !== false ) {
			return substr($url, 0, $pos);
		}
		return $url;
	}

  /**
   * Populate lookup arrays with default values from $menu and $submenu. Used later to merge
   * a custom menu with the native WordPress menu structure somewhat gracefully.
   *
   * @param array $menu
   * @param array $submenu
   * @return array An array with two elements containing menu and submenu defaults.
   */
	function build_lookups($menu, $submenu){
		$defaults = array();

		foreach($menu as $pos => $item){
			$item = $this->menu2assoc($item, $pos);
			$defaults[$this->unique_menu_id($item)] = $item;
		}

		foreach($submenu as $parent => $items){
			foreach($items as $pos => $item){
				$item = $this->menu2assoc($item, $pos, $parent);
				$defaults[$this->unique_menu_id($item)] = $item;
			}
		}

		return $defaults;
	}

  /**
   * Merge $menu and $submenu into the $tree. Adds/replaces defaults, inserts new items
   * and marks missing items as such.
   *
   * @param array $tree A menu in plugin's internal form
   * @param array $menu WordPress menu structure
   * @param array $submenu WordPress submenu structure
   * @return array Updated menu tree
   */
	function menu_merge($tree, $menu, $submenu){
		$default_items = $this->build_lookups($menu, $submenu);
		
		//Iterate over all menus and submenus and look up default values
		foreach ($tree as &$topmenu){
			$top_uid = $this->unique_menu_id($topmenu);
			//Is this menu present in the default WP menu?
			if (isset($default_items[$top_uid])){
				//Yes, load defaults from that item
				$topmenu['defaults'] = $default_items[$top_uid];
				//Note that the original item was used
				$default_items[$top_uid]['used'] = true;
			} else {
				//Record the menu as missing, unless it's a menu separator
				if ( empty($topmenu['separator']) ){
					$topmenu['missing'] = true;
					//[Nasty] Fill the 'defaults' array for menu's that don't have it.
					//This should never be required - saving a custom menu should set the defaults
					//for all menus it contains automatically.
					if ( empty($topmenu['defaults']) ){   
						$tmp = $topmenu;
						$topmenu['defaults'] = $tmp;
					}
				}
			}

			if (is_array($topmenu['items'])) {
				//Iterate over submenu items
				foreach ($topmenu['items'] as $file => &$item){
					$uid = $this->unique_menu_id($item);
					
					//Is this item present in the default WP menu?
					if (isset($default_items[$uid])){
						//Yes, load defaults from that item
						$item['defaults'] = $default_items[$uid];
						$default_items[$uid]['used'] = true;
					} else {
						//Record as missing
						$item['missing'] = true;
						if ( empty($item['defaults']) ){
							$tmp = $item;
							$item['defaults'] = $tmp;
						}
					}
				}
			}
		}

		//If we don't unset these they will fuck up the next two loops where the same names are used.
		unset($topmenu);
		unset($item);

		//Note : Now we have some items marked as missing, and some items in lookup arrays
		//that are not marked as used. The missing items are handled elsewhere (e.g. tree2wp()),
		//but lets merge in the unused items now.

		//Find and merge unused menus
		foreach ($default_items as $item){
			//Skip used menus and separators
			if ( !empty($item['used']) || !empty($item['separator'])) {
				continue;
			}

			//Found an unused item. Build the tree entry.
			$entry = $this->blank_menu;
			$entry['defaults'] = $item;
			$entry['unused'] = true; //Note that this item is unused

			//Add the new entry to the menu tree
			if ( !empty($item['parent']) ) {
				if (isset($tree[$item['parent']])) {
					//Okay, insert the item.
					$tree[$item['parent']]['items'][$item['file']] = $entry;
				} else {
					//Ooops? This should never happen. Some kind of inconsistency?
				}
			} else {
				$tree[$item['file']] = $entry;
			}
		}

		//Resort the tree to ensure the found items are in the right spots
		$tree = $this->sort_menu_tree($tree);

		return $tree;
	}

  /**
   * Generate an ID that semi-uniquely identifies a given menu item.
   *
   * @param array $item The menu item in question.
   * @param string $parent_file The parent menu. If omitted, $item['defaults']['parent'] will be used.
   * @return string Unique ID
   */
	function unique_menu_id($item, $parent_file = ''){
		//Maybe it already has an ID?
		$menu_id = $this->get_menu_field($item, 'menu_id');
		if ( !empty($menu_id) ) {
			return $menu_id;
		}

		if ( isset($item['defaults']['file']) ) {
			$item_file = $item['defaults']['file'];
		} else {
			$item_file = $this->get_menu_field($item, 'file');
		}

		if ( empty($parent_file) ) {
			if ( isset($item['defaults']['parent']) ) {
				$parent_file = $item['defaults']['parent'];
			} else {
				$parent_file = $this->get_menu_field($item, 'parent');
			}
		}

		return $parent_file . '>' . $item_file;
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
			
			$tree_item = $this->blank_menu;
			$tree_item['defaults'] = $this->menu2assoc($item, $pos);
			$tree_item['separator'] = $tree_item['defaults']['separator'];
			
			//Attach submenu items
			$parent = $tree_item['defaults']['file'];
			if ( isset($submenu[$parent]) ){
				foreach($submenu[$parent] as $pos => $subitem){
					$tree_item['items'][$subitem[2]] = array_merge(
						$this->blank_menu,
						array('defaults' => $this->menu2assoc($subitem, $pos, $parent))
					);
				}				
			}
			
			$tree[$parent] = $tree_item;
		}

		$tree = $this->sort_menu_tree($tree);

		return $tree;
	}

  /**
   * Set all undefined menu fields to the default value
   *
   * @param array $item Menu item in the plugin's internal form
   * @return array
   */
	function apply_defaults($item){
		foreach($item as $key => $value){
			//Is the field set?
			if ($value === null){
				//Use default, if available
				if (isset($item['defaults'], $item['defaults'][$key])){
					$item[$key] = $item['defaults'][$key];
				}
			}
		}
		return $item;
	}
	
	
  /**
   * Apply custom menu filters to an item of the custom menu.
   *
   * Calls two types of filters :
   * 	'custom_admin_$item_type' with the entire $item passed as the argument. 
   * 	'custom_admin_$item_type-$field' with the value of a single field of $item as the argument.
   *	
   * Used when converting the current custom menu to a WP-format menu. 
   *
   * @param array $item Associative array representing one menu item (either top-level or submenu).
   * @param string $item_type 'menu' or 'submenu'
   * @param mixed $extra Optional extra data to pass to hooks.
   * @return array Filtered menu item.
   */
	function apply_menu_filters($item, $item_type = '', $extra = null){
		if ( empty($item_type) ){
			//Only top-level menus have an icon
			$item_type = isset($item['icon_url'])?'menu':'submenu';
		}
		
		$item = apply_filters("custom_admin_{$item_type}", $item, $extra);
		foreach($item as $field => $value){
			$item[$field] = apply_filters("custom_admin_{$item_type}-$field", $value, $extra);
		}
		
		return $item;
	}
	
  /**
   * Get the value of a menu/submenu field.
   * Will return the corresponding value from the 'defaults' entry of $item if the 
   * specified field is not set in the item itself.
   *
   * @param array $item
   * @param string $field_name
   * @param mixed $default Returned if the requested field is not set and is not listed in $item['defaults']. Defaults to null.  
   * @return mixed Field value.
   */
	function get_menu_field($item, $field_name, $default = null){
		if ( isset($item[$field_name]) ){
			return $item[$field_name];
		} else {
			if ( isset($item['defaults'], $item['defaults'][$field_name]) ){
				return $item['defaults'][$field_name];
			} else {
				return $default;
			}
		}
	}

  /**
   * Custom comparison function that compares menu items based on their position in the menu.
   *
   * @param array $a
   * @param array $b
   * @return int
   */
	function compare_position($a, $b){
		return $this->get_menu_field($a, 'position', 0) - $this->get_menu_field($b, 'position', 0);
	}
	
  /**
   * Sort the menus and menu items of a given menu according to their positions 
   *
   * @param array $tree A menu structure in the internal format
   * @return array Sorted menu in the internal format
   */
	function sort_menu_tree($tree){
		//Resort the tree to ensure the found items are in the right spots
		uasort($tree, array(&$this, 'compare_position'));
		//Resort all submenus as well
		foreach ($tree as &$topmenu){
			if (!empty($topmenu['items'])){
				uasort($topmenu['items'], array(&$this, 'compare_position'));
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
		uasort($tree, array(&$this, 'compare_position'));

		//Prepare the top menu
		$first_nonseparator_found = false;
		foreach ($tree as $topmenu){
			
			//Skip missing menus, unless they're user-created and thus might point to a non-standard file
			$custom = $this->get_menu_field($topmenu, 'custom', false); 
			if ( !empty($topmenu['missing']) && !$custom ) {
				continue;
			}
			
			//Skip leading menu separators. Fixes a superfluous separator showing up
			//in WP 3.0 (multisite mode) when there's a custom menu and the current user
			//can't access its first item ("Super Admin").
			if ( !empty($topmenu['separator']) && !$first_nonseparator_found ) continue;
			
			$first_nonseparator_found = true;
			
			//Apply defaults & filters
			$topmenu = $this->apply_defaults($topmenu);
			$topmenu = $this->apply_menu_filters($topmenu, 'menu');
			
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
				uasort($items, array(&$this, 'compare_position'));
				
				foreach ($items as $item) {
					
					//Skip missing items, unless they're user-created
					$custom = $this->get_menu_field($item, 'custom', false);
					if ( !empty($item['missing']) && !$custom ) continue;
					
					//Special case : plugin pages that have been moved to a different menu.
					//If the file field hasn't already been modified, we'll need to adjust it
					//to point to the old parent. This is required because WP identifies 
					//plugin pages using *both* the plugin file and the parent file.
					if ( $this->get_menu_field($item, 'is_plugin_page') && ($item['file'] === null) ){
						$default_parent = '';
						if ( isset($item['defaults']) && isset($item['defaults']['parent'])){
							$default_parent = $item['defaults']['parent'];
						}
						if ( $topmenu['file'] != $default_parent ){
							$item['file'] = $default_parent . '?page=' . $item['defaults']['file'];
						}
					}
					
					$item = $this->apply_defaults($item);
					$item = $this->apply_menu_filters($item, 'submenu', $topmenu['file']);
					
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
   * Upgrade a menu tree to the currently used structure
   * Does nothing if the menu is already up to date.
   *
   * @param array $tree
   * @return array
   */
	function upgrade_menu_structure($tree){
		//Append new fields, if any
		foreach($tree as &$menu){
			$menu = array_merge($this->blank_menu, $menu);
            $menu['defaults'] = array_merge($this->basic_defaults, $menu['defaults']);
            
			foreach($menu['items'] as $item_file => $item){
				$item = array_merge($this->blank_menu, $item);
                $item['defaults'] = array_merge($this->basic_defaults, $item['defaults']);
				$menu['items'][$item_file] = $item;
			}
		}
		
		return $tree;
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
		
		$post = $_POST;
		$get = $_GET;
		if ( function_exists('wp_magic_quotes') ){
			//Ceterum censeo, WP shouldn't mangle superglobals.
			$post = stripslashes_deep($post); 
			$get = stripslashes_deep($get);
		}
		
		$action = isset($post['action']) ? $post['action'] : (isset($get['action']) ? $get['action'] : '');
		do_action('admin_menu_editor_header', $action);
		
		$this->handle_form_submission($post);
		$this->display_editor_ui();
	}

	private function handle_form_submission($post) {
		if (isset($post['data'])){
			check_admin_referer('menu-editor-form');

			//Try to decode a menu tree encoded as JSON
			$data = $this->json_decode($post['data'], true);
			if (!$data || (count($data) < 2) ){
				$fixed = stripslashes($post['data']);
				$data = $this->json_decode( $fixed, true );
			}

			$url = remove_query_arg('noheader');
			if ($data){
				//Ensure the user doesn't change the required capability to something they themselves don't have.
				if ( isset($data['options-general.php']['items']['menu_editor']) ){
					$item = $data['options-general.php']['items']['menu_editor'];
					if ( !empty($item['access_level']) && !current_user_can($item['access_level']) ){
						$item['access_level'] = null;
						$data['options-general.php']['items']['menu_editor'] = $item;
					}
				}

				//Save the custom menu
				$this->options['custom_menu'] = $data;
				$this->save_options();
				//Redirect back to the editor and display the success message
				wp_redirect( add_query_arg('message', 1, $url) );
			} else {
				//Or redirect & display the error message
				wp_redirect( add_query_arg('message', 2, $url) );
			}
			die();
		}
	}

	private function display_editor_ui() {
		//Prepare a bunch of parameters for the editor.
		$editor_data = array(
			'message' => isset($_GET['message']) ? intval($_GET['message']) : null,
			'images_url' => $this->plugin_dir_url . '/images',
			'hide_advanced_settings' => $this->options['hide_advanced_settings'],
		);

		//Build a tree struct. for the default menu
		$default_menu = $this->wp2tree($this->default_wp_menu, $this->default_wp_submenu);

		//Is there a custom menu?
		if (!empty($this->custom_menu)){
			$custom_menu = $this->custom_menu;
		} else {
			//Start out with the default menu if there is no user-created one
			$custom_menu = $default_menu;
		}

		//Encode both menus as JSON
		$editor_data['default_menu_js'] = $this->json_encode($default_menu);
		$editor_data['custom_menu_js'] = $this->json_encode($custom_menu);

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
	    foreach($default_menu as $toplevel){
	        if ( $toplevel['separator'] ) continue;

	        $top_title = strip_tags( preg_replace('@<span[^>]*>.*</span>@i', '', $this->get_menu_field($toplevel, 'menu_title')) );
	        if ( empty($toplevel['items'])) {
	            //This menu has no items, so it can only link to itself
		        $known_pages[$this->get_menu_field($toplevel, 'file')] = array($top_title, $top_title);
			} else {
				//When a menu has some items, it's own URL is ignored by WP and the first item is used instead.
			    foreach($toplevel['items'] as $subitem){
			        $sub_title = strip_tags( preg_replace('@<span[^>]*>.*</span>@i', '', $this->get_menu_field($subitem, 'menu_title')) );
				    $known_pages[$this->get_menu_field($subitem, 'file')] = array($top_title, $sub_title);
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
		
		$this->options['hide_advanced_settings'] = !empty($_POST['hide_advanced_settings']);
		$this->save_options();
		die('1');
	}

} //class

endif;