//(c) W-Shadow

function escapeJS (s) {
	s = s + '';
	return s.replace(/&/g,'&amp;').replace(/>/g,'&gt;').replace(/</g,'&lt;').
		  replace(/"/g,'&quot;').replace(/'/g,"&#39;").replace(/\\/g,'&#92;');
};

(function ($){
	
function outputWpMenu(menu){
	//Remove the current menu data
	$('.ws_container').remove();
	$('.ws_submenu').remove();
	
	//Display the new menu
	var i = 0;
	for (var filename in menu){
		outputTopMenu(menu[filename], filename, i);
		i++;
	}
	
	//Make the submenus sortable
	$('.ws_submenu').sortable({
		items: '> .ws_container',
		cursor: 'move',
		dropOnEmpty: true,
	});
	
	//Highlight the clicked menu item and show it's submenu
    $('.ws_item_head').click(function () {
		var p = $(this).parent();
		//Highlight the active item
		p.siblings().removeClass('ws_active');
		p.addClass('ws_active');
		//Show the appropriate submenu
		if (p.hasClass('ws_menu')) {
			$('.ws_submenu:visible').hide();
			$('#'+p.attr('submenu_id')).show();
		}
    });
    
    //Expand/collapse a menu item 
    $('.ws_edit_link').click(function () {
		var box = $(this).parent().parent().find('.ws_editbox');
		$(this).toggleClass('ws_edit_link_expanded');
		//show/hide the editbox
		if ($(this).hasClass('ws_edit_link_expanded')){
			box.show();	
		} else {
			//Make sure changes are applied before the menu is collapsed
			box.find('input').change();
			box.hide();
		}
    });
    
    
    
    //The "Default" button : Reset to default value when clicked
    $('.ws_reset_button').click(function () {
    	//Find the related input field
		var field = $(this).siblings('input');
		if (field.length > 0) {
			//Set the value to the default
			field.val(field.attr('default'));
			field.addClass('ws_input_default');
			//Trigget the change event to ensure consistency
			field.change();
		}	
	});
	
	//When a field is edited, change it's appearance if it's contents don't match the default value.
	$('.ws_edit_field input[type="text"]').change(function () {
		if ( $(this).attr('default') != $(this).val() ) {
			$(this).removeClass('ws_input_default');
		}
		
		//If the changed field is the menu title, update the header
		if ( $(this).parent().attr('field_name')=='menu_title' ){
			$(this).parent().parent().parent().find('.ws_item_title').html($(this).val()+'&nbsp;');
		}
	});
}

function outputTopMenu(menu, filename, ind){
	id = 'topmenu-'+ind;
	submenu_id = 'submenu-'+ind;
	
	//menu = menu_obj[filename];
	
	var subclass = '';
	//Apply subclasses based on the item's  state 
	if ( menu.separator /*(!menu.defaults.menu_title) && (!menu.menu_title)*/ ) {
		subclass = subclass + ' ws_menu_separator';
	}
	if (menu.missing) {
		subclass = subclass + ' ws_missing';
	}
	if (menu.hidden) {
		subclass = subclass + ' ws_hidden';
	}
	if (menu.unused) {
		subclass = subclass + ' ws_unused';
	}
	
	var s = '<div id="'+id+'" class="ws_container ws_menu '+subclass+'" submenu_id="'+submenu_id+'">'+
			'<div class="ws_item_head">'+
				'<a class="ws_edit_link"> </a>'+
				'<span class="ws_item_title">'+
					((menu.menu_title!=null)?menu.menu_title:menu.defaults.menu_title)+
				'&nbsp;</span>'+
			'</div>'+
			'<div class="ws_editbox" style="display: none;">'+buildEditboxFields(menu)+'</div>'+
		'</div>';
	
	$('#ws_menu_box').append(s);
	//Create a container for menu items, even if there are none
	$('#ws_submenu_box').append('<div class="ws_submenu" id="'+submenu_id+'" style="display:none;"></div>');
	
	//Only show menus that have items. 
	//Skip arrays (with a length) because filled menus are encoded as custom objects (). 
	if (menu.items && (typeof menu.items != 'Array')){
		var i = 0;
		for (var item_file in menu.items){
			outputMenuEntry(menu.items[item_file], i, submenu_id);
			i++;
		}
	}
}

function outputMenuEntry(entry, ind, parent){
	if (!entry.defaults) return;
	
	var subclass = '';
	//Apply subclasses based on the item's  state 
	if (entry.missing) {
		subclass = subclass + ' ws_missing';
	}
	if (entry.hidden) {
		subclass = subclass + ' ws_hidden';
	}
	if (entry.unused) {
		subclass = subclass + ' ws_unused';
	}
	
	var item = $('#'+parent).append('<div class="ws_container ws_item '+subclass+'">'+
			'<div class="ws_item_head">'+
				'<a class="ws_edit_link"> </a>'+
				'<span class="ws_item_title">'+
					((entry.menu_title!=null)?entry.menu_title:entry.defaults.menu_title)+
				'&nbsp;</span>'+
			'</div>'+
			'<div class="ws_editbox" style="display:none;">'+buildEditboxFields(entry)+'</div>'+
		'<div>');
}

function buildEditboxField(entry, field_name, field_caption){
	if (entry[field_name]===undefined) {
		return ''; //skip fields this entry doesn't have
	}

	return '<div class="ws_edit_field" field_name="'+field_name+'">' + (field_caption) + '<br />' + 
		'<input type="text" value="'+escapeJS((entry[field_name]!=null)?entry[field_name]:entry.defaults[field_name])+
		'" default=\''+escapeJS(entry.defaults[field_name])+'\''+
		' class="'+((entry[field_name]==null)?'ws_input_default':'')+'">'+
		'<span class="ws_reset_button">[default]</span></div>';
}

function buildEditboxFields(entry){
	var  fields = {
		'menu_title' : "Menu title",
		'page_title' : "Page title",
		'access_level' : 'Access level',
		'file' : 'File',
		'css_class' : 'CSS class',
		'hookname' : 'CSS ID',
		'icon_url' : 'Icon URL'
	};
	var s = '';
	
	for (var field_name in fields){
		s = s + buildEditboxField(entry, field_name, fields[field_name]);
	}
	return s;		
}

//Encode the current menu structure as JSON
function encodeMenuAsJSON(){
	var data = {}; 
	var separator_count = 0;
	var menu_position = 0;

	//Iterate over all menus
	$('#ws_menu_box .ws_menu').each(function(i) {
		
		var menu_obj = {};
		menu_obj.defaults = {};
		
		menu_position++;
		menu_obj.position = menu_position;
		menu_obj.defaults.position = menu_position; //the real default value will later overwrite this
		
		var filename = $(this).find('.ws_edit_field[field_name="file"] input').val();
		//Check if this is a separator
		if (filename==''){
			filename = 'separator_'+separator_count+'_';
			menu_obj.separator = true;
			separator_count++;
		}
		
		//Iterate over all fields of the menu
		$(this).find('.ws_edit_field').each(function() {
			//Get the name of this field
			field_name = $(this).attr('field_name');
			//Skip if unnamed
			if (!field_name) return true;
			
			input_box = $(this).find('input');
			//Save null if default used, custom value otherwise
			if (input_box.hasClass('ws_input_default')){
				menu_obj[field_name] = null;
			} else {
				menu_obj[field_name] = input_box.val();
			}
			menu_obj.defaults[field_name]=input_box.attr('default');
			
		});
		//Check if the menu is hidden
		if ($(this).hasClass('ws_hidden')){
			menu_obj['hidden'] = true;
		}
		
		menu_obj.items = {};
		
		var item_position = 0;

		//Iterate over the menu's items, if any
		$('#'+$(this).attr('submenu_id')).find('.ws_item').each(function (i) {
			var filename = $(this).find('.ws_edit_field[field_name="file"] input').val();
			
			var item = {};
			item.defaults = {};
			
			//Save the position data (probably not all that useful)
			item_position++;
			item.position = item_position;
			item.defaults.position = item_position;
			
			//Iterate over all fields of the item
			$(this).find('.ws_edit_field').each(function() {
				//Get the name of this field
				field_name = $(this).attr('field_name');
				//Skip if unnamed
				if (!field_name) return true;
				
				input_box = $(this).find('input');
				//Save null if default used, custom value otherwise
				if (input_box.hasClass('ws_input_default')){
					item[field_name] = null;
				} else {
					item[field_name] = input_box.val();
				}
				item.defaults[field_name]=input_box.attr('default');
				
			});
			//Check if the item is hidden
			if ($(this).hasClass('ws_hidden')){
				item.hidden = true;
			}
			//Save the item in the parent menu  
			menu_obj.items[filename] = item;
		});
		//*/
		
		//Attach the menu to the main struct
		data[filename] = menu_obj;
		
	});
	
	return $.toJSON(data);
}

var menu_in_clipboard = null;
var submenu_in_clipboard = null;
var item_in_clipboard = null;
var ws_paste_count = 0;

$(document).ready(function(){

	//Show the default menu
    outputWpMenu(customMenu);
    
    //Make the top menu box sortable (we only need to do this once)
	$('#ws_menu_box').sortable({
		items: '> .ws_container',
		cursor: 'move',
		dropOnEmpty: true,
	});
    
	//===== Toolbar buttons =======
	//Show/Hide menu
	$('#ws_hide_menu').click(function () {
		//Get the selected menu
		var selection = $('#ws_menu_box .ws_active');
		if (!selection.length) return;
		
		//Mark the menu as hidden
		selection.toggleClass('ws_hidden');
		//Also mark all of it's submenus as hidden/visible
		if (selection.hasClass('ws_hidden')){
			$('#' + selection.attr('submenu_id') + ' .ws_item').addClass('ws_hidden');
		} else {
			$('#' + selection.attr('submenu_id') + ' .ws_item').removeClass('ws_hidden');
		}
	});
	
	//Delete menu
	$('#ws_delete_menu').click(function () {
		//Get the selected menu
		var selection = $('#ws_menu_box .ws_active');
		if (!selection.length) return;
		
		if (confirm('Are you sure you want to delete this menu?')){
			//Delete the submenu first
			$('#' + selection.attr('submenu_id')).remove();
			//Delete the menu
			selection.remove();
		}
	});
	
	//Copy menu
	$('#ws_copy_menu').click(function () {
		//Get the selected menu
		var selection = $('#ws_menu_box .ws_active');
		if (!selection.length) return;
		
		//Store a copy in clipboard
		menu_in_clipboard = selection.clone(true); //just like that
		menu_in_clipboard.removeClass('ws_active');
		submenu_in_clipboard = $('#'+selection.attr('submenu_id')).clone(true);
	});
	
	//Cut menu
	$('#ws_cut_menu').click(function () {
		//Get the selected menu
		var selection = $('#ws_menu_box .ws_active');
		if (!selection.length) return;
		
		//Store a copy of both menu and it's submenu in clipboard
		menu_in_clipboard = selection.removeClass('ws_active').clone(true);
		menu_in_clipboard.removeClass('ws_active');
		submenu_in_clipboard = $('#'+selection.attr('submenu_id')).clone(true);
		//Remove the original menu and submenu		
		selection.remove();
		$('#'+selection.attr('submenu_id')).remove;
	});
	
	//Paste menu
	$('#ws_paste_menu').click(function () {
		//Check if anything has been copied/cut
		if (!menu_in_clipboard) return;
		//Get the selected menu
		var selection = $('#ws_menu_box .ws_active');
		
		ws_paste_count++;
		
		//Clone new objects from the virtual clipboard
		var new_menu = menu_in_clipboard.clone(true);
		var new_submenu = submenu_in_clipboard.clone(true);
		//Close submenu editboxes
		new_submenu.find('.ws_editbox').hide();
		
		//The cloned menu must have a unique file name, unless it's a separator
		if (!new_menu.hasClass('ws_menu_separator')) { 
			new_menu.find('.ws_edit_field[field_name="file"] input').val('custom_menu_'+ws_paste_count);
		}
		
		//The cloned submenu needs a unique ID (could be improved) 
		new_submenu.attr('id', 'ws-pasted-obj-'+ws_paste_count);
		new_menu.attr('submenu_id', 'ws-pasted-obj-'+ws_paste_count); 
		
		//Make the new submenu sortable
		new_submenu.sortable({
			items: '> .ws_container',
			cursor: 'move',
			dropOnEmpty: true,
		});
		
		if (selection.length > 0) {
			//If a menu is selected add the pasted item after it
			selection.after(new_menu); 
		} else {
			//Otherwise add the pasted item at the end
			$('#ws_menu_box').append(new_menu); 
		};
		
		//Insert the submenu in the box, too
		$('#ws_submenu_box').append(new_submenu);
		
		new_menu.show();
		new_submenu.hide();
	});
	
	//New menu
	$('#ws_new_menu').click(function () {
		ws_paste_count++;
		
		//This is a hack.
		//Clone another menu to use as a template
		var menu = $('#ws_menu_box .ws_menu:first').clone(true);
		//Also clone a submenu
		var submenu = $('#' + menu.attr('submenu_id')).clone(true);
		//Assign a new ID
		submenu.attr('id', 'ws-new-submenu-'+ws_paste_count);
		menu.attr('submenu_id', 'ws-new-submenu-'+ws_paste_count);
		//Remove all items from the submenu 
		submenu.empty();
		//Make the submenu sortable
		submenu.sortable({
			items: '> .ws_container',
			cursor: 'move',
			dropOnEmpty: true,
		});
		
		//Cleanup the menu's classes
		menu.attr('class','ws_container ws_menu ws_missing');
		
		
		var temp_id = 'custom_menu_'+ws_paste_count;
		//Assign a stub title
		menu.find('.ws_item_title').text('Custom Menu '+ws_paste_count);
		//All fields start out set to defaults 
		menu.find('input').attr('default','').addClass('ws_input_default');
		//Set all fields
		menu.find('.ws_edit_field[field_name="page_title"] input').val('').attr('default','');
		menu.find('.ws_edit_field[field_name="menu_title"] input').val('Custom Menu '+ws_paste_count).attr('default','Custom Menu '+ws_paste_count);
		menu.find('.ws_edit_field[field_name="access_level"] input').val('read').attr('default','read');
		menu.find('.ws_edit_field[field_name="file"] input').val(temp_id).attr('default',temp_id);
		menu.find('.ws_edit_field[field_name="css_class"] input').val('menu-top').attr('default','menu-top');
		menu.find('.ws_edit_field[field_name="icon_url"] input').val('images/generic.png').attr('default','images/generic.png');
		menu.find('.ws_edit_field[field_name="hookname"] input').val(temp_id).attr('default',temp_id);
		
		//The menus's editbox is always open
		menu.find('.ws_editbox').show();
		//Make sure the edit link is in the right state, too
		menu.find('.ws_edit_link').addClass('ws_edit_link_expanded'); 
		
		//Finally, insert the menu into the box
		$('#ws_menu_box').append(menu);
		//And insert the submenu
		$('#ws_submenu_box').append(submenu);
	});
	
	//===== Item toolbar buttons =======
	//Show/Hide item
	$('#ws_hide_item').click(function () {
		//Get the selected item
		var selection = $('#ws_submenu_box .ws_submenu:visible .ws_active');
		if (!selection.length) return;
		
		//Mark the item as hidden
		selection.toggleClass('ws_hidden');
	});
	
	//Delete menu
	$('#ws_delete_item').click(function () {
		//Get the selected menu
		var selection = $('#ws_submenu_box .ws_submenu:visible .ws_active');
		if (!selection.length) return;
		
		if (confirm('Are you sure you want to delete this menu item?')){
			//Delete the item
			selection.remove();
		}
	});
	
	//Copy item
	$('#ws_copy_item').click(function () {
		//Get the selected item
		var selection = $('#ws_submenu_box .ws_submenu:visible .ws_active');
		if (!selection.length) return;
		
		//Store a copy in clipboard
		item_in_clipboard = selection.clone(true); //just like that
		item_in_clipboard.removeClass('ws_active');
	});
	
	//Cut item
	$('#ws_cut_item').click(function () {
		//Get the selected item
		var selection = $('#ws_submenu_box .ws_submenu:visible .ws_active');
		if (!selection.length) return;
		
		//Store a the item in clipboard
		item_in_clipboard = selection.clone(true);
		item_in_clipboard.removeClass('ws_active');
		//Remove the original item		
		selection.remove();
	});
	
	//Paste item
	$('#ws_paste_item').click(function () {
		//Check if anything has been copied/cut
		if (!item_in_clipboard) return;
		//Get the selected menu
		var selection = $('#ws_submenu_box .ws_submenu:visible .ws_active');
		
		ws_paste_count++;
		
		//Clone a new object from the virtual clipboard
		var new_item = item_in_clipboard.clone(true);
		//The item's editbox is always closed
		new_item.find('.ws_editbox').hide();
		
		if (selection.length > 0) {
			//If an item is selected add the pasted item after it
			selection.after(new_item); 
		} else {
			//Otherwise add the pasted item at the end
			$('#ws_submenu_box .ws_submenu:visible').append(new_item); 
		};
		
		new_item.show();
	});
	
	//New item
	$('#ws_new_item').click(function () {
		if ($('.ws_submenu:visible').length<1) return; //abort if no submenu visible
		
		ws_paste_count++;
		
		//Clone another item to use as a template (hack)
		var menu = $('#ws_submenu_box .ws_item:first').clone(true);
		
		//Cleanup the items's classes
		menu.attr('class','ws_container ws_item ws_missing');
		
		var temp_id = 'custom_item_'+ws_paste_count;
		//Assign a stub title
		menu.find('.ws_item_title').text('Custom Item '+ws_paste_count);
		//All fields start out set to defaults 
		menu.find('input').attr('default','').addClass('ws_input_default');
		//Set all fields
		menu.find('.ws_edit_field[field_name="page_title"] input').val('').attr('default','');
		menu.find('.ws_edit_field[field_name="menu_title"] input').val('Custom Item '+ws_paste_count).attr('default','Custom Item '+ws_paste_count);
		menu.find('.ws_edit_field[field_name="access_level"] input').val('read').attr('default','read');
		menu.find('.ws_edit_field[field_name="file"] input').val(temp_id).attr('default',temp_id);
		
		//The items's editbox is always open
		menu.find('.ws_editbox').show();
		//Make sure the edit link is in the right state, too
		menu.find('.ws_edit_link').addClass('ws_edit_link_expanded'); 
		
		//Finally, insert the item into the box
		$('.ws_submenu:visible').append(menu);
	});
	
	//==============================================
	//				Main buttons
	//==============================================
	
	//Save Changes - encode the current menu as JSON and save
	$('#ws_save_menu').click(function () {
		var data = encodeMenuAsJSON();
		$('#ws_data').val(data);
		$('#ws_main_form').submit();
	});
	
	//Load default menu - load the default WordPress menu
	$('#ws_load_menu').click(function () {
		if (confirm('Are you sure you want to load the default WordPress menu into the editor?')){
			outputWpMenu(defaultMenu);
		}
	});
	
	//Reset menu - re-load the custom menu = discards any changes made by user
	$('#ws_reset_menu').click(function () {
		if (confirm('Are you sure you want to reset the custom menu? Any unsaved changes will be lost!')){
			outputWpMenu(customMenu);
		}
	});
	
  });

	
})(jQuery);