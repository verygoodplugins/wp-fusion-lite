	jQuery(document).ready(function($) {

		var sent = false;

		console.log( 'WPF DEBUG: Async checkout script loaded.' );

		function getParameterByName( name, url ) {
			name = name.replace(/[\[\]]/g, '\\$&');
			var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
				results = regex.exec(url);
			if (!results) return null;
			if (!results[2]) return '';
			return decodeURIComponent(results[2].replace(/\+/g, ' '));
		}

		var handleAsyncOrder = function ( event, request, settings ) {

			console.log( 'WPF DEBUG: handleAsyncOrder running with request:' );
			console.dir( request );

			$( document ).unbind( 'ajaxSuccess', handleAsyncOrder );

			if ( 'success' == request.responseJSON.result && false == sent ) {

				sent = true;

				var key = getParameterByName( 'key', request.responseJSON.redirect );

				var data = {
					'action' : 'wpf_async_woocommerce_checkout',
					'key'    : key
				};

				$.post( wpf_async.ajaxurl, data );

			}
		}

		$('form.checkout').on( 'checkout_place_order', function( e ) {

			console.log( 'WPF DEBUG: Binding to ajaxsuccess.' );

			$( document ).on( 'ajaxSuccess', handleAsyncOrder );
		});

		// This is a fallback for cases where it got missed on placing the order, we'll send the data again on the order received page.

		// pendingOrderKey will be set by enqueue_async_checkout_script() in WPF_WooCommerce if we've reached the Order Received page
		// and the order hasn't yet been processed by WP Fusion.

		if ( typeof wpf_async.pendingOrderKey !== 'undefined' ) {

			console.log('WPF DEBUG: not undefined! proceed');

			sent = true;

			var data = {
				'action' : 'wpf_async_woocommerce_checkout',
				'key'    : wpf_async.pendingOrderKey,
			};

			console.dir( data );

			$.post( wpf_async.ajaxurl, data );

		}

	});