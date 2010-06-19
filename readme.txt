=== Admin Menu Editor ===
Contributors: whiteshadow
Donate link: http://w-shadow.com/
Tags: admin, dashboard, menu, security, wpmu
Requires at least: 2.9.2
Tested up to: 3.0
Stable tag: 0.2

Lets you directly edit the WordPress admin menu. You can re-order, hide or rename existing menus, add custom menus and more. 

== Description ==
Admin Menu Editor lets you manually edit the Dashboard menu. You can reorder the menus, show/hide specific items, change access rights, and more. 

**Features**

* Sort menu items via drag & drop.
* Move a menu item to a different submenu via cut & paste. 
* Edit any existing menu - change the title, access rights, menu icon and so on. Note that you can't lower the required access rights, but you can change them to be more restrictive.
* Hide/show any menu or menu item. A hidden menu is invisible to all users, including administrators.
* Create custom menus that point to any part of the Dashboard. For example, you could create a new menu leading directly to the "Pending comments" page.

[Suggest new features and improvements here](http://feedback.w-shadow.com/forums/58572-admin-menu-editor)

**Known Issues**

* If you delete any of the default menus they will reappear after saving. This is by design. To get rid of a menu for good, either hide it or set it's access rights to a higher level.
* Custom menus will only show up in the final menu if the "Custom" box is checked. If some of your menu items are only visible in the editor but not the Dashboard menu itself, this is probably the reason.
* A plugin's menu that is moved to a different submenu will not work unless you also include the parent file in the "File" field. For example, if the plugin's page was originally in the "Settings" menu and had the "File" field set to "my_plugin", you'll need to change it to "options-general.php?page=my_plugin" and tick the "Custom" checkbox after moving it to a different menu.

== Installation ==

**Normal installation**

1. Download the admin-menu-editor.zip file to your local machine.
1. Unzip the file.
1. Upload the `admin-menu-editor` directory to your `/wp-content/plugins/` directory.
1. Activate the plugin through the 'Plugins' menu in WordPress.

That's it. You can access the the menu editor by going to *Settings -> Menu Editor*. The plugin will automatically load your current menu configuration the first time you run it.

**WPMU/Multi-user installation**

If you have a WPMU site, you can also install Admin Menu Editor as a global plugin. This will enable you to edit the Dashboard menu for all blogs and users at once.

1. Download the admin-menu-editor.zip file to your local machine.
1. Unzip the file.
1. Upload the `admin-menu-editor` directory to your `/wp-content/mu-plugins/` directory.
1. Move the `admin-menu-editor-mu.php` file from `admin-menu-editor/includes` to `/wp-content/mu-plugins/`.

*Note : It is currently not possible to install this plugin both as a normal plugin and as a mu-plugin on the same site.*

== Changelog ==

= 0.2 =
* Provisional WPMU support.
* Missing and unused menu items now get different icons in the menu editor.
* Fixed some visual glitches.
* Items that are not present in the default menu will only be included in the generated menu if their "Custom" flag is set. Makes perfect sense, eh? The takeaway is that you should tick the "Custom" checkbox for the menus you have created manually if you want them to show up.
* You no longer need to manually reload the page to see the changes you made to the menu. Just clicking "Save Changes" is enough.
* Added tooltips to the small flag icons that indicate that a particular menu item is hidden, user-created or otherwise special.
* Updated the readme.txt

= 0.1.6 =
* Fixed a conflict with All In One SEO Pack 1.6.10. It was caused by that plugin adding invisible sub-menus to a non-existent top-level menu.

= 0.1.5 =
* First release on wordpress.org
* Moved all images into a separate directory.
* Added a readme.txt