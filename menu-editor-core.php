<?php

//Load the "framework"
require 'shadow_plugin_framework.php';

class WPMenuEditor extends MenuEd_ShadowPluginFramework {

	protected $default_wp_menu = null; //Holds the default WP menu for later use in the editor
	protected $default_wp_submenu = null; //Holds the default WP menu for later use
	
	private $blank_menu = null;
	private $blank_item = null;	

	function __construct($plugin_file=''){
		if ( empty($plugin_file) ) $plugin_file = __FILE__;
		
		//Determine if the plugin is installed in the mu-plugins directory
		$this->is_mu_plugin = $this->is_in_wpmu_plugin_dir($plugin_file);
		//If so, we'll store the custom menu in a site-wide option
		if ( $this->is_mu_plugin ){
			$this->sitewide_options = true;
		}		
		
		//Set some plugin-specific options
		$this->option_name = 'ws_menu_editor';
		$this->defaults = array();
		$this->serialize_with_json = true; //Store the options in JSON format

		$this->settings_link = 'options-general.php?page=menu_editor';
		
		$this->magic_hooks = true;
		$this->magic_hook_priority = 99999;
		
		//Call the default constructor
		parent::__construct($plugin_file);

        //Build some template arrays
        $this->blank_menu = array(
			'page_title' => null,
			'menu_title' => null,
			'access_level' => null,
			'file' => null,
			'css_class' => null,
			'hookname' => null,
			'icon_url' => null,
			'position' => null,
			'defaults' => null,
			'separator' => null,
			'custom' => null,
		 );

		$this->blank_item = array(
			'menu_title' => null,
			'access_level' => null,
			'file' => null,
			'page_title' => null,
			'position' => null,
			'custom' => null,
		 );

	}

  /**
   * WPMenuEditor::enqueue_scripts()
   * Add the JS required by the editor to the page header
   *
   * @return void
   */
	function enqueue_scripts(){
		wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-sortable');

		//jQuery JSON plugin
		wp_enqueue_script('jquery-json', $this->plugin_dir_url.'/jquery.json-1.3.js', array('jquery'), '1.3');
		//Editor's scipts
        wp_enqueue_script('menu-editor', $this->plugin_dir_url.'/menu-editor.js', array('jquery'));
	}
	
  /**
   * WPMenuEditor::print_editor_css()
   * Add the editor's CSS file to the page header
   *
   * @return void
   */
	function print_editor_css(){
		echo '<link type="text/css" rel="stylesheet" href="', $this->plugin_dir_url, '/menu-editor.css" />',"\n";
	}

  /**
   * WPMenuEditor::hook_admin_menu()
   * Create a configuration page and load the custom menu
   *
   * @return void
   */
	function hook_admin_menu(){
		global $menu, $submenu;
		
		//The menu editor is only visible to users with the manage_options privilege.
		//Or, if the plugin is installed in mu-plugins, only to the site administrator(s). 
		if ( !$this->is_mu_plugin || ( function_exists('is_site_admin') && is_site_admin() ) ){
			$page = add_options_page('Menu Editor', 'Menu Editor', 'manage_options', 'menu_editor', array(&$this, 'page_menu_editor'));
			//Output our JS & CSS on that page only
			add_action("admin_print_scripts-$page", array(&$this, 'enqueue_scripts'));
			add_action("admin_print_scripts-$page", array(&$this, 'print_editor_css'));
		}
		
		$this->default_wp_menu = $menu;
		$this->default_wp_submenu = $submenu;
		
		//Is there a custom menu to use?
		if ( !empty($this->options['custom_menu']) ){
			//Merge in data from the default menu
			$tree = $this->menu_merge($this->options['custom_menu'], $menu, $submenu);
			//Apply the custom menu
			list($menu, $submenu) = $this->tree2wp($tree);
			//Save for later - the editor page will need it
			$this->custom_menu = $tree;
			//Re-filter the menu (silly WP should do that itself, oh well)
			$this->filter_menu();
		}
	}

  /**
   * WPMenuEditor::filter_menu()
   * Loop over the Dashboard submenus and remove pages for which the current user does not have privs.
   *
   * @return void
   */
	function filter_menu(){
		global $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv;
		
		foreach ( array( 'submenu' ) as $sub_loop ) {
			foreach ($$sub_loop as $parent => $sub) {
				foreach ($sub as $index => $data) {
					if ( ! current_user_can($data[1]) ) {
						unset(${$sub_loop}[$parent][$index]);
						$_wp_submenu_nopriv[$parent][$data[2]] = true;
					}
				}

				if ( empty(${$sub_loop}[$parent]) )
					unset(${$sub_loop}[$parent]);
			}
		}
	}

  /**
   * WPMenuEditor::page_menu_editor()
   * Output the menu editor page
   *
   * @return void
   */
	function page_menu_editor(){
		global $menu, $submenu;
		if ( !current_user_can('manage_options') ){
			die("Access denied");
		}
		
	//Handle form submissions
	if (isset($_POST['data'])){
		check_admin_referer('menu-editor-form');
		
		//Try to decode a menu tree encoded as JSON
		$data = $this->json_decode($_POST['data'], true);
		if (!$data){
			$fixed = stripslashes($_POST['data']);
			$data = $this->json_decode( $fixed, true );
		}
	
		$url = remove_query_arg('noheader');

		if ($data){
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
	
?>
<div class="wrap">
<h2>Menu Editor</h2>
<?php
	
	if ( !empty($_GET['message']) ){
		if ( intval($_GET['message']) == 1 ){
			echo '<div id="message" class="updated fade"><p><strong>Settings saved.</strong></p></div>';
		} elseif ( intval($_GET['message']) == 2 ) {
			echo '<div id="message" class="error"><p><strong>Failed to decode input! The menu wasn\'t modified.</strong></p></div>';
		}
	}
	
	//Build a tree struct. for the default menu
	$default_menu = $this->wp2tree($this->default_wp_menu, $this->default_wp_submenu);
	
	//Is there a custom menu?
	if (!empty($this->options['custom_menu'])){
		$custom_menu = $this->options['custom_menu'];
		//Merge in the current defaults
		$custom_menu = $this->menu_merge($custom_menu, $this->default_wp_menu, $this->default_wp_submenu);
	} else {
		//Start out with the default menu if there is no user-created one
		$custom_menu = $default_menu;
	}
	
	//Encode both menus as JSON
	$default_menu_js = $this->getMenuAsJS($default_menu);
	$custom_menu_js = $this->getMenuAsJS($custom_menu);

	$plugin_url = $this->plugin_dir_url;
	$images_url = $this->plugin_dir_url . '/images';
?>
<div id='ws_menu_editor'>
	<div id='ws_menu_box' class='ws_main_container'>
		<div class='ws_toolbar'>
			<a id='ws_cut_menu' class='ws_button' href='javascript:void(0)' title='Cut'><img src='<?php echo $images_url; ?>/cut.png' /></a>
			<a id='ws_copy_menu' class='ws_button' href='javascript:void(0)' title='Copy'><img src='<?php echo $images_url; ?>/page_white_copy.png' /></a>
			<a id='ws_paste_menu' class='ws_button' href='javascript:void(0)' title='Paste'><img src='<?php echo $images_url; ?>/page_white_paste.png' /></a>
			<a id='ws_new_menu' class='ws_button' href='javascript:void(0)' title='New'><img src='<?php echo $images_url; ?>/page_white_add.png' /></a>
			<a id='ws_hide_menu' class='ws_button' href='javascript:void(0)' title='Show/Hide'><img src='<?php echo $images_url; ?>/plugin_disabled.png' /></a>
			<a id='ws_delete_menu' class='ws_button' href='javascript:void(0)' title='Delete'><img src='<?php echo $images_url; ?>/page_white_delete.png' /></a>
		</div>
	</div>
	<div id='ws_submenu_box' class='ws_main_container'>
		<div class='ws_toolbar'>
			<a id='ws_cut_item' class='ws_button' href='javascript:void(0)' title='Cut'><img src='<?php echo $images_url; ?>/cut.png' /></a>
			<a id='ws_copy_item' class='ws_button' href='javascript:void(0)' title='Copy'><img src='<?php echo $images_url; ?>/page_white_copy.png' /></a>
			<a id='ws_paste_item' class='ws_button' href='javascript:void(0)' title='Paste'><img src='<?php echo $images_url; ?>/page_white_paste.png' /></a>
			<a id='ws_new_item' class='ws_button' href='javascript:void(0)' title='New'><img src='<?php echo $images_url; ?>/page_white_add.png' /></a>
			<a id='ws_hide_item' class='ws_button' href='javascript:void(0)' title='Show/Hide'><img src='<?php echo $images_url; ?>/plugin_disabled.png' /></a>
			<a id='ws_delete_item' class='ws_button' href='javascript:void(0)' title='Delete'><img src='<?php echo $images_url; ?>/page_white_delete.png' /></a>
		</div>
	</div>
</div>

<form method="post" action="<?php echo admin_url('options-general.php?page=menu_editor&noheader=1'); ?>" id='ws_main_form' name='ws_main_form'>
<div class="ws_main_container" style="width: 138px;">
	<?php wp_nonce_field('menu-editor-form'); ?>
	<input type="button" id='ws_save_menu' class="button-primary ws_main_button" value="Save Changes"
		style="margin-bottom: 20px;" />
	<input type="button" id='ws_load_menu' value="Load default menu" class="button ws_main_button" />
	<input type="button" id='ws_reset_menu' value="Reset menu" class="button ws_main_button" />
	<input type="hidden" name="data" id="ws_data" value="">
</div>
</form>

</div>
<script type='text/javascript'>

var defaultMenu = <?php echo $default_menu_js; ?>;
var customMenu = <?php echo $custom_menu_js; ?>;

</script>
		<?php
	}

  /**
   * WPMenuEditor::getMenuAsJS()
   * Encode a menu tree as JSON
   *
   * @param array $tree
   * @return string
   */
	function getMenuAsJS($tree){
		return $this->json_encode($tree);
	}

  /**
   * WPMenuEditor::menu2assoc()
   * Convert a WP menu structure to an associative array
   *
   * @param array $item An element of the $menu array
   * @param integer $pos The position (index) of the menu item
   * @return array
   */
	function menu2assoc($item, $pos=0){
		$item = array(
			'page_title' => $item[3],
			'menu_title' => $item[0],
			'access_level' => $item[1],
			'file' => $item[2],
			'css_class' => $item[4],
			'hookname' => (isset($item[5])?$item[5]:''), //ID
			'icon_url' => (isset($item[6])?$item[6]:''),
			'position' => $pos,
		 );
		return $item;
	}

  /**
   * WPMenuEditor::submenu2assoc()
   * Converts a WP submenu structure to an associative array
   *
   * @param array $item An element of the $submenu array
   * @param integer $pos The position (index) of that element
   * @return
   */
	function submenu2assoc($item, $pos=0){
		$item = array(
			'menu_title' => $item[0],
			'access_level' => $item[1],
			'file' => $item[2],
			'page_title' => (isset($item[3])?$item[3]:''),
			'position' => $pos,
		 );
		return $item;
	}

  /**
   * WPMenuEditor::build_lookups()
   * Populate lookup arrays with default values from $menu and $submenu. Used later to merge
   * a custom menu with the native WordPress menu structure somewhat gracefully.
   *
   * @param array $menu
   * @param array $submenu
   * @return array An array with two elements containing menu and submenu defaults.
   */
	function build_lookups($menu, $submenu){
		//Process the top menu
		$menu_defaults = array();
		foreach($menu as $pos => $item){
			$item = $this->menu2assoc($item, $pos);
			if ($item['file'] != '') { //skip separators (empty menus)
				$menu_defaults[$item['file']] = $item; //index by filename
			}
		}

		//Process the submenu
		$submenu_defaults = array();
		foreach($submenu as $parent => $items){
			foreach($items as $pos => $item){
				$item = $this->submenu2assoc($item, $pos);
				//save the default parent menu
				$item['parent'] = $parent;
				$submenu_defaults[$item['file']] = $item; //index by filename
			}
		}

		return array($menu_defaults, $submenu_defaults);
	}

  /**
   * WPMenuEditor::menu_merge()
   * Merge $menu and $submenu into the $tree. Adds/replaces defaults, inserts new items
   * and marks missing items as such.
   *
   * @param array $tree A menu in plugin's internal form
   * @param array $menu WordPress menu structure
   * @param array $submenu WordPress submenu structure
   * @return array Updated menu tree
   */
	function menu_merge($tree, $menu, $submenu){
		list($menu_defaults, $submenu_defaults) = $this->build_lookups($menu, $submenu);

		//Iterate over all menus and submenus and look up default values
		foreach ($tree as $topfile => &$topmenu){

			//Is this menu present in the default WP menu?
			if (isset($menu_defaults[$topfile])){
				//Yes, load defaults from that item
				$topmenu['defaults'] = $menu_defaults[$topfile];
				//Note that the original item was used
				$menu_defaults[$topfile]['used'] = true;
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
					//Is this item present in the default WP menu?
					if (isset($submenu_defaults[$file])){
						//Yes, load defaults from that item
						$item['defaults'] = $submenu_defaults[$file];
						$submenu_defaults[$file]['used'] = true;
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

		//Find and merge unused toplevel menus
		foreach ($menu_defaults as $topfile => $topmenu){
			if ( !empty($topmenu['used']) ) continue;

			//Found an unused item. Build the tree entry.
			$entry = $this->blank_menu;
			$entry['defaults'] = $topmenu;
			$entry['items'] = array(); //prepare a place for menu items, if any.
			//Note that this item is unused
			$entry['unused'] = true;
			//Add the new entry to the menu tree
			$tree[$topfile] = $entry;
		}
		unset($topmenu);

		//Find and merge submenu items
		foreach($submenu_defaults as $file => $item){
			if ( !empty($item['used']) ) continue;
			//Found an unused item. Build an entry and attach it under the default toplevel menu.
			$entry = $this->blank_item;
			$entry['defaults'] = $item;
			//Note that this item is unused
			$entry['unused'] = true;

			//Check if the toplevel menu exists
			if (isset($tree[$item['parent']])) {
				//Okay, insert the item.
				$tree[$item['parent']]['items'][$item['file']] = $entry;
			} else {
				//Ooops? This should never happen. Some kind of inconsistency?
			}
		}

		//Resort the tree to ensure the found items are in the right spots
		$tree = $this->sort_menu_tree($tree);

		return $tree;
	}

  /**
   * WPMenuEditor::wp2tree()
   * Convert the WP menu structure to the internal representation. All properties set as defaults.
   *
   * @param array $menu
   * @param array $submenu
   * @return array
   */
	function wp2tree($menu, $submenu){
		$tree = array();
		$separator_count = 0;
		foreach ($menu as $pos => $item){
			
			$tree_item = $this->blank_menu;
			$tree_item['defaults'] = $this->menu2assoc($item, $pos);
			$tree_item['separator'] = empty($item[2]) || empty($item[0]);
			
			$item_file = $tree_item['defaults']['file'];
			if ( empty($item_file) ){
				$item_file = 'separator_'.$separator_count.'_';
				$separator_count++;
			}
			
			$tree[$item_file] = $tree_item;
		}

		//Attach all submenu items
		foreach($submenu as $parent=>$items){
			//Skip items that belong to a non-existent parent menu. 
			//Rationale : All In One SEO Pack 1.6.10 (and possibly others) doth add such invalid submenus.
			if ( !isset($tree[$parent]) ) continue;
			
			foreach($items as $pos=>$item){
				//Add this item under the parent
				$tree[$parent]['items'][$item[2]] = array_merge(
					$this->blank_item,
					array('defaults' => $this->submenu2assoc($item, $pos))
				);
			}
		}
		
		$tree = $this->sort_menu_tree($tree);

		return $tree;
	}

  /**
   * WPMenuEditor::apply_defaults()
   * Sets all undefined fields to the default value
   *
   * @param array $item Menu item in the plugin's internal form
   * @return array
   */
	function apply_defaults($item){
		foreach($item as $key => $value){
			//Is the field set?
			if ($value === null){
				//Use default, if available
				if (isset($item['defaults']) && isset($item['defaults'][$key])){
					$item[$key] = $item['defaults'][$key];
				}
			}
		}
		return $item;
	}

  /**
   * WPMenuEditor::compare_position()
   * Custom comparison function that compares menu items based on their position in the menu.
   *
   * @param array $a
   * @param array $b
   * @return int
   */
	function compare_position($a, $b){
		if ($a['position']!==null) {
			$p1 = $a['position'];
		} else {
			if ( isset($a['defaults']['position']) ){
				$p1 = $a['defaults']['position'];
			} else {
				$p1 = 0;
			}
		}

		if ($b['position']!==null) {
			$p2 = $b['position'];
		} else {
			if ( isset($b['defaults']['position']) ){
				$p2 = $b['defaults']['position'];
			} else {
				$p2 = 0;
			}
		}

		return $p1 - $p2;
	}
	
  /**
   * WPMenuEditor::sort_menu_tree()
   * Sort the menus and menu items of a given menu according to their positions 
   *
   * @param array $tree A menu structure in the internal format
   * @return array Sorted menu in the internal format
   */
	function sort_menu_tree($tree){
		//Resort the tree to ensure the found items are in the right spots
		uasort($tree, array(&$this, 'compare_position'));
		//Resort all submenus as well
		foreach ($tree as $topfile => &$topmenu){
			if (!empty($topmenu['items'])){
				uasort($topmenu['items'], array(&$this, 'compare_position'));
			}
		}
		
		return $tree;
	}

  /**
   * WPMenuEditor::tree2wp()
   * Convert internal menu representation to the form used by WP.
   *
   * @param array $tree
   * @return array $menu and $submenu
   */
	function tree2wp($tree){
		$menu = array();
		$submenu = array();
		
		//Sort the menu by position
		uasort($tree, array(&$this, 'compare_position'));

		//Prepare the top menu
		foreach ($tree as &$topmenu){
			
			//Skip missing menus, unless they're user-created and thus might point to a non-standard file 
			if ( !empty($topmenu['missing']) && empty($topmenu['custom']) ) continue;
			//Skip hidden entries
			if (!empty($topmenu['hidden'])) continue;
			
			//Build the WP item structure, using defaults where necessary
			$topmenu = $this->apply_defaults($topmenu);
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
					if ( !empty($item['missing']) && empty($item['custom']) ) continue;
					//Skip hidden items
					if (!empty($item['hidden'])) {
						continue;
					}
	
					$item = $this->apply_defaults($item);
					$submenu[$topmenu['file']][] = array(
						$item['menu_title'],
						$item['access_level'],
						$item['file'],
						$item['page_title'],
					 );
				}
			}
		}
		
		return array($menu, $submenu);
	}

  /**
   * WPMenuEditor::is_wp27()
   * Check if running WordPress 2.7 or later (unused)
   *
   * @return bool
   */
	function is_wp27(){
		global $wp_version;
		return function_exists('register_uninstall_hook');
	}
} //class

?>