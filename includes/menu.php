<?php
abstract class ameMenu {
	const format_name = 'Admin Menu Editor menu';
	const format_version = '5.0';

	/**
	 * Load an admin menu from a JSON string.
	 *
	 * @static
	 * @throws InvalidMenuException when the supplied input is not a valid menu.
	 *
	 * @param string $json A JSON-encoded menu structure.
	 * @param bool $assume_correct_format Skip the format header check and assume everything is fine. Defaults to false.
	 * @return array
	 */
	public static function load_json($json, $assume_correct_format = false) {
		$arr = json_decode($json, true);
		if ( !is_array($arr) ) {
			throw new InvalidMenuException('The input is not a valid JSON-encoded admin menu.');
		}
		return self::load_array($arr, $assume_correct_format);
	}

	/**
	 * Load an admin menu structure from an associative array.
	 *
	 * @static
	 * @throws InvalidMenuException when the supplied input is not a valid menu.
	 *
	 * @param array $arr
	 * @param bool $assume_correct_format
	 * @return array
	 */
	public static function load_array($arr, $assume_correct_format = false){
		if ( !$assume_correct_format ) {
			if ( isset($arr['format']) && ($arr['format']['name'] == self::format_name) ) {
				if ( !version_compare($arr['format']['version'], self::format_version, '<=') ) {
					throw new InvalidMenuException("Can't load a menu created by a newer version of the plugin.");
				}
			} else {
				return self::load_menu_40($arr);
			}
		}

		if ( !(isset($arr['tree']) && is_array($arr['tree'])) ) {
			throw new InvalidMenuException("Failed to load a menu - the menu tree is missing.");
		}

		$menu = array('tree' => array());
		$menu = self::add_format_header($menu);

		foreach($arr['tree'] as $file => $item) {
			$menu['tree'][$file] = ameMenuItem::normalize($item);
		}

		return $menu;
	}

	/**
	 * "Pre-load" an old menu structure.
	 *
	 * In older versions of the plugin, the entire menu consisted of
	 * just the menu tree and nothing else. This was internally known as
	 * menu format "4".
	 *
	 * To improve portability and forward-compatibility, newer versions
	 * use a simple dictionary-based container instead, with the menu tree
	 * being one of the possible entries.
	 *
	 * @static
	 * @param array $arr
	 * @return array
	 */
	private static function load_menu_40($arr) {
		//This is *very* basic and might need to be improved.
		$menu = array('tree' => $arr);
		return self::load_array($menu, true);
	}

	private static function add_format_header($menu) {
		$menu['format'] = array(
			'name' => self::format_name,
			'version' => self::format_version,
		);
		return $menu;
	}

	/**
	 * Serialize an admin menu as JSON.
	 *
	 * @static
	 * @param array $menu
	 * @return string
	 */
	public static function to_json($menu) {
		$menu = self::add_format_header($menu);
		return json_encode($menu);
	}
}


class InvalidMenuException extends Exception {}