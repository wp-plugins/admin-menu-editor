/*global wsAmeCurrentMenuItem*/

(function($) {
	var currentItem = (typeof wsAmeCurrentMenuItem !== 'undefined') ? wsAmeCurrentMenuItem : {};

	//The page heading is typically hardcoded and/or not configurable, so we need to use JS to change it.
	var customPageHeading = currentItem.hasOwnProperty('customPageHeading') ? currentItem['customPageHeading'] : null;
	var ameHideHeading = null;
	if ( customPageHeading ) {
		//Temporarily hide the heading to prevent the original text from showing up briefly
		//before being replaced when the DOM is ready (see below).
		ameHideHeading = $('<style type="text/css">.wrap > h2:first-child { visibility: hidden; }</style>')
			.appendTo('head');
	}

	jQuery(function($) {
		//Menu separators shouldn't be clickable and should have a custom class.
		$('#adminmenu')
				.find('.ws-submenu-separator')
				.closest('a').click(function() {
					return false;
				})
				.closest('li').addClass('ws-submenu-separator-wrap');

		//Replace the original page heading with the custom heading.
		if ( customPageHeading ) {
			function replaceAdminPageHeading(newText) {
				var headingText = $('.wrap > h2:first')
					.contents()
					.filter(function() {
						//Find text nodes.
						if ((this.nodeType != 3) || (!this.nodeValue)) {
							return false;
						}
						//Skip whitespace.
						return /\S/.test(this.nodeValue);
					}).get(0);

				if (headingText && headingText.nodeValue) {
					headingText.nodeValue = newText;
				}
			}

			//Normal headings have at least one tab's worth of trailing whitespace. We need to replicate that
			//to keep the page layout roughly the same.
			replaceAdminPageHeading(customPageHeading + '\t');
			ameHideHeading.remove(); //Make the heading visible.
		}
	});

})(jQuery);