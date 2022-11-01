	jQuery(document).ready(function($) {

		var sent = false;

		// console.log( 'WPF DEBUG: Async checkout script loaded.' );

		var handleAsyncOrder = function ( event, request, settings ) {

			// console.log( 'WPF DEBUG: handleAsyncOrder running with request:' );
			// console.log( JSON.stringify( request ) );

			$( document ).unbind( 'ajaxSuccess', handleAsyncOrder );

			if ( 'success' == request.responseJSON.result && false == sent ) {

				sent = true;

				var data = {
					'action'   : 'wpf_async_woocommerce_checkout',
					'order_id' : request.responseJSON.order_id
				};

				$.post( wpf_async.ajaxurl, data );

			}
		}

		$('form.checkout').on( 'checkout_place_order', function( e ) {

			// console.log( 'WPF DEBUG: Binding to ajaxsuccess.' );

			$( document ).on( 'ajaxSuccess', handleAsyncOrder );
		});

		// This is a fallback for cases where it got missed on placing the order, we'll send the data again on the order received page.

		// pendingOrderID will be set by enqueue_async_checkout_script() in WPF_WooCommerce if we've reached the Order Received page
		// and the order hasn't yet been processed by WP Fusion.

		if ( typeof wpf_async.pendingOrderID !== 'undefined' ) {

			// console.log('WPF DEBUG: not undefined! proceed');

			sent = true;

			var data = {
				'action'   : 'wpf_async_woocommerce_checkout',
				'order_id' : wpf_async.pendingOrderID,
			};

			// console.dir( data );

			$.post( wpf_async.ajaxurl, data );

		}

	});