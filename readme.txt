=== Admin Menu Editor ===
Contributors: whiteshadow
Donate link: http://w-shadow.com/
Tags: admin, dashboard, menu, security
Requires at least: 2.7.0
Tested up to: 2.9
Stable tag: 0.1.6

Lets you directly edit the WordPress admin menu. You can re-order, hide or rename existing menus, add custom menus and more. 

== Description ==
Admin Menu Editor lets you manually edit the Dashboard menu. You can reorder the menus, show/hide specific items, change access rights, and more. 

**Features**

* Sort menu items via drag & drop.
* Move a menu item to a different submenu via cut & paste. 
* Edit any existing menu - change the title, access rights, menu icon and so on. Note that you can't lower the required access rights, but you can change them to be more restrictive.
* Hide/show any menu or menu item. A hidden menu is invisible to all users, including administrators.
* Create custom menus that point to any part of the Dashboard. For example, you could create a new menu leading directly to the "Pending comments" page.

**Known Issues**

* If you delete any of the default menus they will reappear after saving. This is by design.
* You can't use arbitrary URLs as menu targets because WordPress will automatically strip off the "http:/".
* A plugin's menu that is moved to a different submenu will not work unless you also include the parent file in the "File" field.

== Installation ==

To do a new installation of the plugin, please follow these steps

1. Download the admin-menu-editor.zip file to your local machine.
1. Unzip the file 
1. Upload `admin-menu-editor` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress.

That's it. You can access the the menu editor by going to *Settings -> Menu Editor*. The plugin will automatically load your current menu configuration the first time you run it.

To upgrade your installation

1. De-activate the plugin
1. Get and upload the new files (do steps 1. - 3. from "new installation" instructions)
1. Reactivate the plugin. Your settings should have been retained from the previous version.

== Changelog ==

= 0.1.5 =
* First release on wordpress.org
* Moved all images into a separate directory.
* Added a readme.txt
