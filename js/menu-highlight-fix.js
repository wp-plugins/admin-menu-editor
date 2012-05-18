jQuery(function($) {
	// parseUri 1.2.2
	// (c) Steven Levithan <stevenlevithan.com>
	// MIT License

	function parseUri (str) {
		var	o   = parseUri.options,
			m   = o.parser[o.strictMode ? "strict" : "loose"].exec(str),
			uri = {},
			i   = 14;

		while (i--) uri[o.key[i]] = m[i] || "";

		uri[o.q.name] = {};
		uri[o.key[12]].replace(o.q.parser, function ($0, $1, $2) {
			if ($1) uri[o.q.name][$1] = $2;
		});

		return uri;
	}

	parseUri.options = {
		strictMode: false,
		key: ["source","protocol","authority","userInfo","user","password","host","port","relative","path","directory","file","query","anchor"],
		q:   {
			name:   "queryKey",
			parser: /(?:^|&)([^&=]*)=?([^&]*)/g
		},
		parser: {
			strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
			loose:  /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
		}
	};

	//Find the menu item whose URL best matches the currently open page.
	var currentUri = parseUri(location.href);
	var bestMatch = {
		uri : null,
		link : null,
		matchingParams : -1,
		differentParams : 10000
	};
	$('#adminmenu li a').each(function(index, link) {
		var uri = parseUri(link.href);

		//Check for a close match - everything but query and #anchor.
		var components = ['protocol', 'host', 'port', 'user', 'password', 'path'];
		var isCloseMatch = true;
		for (var i = 0; (i < components.length) && isCloseMatch; i++) {
			isCloseMatch = isCloseMatch && (uri[components[i]] == currentUri[components[i]]);
		}

		if (!isCloseMatch) {
			return true;
		}

		//Calculate the number of matching and different query parameters.
		var matchingParams = 0, differentParams = 0, param;
		for(param in uri.queryKey) {
			if (uri.queryKey.hasOwnProperty(param)) {
				if (currentUri.queryKey.hasOwnProperty(param) && (uri.queryKey[param] == currentUri.queryKey[param])) {
					matchingParams++;
				} else {
					differentParams++;
				}
			}
		}
		for(param in currentUri.queryKey) {
			if (currentUri.queryKey.hasOwnProperty(param) && !uri.queryKey.hasOwnProperty(param)) {
				differentParams++;
			}
		}

		//Note: We're not checking for #anchor matches since it's extremely unlikely we'll ever encounter
		//a case where two menu pages are identical except for the anchor.

		if (
			(matchingParams > bestMatch.matchingParams)
				||
			(
				(matchingParams == bestMatch.matchingParams) &&
				(differentParams < bestMatch.differentParams)
			)
				||
			(
				//Prefer sub-menu links.
				(matchingParams == bestMatch.matchingParams) &&
				(differentParams == bestMatch.differentParams) &&
				!$(link).hasClass('menu-top')
			)
		) {
			bestMatch.uri = uri;
			bestMatch.link = link;
			bestMatch.matchingParams = matchingParams;
			bestMatch.differentParams = differentParams;
		}
	});

	//Highlight and/or expand the best matching menu.
	if (bestMatch.link !== null) {
		var bestMatchLink = $(bestMatch.link);
		var parentMenu = bestMatchLink.parents('li.menu-top').first();
		//console.log('Best match is: ', bestMatchLink);

		//TODO: Account for users that have set all menus to be always open.

		if (bestMatchLink.hasClass('menu-top')) {
			if (!bestMatchLink.hasClass('current')) {
				$('#adminmenu li.wp-has-current-submenu')
					.removeClass('wp-has-current-submenu wp-menu-open')
					.addClass('wp-not-current-submenu');

				bestMatchLink.addClass('current');
				parentMenu.addClass('current');
			}
		} else {
			if (!parentMenu.hasClass('wp-has-current-submenu')) {
				$('#adminmenu li.wp-has-current-submenu')
					.removeClass('wp-has-current-submenu wp-menu-open')
					.addClass('wp-not-current-submenu');

				var parentLink = parentMenu.find('> a.menu-top');
				parentMenu
					.removeClass('wp-not-current-submenu')
					.addClass('wp-has-current-submenu wp-menu-open');
				parentLink
					.removeClass('wp-not-current-submenu')
					.addClass('wp-has-current-submenu wp-menu-open');
			}

			bestMatchLink.addClass('current').parent('li').addClass('current');
		}
	}
});
