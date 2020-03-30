jQuery(document).ready(function($){

	if(wpf_ajax.hasOwnProperty('delay')) {

		setTimeout(function() {

			if( wpf_ajax.tags !== null ) {

				var data = {
					'action'	 : 'apply_tags',
					'tags'		 : wpf_ajax.tags
				};

				$.post(wpf_ajax.ajaxurl, data);

			}

			if( wpf_ajax.remove !== null ) {

				var data = {
					'action'	 : 'remove_tags',
					'tags'		 : wpf_ajax.remove
				};

				$.post(wpf_ajax.ajaxurl, data);

			}

		}, wpf_ajax.delay);

	}

	$(document).on('mousedown', '[data-apply-tags]', function(e) {

		if( e.which <= 2 ) {

			var tags = $(this).attr('data-apply-tags');

			var data = {
				'action'	 : 'apply_tags',
				'tags'		 : tags.split(',')
			};

			$.post(wpf_ajax.ajaxurl, data);

		}
	});

	$(document).on('mousedown', '[data-remove-tags]', function(e) {

		if( e.which <= 2 ) {

			var tags = $(this).attr('data-remove-tags');

			var data = {
				'action'	 : 'remove_tags',
				'tags'		 : tags.split(',')
			};

			$.post(wpf_ajax.ajaxurl, data);

		}
	});

});