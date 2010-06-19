<?php

/**
 * @author W-Shadow
 * @copyright 2008-2010
 */
 
//Make sure the needed constants are defined
if ( ! defined( 'WP_CONTENT_URL' ) )
	define( 'WP_CONTENT_URL', get_option( 'siteurl' ) . '/wp-content' );
if ( ! defined( 'WP_CONTENT_DIR' ) )
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
if ( ! defined( 'WP_PLUGIN_URL' ) )
	define( 'WP_PLUGIN_URL', WP_CONTENT_URL. '/plugins' );
if ( ! defined( 'WP_PLUGIN_DIR' ) )
	define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
	

//Load JSON functions for PHP < 5.2
if (!class_exists('Services_JSON')){
	require ABSPATH . WPINC . '/class-json.php';
}

class MenuEd_ShadowPluginFramework {
	public static $framework_version = '0.3';
	
	public $is_mu_plugin = null; //True if installed in the mu-plugins directory, false otherwise
	
	protected $options = array();
	public $option_name = ''; //should be set or overriden by the plugin
	protected $defaults = array(); //should be set or overriden by the plugin
	protected $sitewide_options = false; //WPMU only : save the setting in a site-wide option
	protected $serialize_with_json = false; //Use the JSON format for option storage 
	
	public $plugin_file = ''; //Filename of the plugin.
	public $plugin_basename = ''; //Basename of the plugin, as returned by plugin_basename().
	public $plugin_dir_url = ''; //The URL of the plugin's folder
	
	protected $magic_hooks = false; //Automagically set up hooks for all methods named "hook_[hookname]" .
	protected $magic_hook_priority = 10; //Priority for magically set hooks.
	
	protected $settings_link = ''; //If set, this will be automatically added after "Deactivate"/"Edit". 
	
  /**
   * ShadowPluginFramework::__construct()
   * Initializes the plugin and loads settings from the database.
   *
   * @param string $plugin_file Plugin's filename. Usuallly you can just use __FILE__.
   * @return void
   */
	protected function __construct( $plugin_file = ''){
		if ($plugin_file == ''){
			//Try to guess the name of the file that included this file.
			//Not implemented yet.
		}
		
		if ( is_null($this->is_mu_plugin) )
			$this->is_mu_plugin = $this->is_in_wpmu_plugin_dir($plugin_file);
		
		$this->plugin_file = $plugin_file;
		$this->plugin_basename = plugin_basename($this->plugin_file);
		
		if ( $this->is_mu_plugin ){
			$this->plugin_dir_url = WPMU_PLUGIN_URL . '/' . dirname($this->plugin_basename);
		} else {
			$this->plugin_dir_url = WP_PLUGIN_URL . '/' . dirname($this->plugin_basename);
		}
		
		/************************************
				Load settings
		************************************/
		//The provided $option_name overrides the default only if it is set to something useful
		if ( $this->option_name == '' )  {
			//Generate a unique name 
			$this->option_name = 'plugin_'.md5($this->plugin_basename);
		}
		
		//Do we need to load the plugin's settings?
		if ($this->option_name != null){
			$this->load_options();
		}
		
		/************************************
				Add the default hooks
		************************************/
		add_action('activate_'.$this->plugin_basename, array(&$this,'activate'));
		add_action('deactivate_'.$this->plugin_basename, array(&$this,'deactivate'));
		
		if ($this->settings_link)
			add_filter('plugin_action_links', array(&$this, 'plugin_action_links'), 10, 2);
		
		if ($this->magic_hooks)
			$this->set_magic_hooks();
	}
	
  /**
   * ShadowPluginFramework::load_options()
   * Loads the plugin's configuration : loads an option specified by $this->option_name into $this->options.
   *
   * @return boolean TRUE if options were loaded okay and FALSE otherwise. 
   */
	function load_options(){
		if ( $this->sitewide_options ) {
			$this->options = get_site_option($this->option_name);
		} else {
			$this->options = get_option($this->option_name);
		}
		
		if ( $this->serialize_with_json || is_string($this->options) ){
			$this->options = $this->json_decode($this->options, true);
		}
		
		if(!is_array($this->options)){
			$this->options = $this->defaults;
			return false;
		} else {
			$this->options = array_merge($this->defaults, $this->options);
			return true;
		}
	}
	
  /**
   * ShadowPluginFramework::save_options()
   * Saves the $options array to the database.
   *
   * @return void
   */
	function save_options(){
		if ($this->option_name) {
			$stored_options = $this->options;
			if ( $this->serialize_with_json ){
				$stored_options = $this->json_encode($stored_options);
			}
			
			if ( $this->sitewide_options ) {
				update_site_option($this->option_name, $stored_options);
			} else {
				update_option($this->option_name, $stored_options);
			}
		}
	}
	
	
  /**
   * Backwards fompatible json_decode.
   *
   * @param string $data
   * @param bool $assoc Decode objects as associative arrays.
   * @return string
   */
    function json_decode($data, $assoc=false){
        $flag = $assoc?SERVICES_JSON_LOOSE_TYPE:0;
        $json = new Services_JSON($flag);
        return( $json->decode($data) );
    }

  /**
   * Backwards fompatible json_encode.
   *
   * @param mixed $data
   * @return string
   */
    function json_encode($data) {
        $json = new Services_JSON();
        return( $json->encodeUnsafe($data) );
    }
    

	
  /**
   * ShadowPluginFramework::set_magic_hooks()
   * Automagically sets up hooks for all methods named "hook_[tag]". Uses the Reflection API.
   *
   * @return void
   */
	function set_magic_hooks(){
		$class = new ReflectionClass(get_class($this));
		$methods = $class->getMethods();
		
		foreach ($methods as $method){
			//Check if the method name starts with "hook_"
			if (strpos($method->name, 'hook_') === 0){
				//Get the hook's tag from the method name 
				$hook = substr($method->name, 5);
				//Add the hook. Uses add_filter because add_action is simply a wrapper of the same.
				add_filter($hook, array(&$this, $method->name), 
					$this->magic_hook_priority, $method->getNumberOfParameters());
			}
		}
		
		unset($class);
	}
	

  /**
   * ShadowPluginFramework::activate()
   * Stub function for the activation hook. Simply stores the default configuration.
   *
   * @return void
   */
	function activate(){
		$this->save_options();
	}
	
  /**
   * ShadowPluginFramework::deactivate()
   * Stub function for the deactivation hook. Does nothing. 
   *
   * @return void
   */
	function deactivate(){
		
	}
	
  /**
   * ShadowPluginFramework::plugin_action_links()
   * Adds a "Settings" link to the plugin's action links. Default handler for the 'plugin_action_links' hook. 
   *
   * @param array $links
   * @param string $file
   * @return array
   */
	function plugin_action_links($links, $file) {
        if ($file == $this->plugin_basename)
            $links[] = "<a href='" . $this->settings_link . "'>" . __('Settings') . "</a>";
        return $links;
    }
    
  /**
   * ShadowPluginFramework::uninstall()
   * Default uninstaller. Removes the plugins configuration record (if available). 
   *
   * @return void
   */
    function uninstall(){
		if ($this->option_name)
			delete_option($this->option_name);
	}
	
  /**
   * MenuEd_ShadowPluginFramework::is_in_wpmu_plugin_dir()
   * Checks if the specified file is inside the mu-plugins directory.
   *
   * @param string $filename The filename to check. Leave blank to use the current plugin's filename. 
   * @return bool
   */
	function is_in_wpmu_plugin_dir( $filename = '' ){
		if ( !defined('WPMU_PLUGIN_DIR') ) return false;
		
		if ( empty($filename) ){
			$filename = $this->plugin_file;
		}
		
		return (strpos( realpath($filename), realpath(WPMU_PLUGIN_DIR) ) !== false);
	}
	
}

?>