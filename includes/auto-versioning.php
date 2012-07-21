<?php

if ( !class_exists('AutoVersioning') ) {

/**
 * @link http://stackoverflow.com/questions/118884/what-is-an-elegant-way-to-force-browsers-to-reload-cached-css-js-files
 */
class AutoVersioning {
	private static $version_in_filename = true;

	public static function add_dependency($wp_api_function, $handle, $src, $deps, $last_param, $add_ver_to_filename = true ) {
		list($src, $version) = self::auto_version($src, $add_ver_to_filename);
		call_user_func($wp_api_function, $handle, $src, $deps, $version, $last_param);
	}

	/**
	 * Automatically version a script or style sheet URL based on file modification time.
	 *
	 * Returns auto-versioned $src and $ver values suitable for use with WordPress dependency APIs like
	 * wp_register_script() and wp_register_style().
	 *
	 * @static
	 * @param string $url
	 * @param bool $add_ver_to_filename
	 * @return array array($url, $version)
	 */
	private static function auto_version($url, $add_ver_to_filename = true) {
		global $wp_rewrite; /** @var WP_Rewrite $wp_rewrite */

		$version = false;
		$filename = self::guess_filename_from_url($url);

		if ( ($filename !== null) && is_file($filename) ) {
			$mtime = filemtime($filename);
			if ( $add_ver_to_filename && $wp_rewrite->using_mod_rewrite_permalinks() ) {
				$url = preg_replace('@\.([^./\?]+)(\?.*)?$@', '.' . $mtime . '.$1', $url);
				$version = null;
			} else {
				$version = $mtime;
			}
		}

		return array($url, $version);
	}

	private static function guess_filename_from_url($url) {
		$url_mappings = array(
			plugins_url() => WP_PLUGIN_DIR,
			plugins_url('', WPMU_PLUGIN_DIR . '/dummy') => WPMU_PLUGIN_DIR,
			get_stylesheet_directory_uri() => get_stylesheet_directory(),
			get_template_directory_uri() => get_template_directory(),
			content_url() => WP_CONTENT_DIR,
			site_url('/' . WPINC) => ABSPATH . WPINC,
		);

		$filename = null;
		foreach($url_mappings as $root_url => $directory) {
			if ( strpos($url, $root_url) === 0 ) {
				$filename = $directory . '/' . substr($url, strlen($root_url));
				//Get rid of the query string, if any.
				list($filename, ) = explode('?', $filename, 2);
				break;
			}
		}

		return $filename;
	}

	public static function apply_to_all_dependencies($add_ver_to_filename = true) {
		self::$version_in_filename = $add_ver_to_filename;
		foreach(array('script_loader_src', 'style_loader_src') as $hook) {
			add_filter($hook, __CLASS__ . '::_filter_dependency_src', 10, 1);
		}
	}

	public static function _filter_dependency_src($src) {
		//Only add version info to CSS/JS files that don't already have it in the file name.
		if ( preg_match('@(?<!\.\d{10})\.(css|js)(\?|$)@i', $src) ) {
			list($src, $version) = self::auto_version($src, self::$version_in_filename);
			if ( !empty($version) ) {
				$src = add_query_arg('ver', $version, $src);
			}
		}
		return $src;
	}
}

} //class_exists()

if ( !function_exists('wp_register_auto_versioned_script') ) {
	function wp_register_auto_versioned_script($handle, $src, $deps = array(), $in_footer = false, $add_ver_to_filename = true) {
		AutoVersioning::add_dependency('wp_register_script', $handle, $src, $deps, $in_footer, $add_ver_to_filename);
	}
}

if ( !function_exists('wp_register_auto_versioned_style') ) {
	function wp_register_auto_versioned_style( $handle, $src, $deps = array(), $media = 'all', $add_ver_to_filename = true ) {
		AutoVersioning::add_dependency('wp_register_style', $handle, $src, $deps, $media, $add_ver_to_filename);
	}
}

if ( !function_exists('wp_enqueue_auto_versioned_script') ) {
	function wp_enqueue_auto_versioned_script( $handle, $src, $deps = array(), $in_footer = false, $add_ver_to_filename = true ) {
		AutoVersioning::add_dependency('wp_enqueue_script', $handle, $src, $deps, $in_footer, $add_ver_to_filename);
	}
}

if ( !function_exists('wp_enqueue_auto_versioned_style') ) {
	function wp_enqueue_auto_versioned_style( $handle, $src, $deps = array(), $media = 'all', $add_ver_to_filename = true ) {
		AutoVersioning::add_dependency('wp_enqueue_style', $handle, $src, $deps, $media, $add_ver_to_filename);
	}
}