<?php

/**
 * This class contains a number of static methods for working with individual menu items.
 *
 * Note: This class is not fully self-contained. Some of the methods will query global state.
 * This is necessary because the interpretation of certain menu fields depends on things like
 * currently registered hooks and the presence of specific files in admin/plugin folders.
 */
abstract class ameMenuItem {
	/**
	 * Convert a WP menu structure to an associative array.
	 *
	 * @param array $item An menu item.
	 * @param int $position The position (index) of the the menu item.
	 * @param string $parent The slug of the parent menu that owns this item. Blank for top level menus.
	 * @return array
	 */
	public static function fromWpItem($item, $position = 0, $parent = '') {
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
			//$item['url'] = self::get_menu_url($item['file'], $parent);
			$item['url'] = 'error-url-generation-disabled';
		}

		$item['menu_id'] = self::unique_menu_id($item);

		return array_merge(self::basic_defaults(), $item);
	}

	public static function basic_defaults() {
		static $basic_defaults = null;
		if ( $basic_defaults !== null ) {
			return $basic_defaults;
		}

		$basic_defaults = array(
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

		return $basic_defaults;
	}

	public static function blank_menu() {
		static $blank_menu = null;
		if ( $blank_menu !== null ) {
			return $blank_menu;
		}

		//Template for a basic menu item.
		$blank_menu = array_fill_keys(array_keys(self::basic_defaults()), null);
		$blank_menu['items'] = array();
		$blank_menu['defaults'] = self::basic_defaults();
		return $blank_menu;
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
	public static function get($item, $field_name, $default = null){
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
	  * Generate an ID that semi-uniquely identifies a given menu item.
	  *
	  * @param array $item The menu item in question.
	  * @param string $parent_file The parent menu. If omitted, $item['defaults']['parent'] will be used.
	  * @return string Unique ID
	  */
	public static function unique_menu_id($item, $parent_file = ''){
		//Maybe it already has an ID?
		$menu_id = self::get($item, 'menu_id');
		if ( !empty($menu_id) ) {
			return $menu_id;
		}

		if ( isset($item['defaults']['file']) ) {
			$item_file = $item['defaults']['file'];
		} else {
			$item_file = self::get($item, 'file');
		}

		if ( empty($parent_file) ) {
			if ( isset($item['defaults']['parent']) ) {
				$parent_file = $item['defaults']['parent'];
			} else {
				$parent_file = self::get($item, 'parent');
			}
		}

		return $parent_file . '>' . $item_file;
	}

  /**
   * Set all undefined menu fields to the default value.
   *
   * @param array $item Menu item in the plugin's internal form
   * @return array
   */
	public static function apply_defaults($item){
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
	public static function apply_filters($item, $item_type = '', $extra = null){
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
   * Custom comparison function that compares menu items based on their position in the menu.
   *
   * @param array $a
   * @param array $b
   * @return int
   */
	public static function compare_position($a, $b){
		return self::get($a, 'position', 0) - self::get($b, 'position', 0);
	}

	/**
	 * Generate a URL for a menu item.
	 *
	 * @param string $item_slug
	 * @param string $parent_slug
	 * @return string An URL relative to the /wp-admin/ directory.
	 */
	private static function get_menu_url($item_slug, $parent_slug = '') {
		$menu_url = is_array($item_slug) ? self::get($item_slug, 'file') : $item_slug;
		$parent_url = !empty($parent_slug) ? $parent_slug : 'admin.php';

		if ( self::is_hook_or_plugin_page($menu_url, $parent_url) ) {
			$base_file = self::is_hook_or_plugin_page($parent_url) ? 'admin.php' : $parent_url;
			$url = add_query_arg(array('page' => $menu_url), $base_file);
		} else {
			$url = $menu_url;
		}
		return $url;
	}

	private static function is_hook_or_plugin_page($page_url, $parent_page_url = '') {
		if ( empty($parent_page_url) ) {
			$parent_page_url = 'admin.php';
		}
		$pageFile = self::remove_query_from($page_url);

		$hasHook = (get_plugin_page_hook($page_url, $parent_page_url) !== null);
		$adminFileExists = is_file(ABSPATH . '/wp-admin/' . $pageFile);
		$pluginFileExists = ($page_url != 'index.php') && is_file(WP_PLUGIN_DIR . '/' . $pageFile);

		return !$adminFileExists && ($hasHook || $pluginFileExists);
	}

	private static function remove_query_from($url) {
		$pos = strpos($url, '?');
		if ( $pos !== false ) {
			return substr($url, 0, $pos);
		}
		return $url;
	}
}