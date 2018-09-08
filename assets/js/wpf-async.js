
jQuery.each(wpf_async.hooks, function(index, val) {
	 
	var data = {
		'action'	: 'wpf_async',
		'index'		: index,
		'data'		: val
	};

	jQuery.post(wpf_async.ajaxurl, data);

});