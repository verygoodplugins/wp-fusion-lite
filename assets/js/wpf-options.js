jQuery( document ).ready( function ( $ ) {
	// Settings page specific functions

	if ( $( 'body' ).hasClass( 'settings_page_wpf-settings' ) ) {
		$( 'table [data-toggle="toggle"]' ).on( 'change', function () {
			$( this ).parent().find( 'label' ).toggleClass( 'collapsed' );
			$( this ).parents().next( '.table-collapse' ).toggleClass( 'hide' );
		} );

		/**
		 * Preserves user's currently selected tab after page reload
		 */

		const hash = window.location.hash;

		if ( hash ) {
			$( 'ul.nav a[href="' + hash + '"]' ).tab( 'show' );
		}

		$( 'a[data-toggle="tab"]' ).on( 'shown.bs.tab', function ( e ) {
			const scrollmem = $( 'body' ).scrollTop();
			window.location.hash = e.target.hash;
			$( 'html,body' ).scrollTop( scrollmem );
		} );

		//
		// Import Users
		//

		$( '.delete-import-group' ).on( 'click', function () {
		const button = $( this );

		button.prop( 'disabled', true );

			if ( confirm( wpf_ajax.strings.deleteImportGroup ) == true ) {
				const data = {
					action: 'delete_import_group',
					_ajax_nonce: wpf_ajax.nonce,
					group_id: button.data( 'delete' ),
				};

				$.post( ajaxurl, data, function ( response ) {
					if ( response.success == true ) {
						button.closest( 'tr' ).remove();
					}
				} );
			} else {
				button.prop( 'disabled', false );
			}
		} );

		// Integrations checkboxes.

		$( '.wpf-integration input[type="checkbox"]' ).on( 'change', function () {
			if ( $( this ).is( ':checked' ) ) {
				$( this ).closest( 'a' ).addClass( 'active' );
			} else {
				$( this ).closest( 'a' ).removeClass( 'active' );
			}
		} );

		//
		// Logging
		//

		function GetURLParameter( sParam ) {
			const sPageURL = window.location.search.substring( 1 );
			const sURLVariables = sPageURL.split( '&' );

			for ( let i = 0; i < sURLVariables.length; i++ ) {
				const sParameterName = sURLVariables[ i ].split( '=' );
				if ( sParameterName[ 0 ] == sParam ) {
					return sParameterName[ 1 ];
				}
			}
		}

		if ( GetURLParameter( 'orderby' ) ) {
			$( 'ul.nav a[href="#logs"]' ).tab( 'show' );
		}

		//
		// Webhooks test
		//

		$( '#test-webhooks-btn' ).on( 'click', function ( event ) {
			event.preventDefault();

			const data = {
				url: $( this ).attr( 'data-url' ),
				key: $( 'input#access_key' ).val(),
			};

			$( this ).parent().find( 'span.label' ).remove();

			$( this )
				.parent()
				.append(
					'<span style="display: inline-block; margin-top: 10px;" class="label label-success">' +
						wpf_ajax.strings.webhooks.testing +
						'</span>'
				);

			$.post(
				'https://wpfusion.com/?action=test-wpf-webhooks',
				data,
				function ( response ) {
					$( '#test-webhooks-btn' ).parent().find( 'span.label' ).remove();

					try {
						var result = JSON.parse( response );
					} catch ( e ) {
						$( '#test-webhooks-btn' )
							.parent()
							.append(
								'<span style="display: inline-block; margin-top: 10px;" class="label label-danger">' +
									wpf_ajax.strings.webhooks.unexpectedError +
									'</span>'
							);
						return;
					}

					if ( result.status == 'success' ) {
						$( '#test-webhooks-btn' )
							.parent()
							.append(
								'<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-success">' +
									wpf_ajax.strings.webhooks.success +
									'</span>'
							);
					} else if ( result.status == 'unauthorized' ) {
						$( '#test-webhooks-btn' )
							.parent()
							.append(
								'<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-danger">' +
									wpf_ajax.strings.webhooks.unauthorized +
									'</span>'
							);
					} else if ( result.status == 'error' ) {
						$( '#test-webhooks-btn' )
							.parent()
							.append(
								'<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-danger">' +
									wpf_ajax.strings.error +
									': ' +
									result.message +
									'</span>'
							);
					} else {
						$( '#test-webhooks-btn' )
							.parent()
							.append(
								'<span id="webhook-test-result" style="display: inline-block; margin-top: 10px;" class="label label-danger">' +
									wpf_ajax.strings.webhooks.unexpectedError +
									'</span>'
							);
					}

					if ( typeof result.cloudflare !== 'undefined' ) {
						$( '#test-webhooks-btn' )
							.parent()
							.find( 'span#webhook-test-result' )
							.append( ' ' + wpf_ajax.strings.webhooks.cloudflare );
					}
				}
			);
		} );

		//
		// Test Connection and perform initial sync
		//

		// Sync tags and custom fields

		const syncTags = function ( button, total, crmContainer ) {
			button.addClass( 'button-primary' );
			button.find( 'span.dashicons' ).addClass( 'wpf-spin' );
			button.find( 'span.text' ).html( wpf_ajax.strings.syncTags );

			const data = {
				action: 'wpf_sync',
				_ajax_nonce: wpf_ajax.nonce,
			};

			$.post( ajaxurl, data, function ( response ) {
				if ( response.success == true ) {
					if ( true == wpf_ajax.connected ) {
						// If connection already configured, skip users sync
						button.find( 'span.dashicons' ).removeClass( 'wpf-spin' );
						button.find( 'span.text' ).html( 'Complete' );
					} else {
						button.find( 'span.text' ).html( wpf_ajax.strings.loadContactIDs );

						const data = {
							action: 'wpf_batch_init',
							_ajax_nonce: wpf_ajax.nonce,
							hook: 'users_sync',
						};

						$.post( ajaxurl, data, function ( total ) {
							//getBatchStatus(total, 'Users (syncing contact IDs and tags, no data is being sent)');
							wpf_ajax.connected = true;
							button.find( 'span.dashicons' ).removeClass( 'wpf-spin' );
							button.find( 'span.text' ).html( 'Complete' );

							$( crmContainer )
								.find( '#connection-output' )
								.html(
									'<div class="updated"><p>' +
										wpf_ajax.strings.connectionSuccess.replace(
											'CRMNAME',
											$( crmContainer ).attr( 'data-name' )
										) +
										'</p></div>'
								);
						} );
					}
				} else {
					$( crmContainer )
						.find( '#connection-output' )
						.html(
							'<div class="error"><p><strong>' +
								wpf_ajax.strings.error +
								': </strong>' +
								response.data +
								'</p></div>'
						);
				}
			} );
		};

		// Handle resync fields when inputs change on the setup tab.
		$( '[data-resync-fields]' ).each( function () {
			const resyncFields = $( this ).data( 'resync-fields' ).split( ',' );
			const testConnectionButton = $( this );

			$.each( resyncFields, function ( index, fieldId ) {
				$( '#' + fieldId ).on( 'change', function () {
					testConnectionButton.trigger( 'click' );
				} );
			} );
		} );

		// Button handler for test connection / resync

		$( 'a#test-connection, a#header-resync' ).on( 'click', function () {
			const button = $( this );
			const crmContainer = $( 'div.crm-config.crm-active' );

			button.addClass( 'button-primary' );
			button.find( 'span.dashicons' ).addClass( 'wpf-spin' );
			button.find( 'span.text' ).html( wpf_ajax.strings.connecting );

			const crm = $( crmContainer ).attr( 'data-crm' );

			const data = {
				action: 'wpf_test_connection_' + crm,
				_ajax_nonce: wpf_ajax.nonce,
			};

			// Add the submitted data - fixed version
			const postFields = $( crmContainer )
				.find( '#test-connection' )
				.attr( 'data-post-fields' );

			if ( postFields ) {
				postFields.split( ',' ).forEach( function ( el ) {
					const field = $( '#' + el );
					// check if field isn't disabled (i.e. completed OAuth tokens)
					if ( field.length && field.val() ) {
						if ( ! field.is( ':disabled' ) ) {
							data[ el ] = field.val();
						} else {
							data[ el ] = null; // send an empty string to avoid PHP warnings.
						}
					}
				} );
			}

			// Test the CRM connection

			$.post( ajaxurl, data, function ( response ) {
				if ( response.success != true ) {
					$( 'li#tab-setup a' ).trigger( 'click' ); // make sure we're on the Setup tab

					$( crmContainer )
						.find( '#connection-output' )
						.html(
							'<div class="error"><p><strong>' +
								wpf_ajax.strings.error +
								': </strong>' +
								response.data +
								'</p></div>'
						);

					button.find( 'span.dashicons' ).removeClass( 'wpf-spin' );
					button.find( 'span.text' ).html( 'Retry' );
				} else {
					$( crmContainer ).find( 'div.error' ).remove();

					$( '#wpf-needs-setup' ).slideUp( 400 );
					const total = parseFloat( button.attr( 'data-total-users' ) );
					syncTags( button, total, crmContainer );

					// disable the CRM select.
					$( '#wpf-settings select#crm' ).prop( 'disabled', true );

					// Hide all non-selected CRM containers so their settings don't get saved.
					$( 'div.crm-config' )
						.not( '#' + crm )
						.remove();

					// remove disabled on submit button.
					$( 'p.submit input[type="submit"]' ).prop( 'disabled', false );
				}
			} );
		} );

		//
		// Auto test connection (Zoho / HubSpot) if keys are provided but connection not configured
		//

		if ( $( '.crm-config.crm-active' ).length ) {
			const container = $( '.crm-config.crm-active' );
			const button = container.find( '#test-connection' );

			if ( button.length && false == wpf_ajax.connected ) {
				postFields = button.attr( 'data-post-fields' ).split( ',' );

				let proceed = true;

				$( postFields ).each( function ( index, el ) {
					const field = $( '#' + el );
					if (
						field.length &&
						( field.val() === null || field.val().length === 0 )
					) {
						proceed = false;
					}
				} );

				if ( proceed == true ) {
					button.trigger( 'click' );
				}
			}
		}

		// Possibily set CRM from URL parameter.
		const crmParam = GetURLParameter( 'crm' );

		if ( crmParam ) {
			const $crmSelect = $( '#wpf-settings select#crm' );

			// Check if the CRM value exists in the dropdown
			if ( $crmSelect.find( 'option[value="' + crmParam + '"]' ).length ) {
				// Set the value
				$crmSelect.val( crmParam );

				$( '#wpf-settings' )
					.find( 'div.crm-active' )
					.slideUp()
					.removeClass( 'crm-active' )
					.addClass( 'hidden' );
				$( '#wpf-settings' )
					.find( 'div#' + crmParam )
					.slideDown()
					.addClass( 'crm-active' )
					.removeClass( 'hidden' );
			}
		}

		//
		// Change CRM
		//

		$( '#wpf-settings select#crm' ).on( 'change', function ( event ) {
			$( '#wpf-settings' )
				.find( 'div.crm-active' )
				.slideUp()
				.removeClass( 'crm-active' )
				.addClass( 'hidden' );
			$( '#wpf-settings' )
				.find( 'div#' + $( this ).val() )
				.slideDown()
				.addClass( 'crm-active' )
				.removeClass( 'hidden' );

			// if the CRM name is staging, enable the save button:

			if ( $( this ).val() == 'staging' ) {
				$( 'p.submit input[type="submit"]' ).prop( 'disabled', false );
			}
		} );

		function paramReplace( name, string, value ) {
			// Find the param with regex
			// Grab the first character in the returned string (should be ? or &)
			// Replace our href string with our new value, passing on the name and delimeter
			const re = new RegExp( '[\\?&]' + name + '=([^&#]*)' ),
				delimeter = re.exec( string )[ 0 ].charAt( 0 ),
				newString = string.replace( re, delimeter + name + '=' + value );

			return newString;
		}

		//
		// Fill slug into auth link (for oauth apps with slug)
		//

		$( '#nationbuilder_slug' ).on( 'input', function ( event ) {
			if ( $( this ).val().length ) {
				const newUrl = paramReplace(
					'slug',
					$( 'a#nationbuilder-auth-btn' ).attr( 'href' ),
					$( this ).val()
				);

				$( 'a#nationbuilder-auth-btn' ).attr( 'href', newUrl );

				$( 'a#nationbuilder-auth-btn' )
					.removeClass( 'button-disabled' )
					.addClass( 'button-primary' );
			} else {
				$( 'a#nationbuilder-auth-btn' )
					.removeClass( 'button-primary' )
					.addClass( 'button-disabled' );
			}
		} );

		// Mautic ouath.
		$( '#mautic_url,#mautic_client_id,#mautic_client_secret' ).on(
			'input',
			function ( event ) {
				if (
					$( '#mautic_url' ).val().length &&
					$( '#mautic_client_id' ).val().length &&
					$( '#mautic_client_secret' ).val().length
				) {
					$( 'a#mautic-auth-btn' )
						.removeClass( 'button-disabled' )
						.addClass( 'button-primary' );
				} else {
					$( 'a#mautic-auth-btn' )
						.removeClass( 'button-primary' )
						.addClass( 'button-disabled' );
				}
			}
		);

		$( 'a#mautic-auth-btn' ).on( 'click', function ( event ) {
			event.preventDefault();
			const data = {
				action: 'wpf_save_client_credentials',
				_ajax_nonce: wpf_ajax.nonce,
				url: $( '#mautic_url' ).val(),
				client_id: $( '#mautic_client_id' ).val(),
				client_secret: $( '#mautic_client_secret' ).val(),
			};

			$.post( ajaxurl, data, function ( response ) {
				if ( response.success === true ) {
					window.location.href = response.data.url;
				}
			} );
		} );

		//
		// Dynamics 365 crm url
		//

		$( '#dynamics_365_rest_url' ).on( 'input', function ( event ) {
			const dyn_input = $( this ).val();
			let url;
			try {
				url = new URL( dyn_input );
			} catch ( _ ) {
				$( 'a#dynamics-365-auth-btn' )
					.removeClass( 'button-primary' )
					.addClass( 'button-disabled' );
				return false;
			}
			const host = url.host.split( '.' );
			if (
				host.slice( Math.max( host.length - 2, 0 ) ).join( '.' ) !=
				'dynamics.com'
			) {
				$( 'a#dynamics-365-auth-btn' )
					.removeClass( 'button-primary' )
					.addClass( 'button-disabled' );
				return false;
			}

			const newUrl = paramReplace(
				'rest_url',
				$( 'a#dynamics-365-auth-btn' ).attr( 'href' ),
				encodeURIComponent( dyn_input )
			);

			$( 'a#dynamics-365-auth-btn' ).attr( 'href', newUrl );

			$( 'a#dynamics-365-auth-btn' )
				.removeClass( 'button-disabled' )
				.addClass( 'button-primary' );
		} );

		//
		// Fill URL into link (FluentCRM, Groundhogg)
		//

		$( 'input.wp-rest-url' ).on( 'input', function ( event ) {
			const crmContainer = $( this ).closest( '.crm-config' );
			const crm = crmContainer.attr( 'data-crm' );

			if ( $( this ).val().length && $( this ).val().includes( 'https://' ) ) {
				let url = $( this ).val().trim().replace( /\/?$/, '/' );

				url =
					url +
					'wp-admin/authorize-application.php?app_name=WP+Fusion+-+' +
					wpf_ajax.sitetitle +
					'&success_url=' +
					wpf_ajax.optionsurl +
					'%26crm=' +
					crm;

				crmContainer.find( 'a.rest-auth-btn' ).attr( 'href', url );

				crmContainer
					.find( 'a.rest-auth-btn' )
					.removeClass( 'button-disabled' )
					.addClass( 'button-primary' );
			} else {
				crmContainer
					.find( 'a.rest-auth-btn' )
					.removeClass( 'button-primary' )
					.addClass( 'button-disabled' );
			}
		} );

		//
		// Salesforce topics
		//

		$( '#salesforce.crm-config input[type="radio"]' ).on(
			'change',
			function () {
				if ( $( this ).val() == 'Picklist' ) {
					$( '#wpf_options-sf_tag_picklist' )
						.closest( 'tr' )
						.removeClass( 'disabled' );
				} else {
					$( '#wpf_options-sf_tag_picklist' )
						.closest( 'tr' )
						.addClass( 'disabled' );
				}
			}
		);

		//
		// Zoho/Hubspot tags
		//

		$(
			'#zoho.crm-config input[type="radio"],#hubspot.crm-config input[type="radio"]'
		).on( 'change', function () {
			if ( $( this ).val() == 'multiselect' ) {
				$( '#zoho_multiselect_field,#hubspot_multiselect_field' )
					.closest( 'tr' )
					.removeClass( 'disabled' );
				$( '#zoho_multiselect_field,#hubspot_multiselect_field' ).prop(
					'disabled',
					false
				);
			} else {
				$( '#zoho_multiselect_field,#hubspot_multiselect_field' )
					.closest( 'tr' )
					.addClass( 'disabled' );
				$( '#zoho_multiselect_field,#hubspot_multiselect_field' ).prop(
					'disabled',
					true
				);
			}
		} );

		//
		// Activate / deactivate license
		//

		$( '#edd-license' ).on( 'click', function () {
			$( this ).html(
			'<span class="dashicons dashicons-update-alt wpf-spin"></span> Connecting'
		);
		$( this ).prop( 'disabled', true );

			const button = $( this );

			const data = {
				action: $( this ).attr( 'data-action' ),
				_ajax_nonce: wpf_ajax.nonce,
				key: $( '#license_key' ).val(),
			};

			$.post( ajaxurl, data, function ( response ) {
				if ( response.success == true && response.data == 'activated' ) {
					button
						.html( 'Deactivate License' )
						.prop( 'disabled', false )
					.attr( 'data-action', 'edd_deactivate' );
				button.addClass( 'activated' );
				$( '#license_key' ).prop( 'disabled', true );
					$( '#license_status' ).val( 'valid' );
					$( '#connection-output-edd' ).html( '' );
				} else if (
					response.success == true &&
					response.data == 'deactivated'
				) {
					button
						.html( 'Activate License' )
						.prop( 'disabled', false )
						.attr( 'data-action', 'edd_activate' );
					button.removeClass( 'activated' );
					$( '#license_key' ).prop( 'disabled', false );
					$( '#license_key' ).val( '' );
					$( '#license_status' ).val( 'invalid' );
				} else {
					$( '#license_key' ).prop( 'disabled', false );
					button.html( 'Retry' ).prop( 'disabled', false );
					$( '#connection-output-edd' ).html(
						'<div class="error validation-error"><p>' +
							wpf_ajax.strings.licenseError +
							'</p></div><br/>' +
							response.data
					);
				}
			} );
		} );

		// Dismiss notice

		$( '.wpf-notice button' ).on( 'click', function ( event ) {
			const data = {
				action: 'dismiss_wpf_notice',
				_ajax_nonce: wpf_ajax.nonce,
				id: $( this ).closest( 'div' ).attr( 'data-notice' ),
			};

			$.post( ajaxurl, data );
		} );

		// Webhooks test url

		if ( $( '#webhook-base-url' ).val() ) {
			$( '#webhook-base-url' ).attr(
				'size',
				$( '#webhook-base-url' ).val().length + 5
			);
		}

		// Add new field

		$( '#wpf-add-new-field' ).on( 'blur', function ( event ) {
			const val = $( this ).val();

			if ( val != val.toLowerCase() || val.indexOf( ' ' ) >= 0 ) {
				alert( wpf_ajax.strings.addFieldUnknown );
			}
		} );

		// FluentCRM tag format warning
		$( '#fluentcrm_tag_format' ).on( 'change', function () {
			if ( wpf_ajax.connected ) {
				if ( confirm( wpf_ajax.strings.fluentcrmTagFormatWarning ) ) {
					$( 'a#test-connection' ).trigger( 'click' );
				}
			}
		} );

		// Passwords warning

		$( '#wpf_cb_user_pass' ).on( 'change', function ( event ) {
			if ( this.checked ) {
				const r = confirm( wpf_ajax.strings.syncPasswordsWarning );

				if ( r !== true ) {
					$( this ).prop( 'checked', false );
				}
			}
		} );

		$( 'table#contact-fields-table select.select4-crm-field' ).on(
			'change',
			function ( event ) {
			if ( ! $( this ).val() ) {
				$( this )
					.closest( 'td' )
					.siblings()
					.find( 'input.contact-fields-checkbox' )
					.prop( 'disabled', true );
				$( this )
					.closest( 'tr' )
					.find( 'input.contact-fields-checkbox' )
					.prop( 'checked', false )
					.trigger( 'change' );
				$( this ).closest( 'tr' ).removeClass( 'success' );
				} else {
					$( this )
						.closest( 'td' )
						.siblings()
						.find( 'input.contact-fields-checkbox' )
						.prop( 'disabled', false );
					$( this )
						.closest( 'tr' )
						.find( 'input.contact-fields-checkbox' )
						.prop( 'checked', true )
						.trigger( 'change' );
					$( this ).closest( 'tr' ).addClass( 'success' );
				}
			}
		);

		// Enhanced unlock handler for both legacy and value-specific unlock conditions
		$( '[data-unlock], [data-unlock-conditions]' ).on( 'change', function () {
			processUnlockConditions( $( this ) );
		} );

		// Function to process unlock conditions for form fields
		function processUnlockConditions( $element ) {
			const unlockConditions = $element.data( 'unlock-conditions' );
			const currentValue = $element.val();
			const isCheckbox = $element.is( 'input[type="checkbox"]' );
			const isRadio = $element.is( 'input[type="radio"]' );

			if ( unlockConditions && typeof unlockConditions === 'object' ) {
				// New value-specific unlock conditions
				let fieldsToUnlock = [];

				if ( isCheckbox ) {
					// For checkboxes, unlock fields if checked, otherwise disable all
					if ( $element.prop( 'checked' ) ) {
						// Check if there's a 'checked' condition, otherwise use first available condition
						fieldsToUnlock =
							unlockConditions[ '1' ] ||
							unlockConditions.true ||
							unlockConditions.checked ||
							unlockConditions.default ||
							[];
					} else {
						fieldsToUnlock =
							unlockConditions[ '0' ] ||
							unlockConditions.false ||
							unlockConditions.unchecked ||
							[];
					}
				} else {
					// For selects and radios, use current value to determine fields to unlock
					fieldsToUnlock =
						unlockConditions[ currentValue ] || unlockConditions.default || [];
				}

				// Get all possible unlock fields to disable the ones not in current selection
				let allUnlockFields = [];
				$.each( unlockConditions, function ( conditionValue, fields ) {
					if ( $.isArray( fields ) && conditionValue !== 'default' ) {
						allUnlockFields = allUnlockFields.concat( fields );
					}
				} );
				if (
					unlockConditions.default &&
					$.isArray( unlockConditions.default )
				) {
					allUnlockFields = allUnlockFields.concat( unlockConditions.default );
				}
				// Remove duplicates
				allUnlockFields = allUnlockFields.filter( function ( item, index ) {
					return allUnlockFields.indexOf( item ) === index;
				} );

				// First, disable all unlock fields
				$.each( allUnlockFields, function ( index, target ) {
					$( '#' + target )
						.closest( 'tr' )
						.addClass( 'disabled' );
					$( '#' + target ).prop( 'disabled', true );
				} );

				// Then, enable only the fields that should be unlocked for current value
				$.each( fieldsToUnlock, function ( index, target ) {
					$( '#' + target )
						.closest( 'tr' )
						.removeClass( 'disabled' );
					$( '#' + target ).prop( 'disabled', false );
				} );
			} else {
				// Legacy unlock format - maintain existing behavior
				let targets = $element.data( 'unlock' );
				if ( targets ) {
					targets = targets.split( ' ' );
					let isUnlocked = false;

					if ( isCheckbox ) {
						// Checkbox: unlock if checked
						isUnlocked = $element.prop( 'checked' );
					} else if ( isRadio ) {
						// Radio: unlock if this specific radio is checked
						isUnlocked = $element.prop( 'checked' );
					} else {
						// Select: unlock if value is not empty/false
						isUnlocked =
							currentValue && currentValue !== false && currentValue !== '';
					}

					$.each( targets, function ( index, target ) {
						if ( isUnlocked ) {
							$( '#' + target )
								.closest( 'tr' )
								.removeClass( 'disabled' );
							$( '#' + target ).prop( 'disabled', false );
						} else {
							$( '#' + target )
								.closest( 'tr' )
								.addClass( 'disabled' );
							$( '#' + target ).prop( 'disabled', true );
						}
					} );
				}
			}
		}

		$( '.contact-fields-checkbox' ).on( 'click', function () {
			$( this ).closest( 'tr' ).toggleClass( 'success' );
		} );

		$( 'form' ).on( 'submit', function () {
			$( this ).find( ':input' ).prop( 'disabled', false );
		} );

		// Lite upgrade on Contact Fields

		function setProUpgradePosition() {
			const position = $( 'tbody.disabled' ).first().position();
			const lastPosition = $( 'tbody.disabled' ).last().position();

			$( '#contact-fields-pro-notice' ).css( {
				top: position.top + 44,
				height:
					lastPosition.top -
					position.top +
					$( 'tbody.disabled' ).last().height() -
					2,
			} );
		}

		if (
			$( '#contact-fields-pro-notice' ).length &&
			$( 'tbody.disabled' ).length
		) {
			setProUpgradePosition();

			$( '#tab-contact-fields' ).on( 'shown.bs.tab', function ( e ) {
				setProUpgradePosition();
			} );
		}

		function handleImportSettings( e ) {
			e.preventDefault();

			const fileInput = $( '#wpf-import-settings' )[ 0 ];
			if ( fileInput.files.length === 0 ) {
				alert( 'Please select a file to import.' );
				return;
			}

			// Extract the nonce from the button's href attribute
			const nonceMatch = $( this )
				.attr( 'href' )
				.match( /_wpnonce=([^&]*)/ );
			const nonce = nonceMatch ? nonceMatch[ 1 ] : wpf_ajax.nonce;

			const formData = new FormData();
			formData.append( 'action', 'wpf_import_settings' );
			formData.append( 'file', fileInput.files[ 0 ] );
			formData.append( 'nonce', nonce );

			// File uploads require special AJAX handling
			$.ajax( {
				url: ajaxurl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success( response ) {
					if ( response.success ) {
						alert( response.data );
						window.location.reload();
					} else {
						alert( 'Error: ' + response.data );
					}
				},
			} );
		}

		$( '#wpf-import-settings-button' ).on( 'click', handleImportSettings );

		//
		// OAuth Token Toggle Functionality
		//

		$( '.oauth-tokens-toggle' ).on( 'click', function ( e ) {
			e.preventDefault();

			const toggle = $( this );
			const container = toggle
				.closest( 'tr' )
				.find( '.oauth-tokens-container' );
			const toggleText = toggle.find( '.toggle-text' );
			const icon = toggle.find( '.dashicons' );

			// Toggle the container visibility
			container.toggleClass( 'hide' );

			// Update the toggle text and icon
			if ( container.hasClass( 'hide' ) ) {
				toggleText.text( 'Show tokens for debugging' );
				icon
					.removeClass( 'dashicons-hidden' )
					.addClass( 'dashicons-visibility' );
			} else {
				toggleText.text( 'Hide tokens' );
				icon
					.removeClass( 'dashicons-visibility' )
					.addClass( 'dashicons-hidden' );
			}
		} );

		//
		// CRM Disconnect Functionality
		//

		$( '.wpf-disconnect-crm' ).on( 'click', function ( e ) {
			e.preventDefault();

			const button = $( this );

			// Show confirmation dialog
			if (
				! confirm(
					'Are you sure you want to disconnect the CRM connection?\n\nThis will:\n• Remove all stored access tokens\n• Reset all settings\n• Require re-authorization to reconnect'
				)
			) {
				return false;
			}

			// Disable the button and show loading state
			button.prop( 'disabled', true );
			const originalText = button.text();
			button.text( 'Disconnecting...' );

			// Prepare AJAX data
			const data = {
				action: 'wpf_disconnect_crm',
				_ajax_nonce: wpf_ajax.nonce,
			};

			// Send AJAX request
			$.post( ajaxurl, data, function ( response ) {
				if ( response.success ) {
					// Show success message
					alert(
						'CRM connection disconnected successfully. The page will now reload.'
					);

					// Reload the page to show the authorization screen
					window.location.reload();
				} else {
					// Show error message
					alert(
						'Error disconnecting CRM: ' + ( response.data || 'Unknown error' )
					);

					// Re-enable the button
					button.prop( 'disabled', false );
					button.text( originalText );
				}
			} ).fail( function () {
				// Handle AJAX failure
				alert( 'Failed to disconnect CRM. Please try again.' );

				// Re-enable the button
				button.prop( 'disabled', false );
				button.text( originalText );
			} );
		} );
	} // end WPF settings page listeners
} );
