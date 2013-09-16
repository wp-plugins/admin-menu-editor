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
require $thisDirectory . '/role-utils.php';
require $thisDirectory . '/menu-item.php';
require $thisDirectory . '/menu.php';
require $thisDirectory . '/auto-versioning.php';

class WPMenuEditor extends MenuEd_ShadowPluginFramework {
	const WPML_CONTEXT = 'admin-menu-editor menu texts';

	private $plugin_db_version = 140;

	/** @var array The default WordPress menu, before display-specific filtering. */
	protected $default_wp_menu;
	/** @var array The default WordPress submenu. */
	protected $default_wp_submenu;

	/**
	 * We also keep track of the final, ready-for-display version of the default WP menu
	 * and submenu. These values are captured *just* before the admin menu HTML is output
	 * by _wp_menu_output() in /wp-admin/menu-header.php, and are restored afterwards.
	 */
	private $old_wp_menu;
	private $old_wp_submenu;

	private $title_lookups = array();   //A list of page titles indexed by $item['file']. Used to
	                                    //fix the titles of moved plugin pages.
	private $reverse_item_lookup = array(); //Contains the final (merged & filtered) list of admin menu items,
                                            //indexed by URL.

	/**
	 * @var array List of per-URL capabilities, indexed by priority. Used while merging and
	 * building the final admin menu.
	 */
	private $page_access_lookup = array();

	/**
	 * @var array A log of menu access checks.
	 */
	private $security_log = array();

	/**
	 * @var array The current custom menu with defaults merged in.
	 */
	private $merged_custom_menu = null;

	/**
	 * @var array The custom menu in WP-compatible format (top-level).
	 */
	private $custom_wp_menu = null;

	/**
	 * @var array The custom menu in WP-compatible format (sub-menu).
	 */
	private $custom_wp_submenu = null;

	private $item_templates = array();  //A lookup list of default menu items, used as templates for the custom menu.

	private $cached_custom_menu = null; //Cached, non-merged version of the custom menu. Used by load_custom_menu().
	private $cached_virtual_caps = null;//List of virtual caps. Used by get_virtual_caps().

	//Our personal copy of the request vars, without any "magic quotes".
	private $post = array();
	private $get = array();

	function init(){
		$this->sitewide_options = true;

		//Set some plugin-specific options
		if ( empty($this->option_name) ){
			$this->option_name = 'ws_menu_editor';
		}
		$this->defaults = array(
			'hide_advanced_settings' => true,
			'custom_menu' => null,
			'first_install_time' => null,
			'display_survey_notice' => true,
			'plugin_db_version' => 0,
			'security_logging_enabled' => false,

			'menu_config_scope' => ($this->is_super_plugin() || !is_multisite()) ? 'global' : 'site',

			//super_admin, specific_user, or a capability.
			'plugin_access' => $this->is_super_plugin() ? 'super_admin' : 'manage_options',
			//The ID of the user who is allowed to use this plugin. Only used when plugin_access == specific_user.
			'allowed_user_id' => null,
			//The user who can see this plugin on the "Plugins" page. By default all admins can see it.
			'plugins_page_allowed_user_id' => null,
		);
		$this->serialize_with_json = false; //(Don't) store the options in JSON format

		$this->settings_link = 'options-general.php?page=menu_editor';
		
		$this->magic_hooks = true;
		$this->magic_hook_priority = 99999;
		
		//AJAXify screen options
		add_action('wp_ajax_ws_ame_save_screen_options', array(&$this,'ajax_save_screen_options'));

		//AJAXify hints
		add_action('wp_ajax_ws_ame_hide_hint', array($this, 'ajax_hide_hint'));

		//Make sure we have access to the original, un-mangled request data.
		//This is necessary because WordPress will stupidly apply "magic quotes"
		//to the request vars even if this PHP misfeature is disabled.
		$this->capture_request_vars();

		add_action('admin_enqueue_scripts', array($this, 'enqueue_menu_fix_script'));

		//Enqueue miscellaneous helper scripts and styles.
		add_action('admin_enqueue_scripts', array($this, 'enqueue_helper_scripts'));
		add_action('admin_print_styles', array($this, 'enqueue_helper_styles'));

		//User survey
		add_action('admin_notices', array($this, 'display_survey_notice'));
	}
	
	function init_finish() {
		parent::init_finish();
		$should_save_options = false;

		//If we have no stored settings for this version of the plugin, try importing them
		//from other versions (i.e. the free or the Pro version).
		if ( !$this->load_options() ){
			$this->import_settings();
			$should_save_options = true;
		}

		//Track first install time.
        if ( !isset($this->options['first_install_time']) ) {
			$this->options['first_install_time'] = time();
			$should_save_options = true;
        }

		if ( $this->options['plugin_db_version'] < $this->plugin_db_version ) {
			/* Put any activation code here. */

			$this->options['plugin_db_version'] = $this->plugin_db_version;
			$should_save_options = true;
		}

		if ( $should_save_options ) {
			$this->save_options();
		}

		//This is here and not in init() because it relies on $options being initialized.
		if ( $this->options['security_logging_enabled'] ) {
			add_action('admin_notices', array($this, 'display_security_log'));
		}
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
			$this->set_custom_menu(null);
		}
		
		//The menu editor is only visible to users with the manage_options privilege.
		//Or, if the plugin is installed in mu-plugins, only to the site administrator(s). 
		if ( $this->current_user_can_edit_menu() ){
			$this->log_security_note('Current user can edit the admin menu.');

			$page = add_options_page(
				apply_filters('admin_menu_editor-self_page_title', 'Menu Editor'), 
				apply_filters('admin_menu_editor-self_menu_title', 'Menu Editor'), 
				apply_filters('admin_menu_editor_capability', 'manage_options'),
				'menu_editor', 
				array(&$this, 'page_menu_editor')
			);
			//Output our JS & CSS on that page only
			add_action("admin_print_scripts-$page", array(&$this, 'enqueue_scripts'));
			add_action("admin_print_styles-$page", array(&$this, 'enqueue_styles'));

			//Compatibility fix for All In One Event Calendar; see the callback for details.
			add_action("admin_print_scripts-$page", array($this, 'dequeue_ai1ec_scripts'));

			//Compatibility fix for Participants Database.
			add_action("admin_print_scripts-$page", array($this, 'dequeue_pd_scripts'));

			//Experimental compatibility fix for Ultimate TinyMCE
			add_action("admin_print_scripts-$page", array($this, 'remove_ultimate_tinymce_qtags'));

			//Make a placeholder for our screen options (hacky)
			add_meta_box("ws-ame-screen-options", "[AME placeholder]", '__return_false', $page);
		}
		
		//Store the "original" menus for later use in the editor
		$this->default_wp_menu = $menu;
		$this->default_wp_submenu = $submenu;
		
		//Generate item templates from the default menu.
		$this->item_templates = $this->build_templates($this->default_wp_menu, $this->default_wp_submenu);

		//Is there a custom menu to use?
		$custom_menu = $this->load_custom_menu();
		if ( $custom_menu !== null ){
			//Merge in data from the default menu
			$custom_menu['tree'] = $this->menu_merge($custom_menu['tree']);

			//Save the merged menu for later - the editor page will need it
			$this->merged_custom_menu = $custom_menu;

			//Convert our custom menu to the $menu + $submenu structure used by WP.
			//Note: This method sets up multiple internal fields and may cause side-effects.
			$this->build_custom_wp_menu($this->merged_custom_menu['tree']);

			if ( !$this->user_can_access_current_page() ) {
				$this->log_security_note('DENY access.');
				$message = 'You do not have sufficient permissions to access this admin page.';
				if ( $this->options['security_logging_enabled'] ) {
					$message .= '<p><strong>Admin Menu Editor security log</strong></p>';
					$message .= $this->get_formatted_security_log();
				}
				wp_die($message);
			} else {
				$this->log_security_note('ALLOW access.');
			}

			//Replace the admin menu just before it is displayed and restore it afterwards.
			//The fact that replace_wp_menu() is attached to the 'parent_file' hook is incidental;
			//there just wasn't any other, more suitable hook available.
			add_filter('parent_file', array($this, 'replace_wp_menu'));
			add_action('adminmenu', array($this, 'restore_wp_menu'));

			//A compatibility hack for Ozh's Admin Drop Down Menu. Make sure it also sees the modified menu.
			$ozh_adminmenu_priority = has_action('in_admin_header', 'wp_ozh_adminmenu');
			if ( $ozh_adminmenu_priority !== false ) {
				add_action('in_admin_header', array($this, 'replace_wp_menu'), $ozh_adminmenu_priority - 1);
				add_action('in_admin_header', array($this, 'restore_wp_menu'), $ozh_adminmenu_priority + 1);
			}
		}
	}

	/**
	 * Replace the current WP menu with our custom one.
	 *
	 * @param string $parent_file Ignored. Required because this method is a hook for the 'parent_file' filter.
	 * @return string Returns the $parent_file argument.
	 */
	public function replace_wp_menu($parent_file = '') {
		global $menu, $submenu;

		$this->old_wp_menu = $menu;
		$this->old_wp_submenu = $submenu;

		$menu = $this->custom_wp_menu;
		$submenu = $this->custom_wp_submenu;
		list($menu, $submenu) = $this->filter_menu($menu, $submenu);

		return $parent_file;
	}

	/**
	 * Restore the default WordPress menu that was replaced using replace_wp_menu().
	 *
	 * @return void
	 */
	public function restore_wp_menu() {
		global $menu, $submenu;
		$menu = $this->old_wp_menu;
		$submenu = $this->old_wp_submenu;
	}

	/**
	 * Filter a menu so that it can be handed to _wp_menu_output(). This method basically
	 * emulates the filtering that WordPress does in /wp-admin/includes/menu.php, with a few
	 * additions of our own.
	 *
	 * - Removes inaccessible items and superfluous separators.
	 *
	 * - Sets accessible items to a capability that the user is guaranteed to have to prevent
	 *   _wp_menu_output() from choking on plugin-specific capabilities like "cap1,cap2+not:cap3".
	 *
	 * - Adds position-dependent CSS classes.
	 *
	 * @param array $menu
	 * @param array $submenu
	 * @return array An array with two items - the filtered menu and submenu.
	 */
	private function filter_menu($menu, $submenu) {
		global $_wp_menu_nopriv; //Caution: Modifying this array could lead to unexpected consequences.

		//Remove sub-menus which the user shouldn't be able to access,
		//and ensure the rest are visible.
		foreach ($submenu as $parent => $items) {
			foreach ($items as $index => $data) {
				if ( ! $this->current_user_can($data[1]) ) {
					unset($submenu[$parent][$index]);
					$_wp_submenu_nopriv[$parent][$data[2]] = true;
				} else {
					//The menu might be set to some kind of special capability that is only valid
					//within this plugin and not WP in general. Ensure WP doesn't choke on it.
					//(This is safe - we'll double-check the caps when the user tries to access a page.)
					$submenu[$parent][$index][1] = 'exist'; //All users have the 'exist' cap.
				}
			}

			if ( empty($submenu[$parent]) ) {
				unset($submenu[$parent]);
			}
		}

		//Remove menus that have no accessible sub-menus and require privileges that the user does not have.
		//Ensure the rest are visible. Run re-parent loop again.
		foreach ( $menu as $id => $data ) {
			if ( ! $this->current_user_can($data[1]) ) {
				$_wp_menu_nopriv[$data[2]] = true;
			} else {
				$menu[$id][1] = 'exist';
			}

			//If there is only one submenu and it is has same destination as the parent,
			//remove the submenu.
			if ( ! empty( $submenu[$data[2]] ) && 1 == count ( $submenu[$data[2]] ) ) {
				$subs = $submenu[$data[2]];
				$first_sub = array_shift($subs);
				if ( $data[2] == $first_sub[2] ) {
					unset( $submenu[$data[2]] );
				}
			}

			//If submenu is empty...
			if ( empty($submenu[$data[2]]) ) {
				// And user doesn't have privs, remove menu.
				if ( isset( $_wp_menu_nopriv[$data[2]] ) ) {
					unset($menu[$id]);
				}
			}
		}
		unset($id, $data, $subs, $first_sub);

		//Remove any duplicated separators
		$separator_found = false;
		foreach ( $menu as $id => $data ) {
			if ( 0 == strcmp('wp-menu-separator', $data[4] ) ) {
                if ($separator_found) {
                    unset($menu[$id]);
                }
                $separator_found = true;
            } else {
				$separator_found = false;
			}
		}
		unset($id, $data);

		//Remove the last menu item if it is a separator.
		$last_menu_key = array_keys( $menu );
		$last_menu_key = array_pop( $last_menu_key );
		if (!empty($menu) && 'wp-menu-separator' == $menu[$last_menu_key][4]) {
			unset($menu[$last_menu_key]);
		}
		unset( $last_menu_key );

		//Add display-specific classes like "menu-top-first" and others.
		$menu = add_menu_classes($menu);

		return array($menu, $submenu);
	}


	/**
	  * Add the JS required by the editor to the page header
	  *
	  * @return void
	  */
	function enqueue_scripts(){
		//jQuery JSON plugin
		wp_register_auto_versioned_script('jquery-json', plugins_url('js/jquery.json.js', $this->plugin_file), array('jquery'));
		//jQuery sort plugin
		wp_register_auto_versioned_script('jquery-sort', plugins_url('js/jquery.sort.js', $this->plugin_file), array('jquery'));
		//qTip2 - jQuery tooltip plugin
		wp_register_auto_versioned_script('jquery-qtip', plugins_url('js/jquery.qtip.min.js', $this->plugin_file), array('jquery'));
		//jQuery Form plugin. This is a more recent version than the one included with WP.
		wp_register_auto_versioned_script('ame-jquery-form', plugins_url('js/jquery.form.js', $this->plugin_file), array('jquery'));

		//Editor's scripts
		wp_register_auto_versioned_script(
			'menu-editor',
			plugins_url('js/menu-editor.js', $this->plugin_file),
			array(
				'jquery', 'jquery-ui-sortable', 'jquery-ui-dialog',
				'ame-jquery-form', 'jquery-ui-droppable', 'jquery-qtip',
				'jquery-sort', 'jquery-json'
			)
		);

		//Add scripts to our editor page, but not the settings sub-section
		//that shares the same page slug. Some of the scripts would crash otherwise.
		if ( !$this->is_editor_page() ) {
			return;
		}

		wp_enqueue_script('menu-editor');

		//We use WordPress media uploader to let the user upload custom menu icons (WP 3.5+).
		if ( function_exists('wp_enqueue_media') ) {
			wp_enqueue_media();
		}

		//Remove the default jQuery Form plugin to prevent conflicts with our custom version.
		wp_dequeue_script('jquery-form');

		//Actors (roles and users) are used in the permissions UI, so we need to pass them along.
		$actors = array();
		$roles = array();

		$wp_roles = ameRoleUtils::get_roles();
		foreach($wp_roles->roles as $role_id => $role) {
			$actors['role:' . $role_id] = $role['name'];
			$role['capabilities'] = $this->castValuesToBool($role['capabilities']);
			$roles[$role_id] = $role;
		}

		if ( is_multisite() && is_super_admin() ) {
			$actors['special:super_admin'] = 'Super Admin';
		}

		//Known users. Right now, this is limited to the current user only.
		$users = array();

		$current_user = wp_get_current_user();
		$users[$current_user->get('user_login')] = array(
			'user_login' => $current_user->get('user_login'),
			'id' => $current_user->ID,
			'roles' => array_values($current_user->roles),
			'capabilities' => $this->castValuesToBool($current_user->caps),
			'is_super_admin' => is_multisite() && is_super_admin(),
		);

        $actors['user:' . $current_user->get('user_login')] = sprintf(
            'Current user (%s)',
            $current_user->get('user_login')
        );
		//Note: Users do NOT get added to the actor list because that feature
		//is not fully implemented.

		//The editor will need access to some of the plugin data and WP data.
		wp_localize_script(
			'menu-editor',
			'wsEditorData',
			array(
				'imagesUrl' => plugins_url('images', $this->plugin_file),
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
				'customItemTemplate' => array(
					'name' => '< Custom >',
					'defaults' => ameMenuItem::custom_item_defaults(),
				),

				'actors' => $actors,
				'roles' => $roles,
				'users' => $users,
                'currentUserLogin' => $current_user->get('user_login'),
                'selectedActor' => isset($this->get['selected_actor']) ? strval($this->get['selected_actor']) : null,

				'showHints' => $this->get_hint_visibility(),

			    'isDemoMode' => defined('IS_DEMO_MODE'),
			    'isMasterMode' => defined('IS_MASTER_MODE'),
			)
		);
	}

	/**
	 * Compatibility workaround for All In One Event Calendar 1.8.3-premium.
	 *
	 * The event calendar plugin is known to crash Admin Menu Editor Pro 1.40. The exact cause
	 * of the crash is unknown, but we can prevent it by removing AIOEC scripts from the menu
	 * editor page.
	 *
	 * This should not affect the functionality of the event calendar plugin. The scripts
	 * in question don't seem to do anything on pages not related to the event calendar. AIOEC
	 * just loads them indiscriminately on all pages.
	 */
	public function dequeue_ai1ec_scripts() {
		wp_dequeue_script('ai1ec_requirejs');
		wp_dequeue_script('ai1ec_common_backend');
		wp_dequeue_script('ai1ec_add_new_event_require');
	}

	/**
	 * Compatibility workaround for Participants Database 1.4.5.2.
	 *
	 * Participants Database loads its settings JavaScript on every page in the "Settings" menu,
	 * not just its own. It doesn't bother to also load the script's dependencies, though, so
	 * the script crashes *and* it breaks the menu editor by way of collateral damage.
	 *
	 * Fix by forcibly removing the offending script from the queue.
	 */
	public function dequeue_pd_scripts() {
		if ( is_plugin_active('participants-database/participants-database.php') ) {
			wp_dequeue_script('settings_script');
		}
	}

	public function remove_ultimate_tinymce_qtags() {
		remove_action('admin_print_footer_scripts', 'jwl_ult_quicktags');
	}

	 /**
	  * Add the editor's CSS file to the page header
	  *
	  * @return void
	  */
	function enqueue_styles(){
		wp_enqueue_auto_versioned_style('jquery-qtip-syle', plugins_url('css/jquery.qtip.min.css', $this->plugin_file), array());

		wp_register_auto_versioned_style('menu-editor-base-style', plugins_url('css/menu-editor.css', $this->plugin_file));
		wp_register_auto_versioned_style(
			'menu-editor-colours-classic',
			plugins_url('css/style-classic.css', $this->plugin_file),
			array('menu-editor-base-style')
		);
		wp_register_auto_versioned_style(
			'menu-editor-colours-wp-gray',
			plugins_url('css/style-wp-gray.css', $this->plugin_file),
			array('menu-editor-base-style')
		);

		wp_enqueue_style('menu-editor-colours-classic');
	}

	/**
	 * Set and save a new custom menu for the current site.
	 *
	 * @param array|null $custom_menu
	 */
	function set_custom_menu($custom_menu) {
		$previous_custom_menu = $this->load_custom_menu();
		$this->update_wpml_strings($previous_custom_menu, $custom_menu);

		if ( $this->should_use_site_specific_menu() ) {
			$site_specific_options = get_option($this->option_name);
			if ( !is_array($site_specific_options) ) {
				$site_specific_options = array();
			}
			$site_specific_options['custom_menu'] = $custom_menu;
			update_option($this->option_name, $site_specific_options);
		} else {
			$this->options['custom_menu'] = $custom_menu;
			$this->save_options();
		}

		$this->cached_custom_menu = null;
		$this->cached_virtual_caps = null;
	}

	/**
	 * Load the current custom menu for this site, if any.
	 *
	 * @return array|null Either a menu in the internal format, or NULL if there is no custom menu available.
	 */
	function load_custom_menu() {
		if ( $this->cached_custom_menu !== null ) {
			return $this->cached_custom_menu;
		}

		if ( $this->should_use_site_specific_menu() ) {
			$site_specific_options = get_option($this->option_name, null);
			if ( is_array($site_specific_options) && isset($site_specific_options['custom_menu']) ) {
				$this->cached_custom_menu = ameMenu::load_array($site_specific_options['custom_menu']);
			}
		} else {
			if ( empty($this->options['custom_menu']) ) {
				return null;
			}
			$this->cached_custom_menu = ameMenu::load_array($this->options['custom_menu']);
		}

		return $this->cached_custom_menu;
	}

	/**
	 * Determine if we should use a site-specific admin menu configuration
	 * for the current site, or fall back to the global config.
	 *
	 * @return bool True = use the site-specific config (if any), false = use the global config.
	 */
	protected function should_use_site_specific_menu() {
		if ( !is_multisite() ) {
			//If this is a single-site WP installation then there's really
			//no difference between "site-specific" and "global".
			return false;
		}
		return ($this->options['menu_config_scope'] === 'site');
	}

	/**
	 * Determine if the current user may use the menu editor.
	 * 
	 * @return bool
	 */
	public function current_user_can_edit_menu(){
		$access = $this->options['plugin_access'];

		if ( $access === 'super_admin' ) {
			return is_super_admin();
		} else if ( $access === 'specific_user' ) {
			return get_current_user_id() == $this->options['allowed_user_id'];
		} else {
			$capability = apply_filters('admin_menu_editor-capability', $access);
			return current_user_can($capability);
		}
	}
	
	/**
	 * Apply the custom page title, if any.
	 *
	 * This is a callback for the "admin_title" filter. It will change the browser window/tab
	 * title (i.e. <title>), but not the title displayed on the admin page itself.
	 * 
	 * @param string $admin_title The current admin title (full).
	 * @param string $title The current page title. 
	 * @return string New admin title.
	 */
	function hook_admin_title($admin_title, $title){
		$item = $this->get_current_menu_item();
		if ( $item === null ) {
			return $admin_title;
		}

		//Check if the we have a custom title for this page.
		$default_title = isset($item['defaults']['page_title']) ? $item['defaults']['page_title'] : '';
		if ( !empty($item['page_title']) && $item['page_title'] != $default_title ) {
			if ( empty($title) ) {
				$admin_title = $item['page_title'] . $admin_title;
			} else {
				//Replace the first occurrence of the default title with the custom one.
				$title_pos = strpos($admin_title, $title);
				$admin_title = substr_replace($admin_title, $item['page_title'], $title_pos, strlen($title));
			}
		}

		return $admin_title;
	}
	
  /**
   * Populate a lookup array with default values (templates) from $menu and $submenu.
   * Used later to merge a custom menu with the native WordPress menu structure.
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
			//Skip sub-menus attached to non-existent parents. This should theoretically never happen,
			//but a buggy plugin can cause such a situation.
			if ( !isset($name_lookup[$parent]) ) {
				continue;
			}

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
   * Merge a custom menu with the current default WordPress menu. Adds/replaces defaults,
   * inserts new items and removes missing items.
   *
   * @uses self::$item_templates
   *
   * @param array $tree A menu in plugin's internal form
   * @return array Updated menu tree
   */
	function menu_merge($tree){
		//Iterate over all menus and submenus and look up default values
		foreach ($tree as &$topmenu){

			if ( !ameMenuItem::get($topmenu, 'custom') ) {
				$template_id = ameMenuItem::template_id($topmenu);
				//Is this menu present in the default WP menu?
				if (isset($this->item_templates[$template_id])){
					//Yes, load defaults from that item
					$topmenu['defaults'] = $this->item_templates[$template_id]['defaults'];
					//Note that the original item was used
					$this->item_templates[$template_id]['used'] = true;
				} else {
					//Record the menu as missing, unless it's a menu separator
					if ( empty($topmenu['separator']) ){
						$topmenu['missing'] = true;

						$temp = ameMenuItem::apply_defaults($topmenu);
						$temp = $this->set_final_menu_capability($temp);
						$this->add_access_lookup($temp, 'menu', true);
                    }
				}
			}

			if (is_array($topmenu['items'])) {
				//Iterate over submenu items
				foreach ($topmenu['items'] as &$item){
					if ( !ameMenuItem::get($item, 'custom') ) {
						$template_id = ameMenuItem::template_id($item);

						//Is this item present in the default WP menu?
						if (isset($this->item_templates[$template_id])){
							//Yes, load defaults from that item
							$item['defaults'] = $this->item_templates[$template_id]['defaults'];
							$this->item_templates[$template_id]['used'] = true;
						} else if ( empty($item['separator']) ) {
							//Record as missing, unless it's a menu separator
							$item['missing'] = true;

							$temp = ameMenuItem::apply_defaults($item);
							$temp = $this->set_final_menu_capability($temp);
							$this->add_access_lookup($temp, 'submenu', true);
                        }
					}
				}
			}
		}

		//If we don't unset these they will fuck up the next two loops where the same names are used.
		unset($topmenu);
		unset($item);

		//Now we have some items marked as missing, and some items in lookup arrays
		//that are not marked as used. Lets remove the missing items from the tree.
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

		//Lets merge in the unused items.
		foreach ($this->item_templates as $template_id => $template){
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
					$tree[$template['defaults']['parent']]['items'][] = $entry;
				} else {
					//This can happen if the original parent menu has been moved to a submenu.
					//Todo: Handle this unusual situation.
				}
			} else {
				$tree[$template['defaults']['file']] = $entry;
			}
		}

		//Resort the tree to ensure the found items are in the right spots
		$tree = ameMenu::sort_menu_tree($tree);

		return $tree;
	}

	/**
	 * Add a page and its required capability to the page access lookup.
	 *
	 * The lookup array is indexed by priority. Priorities (highest to lowest):
	 *      - Has custom permissions and a known template.
	 *      - Has custom permissions, template missing or can't be determined correctly.
	 *      - Default permissions.
	 *      - Everything else.
	 * Additionally, submenu items have slightly higher priority that top level menus.
	 * The desired end result is for menu items with custom permissions to override
	 * default menus.
	 *
	 * Note to self: If we were to keep items with an unknown template instead of throwing
	 * them away during the merge phase, we could simplify this considerably.
	 *
	 * @param array $item Menu item (with defaults already applied).
	 * @param string $item_type 'menu' or 'submenu'.
	 * @param bool $missing Whether the item template is missing or unknown.
	 */
	private function add_access_lookup($item, $item_type = 'menu', $missing = false) {
		if ( empty($item['url']) ) {
			return;
		}

		$has_custom_settings = !empty($item['grant_access']) || !empty($item['extra_capability']);
		$priority = 6;
		if ( $missing ) {
			if ( $has_custom_settings ) {
				$priority = 4;
			} else {
				return; //Don't even consider missing menus without custom access settings.
			}
		} else if ( $has_custom_settings ) {
			$priority = 2;
		}

		if ( $item_type == 'submenu' ) {
			$priority--;
		}

		$this->page_access_lookup[$item['url']][$priority] = $item['access_level'];
	}

  /**
   * Generate WP-compatible $menu and $submenu arrays from a custom menu tree.
   * 
   * Side-effects: This function executes several filters that may modify global state.
   * Specifically, IFrame-handling callbacks in 'extras.php' will add add new hooks
   * and other menu-related structures.
   *
   * @uses WPMenuEditor::$custom_wp_menu Stores the generated top-level menu here.
   * @uses WPMenuEditor::$custom_wp_submenu Stores the generated sub-menu here.
   *
   * @uses WPMenuEditor::$title_lookups Generates a lookup list of page titles.
   * @uses WPMenuEditor::$reverse_item_lookup Generates a lookup list of url => menu item relationships.
   *
   * @param array $tree The new menu, in the internal tree format.
   * @return void
   */
	function build_custom_wp_menu($tree){
		$new_tree = array();
		$new_menu = array();
		$new_submenu = array();
		$this->title_lookups = array();
		
		//Sort the menu by position
		uasort($tree, 'ameMenuItem::compare_position');

		//Prepare the top menu
		$first_nonseparator_found = false;
		foreach ($tree as $topmenu){

			//Skip missing and hidden menus.
			if ( !empty($topmenu['missing']) || !empty($topmenu['hidden']) ) {
				continue;
			}
			
			//Skip leading menu separators. Fixes a superfluous separator showing up
			//in WP 3.0 (multisite mode) when there's a custom menu and the current user
			//can't access its first item ("Super Admin").
			if ( !empty($topmenu['separator']) && !$first_nonseparator_found ) {
				continue;
			}
			$first_nonseparator_found = true;

			$topmenu = $this->prepare_for_output($topmenu, 'menu');

			if ( empty($topmenu['separator']) ) {
				$this->title_lookups[$topmenu['file']] = !empty($topmenu['page_title']) ? $topmenu['page_title'] : $topmenu['menu_title'];
			}
				
			//Prepare the submenu of this menu
			$new_items = array();
			if( !empty($topmenu['items']) ){
				$items = $topmenu['items'];
				//Sort by position
				uasort($items, 'ameMenuItem::compare_position');
				
				foreach ($items as $item) {
					//Skip missing and hidden items
					if ( !empty($item['missing']) || !empty($item['hidden']) ) {
						continue;
					}

					$item = $this->prepare_for_output($item, 'submenu', $topmenu['file']);
					$new_items[] = $item;

					//Make a note of the page's correct title so we can fix it later if necessary.
					$this->title_lookups[$item['file']] = !empty($item['page_title']) ? $item['page_title'] : $item['menu_title'];
				}
			}

			$topmenu['items'] = $new_items;
			$new_tree[] = $topmenu;
		}

		//Use only the highest-priority capability for each URL.
		foreach($this->page_access_lookup as $url => $capabilities) {
			ksort($capabilities);
			$this->page_access_lookup[$url] = reset($capabilities);
		}

		//Convert the prepared tree to the internal WordPress format.
		foreach($new_tree as $topmenu) {
			$trueAccess = isset($this->page_access_lookup[$topmenu['url']]) ? $this->page_access_lookup[$topmenu['url']] : null;
			if ( $trueAccess === 'do_not_allow' ) {
				$topmenu['access_level'] = $trueAccess;
			}
			if ( !isset($this->reverse_item_lookup[$topmenu['url']]) ) { //Prefer sub-menus.
				$this->reverse_item_lookup[$topmenu['url']] = $topmenu;
			}

			$new_menu[] = $this->convert_to_wp_format($topmenu);

			foreach($topmenu['items'] as $item) {
				$trueAccess = isset($this->page_access_lookup[$item['url']]) ? $this->page_access_lookup[$item['url']] : null;
				if ( $trueAccess === 'do_not_allow' ) {
					$item['access_level'] = $trueAccess;
				}
				$this->reverse_item_lookup[$item['url']] = $item;
				$new_submenu[$topmenu['file']][] = $this->convert_to_wp_format($item);
			}
		}

		$this->custom_wp_menu = $new_menu;
		$this->custom_wp_submenu = $new_submenu;
	}

	/**
	 * Convert a menu item from the internal format used by this plugin to the format
	 * used by WP. The menu should be prepared using the prepare... function beforehand.
	 *
	 * @see self::prepare_for_output()
	 *
	 * @param array $item
	 * @return array
	 */
	private function convert_to_wp_format($item) {
		//Build the menu structure that WP expects
		$wp_item = array(
			$item['menu_title'],
			$item['access_level'],
			$item['file'],
			$item['page_title'],
			$item['css_class'],
			$item['hookname'], //ID
			$item['icon_url']
		);

		return $wp_item;
	}

	/**
	 * Prepare a menu item to be converted to the WordPress format and added to the current
	 * WordPress admin menu. This function applies menu defaults and templates, calls filters
	 * that allow other components to tweak the menu, decides on what capability/-ies to use,
	 * and so on.
	 *
	 * Caution: The filters called by this function may cause side-effects. Specifically, the Pro-only feature
	 * for displaying menu pages in a frame does this. See wsMenuEditorExtras::create_framed_menu().
	 * Therefore, it is not safe to call this function more than once for the same item.
	 *
	 * @param array $item Menu item in the internal format.
	 * @param string $item_type Either 'menu' or 'submenu'.
	 * @param string $parent Optional. The parent of this sub-menu item. An empty string for top-level menus.
	 * @return array Menu item in the internal format.
	 */
	private function prepare_for_output($item, $item_type = 'menu', $parent = '') {
		// Special case : plugin pages that have been moved from a sub-menu to a different
		// menu or the top level. We'll need to adjust the file field to point to the correct URL.
		// This is required because WP identifies plugin pages using *both* the plugin file
		// and the parent file.
		if ( $item['template_id'] !== '' && !$item['separator'] ) {
			$template = $this->item_templates[$item['template_id']];
			if ( $template['defaults']['is_plugin_page'] ) {
				$default_parent = $template['defaults']['parent'];
				if ( $parent != $default_parent ){
					$item['file'] = $template['defaults']['url'];
				}
			}
		}

		//Menus that have both a custom icon URL and a "menu-icon-*" class will get two overlapping icons.
		//Fix this by automatically removing the class. The user can set a custom class attr. to override.
		if (
			ameMenuItem::is_default($item, 'css_class')
			&& !ameMenuItem::is_default($item, 'icon_url')
			&& !in_array($item['icon_url'], array('', 'none', 'div')) //Skip "no custom icon" icons.
		) {
			$new_classes = preg_replace('@\bmenu-icon-[^\s]+\b@', '', $item['defaults']['css_class']);
			if ( $new_classes !== $item['defaults']['css_class'] ) {
				$item['css_class'] = $new_classes;
			}
		}

		//Apply defaults & filters
		$item = ameMenuItem::apply_defaults($item);
		$item = ameMenuItem::apply_filters($item, $item_type, $parent); //may cause side-effects

		$item = $this->set_final_menu_capability($item);
		if ( !$this->options['security_logging_enabled'] ) {
			unset($item['access_check_log']); //Throw away the log to conserve memory.
		}
		$this->add_access_lookup($item, $item_type);

		//Menus without a custom icon image should have it set to "none" (or "div" in older WP versions).
		//See /wp-admin/menu-header.php for details on how this works.
		if ( $item['icon_url'] === '' ) {
			$item['icon_url'] = 'none';
		}

		//Used later to determine the current page based on URL.
		$item['url'] = ameMenuItem::generate_url($item['file'], $parent);

		//Convert relative URls to fully qualified ones. This prevents problems with WordPress
		//incorrectly converting "index.php?page=xyz" to, say, "tools.php?page=index.php?page=xyz"
		//if the menu item was moved from "Dashboard" to "Tools".
		$itemFile = ameMenuItem::remove_query_from($item['file']);
		$shouldMakeAbsolute =
			   (strpos($item['file'], '://') === false)
			&& (substr($item['file'], 0, 1) != '/')
			&& ($itemFile == 'index.php')
			&& (strpos($item['file'], '?') !== false);

		if ( $shouldMakeAbsolute ) {
			$item['file'] = admin_url($item['url']);
		}

		//WPML support: Use translated menu titles where available.
		if ( !$item['separator'] && function_exists('icl_t') ) {
			$item['menu_title'] = icl_t(
				self::WPML_CONTEXT,
				$this->get_wpml_name_for($item, 'menu_title'),
				$item['menu_title']
			);
		}

		return $item;
	}

	/**
	 * Figure out if the current user can access a menu item and what capability they would need.
	 *
	 * This method takes into account the default capability set by WordPress as well as any
	 * custom role and capability settings specified by the user. It will set "access_level"
	 * to the required capability, or set it to 'do_not_allow' if the current user can't access
	 * this menu.
	 *
	 * @param array $item Menu item (with defaults applied).
	 * @return array
	 */
	private function set_final_menu_capability($item) {
		$item['access_check_log'] = array(
			str_repeat('=', 79),
			'Figuring out what capability the user will need to access this item...'
		);

		$item = apply_filters('custom_admin_menu_capability', $item);

		$item['access_check_log'][] = '-----';

		//Check if the current user can access this menu.
		$user_has_access = true;
		$cap_to_use = '';
		if ( !empty($item['access_level']) ) {
			$user_has_cap = $this->current_user_can($item['access_level']);
			$user_has_access = $user_has_access && $user_has_cap;
			$cap_to_use = $item['access_level'];

			$item['access_check_log'][] = sprintf(
				'Required capability: %1$s. User %2$s this capability.',
				htmlentities($cap_to_use),
				$user_has_cap ? 'HAS' : 'DOES NOT have'
			);
		} else {
			$item['access_check_log'][] = '- No required capability set.';
		}

		if ( !empty($item['extra_capability']) ) {
			$user_has_cap = $this->current_user_can($item['extra_capability']);
			$user_has_access = $user_has_access && $user_has_cap;
			$cap_to_use = $item['extra_capability'];

			$item['access_check_log'][] = sprintf(
				'Extra capability: %1$s. User %2$s this capability.',
				htmlentities($cap_to_use),
				$user_has_cap ? 'HAS' : 'DOES NOT have'
			);
		} else {
			$item['access_check_log'][] = 'No "extra capability" set.';
		}

		$capability = $user_has_access ? $cap_to_use : 'do_not_allow';
		$item['access_check_log'][] = 'Final capability setting: ' . $capability;
		$item['access_check_log'][] = str_repeat('=', 79);

		$item['access_level'] = $capability;
		return $item;
	}
	
  /**
   * Output the menu editor page
   *
   * @return void
   */
	function page_menu_editor(){
		if ( !$this->current_user_can_edit_menu() ){
			wp_die(sprintf(
				'You do not have sufficient permissions to use Admin Menu Editor. Required: <code>%s</code>.',
				htmlentities($this->options['plugin_access'])
			));
		}

		$action = isset($this->post['action']) ? $this->post['action'] : (isset($this->get['action']) ? $this->get['action'] : '');
		do_action('admin_menu_editor_header', $action);

		if ( !empty($action) ) {
			$this->handle_form_submission($this->post, $action);
		}

		$sub_section = isset($this->get['sub_section']) ? $this->get['sub_section'] : null;
		if ( $sub_section === 'settings' ) {
			$this->display_plugin_settings_ui();
		} else {
			$this->display_editor_ui();
		}
	}

	private function handle_form_submission($post, $action = '') {
		if ( $action == 'save_menu' ) {
			//Save the admin menu configuration.
			if ( isset($post['data']) ){
				check_admin_referer('menu-editor-form');

				//Try to decode a menu tree encoded as JSON
				$url = remove_query_arg(array('noheader'));
				try {
					$menu = ameMenu::load_json($post['data'], true);
				} catch (InvalidMenuException $ex) {
					//Or redirect & display the error message
					wp_redirect( add_query_arg('message', 2, $url) );
					die();
				}

				//Save the custom menu
				$this->set_custom_menu($menu);

				//Redirect back to the editor and display the success message.
				//Also, automatically select the last selected actor (convenience feature).
				$query = array('message' => 1);
				if ( isset($post['selected_actor']) && !empty($post['selected_actor']) ) {
					$query['selected_actor'] = strval($post['selected_actor']);
				}
				wp_redirect( add_query_arg($query, $url) );
				die();
			} else {
				$message = "Failed to save the menu. ";
				if ( isset($this->post['data_length']) && is_numeric($this->post['data_length']) ) {
					$message .= sprintf(
						'Expected to receive %d bytes of menu data in $_POST[\'data\'], but got nothing.',
						intval($this->post['data_length'])
					);
				}
				wp_die($message);
			}

		} else if ( $action == 'save_settings' ) {

			//Save overall plugin configuration (permissions, etc).
			check_admin_referer('save_settings');

			//Plugin access setting.
			$valid_access_settings = array('super_admin', 'manage_options');
			//On Multisite only Super Admins can choose the "Only the current user" option.
			if ( !is_multisite() || is_super_admin() ) {
				$valid_access_settings[] = 'specific_user';
			}
			if ( isset($this->post['plugin_access']) && in_array($this->post['plugin_access'], $valid_access_settings) ) {
				$this->options['plugin_access'] = $this->post['plugin_access'];

				if ( $this->options['plugin_access'] === 'specific_user' ) {
					$this->options['allowed_user_id'] = get_current_user_id();
				} else {
					$this->options['allowed_user_id'] = null;
				}
			}

			//Whether to hide the plugin on the "Plugins" admin page.
			if ( !is_multisite() || is_super_admin() ) {
				if ( !empty($this->post['hide_plugin_from_others']) ) {
					$this->options['plugins_page_allowed_user_id'] = get_current_user_id();
				} else {
					$this->options['plugins_page_allowed_user_id'] = null;
				}
			}

			//Configuration scope. The Super Admin is the only one who can change it since it affects all sites.
			if ( is_multisite() && is_super_admin() ) {
				$valid_scopes = array('global', 'site');
				if ( isset($this->post['menu_config_scope']) && in_array($this->post['menu_config_scope'], $valid_scopes) ) {
					$this->options['menu_config_scope'] = $this->post['menu_config_scope'];
				}
			}

			//Security logging.
			$this->options['security_logging_enabled'] = !empty($this->post['security_logging_enabled']);

			//Hide some menu options by default.
			$this->options['hide_advanced_settings'] = !empty($this->post['hide_advanced_settings']);

			$this->save_options();
			wp_redirect(add_query_arg('updated', 1, $this->get_settings_page_url()));
		}
	}

	private function display_editor_ui() {
		//Prepare a bunch of parameters for the editor.
		$editor_data = array(
			'message' => isset($this->get['message']) ? intval($this->get['message']) : null,
			'images_url' => plugins_url('images', $this->plugin_file),
			'hide_advanced_settings' => $this->options['hide_advanced_settings'],
			'settings_page_url' => $this->get_settings_page_url(),
		);

		//Build a tree struct. for the default menu
		$default_tree = ameMenu::wp2tree($this->default_wp_menu, $this->default_wp_submenu);
		$default_menu = ameMenu::load_array($default_tree);

		//Is there a custom menu?
		if (!empty($this->merged_custom_menu)){
			$custom_menu = $this->merged_custom_menu;
		} else {
			//Start out with the default menu if there is no user-created one
			$custom_menu = $default_menu;
		}

		//Encode both menus as JSON
		$editor_data['default_menu_js'] = ameMenu::to_json($default_menu);
		$editor_data['custom_menu_js'] = ameMenu::to_json($custom_menu);

		//Create a list of all known capabilities and roles. Used for the drop-down list on the access field.
		$all_capabilities = ameRoleUtils::get_all_capabilities();
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

		//Multi-site installs also get the virtual "Super Admin" cap, but only the Super Admin sees it.
		if ( is_multisite() && !isset($all_capabilities['super_admin']) && is_super_admin() ){
			array_unshift($all_capabilities, 'super_admin');
		}
		$editor_data['all_capabilities'] = $all_capabilities;

		//Create a list of all roles, too.
		$all_roles = ameRoleUtils::get_role_names();
		asort($all_roles);
		$editor_data['all_roles'] = $all_roles;

		//Include hint visibility settings
		$editor_data['show_hints'] = $this->get_hint_visibility();

		require dirname(__FILE__) . '/editor-page.php';
	}

	/**
	 * Display the plugin settings page.
	 */
	private function display_plugin_settings_ui() {
		//These variables are used by settings-page.php.
		$settings = $this->options;
		$settings_page_url = $this->get_settings_page_url();
		$editor_page_url = admin_url($this->settings_link);

		require dirname(__FILE__) . '/settings-page.php';
	}

	/**
	 * Get the fully qualified URL of the "Settings" sub-section of our plugin page.
	 *
	 * @return string
	 */
	private function get_settings_page_url() {
		return add_query_arg('sub_section', 'settings', admin_url($this->settings_link));
	}

	/**
	 * Check if the current page is the "Menu Editor" admin page.
	 *
	 * @return bool
	 */
	protected function is_editor_page() {
		return is_admin()
		&& isset($this->get['page']) && ($this->get['page'] == 'menu_editor')
		&& ( !isset($this->get['sub_section']) || empty($this->get['sub_section']) );
	}

	/**
	 * Check if the current page is the "Settings" sub-section of our admin page.
	 *
	 * @return bool
	 */
	protected function is_settings_page() {
		return is_admin()
		&& isset($this->get['sub_section']) && ($this->get['sub_section'] == 'settings')
		&& isset($this->get['page']) && ($this->get['page'] == 'menu_editor');
	}
	
	/**
	 * Generate a list of "virtual" capabilities that should be granted to certain roles.
	 *
	 * This is based on grant_access settings for the current custom menu and enables
	 * selected roles and users to access menu items that they ordinarily would not
	 * be able to.
	 *
	 * @uses self::get_virtual_caps_for() to actually generate the caps.
	 * @uses self::$cached_virtual_caps to cache the generated list of caps.
	 *
	 * @return array A list of capability => [role1 => true, ... roleN => true] assignments.
	 */
	function get_virtual_caps() {
		if ( $this->cached_virtual_caps !== null ) {
			return $this->cached_virtual_caps;
		}

		$caps = array();
		$custom_menu = $this->load_custom_menu();
		if ( $custom_menu === null ){
			return $caps;
		}

		foreach($custom_menu['tree'] as $item) {
			$caps = array_merge_recursive($caps, $this->get_virtual_caps_for($item));
		}

		$this->cached_virtual_caps = $caps;
		return $caps;
	}

	private function get_virtual_caps_for($item) {
		$caps = array();

		if ( $item['template_id'] !== '' ) {
			$required_cap = ameMenuItem::get($item, 'access_level');
			foreach ($item['grant_access'] as $grant => $has_access) {
				if ( $has_access ) {
					if ( !isset($caps[$grant]) ) {
						$caps[$grant] = array();
					}
					$caps[$grant][$required_cap] = true;
				}
			}
		}

		foreach($item['items'] as $sub_item) {
			$caps = array_merge_recursive($caps, $this->get_virtual_caps_for($sub_item));
		}

		return $caps;
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
		if (!$this->current_user_can_edit_menu() || !check_ajax_referer('ws_ame_save_screen_options', false, false)){
			die( $this->json_encode( array(
				'error' => "You're not allowed to do that!" 
			 )));
		}
		
		$this->options['hide_advanced_settings'] = !empty($this->post['hide_advanced_settings']);
		$this->save_options();
		die('1');
	}

	public function ajax_hide_hint() {
		if ( !isset($this->post['hint']) || !$this->current_user_can_edit_menu() ){
			die("You're not allowed to do that!");
		}

		$show_hints = $this->get_hint_visibility();
		$show_hints[strval($this->post['hint'])] = false;
		$this->set_hint_visibility($show_hints);

		die("OK");
	}

	private function get_hint_visibility() {
		$user = wp_get_current_user();
		$show_hints = get_user_meta($user->ID, 'ame_show_hints', true);
		if ( !is_array($show_hints) ) {
			$show_hints = array();
		}

        $defaults = array(
            'ws_sidebar_pro_ad' => true,
            'ws_whats_new_120' => false,
            'ws_hint_menu_permissions' => true,
        );

		return array_merge($defaults, $show_hints);
	}

	private function set_hint_visibility($show_hints) {
		$user = wp_get_current_user();
		update_user_meta($user->ID, 'ame_show_hints', $show_hints);
	}

	/**
	 * Enqueue a script that fixes a bug where pages moved to a different menu
	 * would not be highlighted properly when the user visits them.
	 */
	public function enqueue_menu_fix_script() {
		wp_enqueue_auto_versioned_script(
			'ame-menu-fix',
			plugins_url('js/menu-highlight-fix.js', $this->plugin_file),
			array('jquery'),
			true
		);
	}

	/**
	 * Check if the current user can access the current admin menu page.
	 *
	 * @return bool
	 */
	private function user_can_access_current_page() {
		$current_item = $this->get_current_menu_item();
		if ( $current_item === null ) {
			$this->log_security_note('Could not determine the current menu item. We won\'t do any custom permission checks.');
			return true; //Let WordPress handle it.
		}

		$this->log_security_note(sprintf(
			'The current menu item is "%s", menu template ID: "%s"',
			htmlentities($current_item['menu_title']),
			htmlentities(ameMenuItem::get($current_item, 'template_id', 'N/A'))
		));
		if ( isset($current_item['access_check_log']) ) {
			$this->log_security_note($current_item['access_check_log']);
		}

		//Note: Per-role and per-user virtual caps will be applied by has_cap filters.
		$allow = $this->current_user_can($current_item['access_level']);
		$this->log_security_note(sprintf(
			'The current user %1$s the "%2$s" capability.',
			$allow ? 'has' : 'does not have',
			htmlentities($current_item['access_level'])
		));

		return $allow;
	}

	/**
	 * Check if the current user has the specified capability.
	 * If the Pro version installed, you can use special syntax to perform complex capability checks.
	 *
	 * @param string $capability
	 * @return bool
	 */
	private function current_user_can($capability) {
		return apply_filters('admin_menu_editor-current_user_can', current_user_can($capability), $capability);
	}

	/**
	 * Determine which menu item matches the currently open admin page.
	 *
	 * @uses self::$reverse_item_lookup
	 * @return array|null Menu item in the internal format, or NULL if no matching item can be found.
	 */
	private function get_current_menu_item() {
		if ( !is_admin() || empty($this->reverse_item_lookup)) {
			if ( !is_admin() ) {
				$this->log_security_note('This is not an admin page. is_admin() returns false.');
			} else if ( empty($this->reverse_item_lookup) ) {
				$this->log_security_note('Warning: reverse_item_lookup is empty!');
			}
			return null;
		}

		//The current menu item doesn't change during a request, so we can cache it
		//and avoid searching the entire menu every time.
		static $cached_item = null;
		if ( $cached_item !== null ) {
			return $cached_item;
		}

		//Find an item where *all* query params match the current ones, with as few extraneous params as possible,
		//preferring sub-menu items. This is intentionally more strict than what we do in menu-highlight-fix.js,
		//since this function is used to check menu access.
		//TODO: Use get_current_screen() to determine the current post type and taxonomy.

		$best_item = null;
		$best_extra_params = PHP_INT_MAX;

		$base_site_url = get_site_url();
		if ( preg_match('@(^\w+://[^/]+)@', $base_site_url, $matches) ) { //Extract scheme + hostname.
			$base_site_url = $matches[1];
		}

		$current_url = $base_site_url . remove_query_arg('___ame_dummy_param___');
		$this->log_security_note(sprintf('Current URL: "%s"', htmlentities($current_url)));

		$current_url = $this->parse_url($current_url);

		foreach($this->reverse_item_lookup as $url => $item) {
			$item_url = $url;
			//Convert to absolute URL. Caution: directory traversal (../, etc) is not handled.
			if (strpos($item_url, '://') === false) {
				if ( substr($item_url, 0, 1) == '/' ) {
					$item_url = $base_site_url . $item_url;
				} else {
					$item_url = admin_url($item_url);
				}
			}
			$item_url = $this->parse_url($item_url);

			//Must match scheme, host, port, user, pass and path.
			$components = array('scheme', 'host', 'port', 'user', 'pass');
			$is_close_match = $this->urlPathsMatch($current_url['path'], $item_url['path']);
			foreach($components as $component) {
				$is_close_match = $is_close_match && ($current_url[$component] == $item_url[$component]);
				if ( !$is_close_match ) {
					break;
				}
			}

			//The current URL must match all query parameters of the item URL.
			$different_params = array_diff_assoc($item_url['params'], $current_url['params']);

			//The current URL must have as few extra parameters as possible.
			$extra_params = array_diff_assoc($current_url['params'], $item_url['params']);

			if ( $is_close_match && (count($different_params) == 0) && (count($extra_params) < $best_extra_params) ) {
				$best_item = $item;
				$best_extra_params = count($extra_params);
			}
		}

		$cached_item = $best_item;
		return $best_item;
	}

	/**
	 * Parse a URL and return its components.
	 *
	 * Returns an array that contains all of these components: 'scheme', 'host', 'port', 'user', 'pass',
	 * 'path', 'query', 'fragment' and 'params'. All entries are strings, except 'params' which is
	 * an associative array of query parameters and their values.
	 *
	 * @param string $url
	 * @return array
	 */
	private function parse_url($url) {
		$url_defaults = array_fill_keys(array('scheme', 'host', 'port', 'user', 'pass', 'path', 'query', 'fragment'), '');
		$url_defaults['port'] = '80';

		$parsed = @parse_url($url);
		if ( !is_array($parsed) ) {
			$parsed = array();
		}
		$parsed = array_merge($url_defaults, $parsed);

		$params = array();
		if (!empty($parsed['query'])) {
			wp_parse_str($parsed['query'], $params);
		};
		$parsed['params'] = $params;

		return $parsed;
	}

	/**
	 * Check if two paths match. Intended for comparing WP admin URLs.
	 *
	 * @param string $path1
	 * @param string $path2
	 * @return bool
	 */
	private function urlPathsMatch($path1, $path2) {
		if ( $path1 == $path2 ) {
			return true;
		}

		// "/wp-admin/index.php" should match "/wp-admin/".
		if (
			($this->endsWith($path1, '/wp-admin/index.php') && $this->endsWith($path2, '/wp-admin/'))
			|| ($this->endsWith($path2, '/wp-admin/index.php') && $this->endsWith($path1, '/wp-admin/'))
		) {
			return true;
		}

		return false;
	}

	/**
	 * Determine if the input $string ends with the specified $suffix.
	 *
	 * @param string $string
	 * @param string $suffix
	 * @return bool
	 */
	private function endsWith($string, $suffix) {
		$len = strlen($suffix);
		if ( $len == 0 ) {
			return true;
		}
		return substr($string, -$len) === $suffix;
	}

	private function castValuesToBool($capabilities) {
		if ( !is_array($capabilities) ) {
			if ( empty($capabilities) ) {
				$capabilities = array();
			} else {
				trigger_error("Unexpected capability array: " . print_r($capabilities, true), E_USER_WARNING);
				return array();
			}
		}
		foreach($capabilities as $capability => $value) {
			$capabilities[$capability] = (bool)$value;
		}
		return $capabilities;
	}

	public function display_survey_notice() {
		//Handle the survey notice
		$hide_param_name = 'ame_hide_survey_notice';
		if ( isset($this->get[$hide_param_name]) ) {
			$this->options['display_survey_notice'] = empty($this->get[$hide_param_name]);
			$this->save_options();
		}

		$display_notice = $this->options['display_survey_notice'] && $this->current_user_can_edit_menu();
		if ( isset($this->options['first_install_time']) ) {
			$minimum_usage_period = 3*24*3600;
			$display_notice = $display_notice && ((time() - $this->options['first_install_time']) > $minimum_usage_period);
		}

		//Only display the notice on the Menu Editor (Pro) page.
		$display_notice = $display_notice && isset($this->get['page']) && ($this->get['page'] == 'menu_editor');
		
		//Let the user override this completely (useful for client sites).
		if ( $display_notice && file_exists(dirname($this->plugin_file) . '/never-display-surveys.txt') ) {
			$display_notice = false;
			$this->options['display_survey_notice'] = false;
			$this->save_options();
		}

		if ( $display_notice ) {
			$free_survey_url = 'https://docs.google.com/spreadsheet/viewform?formkey=dERyeDk0OWhlbkxYcEY4QTNaMnlTQUE6MQ';
			$pro_survey_url =  'https://docs.google.com/spreadsheet/viewform?formkey=dHl4MnlHaVI3NE5JdVFDWG01SkRKTWc6MA';

			if ( apply_filters('admin_menu_editor_is_pro', false) ) {
				$survey_url = $pro_survey_url;
			} else {
				$survey_url = $free_survey_url;
			}

			$hide_url = add_query_arg($hide_param_name, 1);
			printf(
				'<div class="updated">
					<p><strong>Help improve Admin Menu Editor - take the user survey!</strong></p>
					<p><!--suppress HtmlUnknownTarget --><a href="%s" target="_blank" title="Opens in a new window">Take the survey</a></p>
					<p><!--suppress HtmlUnknownTarget --><a href="%s">Hide this notice</a></p>
				</div>',
				esc_attr($survey_url),
				esc_attr($hide_url)
			);
		}
	}

	/**
	 * Capture $_GET and $_POST in $this->get and $this->post.
	 * Slashes added by "magic quotes" will be stripped.
	 *
	 * @return void
	 */
	function capture_request_vars(){
		$this->post = $_POST;
		$this->get = $_GET;

		if ( function_exists('get_magic_quotes_gpc') && get_magic_quotes_gpc() ) {
			$this->post = stripslashes_deep($this->post);
			$this->get = stripslashes_deep($this->get);
		}
	}

	public function enqueue_helper_scripts() {
		wp_enqueue_script(
			'ame-helper-script',
			plugins_url('js/admin-helpers.js', $this->plugin_file),
			array('jquery'),
			'20121121'
		);
	}

	public function enqueue_helper_styles() {
		wp_enqueue_style(
			'ame-helper-style',
			plugins_url('css/admin.css', $this->plugin_file),
			array(),
			'20130211'
		);
	}

	/**
	 * Get one of the plugin configuration values.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public function get_plugin_option($name) {
		if ( array_key_exists($name, $this->options) ) {
			return $this->options[$name];
		}
		return null;
	}


	/**
	 * Log a security-related message.
	 *
	 * @param string|array $message The message to add tot he log, or an array of messages.
	 */
	private function log_security_note($message) {
		if ( !$this->options['security_logging_enabled'] ) {
			return;
		}
		if ( is_array($message) ) {
			$this->security_log = array_merge($this->security_log, $message);
		} else {
			$this->security_log[] = $message;
		}
	}

	/**
	 * Callback for "admin_notices".
	 */
	public function display_security_log() {
		?>
		<div class="updated">
			<h3>Admin Menu Editor security log</h3>
			<?php echo $this->get_formatted_security_log(); ?>
		</div>
		<?php
	}

	/**
	 * Get the security log in HTML format.
	 *
	 * @return string
	 */
	private function get_formatted_security_log() {
		$log = '<div style="font: 12px/16.8px Consolas, monospace; margin-bottom: 1em;">';
		$log .= implode("<br>\n", $this->security_log);
		$log .= '</div>';
		return $log;
	}

	/**
	 * WPML support: Update strings that need translation.
	 *
	 * @param array $old_menu The old custom menu, if any.
	 * @param array $custom_menu The new custom menu.
	 */
	private function update_wpml_strings($old_menu, $custom_menu) {
		if ( !function_exists('icl_register_string') ) {
			return;
		}

		$previous_strings = $this->get_wpml_strings($old_menu);
		$new_strings = $this->get_wpml_strings($custom_menu);

		//Delete strings that are no longer valid.
		if ( function_exists('icl_unregister_string') ) {
			$removed_strings = array_diff_key($previous_strings, $new_strings);
			foreach($removed_strings as $name => $value) {
				icl_unregister_string(self::WPML_CONTEXT, $name);
			}
		}

		//Register/update the new menu strings.
		foreach($new_strings as $name => $value) {
			icl_register_string(self::WPML_CONTEXT, $name, $value);
		}
	}

	/**
	 * Prepare WPML translation strings for all menu and page titles
	 * in the specified menu.
	 *
	 * @param array $custom_menu
	 * @return array Associative array of strings that can be translated, indexed by unique name.
	 */
	private function get_wpml_strings($custom_menu) {
		if ( empty($custom_menu) ) {
			return array();
		}

		$strings = array();
		$translatable_fields = array('menu_title', 'page_title');
		foreach($custom_menu['tree'] as $top_menu) {
			if ( $top_menu['separator'] ) {
				continue;
			}

			foreach($translatable_fields as $field) {
				if ( isset($top_menu[$field]) ) {
					$name = $this->get_wpml_name_for($top_menu, $field);
					$strings[$name] = ameMenuItem::get($top_menu, $field);
				}
			}

			if ( isset($top_menu['items']) && !empty($top_menu['items']) ) {
				foreach($top_menu['items'] as $item) {
					if ( $item['separator'] ) {
						continue;
					}

					foreach($translatable_fields as $field) {
						if ( isset($item[$field]) ) {
							$name = $this->get_wpml_name_for($item, $field);
							$strings[$name] = ameMenuItem::get($item, $field);
						}
					}
				}
			}
		}

		return $strings;
	}

	/**
	 * Create a unique name for a specific field of a specific menu item.
	 * Intended for use with the icl_register_string() function.
	 *
	 * @param array $item Admin menu item in the internal format.
	 * @param string $field Field name.
	 * @return string
	 */
	private function get_wpml_name_for($item, $field = '') {
		$name = ameMenuItem::get($item, 'template_id');
		if ( empty($name) ) {
			$name = 'custom: ' . ameMenuItem::get($item, 'file');
		}
		if ( !empty($field) ) {
			$name = $name . '[' . $field. ']';
		}
		return $name;
	}

} //class