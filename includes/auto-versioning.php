<?php

if ( !class_exists('AutoVersioningSupport') ) {

/**
 * @link http://stackoverflow.com/questions/118884/what-is-an-elegant-way-to-force-browsers-to-reload-cached-css-js-files
 */
class AutoVersioningSupport {
	private static $version_in_filename = true;

	public static function add_dependency($callback, $handle, $src, $deps, $last_param, $add_ver_to_filename = true ) {
		$version = false;
		if ( !empty($src) ) {
			list($src, $version) = self::auto_version($src, $add_ver_to_filename);
		}
		call_user_func($callback, $handle, $src, $deps, $version, $last_param);
	}

	private static function auto_version($url, $add_ver_to_filename = true) {
		/** @var WP_Rewrite $wp_rewrite */
		global $wp_rewrite;

		$path = self::guess_filename_from_url($url);

		$version = false;
		if ( ($path !== null) && is_file($path) ) {
			$mtime = filemtime($path);
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

		$path = null;
		foreach($url_mappings as $root_url => $directory) {
			if ( strpos($url, $root_url) === 0 ) {
				$path = $directory . '/' . substr($url, strlen($root_url));
				//Get rid of the query string, if any.
				list($path, ) = explode('?', $path, 2);
				break;
			}
		}

		return $path;
	}

	public static function auto_version_everything($add_ver_to_filename = true) {
		self::$version_in_filename = $add_ver_to_filename;
		foreach(array('script_loader_src', 'style_loader_src') as $hook) {
			add_filter($hook, __CLASS__ . '::dependency_loader_src', 10, 1);
		}
	}

	public static function dependency_loader_src($src) {
		//Only add version info to CSS/JS files that don't already include it in the file name.
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

//AutoVersioningSupport::auto_version_everything(false);


if ( !function_exists('wp_register_auto_versioned_script') ) {
	/**
	 * Like wp_register_script(), except it auto-versions the URL on file modification time.
	 */
	function wp_register_auto_versioned_script($handle, $src, $deps = array(), $in_footer = false, $add_ver_to_filename = true) {
		AutoVersioningSupport::add_dependency('wp_register_script', $handle, $src, $deps, $in_footer, $add_ver_to_filename);
	}
}

if ( !function_exists('wp_register_auto_versioned_style') ) {
	/**
	 * Like wp_register_style(), except it auto-versions the style sheet URL on file modification time.
	 */
	function wp_register_auto_versioned_style( $handle, $src, $deps = array(), $media = 'all', $add_ver_to_filename = true ) {
		AutoVersioningSupport::add_dependency('wp_register_style', $handle, $src, $deps, $media, $add_ver_to_filename);
	}
}

if ( !function_exists('wp_enqueue_auto_versioned_script') ) {
	/**
	 * Like wp_enqueue_script(), except the script is auto-versioned based on file modification time.
	 */
	function wp_enqueue_auto_versioned_script( $handle, $src = false, $deps = array(), $in_footer = false, $add_ver_to_filename = true ) {
		AutoVersioningSupport::add_dependency('wp_enqueue_script', $handle, $src, $deps, $in_footer, $add_ver_to_filename);
	}
}

if ( !function_exists('wp_enqueue_auto_versioned_style') ) {
	/**
	 * Like wp_enqueue_style(), except the style is auto-versioned based on file modification time.
	 */
	function wp_enqueue_auto_versioned_style( $handle, $src = false, $deps = array(), $media = 'all', $add_ver_to_filename = true ) {
		AutoVersioningSupport::add_dependency('wp_enqueue_style', $handle, $src, $deps, $media, $add_ver_to_filename);
	}
}