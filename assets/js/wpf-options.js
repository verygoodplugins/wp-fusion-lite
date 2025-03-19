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

		$( ".delete-import-group" ).on( "click", function() {

			var button = $(this);

			button.attr('disabled', 'disabled');

			if( confirm( wpf_ajax.strings.deleteImportGroup ) == true) {

		        var data = {
					'action'	  : 'delete_import_group',
					'_ajax_nonce' : wpf_ajax.nonce,
					'group_id'	  : button.data('delete')
				};

				$.post(ajaxurl, data, function(response) {

					if(response.success == true) {
						button.closest('tr').remove();
					}

				});

			} else {
				button.prop('disabled', false);
			}

		});

		// Integrations checkboxes.

		$( '.wpf-integration input[type="checkbox"]' ).on( 'change', function() {
			
			if ( $(this).is(':checked') ) {
				$(this).closest('a').addClass('active');
			} else {
				$(this).closest('a').removeClass('active');
			}

		} );


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
				'action'	  : 'wpf_sync',
				'_ajax_nonce' : wpf_ajax.nonce,
			};

			$.post(ajaxurl, data, function(response) {

				if(response.success == true) {

					if( true == wpf_ajax.connected ) {

						// If connection already configured, skip users sync
						button.find('span.dashicons').removeClass('wpf-spin');
						button.find('span.text').html( 'Complete' );

					} else {

						button.find('span.text').html( wpf_ajax.strings.loadContactIDs );

						var data = {
							'action'	: 'wpf_batch_init',
							'_ajax_nonce' : wpf_ajax.nonce,
							'hook'		: 'users_sync'
						};

						$.post(ajaxurl, data, function(total) {

							//getBatchStatus(total, 'Users (syncing contact IDs and tags, no data is being sent)');
							wpf_ajax.connected = true;
							button.find('span.dashicons').removeClass('wpf-spin');
							button.find('span.text').html( 'Complete' );

							$(crmContainer).find('#connection-output').html( '<div class="updated"><p>' + wpf_ajax.strings.connectionSuccess.replace( 'CRMNAME', $( crmContainer ).attr('data-name') ) + '</p></div>' );

						});

					}

				} else {

					$(crmContainer).find('#connection-output').html('<div class="error"><p><strong>' + wpf_ajax.strings.error + ': </strong>' + response.data + '</p></div>');

				}


			});

		}

		// Handle resync fields when inputs change on the setup tab.
		$('[data-resync-fields]').each(function() {
			var resyncFields = $(this).data('resync-fields').split(',');
			var testConnectionButton = $(this);

			$.each(resyncFields, function(index, fieldId) {
				$('#' + fieldId).on('change', function() {
					testConnectionButton.trigger('click');
				});
			});
		});

		// Button handler for test connection / resync

		$( "a#test-connection, a#header-resync" ).on( "click", function() {

	        var button = $(this);
	        var crmContainer = $('div.crm-config.crm-active');

			button.addClass('button-primary');
			button.find('span.dashicons').addClass('wpf-spin');
			button.find('span.text').html( wpf_ajax.strings.connecting );

	        var crm = $(crmContainer).attr('data-crm');

	        var data = {
				'action'	  : 'wpf_test_connection_' + crm,
				'_ajax_nonce' : wpf_ajax.nonce,
			};

			// Add the submitted data - fixed version
			var postFields = $(crmContainer).find('#test-connection').attr('data-post-fields');
			
			if(postFields) {
				postFields.split(',').forEach(function(el) {
					var field = $('#' + el);
					if(field.length && field.val()) {
						data[el] = field.val();
					}
				});
			}

			// Test the CRM connection

			$.post(ajaxurl, data, function(response) {
				
				if(response.success != true) {

					$('li#tab-setup a').trigger('click'); // make sure we're on the Setup tab

					$(crmContainer).find('#connection-output').html('<div class="error"><p><strong>' + wpf_ajax.strings.error + ': </strong>' + response.data + '</p></div>');

					button.find('span.dashicons').removeClass('wpf-spin');
					button.find('span.text').html( 'Retry' );

				} else {

					$(crmContainer).find('div.error').remove();

					$('#wpf-needs-setup').slideUp(400);
					var total = parseFloat(button.attr('data-total-users'));
					syncTags(button, total, crmContainer);

					// disable the CRM select.
					$('#wpf-settings select#crm').prop('disabled', true);

					// Hide all non-selected CRM containers so their settings don't get saved.
					$('div.crm-config').not('#' + crm).remove();

					// remove disabled on submit button.
					$('p.submit input[type="submit"]').prop('disabled', false);

				}

			});

		});

		//
		// Auto test connection (Zoho / HubSpot) if keys are provided but connection not configured
		//

		if( $( '.crm-config.crm-active' ).length ) {

			var container = $( '.crm-config.crm-active' );
			var button = container.find( '#test-connection' );

			if( button.length && false == wpf_ajax.connected ) {

				postFields = button.attr('data-post-fields').split(',');
				
				var proceed = true;

				$(postFields).each(function(index, el) {
					var field = $('#' + el);
					if (field.length && (field.val() === null || field.val().length === 0)) {
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

			// if the CRM name is staging, enable the save button:

			if ( $(this).val() == 'staging' ) {
				$('p.submit input[type="submit"]').prop('disabled', false);
			};

		});

		function paramReplace( name, string, value ) {
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

		// Mautic ouath.
		$('#mautic_url,#mautic_client_id,#mautic_client_secret').on('input', function(event) {
			
			if($('#mautic_url').val().length &&
				$('#mautic_client_id').val().length &&
				$('#mautic_client_secret').val().length 
			){
				$("a#mautic-auth-btn").removeClass('button-disabled').addClass('button-primary');
			}else{
				$("a#mautic-auth-btn").removeClass('button-primary').addClass('button-disabled');
			}

		});


		$('a#mautic-auth-btn').on('click', function(event) {
			event.preventDefault();
	        var data = {
				'action'	  : 'wpf_save_client_credentials',
				'_ajax_nonce' : wpf_ajax.nonce,
				'url'		  : $('#mautic_url').val(),
				'client_id'		  : $('#mautic_client_id').val(),
				'client_secret'		  : $('#mautic_client_secret').val()
			};

			$.post(ajaxurl, data, function(response) {
				if(response.success === true){
					window.location.href = response.data.url;
				}
			});
		});

		//
		// Dynamics 365 crm url
		//

		$('#dynamics_365_rest_url').on('input', function(event) {
			let dyn_input = $(this).val();
			let url;
			try {
				url = new URL(dyn_input);
			} catch (_) {
				$("a#dynamics-365-auth-btn").removeClass('button-primary').addClass('button-disabled');
				return false;  
			}
			let host = url.host.split('.');
			if(host.slice(Math.max(host.length - 2, 0)).join('.') != 'dynamics.com'){
				$("a#dynamics-365-auth-btn").removeClass('button-primary').addClass('button-disabled');
				return false;
			}
			
			let newUrl = paramReplace( 'rest_url', $("a#dynamics-365-auth-btn").attr('href'), encodeURIComponent( dyn_input ) );

			$("a#dynamics-365-auth-btn").attr('href', newUrl);

			$("a#dynamics-365-auth-btn").removeClass('button-disabled').addClass('button-primary');

		});


		//
		// Fill URL into link (FluentCRM, Groundhogg)
		//

		$('input.wp-rest-url').on('input', function(event) {


			var crmContainer = $(this).closest('.crm-config');
			var crm = crmContainer.attr('data-crm');
			
			if( $(this).val().length && $(this).val().includes( 'https://' ) ) {

				var url = $(this).val().trim().replace(/\/?$/, '/');

				url = url + 'wp-admin/authorize-application.php?app_name=WP+Fusion+-+' + wpf_ajax.sitetitle + '&success_url=' + wpf_ajax.optionsurl + '%26crm=' + crm;

				crmContainer.find("a.rest-auth-btn").attr('href', url);

				crmContainer.find("a.rest-auth-btn").removeClass('button-disabled').addClass('button-primary');

			} else {

				crmContainer.find("a.rest-auth-btn").removeClass('button-primary').addClass('button-disabled');

			}

		});

		//
		// Salesforce topics
		// 

		$('#salesforce.crm-config input[type="radio"]').on('change', function() {

			if ( $(this).val() == 'Picklist' ) {

				$( '#wpf_options-sf_tag_picklist' ).closest( 'tr' ).removeClass( 'disabled' );

			} else {

				$( '#wpf_options-sf_tag_picklist' ).closest( 'tr' ).addClass( 'disabled' );

			}

		});


		//
		// Zoho/Hubspot tags
		// 

		$('#zoho.crm-config input[type="radio"],#hubspot.crm-config input[type="radio"]').on('change', function() {

			if ( $(this).val() == 'multiselect' ) {
		
				$( '#zoho_multiselect_field,#hubspot_multiselect_field' ).closest( 'tr' ).removeClass( 'disabled' );
				$( '#zoho_multiselect_field,#hubspot_multiselect_field' ).prop( 'disabled', false );

			} else {

				$( '#zoho_multiselect_field,#hubspot_multiselect_field' ).closest( 'tr' ).addClass( 'disabled' );
				$( '#zoho_multiselect_field,#hubspot_multiselect_field' ).prop( 'disabled', true );

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
				'action'	  : $(this).attr('data-action'),
				'_ajax_nonce' : wpf_ajax.nonce,
				'key'		  : $('#license_key').val()
			};

			$.post(ajaxurl, data, function(response) {
				
				if(response.success == true && response.data == 'activated') {

					button.html('Deactivate License').prop('disabled', false).attr('data-action', 'edd_deactivate');
					button.addClass('activated');
					$('#license_key').attr('disabled', 'disabled');
					$('#license_status').val('valid');
					$('#connection-output-edd').html('');

				} else if(response.success == true && response.data == 'deactivated') {

					button.html('Activate License').prop('disabled', false).attr('data-action', 'edd_activate');
					button.removeClass('activated');
					$('#license_key').prop('disabled', false);
					$('#license_key').val('');
					$('#license_status').val('invalid');

				} else {

					$('#license_key').prop('disabled', false);
					button.html('Retry').prop('disabled', false);
					$('#connection-output-edd').html('<div class="error validation-error"><p>' + wpf_ajax.strings.licenseError + '</p></div><br/>' + response.data);


				}

			});

		});

		// Dismiss notice

		$( '.wpf-notice button' ).on( "click", function(event) {
	
	        var data = {
				'action'      : 'dismiss_wpf_notice',
				'_ajax_nonce' : wpf_ajax.nonce,
				'id'          : $(this).closest('div').attr('data-notice')
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

		// FluentCRM tag format warning
		$('#fluentcrm_tag_format').on('change', function() {
			if(wpf_ajax.connected) {
				if(confirm(wpf_ajax.strings.fluentcrmTagFormatWarning)) {
					$('a#test-connection').trigger('click');
				}
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

				$(this).closest('td').siblings().find('input.contact-fields-checkbox').prop('disabled', false);
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
	    	$(this).find(':input').prop('disabled', false);
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