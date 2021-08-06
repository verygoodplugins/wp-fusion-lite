jQuery(document).ready(function($){

	// Settings page specific functions

	if($('body').hasClass('settings_page_wpf-settings')) {

		$('table [data-toggle="toggle"]').on( 'change', function(){
			$(this).parent().find('label').toggleClass('collapsed');
			$(this).parents().next('.table-collapse').toggleClass('hide');
		});

		/**
		 * Preserves user's currently selected tab after page reload
		 */
		
		var hash = window.location.hash;

		if ( hash ) {
			$('ul.nav a[href="' + hash + '"]').tab('show');
		}

		$('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {

			var scrollmem = $('body').scrollTop();
			window.location.hash = e.target.hash;
			$('html,body').scrollTop(scrollmem);

		});

		//
		// Import Users
		//

		// Start import
		$( "#import-users-btn" ).on( "click", function() {

			// Make sure a tag is selected
	       	if($('[name^="wpf_options[import_users]"] option:selected').length == 0) {
	       		$('[name^="wpf_options[import_users]"]').next().addClass('error');
				setTimeout( function() { $('[name^="wpf_options[import_users]"]').next().removeClass('error'); }, 1000 );
	       		return;
	       	}

	       	$(this).attr('disabled', 'disabled');
	    	var button = $(this);

	        var data = {
				'action'	: 'import_users',
				'title'		: 'Contacts'
			};

			var args = {
				'tag'		: $( 'select#wpf_options-import_users option:selected' ).val(),
				'role'		: $('#import_role').val(),
				'notify'	: $('#email_notifications').is(':checked')
			}

			startBatch(button, data, args);

		});

		$( ".delete-import-group" ).on( "click", function() {

			var button = $(this);

			button.attr('disabled', 'disabled');

			if( confirm( wpf_ajax.strings.deleteImportGroup ) == true) {

		        var data = {
					'action'	: 'delete_import_group',
					'group_id'	: button.data('delete')
				};

				$.post(ajaxurl, data, function(response) {

					if(response.success == true) {
						button.closest('tr').remove();
					}

				});

			} else {
				button.removeAttr('disabled');
			}

		});

		//
		// Batch process status checker
		//

		var completed = 0;
		var attempts = 0;

		// Handle alternate method for servers that are blocking it the normal way

		var doAltBatch = function() {

			if ( attempts == 0 ) {
				return;
			}

			console.log( 'Doing alternate batch request with completed ' + completed );

			var data = {
				'action' : 'wpf_background_process',
			};

			$.post(ajaxurl, data, function() {

				doAltBatch();

			});

		}

		// Get status of batch process

		var getBatchStatus = function( total, title ) {

			if($('#wpf-batch-status').hasClass('hidden')) {

				$("html, body").animate({ scrollTop: 0 }, "slow");

				if(total == 0 || isNaN(total)) {

					// Handle batch processes where no results returned.
					$('#wpf-batch-status').removeClass('notice-info').addClass('notice-error');
					$('#wpf-batch-status span.title').html('');
					$('#wpf-batch-status #cancel-batch').remove();
					$('#wpf-batch-status span.status').html('No eligible ' + title + ' found. Aborting...');
					$('#wpf-batch-status').removeClass('hidden').slideDown('slow').delay(6000).slideUp('slow').queue(function(){
    					$(this).addClass('hidden').dequeue();
    				});
					return;

				} else {

					$('#wpf-batch-status span.status').html( wpf_ajax.strings.processing + ' ' + total + ' ' + title);
					$('#wpf-batch-status').slideDown('slow').removeClass('hidden');
					$('#cancel-batch').removeAttr('disabled');

				}

			}

			var key = $('#wpf-batch-status').attr( 'data-key' );

			var data = {
				'action'	: 'wpf_batch_status',
				'key'       : key,
			};

			$.post(ajaxurl, data, function(response) {

				response = JSON.parse( response );

				attempts++;

				console.log( 'BATCH step:' );
				console.dir( response );

				if ( response == null ) {

					attempts = 0; // stop the alt method
					console.log('IS NULL');
					return;
				}

				var remaining = parseInt( response.remaining );
				var total = parseInt( response.total );
				var errors = parseInt( response.errors );
				var misc = '';

				if ( response.title !== false ) {
					title = response.title;
				}

				if ( errors > 0 ) {
					misc = '- ' + response.errors + ' ' + wpf_ajax.strings.batchErrorsEncountered;
				}

				if( remaining == 0 || isNaN(remaining) ) {

					attempts = 0; // stop the alt method

					$('#wpf-batch-status span.title').html('');
					$('#wpf-batch-status #cancel-batch').remove();
					$('#wpf-batch-status span.status').html( wpf_ajax.strings.batchOperationComplete );
					$('#wpf-batch-status').delay(3000).queue(function(){
    					$(this).slideUp('slow').dequeue();
    				});

					return;

				}

				// If it's not working start the alternate worker

				if ( attempts == 3 && completed == 0 ) {

					console.log( 'Background worker failing to start. Starting alternate method.' );

					misc = '- ' + wpf_ajax.strings.backgroundWorkerBlocked;

					doAltBatch();

				}

				setTimeout(function() {

					completed = total - remaining;

					if(completed > 0) {
						$('#wpf-batch-status span.status').html( wpf_ajax.strings.processing + ' ' + completed + ' / ' + total + ' ' + title + ' ' + misc);
					} else {
						$('#wpf-batch-status span.status').html( wpf_ajax.strings.processing + ' ' + remaining + ' ' + misc);
					}

					getBatchStatus(total, title);

				}, 5000);

			});

		}

		if($('#wpf-batch-status').hasClass('active')) {

			getBatchStatus($('#wpf-batch-status').attr('data-remaining'), 'records');

		}

		// Cancel batch
		$( "#cancel-batch" ).on( "click", function() {

			var button = $(this);

			if(button.attr('disabled'))
				return;

			button.attr('disabled', 'disabled').html('Cancelling');

			var data = {
				'action'	: 'wpf_batch_cancel',
				'key'       : $('#wpf-batch-status').attr( 'data-key' ),
			};

			attempts = 0; // stop the alt method

			$.post(ajaxurl, data, function() {

				$('#wpf-batch-status').slideUp('slow', function() {
					$(this).addClass('hidden');
				});

			});

		});

		//
		// Export / batch processing tools
		//

		// Start export stage

		var startBatch = function( button, action, args = false ) {

			button.attr('disabled', 'disabled');

			button.html('<span class="dashicons dashicons-update-alt wpf-spin"></span>' + wpf_ajax.strings.beginningProcessing.replace( 'ACTIONTITLE', action.title ));
			 
			var data = {
				'action'	: 'wpf_batch_init',
				'hook'		: action.action,
				'args'		: args
			};

			$.post(ajaxurl, data, function( response ) {

				var items = JSON.parse( response.data );

				console.log('START batch with items:');
				console.dir( items );

				button.html('Background Task Created');
				getBatchStatus( $(items).length, action.title );

			});

		}

		// Export button

		$( "#export-btn" ).on( "click", function() {

			if($('input[name=export_options]:checked').length == 0) {
				return;
			}

			var r = confirm( wpf_ajax.strings.startBatchWarning );

			if( r == false ) {
				return;
			}

	        var button = $(this);
	        var action = { 'action' : $('input[name=export_options]:checked').val(), 'title' : $('input[name=export_options]:checked').attr('data-title') }

			startBatch(button, action);

		});

		//
		// Logging
		//

		function GetURLParameter(sParam) {
		    var sPageURL = window.location.search.substring(1);
		    var sURLVariables = sPageURL.split('&');

		    for (var i = 0; i < sURLVariables.length; i++) {
		        var sParameterName = sURLVariables[i].split('=');
		        if (sParameterName[0] == sParam) {
		            return sParameterName[1];
		        }
		    }

		}

		if( GetURLParameter('orderby') ) {
			$('ul.nav a[href="#logs"]').tab("show")
		}

		//
		// Webhooks test
		//

		$( "#test-webhooks-btn" ).on( "click", function( event ) {

			event.preventDefault();

			var data = {
				'url' : $(this).attr('data-url'),
				'key' : $('input#access_key').val()
			};

			$(this).parent().find('span.label').remove();

			$(this).parent().append('<span style="display: inline-block; margin-top: 10px;" class="label label-success">' + wpf_ajax.strings.webhooks.testing + '</span>');

			$.post('https://wpfusion.com/?action=test-wpf-webhooks', data, function(response) {

				$( "#test-webhooks-btn" ).parent().find('span.label').remove();

				try {

					var result = JSON.parse(response);

				} catch (e) {

					$( "#test-webhooks-btn" ).parent().append('<span style="display: inline-block; margin-top: 10px;" class="label label-danger">' + wpf_ajax.strings.webhooks.unexpectedError + '</span>');
					return;

				}

				if( result.status == 'success' ) {

					$( "#test-webhooks-btn" ).parent().append('<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-success">' + wpf_ajax.strings.webhooks.success + '</span>');

				} else if( result.status == 'unauthorized' ) {

					$( "#test-webhooks-btn" ).parent().append('<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-danger">' + wpf_ajax.strings.webhooks.unauthorized + '</span>');

				} else if( result.status == 'error' ) {

					$( "#test-webhooks-btn" ).parent().append('<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-danger">' + wpf_ajax.strings.error + ': ' + result.message + '</span>');

				} else {

					$( "#test-webhooks-btn" ).parent().append('<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-danger">' + wpf_ajax.strings.webhooks.unexpectedError + '</span>');

				}

				if ( typeof( result.cloudflare ) !== "undefined" ) {
					$( "#test-webhooks-btn" ).parent().find( 'span#webhook-test-result' ).append( ' ' + wpf_ajax.strings.webhooks.cloudflare );
				}

			});

		});


		//
		// Test Connection and perform initial sync
		//

		// Sync tags and custom fields

		var syncTags = function(button, total, crmContainer) {

			button.addClass('button-primary');
			button.find('span.dashicons').addClass('wpf-spin');
			button.find('span.text').html( wpf_ajax.strings.syncTags );

			var data = {
				'action'	: 'wpf_sync'
			};

			$.post(ajaxurl, data, function(response) {

				if(response.success == true) {

					if( $('#connection_configured').val() == true || $('#connection_configured').val() == "1" || $('#connection_configured').val() == "true" ) {

						// If connection already configured, skip users sync
						button.find('span.dashicons').removeClass('wpf-spin');
						button.find('span.text').html( 'Complete' );

					} else {

						button.find('span.text').html( wpf_ajax.strings.loadContactIDs );

						var data = {
							'action'	: 'wpf_batch_init',
							'hook'		: 'users_sync'
						};

						$.post(ajaxurl, data, function(total) {

							//getBatchStatus(total, 'Users (syncing contact IDs and tags, no data is being sent)');

							$('#connection_configured').val(true);
							button.find('span.dashicons').removeClass('wpf-spin');
							button.find('span.text').html( 'Complete' );

							$('#wpf-settings-notices').html( '<div class="updated"><p>' + wpf_ajax.strings.connectionSuccess.replace( 'CRMNAME', $( crmContainer ).attr('data-name') ) + '</p></div>' );

						});

					}

				} else {

					$(crmContainer).find('#connection-output').html('<div class="error"><p><strong>' + wpf_ajax.strings.error + ': </strong>' + response.data + '</p></div>');

				}

			});

		}

		// Button handler for test connection / resync

		$( "a#test-connection, a#header-resync" ).on( "click", function() {

	        var button = $(this);
	        var crmContainer = $('div.crm-config.crm-active');

			button.addClass('button-primary');
			button.find('span.dashicons').addClass('wpf-spin');
			button.find('span.text').html( wpf_ajax.strings.connecting );

	        var crm = $(crmContainer).attr('data-crm');

	        var data = {
				'action'	: 'wpf_test_connection_' + crm
			};

			// Add the submitted data
			postFields = $(crmContainer).find('#test-connection').attr('data-post-fields').split(',');

			$(postFields).each(function(index, el) {
				data[el] = $('input#' + el).val();
			});

			// Test the CRM connection

			$.post(ajaxurl, data, function(response) {
				
				if(response.success != true) {

					$('li#tab-setup a').trigger('click'); // make sure we're on the Setup tab

					$(crmContainer).find('#connection-output').html('<div class="error"><p><strong>' + wpf_ajax.strings.error + ': </strong>' + response.data + '</p></div>');

					button.find('span.dashicons').removeClass('wpf-spin');
					button.find('span.text').html( 'Retry' );

				} else {

					$('#wpf-needs-setup').slideUp(400);
					var total = parseFloat(button.attr('data-total-users'));
					syncTags(button, total, crmContainer);

				}

			});

		});

		//
		// Auto test connection (Zoho / HubSpot) if keys are provided but connection not configured
		//

		if( $( '.crm-config.crm-active' ).length ) {

			var container = $( '.crm-config.crm-active' );
			var button = container.find( '#test-connection' );

			if( button.length && ! $('#connection_configured').val() ) {

				postFields = button.attr('data-post-fields').split(',');
				
				var proceed = true;

				$(postFields).each(function(index, el) {

					if( $('input#' + el).val().length == 0 ) {
						proceed = false;
					}

				});

				if( proceed == true ) {
					button.trigger('click');
				}

			}

		}



		//
		// Change CRM
		//

		$('#wpf-settings select#crm').on( 'change', function(event) {
			
			$('#wpf-settings').find('div.crm-active').slideUp().removeClass('crm-active').addClass('hidden');
			$('#wpf-settings').find('div#' + $(this).val()).slideDown().addClass('crm-active').removeClass('hidden');

		});

		function paramReplace(name, string, value) {
			// Find the param with regex
			// Grab the first character in the returned string (should be ? or &)
			// Replace our href string with our new value, passing on the name and delimeter
			var re = new RegExp("[\\?&]" + name + "=([^&#]*)"),
			delimeter = re.exec(string)[0].charAt(0),
			newString = string.replace(re, delimeter + name + "=" + value);

			return newString;
		}

		//
		// Fill slug into auth link (for oauth apps with slug)
		//

		$('#nationbuilder_slug').on('input', function(event) {
			
			if( $(this).val().length ) {

				var newUrl = paramReplace( 'slug', $("a#nationbuilder-auth-btn").attr('href'), $(this).val() );

				$("a#nationbuilder-auth-btn").attr('href', newUrl);

				$("a#nationbuilder-auth-btn").removeClass('button-disabled').addClass('button-primary');

			} else {

				$("a#nationbuilder-auth-btn").removeClass('button-primary').addClass('button-disabled');

			}

		});

		//
		// Fill URL into link (FluentCRM)
		//

		$('#fluentcrm_rest_url').on('input', function(event) {
			
			if( $(this).val().length && $(this).val().includes( 'https://' ) ) {

				var url = $(this).val() + '/wp-admin/authorize-application.php?app_name=WP+Fusion+-+' + wpf_ajax.sitetitle + '&success_url=' + wpf_ajax.optionsurl + '%26crm=fluentcrm';

				$("a#fluentcrm_rest-auth-btn").attr('href', url);

				$("a#fluentcrm_rest-auth-btn").removeClass('button-disabled').addClass('button-primary');

			} else {

				$("a#fluentcrm_rest-auth-btn").removeClass('button-primary').addClass('button-disabled');

			}

		});

		//
		// Activate / deactivate license
		//

		$( "#edd-license" ).on( "click", function() {
			$(this).html('<span class="dashicons dashicons-update-alt wpf-spin"></span> Connecting'); 
	        $(this).attr('disabled', 'disabled');

	        var button = $(this);

	        var data = {
				'action'	: $(this).attr('data-action'),
				'key'		: $('#license_key').val()
			};

			$.post(ajaxurl, data, function(response) {
				
				if(response.success == true && response.data == 'activated') {

					button.html('Deactivate License').removeAttr('disabled').attr('data-action', 'edd_deactivate');
					$('#license_key').attr('disabled', 'disabled');
					$('#license_status').val('valid');

				} else if(response.success == true && response.data == 'deactivated') {

					button.html('Activate License').removeAttr('disabled').attr('data-action', 'edd_activate');
					$('#license_key').removeAttr('disabled');
					$('#license_key').val('');
					$('#license_status').val('invalid');

				} else {

					$('#license_key').removeAttr('disabled');
					button.html('Retry').addClass('btn-danger').removeAttr('disabled');
					$('#connection-output-edd').html('<div class="error validation-error"><p>' + wpf_ajax.strings.licenseError + '</p></div><br/>' + response.data);


				}

			});

		});

		// Dismiss notice

		$( '.wpf-notice button' ).on( "click", function(event) {
	
	        var data = {
				'action' : 'dismiss_wpf_notice',
				'id'     : $(this).closest('div').attr('data-notice')
			};

			$.post(ajaxurl, data);


		});

		// Webhooks test url

		if ( $('#webhook-base-url').val() ) {
			$('#webhook-base-url').attr( 'size', $('#webhook-base-url').val().length + 5 );
		}

		// Add new field

		$('#wpf-add-new-field').on( "blur", function(event) {

			var val = $(this).val();

			if ( val != val.toLowerCase() || val.indexOf(' ') >= 0 ) {

				alert( wpf_ajax.strings.addFieldUnknown );

			}

		});

		// Passwords warning

		$( '#wpf_cb_user_pass' ).on( 'change', function(event) {
			
			if ( this.checked ) {

				var r = confirm( wpf_ajax.strings.syncPasswordsWarning );

				if ( r !== true ) {
					$(this).prop( 'checked', false );
				}

			}

		});


		$('table#contact-fields-table select.select4-crm-field').on( 'change', function(event) {
			
			if(!$(this).val()) {

				$(this).closest('td').siblings().find('input.contact-fields-checkbox').attr('disabled');
				$(this).closest('tr').find('input.contact-fields-checkbox').prop('checked', false).trigger('change');
				$(this).closest('tr').removeClass('success');

			} else {

				$(this).closest('td').siblings().find('input.contact-fields-checkbox').removeAttr('disabled');
				$(this).closest('tr').find('input.contact-fields-checkbox').prop('checked', true).trigger('change');
				$(this).closest('tr').addClass('success');

			}

		});

		// When a checkbox is configured to unlock other options
		$('[data-unlock]').on( 'change', function() {

			var targets = $(this).data('unlock').split(" ");
			var ischecked = $(this).prop('checked');

			if ( typeof(ischecked) == 'undefined' ) {

				// Selects

				if ( false == $(this).val() ) {
					ischecked = false;
				} else {
					ischecked = true;
				}

				$.each(targets, function( index, target ) {

					if ( ischecked ) {
						$('#' + target).closest( 'tr' ).removeClass('disabled');
					} else {
						$('#' + target).closest( 'tr' ).addClass('disabled');
					}

					$('#' + target).prop('disabled', ! ischecked );

				});

			} else {

				// Others

				$.each(targets, function( index, target ) {

					$( '[id*="' + target + '"]' ).closest( 'tr' ).toggleClass('disabled');
				    $( '[id*="' + target + '"]' ).prop('disabled', function(i, v) { return !v; });

				});

			}

		});

		$( ".contact-fields-checkbox" ).on( "click", function() {
			$(this).closest( 'tr' ).toggleClass('success');
		});

		$('form').on('submit', function() {
	    	$(this).find(':input').removeAttr('disabled');
	    });

	    // Lite upgrade on Contact Fields

	    function setProUpgradePosition() {

	    	var position = $('tbody.disabled').first().position();
	    	var lastPosition = $('tbody.disabled').last().position();

	    	$( '#contact-fields-pro-notice' ).css({
	    		top: position.top + 44,
	    		height: lastPosition.top - position.top + $('tbody.disabled').last().height() - 2,
	    	});

	    }

	    if ( $( '#contact-fields-pro-notice' ).length && $('tbody.disabled').length ) {

	    	setProUpgradePosition();

	    	$('#tab-contact-fields').on('shown.bs.tab', function (e) {
	    		setProUpgradePosition();
	    	});


	    }

	} // end WPF settings page listeners



});