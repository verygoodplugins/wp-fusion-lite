jQuery(document).ready(function($){

	// Settings page specific functions

	if($('body').hasClass('settings_page_wpf-settings')) {

		var spinnerIcon = "data:image/svg+xml;base64,PHN2ZyB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1s%0D%0AbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHdpZHRoPSIxNCIgaGVpZ2h0%0D%0APSIxNCIgdmlld0JveD0iMCAwIDE0IDE0Ij4KPHBhdGggZD0iTTQuMTA5IDEwLjg5MXEwIDAuNDE0%0D%0ALTAuMjkzIDAuNzA3dC0wLjcwNyAwLjI5M3EtMC40MDYgMC0wLjcwMy0wLjI5N3QtMC4yOTctMC43%0D%0AMDNxMC0wLjQxNCAwLjI5My0wLjcwN3QwLjcwNy0wLjI5MyAwLjcwNyAwLjI5MyAwLjI5MyAwLjcw%0D%0AN3pNOCAxMi41cTAgMC40MTQtMC4yOTMgMC43MDd0LTAuNzA3IDAuMjkzLTAuNzA3LTAuMjkzLTAu%0D%0AMjkzLTAuNzA3IDAuMjkzLTAuNzA3IDAuNzA3LTAuMjkzIDAuNzA3IDAuMjkzIDAuMjkzIDAuNzA3%0D%0Aek0yLjUgN3EwIDAuNDE0LTAuMjkzIDAuNzA3dC0wLjcwNyAwLjI5My0wLjcwNy0wLjI5My0wLjI5%0D%0AMy0wLjcwNyAwLjI5My0wLjcwNyAwLjcwNy0wLjI5MyAwLjcwNyAwLjI5MyAwLjI5MyAwLjcwN3pN%0D%0AMTEuODkxIDEwLjg5MXEwIDAuNDA2LTAuMjk3IDAuNzAzdC0wLjcwMyAwLjI5N3EtMC40MTQgMC0w%0D%0ALjcwNy0wLjI5M3QtMC4yOTMtMC43MDcgMC4yOTMtMC43MDcgMC43MDctMC4yOTMgMC43MDcgMC4y%0D%0AOTMgMC4yOTMgMC43MDd6TTQuMzU5IDMuMTA5cTAgMC41MTYtMC4zNjcgMC44ODN0LTAuODgzIDAu%0D%0AMzY3LTAuODgzLTAuMzY3LTAuMzY3LTAuODgzIDAuMzY3LTAuODgzIDAuODgzLTAuMzY3IDAuODgz%0D%0AIDAuMzY3IDAuMzY3IDAuODgzek0xMy41IDdxMCAwLjQxNC0wLjI5MyAwLjcwN3QtMC43MDcgMC4y%0D%0AOTMtMC43MDctMC4yOTMtMC4yOTMtMC43MDcgMC4yOTMtMC43MDcgMC43MDctMC4yOTMgMC43MDcg%0D%0AMC4yOTMgMC4yOTMgMC43MDd6TTguNSAxLjVxMCAwLjYyNS0wLjQzOCAxLjA2MnQtMS4wNjIgMC40%0D%0AMzgtMS4wNjItMC40MzgtMC40MzgtMS4wNjIgMC40MzgtMS4wNjIgMS4wNjItMC40MzggMS4wNjIg%0D%0AMC40MzggMC40MzggMS4wNjJ6TTEyLjY0MSAzLjEwOXEwIDAuNzI3LTAuNTE2IDEuMjM4dC0xLjIz%0D%0ANCAwLjUxMnEtMC43MjcgMC0xLjIzOC0wLjUxMnQtMC41MTItMS4yMzhxMC0wLjcxOSAwLjUxMi0x%0D%0ALjIzNHQxLjIzOC0wLjUxNnEwLjcxOSAwIDEuMjM0IDAuNTE2dDAuNTE2IDEuMjM0eiI+PC9wYXRo%0D%0APgo8L3N2Zz4K";

		$('[data-toggle="tooltip"]').tooltip({html:true});

		$('table [data-toggle="toggle"]').change(function(){
			$(this).parent().find('label').toggleClass('collapsed');
			$(this).parents().next('.table-collapse').toggleClass('hide');
		});

		//
		// Import Users
		//

		// Callback for completion of import users
		var importUsersComplete = function(total, title) {

			$( "#import-users-btn" ).html('Import');
			$( "#import-users-btn" ).removeAttr('disabled');

			if(total > 0) {
				$('#import-output').html('<div class="updated"><p><strong>Success:</strong> ' + total + ' new contacts imported.</p></div>');
			} else {
				$('#import-output').html('<div class="error"><p><strong>Error:</strong> No new contacts found.</p></div>');
			}

		}

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
				'tag'		: $( 'select#import_users option:selected' ).val(),
				'role'		: $('#import_role').val(),
				'notify'	: $('#email_notifications').is(':checked')
			}

			startBatch(button, data, args, importUsersComplete);

		});

		$( ".delete-import-group" ).on( "click", function() {

			var button = $(this);

			button.attr('disabled', 'disabled');

			button.closest('tr').children('.import-date').children('.progress-bar').width('100');

			if(confirm("WARNING: All users from this import will be deleted, and any user content will be reassigned to your account.") == true) {

				button.closest('tr').children('.import-date').children('.progress-bar').width($('#import-groups').width());

		        var data = {
					'action'	: 'delete_import_group',
					'group_id'	: button.data('delete')
				};

				$.post(ajaxurl, data, function(response) {

					if(response.success == true) {
						button.closest('tr').remove();
					} else {
						button.closest('tr').children('.import-date').children('.progress-bar').width('0');
					}

				});

			} else {
				button.closest('tr').children('.import-date').children('.progress-bar').width('0');
				button.removeAttr('disabled');
			}

		});

		//
		// Batch process status checker
		//

		// Get status of batch process

		var getBatchStatus = function(total, title, callback = false) {

			if($('#wpf-batch-status').hasClass('hidden')) {

				$("html, body").animate({ scrollTop: 0 }, "slow");

				if(total == 0 || isNaN(total)) {

					// Handle batch processes where no results returned.
					$('#wpf-batch-status').removeClass('notice-info').addClass('notice-error');
					$('#wpf-batch-status span.title').html('');
					$('#wpf-batch-status #cancel-batch').remove();
					$('#wpf-batch-status span.status').html('<strong>No elligible ' + title + ' found.</strong> Aborting...');
					$('#wpf-batch-status').slideDown('slow').removeClass('hidden').delay(6000).slideUp('slow').addClass('hidden');
					return;

				} else {

					$('#wpf-batch-status span.status').html('Processing ' + total + ' ' + title);
					$('#wpf-batch-status').slideDown('slow').removeClass('hidden');
					$('#cancel-batch').removeAttr('disabled');

				}

			}

			var data = {
				'action'	: 'wpf_batch_status',
			};

			$.post(ajaxurl, data, function(remaining) {

				remaining = parseInt(remaining);

				if(remaining == 0 || isNaN(remaining)) {

					$('#wpf-batch-status span.title').html('');
					$('#wpf-batch-status #cancel-batch').remove();
					$('#wpf-batch-status span.status').html('<strong>Operation complete:</strong> ' + total + ' ' + title + ' processed. Terminating...');
					$('#wpf-batch-status').delay(6000).slideUp('slow').addClass('hidden');

					// Maybe trigger callback
					if(callback != false) {
						callback(total, title);
					}

					return;

				}

				setTimeout(function() {

					var completed = total - remaining;

					if(completed > 0) {
						$('#wpf-batch-status span.status').html('Processing ' + completed + ' of ' + total + ' ' + title);
					}

					getBatchStatus(total, title, callback);

				}, 2000);

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
			};

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

		var startBatch = function(button, action, args = false, callback = false) {

			button.attr('disabled', 'disabled');
			button.html('<img class="rotating" src="' + spinnerIcon + '"/><div style="margin-left:20px;">Beginning ' + action.title + ' Processing</div>');
			 
			var data = {
				'action'	: 'wpf_batch_init',
				'hook'		: action.action,
				'args'		: args
			};

			$.post(ajaxurl, data, function(total) {

				button.html('Background Task Created');
				getBatchStatus(total, action.title, callback);

			});

		}

		// Export button

		$( "#export-btn" ).on( "click", function() {

			if($('input[name=export_options]:checked').length == 0) {
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
		// Test Connection and perform initial sync
		//

		// Sync tags and custom fields

		var syncTags = function(button, total, crmContainer) {

			button.html('<img class="rotating" style="filter: invert(100%); " src="' + spinnerIcon + '"><div style="margin-left:20px;">Syncing Tags &amp; Fields</div>').addClass('btn-success');

			var data = {
				'action'	: 'wpf_sync'
			};

			$.post(ajaxurl, data, function(response) {

				if(response.success == true) {

					if( $('#connection_configured').val() == true || $('#connection_configured').val() == "1" || $('#connection_configured').val() == "true" ) {

						// If connection already configured, skip users sync
						button.html('Complete');

					} else {

						// Begin syncing users and tags
						button.html('<img class="rotating" style="filter: invert(100%);" src="' + spinnerIcon + '"/> <div style="margin-left:20px;">Loading Contact IDs and Tags</div>');

						var data = {
							'action'	: 'wpf_batch_init',
							'hook'		: 'users_sync'
						};

						$.post(ajaxurl, data, function(total) {

							//getBatchStatus(total, 'Users (syncing contact IDs and tags, no data is being sent)');

							$('#connection_configured').val(true);
							button.html('Complete');

							$('<div class="updated"><p><strong>Congratulations:</strong> you\'ve successfully established a connection to ' + $(crmContainer).attr('data-name') + ' and your tags and custom fields have been imported. Press "Save Changes" to continue.</p></div>').insertAfter('.crm-active');

						});

					}

				} else {

					$(crmContainer).find('#connection-output').html('<div class="error"><p><strong>Error: </strong>' + response.data + '</p></div>');

				}

			});

		}

		// Button handler for test connection / resync

		$( "a#test-connection" ).on( "click", function() {

			if( $(this).hasClass('btn-danger') || $(this).hasClass('btn-success') ) {
				$(this).html('<img class="rotating" style="filter: invert(100%);" src="' + spinnerIcon + '"/> Connecting');
			} else {
				$(this).html('<img class="rotating" src="' + spinnerIcon + '"/> Connecting');
			}

	        var button = $(this);
	        var crmContainer = $(this).parents('div.crm-config');
	        var crm = $(crmContainer).attr('data-crm');

	        var data = {
				'action'	: 'wpf_test_connection_' + crm
			};

			// Add the submitted data
			postFields = $(this).attr('data-post-fields').split(',');

			$(postFields).each(function(index, el) {
				data[el] = $('input#' + el).val();
			});

			// Test the CRM connection

			$.post(ajaxurl, data, function(response) {
				
				if(response.success != true) {

					$(crmContainer).find('#connection-output').html('<div class="error"><p><strong>Error: </strong>' + response.data + '</p></div>');
					button.html('Retry').removeClass('btn-success').addClass('btn-danger').removeAttr('disabled');

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

			if( button.length && ! button.hasClass('btn-success') ) {

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

		$('#wpf-settings select#crm').change(function(event) {
			
			$('#wpf-settings').find('div.crm-active').slideUp().removeClass('crm-active').addClass('hidden');
			$('#wpf-settings').find('div#' + $(this).val()).slideDown().addClass('crm-active').removeClass('hidden');

		});

		//
		// Activate / deactivate license
		//

		$( "#edd-license" ).on( "click", function() {
			$(this).html('<img class="rotating" src="' + spinnerIcon + '"/> Connecting'); 
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
					$('#license_status').val('invalid');

				} else {

					button.html('Retry').addClass('btn-danger').removeAttr('disabled');
					$('#connection-output-edd').html('<div class="error validation-error"><p>Error processing request. Debugging info below:</p></div><br/>' + response.data);


				}

			});

		});


		$('table#contact-fields select.select4-crm-field').change(function(event) {
			
			if(!$(this).val()) {

				$(this).closest('td').siblings().find('input.contact-fields-checkbox').attr('disabled');
				$(this).closest('tr').find('input.contact-fields-checkbox').prop('checked', false);
				$(this).closest('tr').removeClass('success');

			} else {

				$(this).closest('td').siblings().find('input.contact-fields-checkbox').removeAttr('disabled');
				$(this).closest('tr').find('input.contact-fields-checkbox').prop('checked', true);
				$(this).closest('tr').addClass('success');

			}

		});

		// When a checkbox is configured to unlock other options
		$('[data-unlock]').click(function() {

			var targets = $(this).data('unlock').split(" ");
			var ischecked = $(this).prop('checked');

			$.each(targets, function( index, target ) {

				$('#' + target).closest( 'tr' ).toggleClass('disabled');
			    $('#' + target).prop('disabled', !ischecked); 

			});

		});

		$( ".contact-fields-checkbox" ).on( "click", function() {
			$(this).closest( 'tr' ).toggleClass('success');
		});

		$('form').bind('submit', function() {
	    	$(this).find(':input').removeAttr('disabled');
	    });

	} // end WPF settings page listeners



});