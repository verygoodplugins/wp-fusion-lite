jQuery(document).ready(function($) {

	var sent = false;

	function getParameterByName( name, url ) {
		name = name.replace(/[\[\]]/g, '\\$&');
		var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
			results = regex.exec(url);
		if (!results) return null;
		if (!results[2]) return '';
		return decodeURIComponent(results[2].replace(/\+/g, ' '));
	}

	var handleAsyncOrder = function ( event, request, settings ) {

		$( document ).unbind( 'ajaxSuccess', handleAsyncOrder );

		if ( 'success' == request.responseJSON.result && false == sent ) {

			sent = true;

			var key = getParameterByName( 'key', request.responseJSON.redirect );

			var data = {
				'action' : 'wpf_async_woocommerce_checkout',
				'key'    : key
			};

			$.post( wpf_ajax.ajaxurl, data );

		}
	}

	$('form.checkout').on( 'checkout_place_order', function( e ) {
		$( document ).on( 'ajaxSuccess', handleAsyncOrder );
	});

});