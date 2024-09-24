
//
// Select4 Fields
//

// Tags select

function initializeTagsSelect(target) {

	if ( '#' === target ) {
		return; // Fixes the widgets editor
	}

	if( typeof(wpf_admin) === 'undefined' ) {
		return;
	}

	if( jQuery( target + " select.select4-wpf-tags").length && wpf_admin.tagSelect4 == 1 ) {

		jQuery( target + " select.select4-wpf-tags").each(function(index, el) {

			if ( jQuery(this).data('select4') ) {
				return;
			}

			// See if we need to do duplication prevention
			var noDupes = jQuery(this).attr('data-no-dupes');

			// Disable options in no-dupes fields
			if( typeof noDupes !== 'undefined' ) {

				// Escaping for IDs with subfields in brackets
				noDupes = noDupes.replace('[', '\\[');
				noDupes = noDupes.replace(']', '\\]');

				noDupes = noDupes.split(',');

				var selectedValues = jQuery(el).val();

				if( selectedValues != null ) {

					jQuery.each(noDupes, function(index, targetId) {

						for (var i = 0; i < selectedValues.length; i++) {
							var text = selectedValues[i].replace("\'", "\\\'");
							jQuery('#' + targetId).find("option[value='" + text + "']").attr('disabled', true);
						}

					});
				}
			}

			var limit = jQuery(this).attr('data-limit');

			if( ! limit || limit.length == 0) {
				limit = -1;
			}

			// Start building up the select args

			var selectArgs = {
				multiple : true,
				minimumResultsForSearch: -1,
				maximumSelectionLength: limit,
				language: {
					maximumSelected: function (a) {
						var b = wpf_admin.strings.maxSelected.replace( 'MAX', a.maximum );
						return 1 != a.maximum && (b += "s"), b;
					},
				},
				escapeMarkup: function (markup) {
					return markup;
				},
			}


			if( jQuery.inArray('add_tags', wpf_admin.crm_supports) > -1 ) {
				// For CRMs that use strings as tags / they don't need to be created before they can be used.

				selectArgs.tags = true;
				
				selectArgs.insertTag = function(data, tag){
					tag.text = tag.text + " (" + wpf_admin.strings.addNew + ")"
					data.push(tag);
				};
				
			} else if( jQuery.inArray('add_tags_api', wpf_admin.crm_supports) > -1 ) {
				// For CRMs that support adding new tags via API
				
				selectArgs.tags = true;
				selectArgs.insertTag = function(data, tag){
					tag.text = tag.text + " (" + wpf_admin.strings.addNew + ")"
					tag.fromAPI = true;
					data.push(tag);
				};

			} else {
				// From CRMs that need to resync

				selectArgs.language.noResults = function() {

					var jQueryresync = jQuery("<a id='wpf-select4-tags-resync'>" + wpf_admin.strings.noResults + "</a>");

					jQueryresync.on('mouseup', function (evt) {
						evt.stopPropagation();
						jQuery(this).hide();
						jQuery(this).after('<span id="wpf-select4-tags-loading"><i class="fa fa-spinner fa-spin"></i> ' + wpf_admin.strings.loadingTags + '</span>');

						var data = {
							'action'	  : 'sync_tags',
							'_ajax_nonce' : wpf_admin.nonce,
						}

						jQuery.post(ajaxurl, data, function(response) {

							jQuery('#wpf-select4-tags-loading').remove();
							jQueryresync.after('<span>' + wpf_admin.strings.resyncComplete + '</span>');

							jQuery(el).append(response);
							jQuery(el).trigger('change');

						});

					});

					return jQueryresync;
				};

			}

			if ( typeof jQuery(this).attr('data-lazy-load') !== 'undefined' ) {

				// Lazy loading if more than 1000 tags

				selectArgs.minimumInputLength = 3;

				selectArgs.ajax = {
	    			url: ajaxurl,
	    			dataType: 'json',
	    			type: 'POST',
	    			delay: 250,
	    			cache: true,
	    			data: function( params ) {
	    				var query = {
							search: params.term,
							action: 'wpf_search_available_tags',
							_ajax_nonce: wpf_admin.nonce,
						}

						return query;
	    			}
	    		}
	    	}

			// Initialize the select4!

			jQuery(this).select4( selectArgs );

			jQuery(this).on('select4:select', function(e) {

				// Check if a new tag from API is added.

				if( e.params.data.fromAPI ) {

					var that = jQuery(this);
					that.next().find( 'span.select4-selection' ).append('<i id="wpf-select4-tags-loading" class="fa fa-spinner fa-spin"></i>');

					var data = {
						'action'	  : 'add_tags_api',
						'_ajax_nonce' : wpf_admin.nonce,
						'tag'         : e.params.data,
					}
					
					jQuery.post(ajaxurl, data, function(response) {

						jQuery('#wpf-select4-tags-loading').remove();

						if( response.success === true ){

							that.find( '[value="'+e.params.data.id+'"]' ).replaceWith('<option selected value="' + response.data.tag_id + '">' + response.data.tag_name + '</option>');

						} else {

							alert( 'Error adding tag: ' + response.data[0].message );
							that.find('[value="' + e.params.data.id + '"]').remove();
						}

					});
				}
			});;

			if( jQuery.inArray('add_tags', wpf_admin.crm_supports) > -1 || jQuery.inArray('add_tags_api', wpf_admin.crm_supports) > -1 ) {

				jQuery(this).on('select4:open', function(e) {
					let selectField = jQuery(this).data('select4');
					selectField.$selection.find('input.select4-search__field').attr('placeholder', function() {
						return jQuery(this).attr('placeholder') + ' ' + wpf_admin.strings.addNewTags;
					});				
				});
			}

			// Prevent same tag in multiple selects if specified

			if( typeof noDupes !== 'undefined' ) {
				
				// Disable tags in linked fields
				jQuery(this).on('select4:select', function(e) {

					var selectedValues = jQuery(this).select4('data');

					jQuery.each(noDupes, function(index, targetId) {

						for (var i = 0; i < selectedValues.length; i++) {
							jQuery('#' + targetId).find("option[value='" + selectedValues[i].id + "']").attr('disabled', true);
						}

					});

					// Reinitialize with other options enabled (this is a mess)
					initializeTagsSelect('#wpbody');

				});

				// Re-enable when tag removed
				jQuery(this).on('select4:unselect', function(e) {

					jQuery.each(noDupes, function(index, targetId) {
						jQuery('#' + targetId).find("option[value='" + e.params.data.id + "']").attr('disabled', false);
					});

					// Reinitialize with other options enabled (this is a mess)
					initializeTagsSelect('#wpbody');

				});

			}

		});
		
	}

}

/*
* Hacky fix for a bug in select2 with jQuery 3.6.0's new nested-focus "protection"
* see: https://github.com/select2/select2/issues/5993
* see: https://github.com/jquery/jquery/issues/4382
*
* TODO: Recheck with the select2 GH issue and remove once this is fixed on their side
*/

jQuery(document).on('select4:open', (event) => {
	const searchField = document.querySelector(
		`.select4-search__field[aria-controls="select4-${event.target.getAttribute('data-select4-id')}-results"]`,
	);
	if (searchField) {
		searchField.focus();
	}
});

jQuery(document).ready(function($){

	if( typeof(wpf_admin) !== undefined ) {
		initializeTagsSelect('#wpbody');
	}

	function setTagLabels( translation, text, domain ) {
		if ( typeof domain === 'string' && domain.includes( 'wp-fusion-lite' ) ) {
			if( wpf_admin.tag_type ){
				if( translation.includes( 'tag' ) ){
					return translation.replace( 'tag', wpf_admin.tag_type );
				}

				if( translation.includes( 'Tag' ) ){
					return translation.replace( 'Tag', wpf_admin.tag_type.charAt(0).toUpperCase() + wpf_admin.tag_type.slice(1) );
				}
			}
		}

		return translation;
	}

	wp.hooks.addFilter(
		'i18n.gettext',
		'wp-fusion-media-tools/overwrite-tag-label',
		setTagLabels
	);

    function formatSmall (state) {
        if (!state.id) {
            return state.text;
        }
        var match = state.text.match(/(.*?)(\s+\(.*\))?$/);
        if (!match) {
            return state.text;
        }
        if (match[2]) {
            return match[1] + '<small>' + match[2] + '</small>';
        } else {
            return state.text;
        }
    }


	// Standard select

	if( $("select.select4").length ) {

		$("select.select4").select4({
			minimumResultsForSearch: -1,
			allowClear: true,
			templateResult: formatSmall, // used when displaying option in the dropdown
			templateSelection: formatSmall, // used when item is selected
			escapeMarkup: function (markup) {
				return markup;
			},
		});
		
	}

	// Search select

	if( $("select.select4-search").length ) {

		$("select.select4-search").select4({
			allowClear: true
		});
		
	}

	// Redirect select

	if( $("select.select4-select-page").length ) {
		var select_page_options  = {
			allowClear: true,
			minimumInputLength: 3,
			tags : true,
			createTag: function (params) {

				var term = $.trim( params.term );
			
				if ( term === '' ) {
					return null;
				}

				// Regular expression to check if the input text looks like a URL
				var urlPattern = /^(https?:\/\/)?(www\.)?[-a-zA-Z0-9@:%._\+~#=]{2,256}\.[a-z]{2,6}\b([-a-zA-Z0-9@:%_\+.~#?&//=]*)/;

				// Check if the input text looks like a URL
				if (urlPattern.test(term)) {
					// Validate that the URL starts with https:// or http://
					if (!/^https?:\/\//i.test(term)) {
						// Prepend https:// to the URL if it doesn't start with https:// or http://
						term = 'https://' + term;
					}
				} else {
					return null;
				}

				return {
				  id: term,
				  text: term + ' (add URL)',
				  newTag: true // add additional parameters
				}
			},
			ajax: {
    			url: ajaxurl,
    			dataType: 'json',
    			type: 'POST',
    			delay: 250,
    			cache: true,
    			data: function( params ) {
    				var query = {
						search: params.term,
						action: 'wpf_get_redirect_options'
					}

					return query;
    			}
    		}
		};

		$("select.select4-select-page").select4(select_page_options);
		
	}

	// Tooltips

	if( typeof $.fn.tipTip !== 'undefined' ) {

		$( '.wpf-tip.wpf-tip-right' ).tipTip({
			'attribute': 'data-tip',
			'fadeIn': 50,
			'fadeOut': 50,
			'delay': 200,
			'defaultPosition': 'right',
		});

		$( '.wpf-tip.wpf-tip-bottom' ).tipTip({
			'attribute': 'data-tip',
			'fadeIn': 50,
			'fadeOut': 50,
			'delay': 200,
			'defaultPosition': 'bottom',
		});
	}


	// Logs User dropdown
	if( $("select.select4-users-log").length ) {

		$("select.select4-users-log").select4({
			allowClear: true,
			placeholder: "All users",
			minimumInputLength: 3,
			width: '225px',
			ajax: {
    			url: ajaxurl,
    			dataType: 'json',
    			type: 'POST',
    			delay: 250,
    			cache: true,
    			data: function( params ) {
    				var query = {
						search: params.term,
						action: 'wpf_get_log_users',
						_ajax_nonce: wpf_admin.nonce
					}

					return query;
    			}
    		}
		});
		
	}


	// CRM field select

	function initializeCRMFieldSelect() {

		if( $("select.select4-crm-field").length && $("select.select4-crm-field").length <= 300 && wpf_admin.fieldSelect4 == 1 ) {

			function matcher (params, data) {
				// Always return the object if there is nothing to compare
				if ($.trim(params.term) === '') {
					return data;
				}

				var original = data.text.toUpperCase();
				var term = params.term.toUpperCase();

				// Check if the text contains the term
				if (original.indexOf(term) > -1) {
					return data;
				}

				// Do a recursive check for options with children
				if (data.children && data.children.length > 0) {
					// Clone the data object if there are children
					// This is required as we modify the object to remove any non-matches
					var match = $.extend(true, {}, data);

					// Check each child of the option
					for (var c = data.children.length - 1; c >= 0; c--) {
						var child = data.children[c];

						var matches = matcher(params, child);

						// If there wasn't a match, remove the object in the array
						if (matches == null) {
							match.children.splice(c, 1);
						}
					}

					// If any children matched, return the new object
					if (match.children.length > 0) {
						return match;
					}

					// If there were no matching children, check just the plain object
					return matcher(params, match);
				}

				// If it doesn't contain the term, don't return anything
				return null;
			}


			if($.inArray('add_fields', wpf_admin.crm_supports) > -1) {

				// For CRMs that support adding new custom fields via API

				$("select.select4-crm-field").select4({
					allowClear: true,
					tags: true,
					multiple: false,
					matcher: matcher,
						createTag: function(params) {

							var term = $.trim(params.term);

							if(term === "") { return null; }

							var optionsMatch = false;

							this.$element.find("option").each(function() {
									if(this.label.toLowerCase().indexOf(term.toLowerCase()) > -1) {
										optionsMatch = true;
									}
							});

							if(optionsMatch) {
									return null;
							}

							return {id: term, text: term + ' (new)'};
						},
					escapeMarkup: function (markup) {
						return markup;
					},
				});

			} else {

				// For CRMs that need to resync

				var $element = $("select.select4-crm-field").select4({
					allowClear: true,
					multiple: false,
					language: {
						noResults: function() {
							var $resync = $("<a id='wpf-select4-tags-resync'>" + wpf_admin.strings.noResults + "</a>");

							$resync.on('mouseup', function (evt) {
								evt.stopPropagation();
								$(this).hide();
								$(this).after('<span id="wpf-select4-tags-loading"><i class="fa fa-spinner fa-spin"></i> ' + wpf_admin.strings.loadingFields + '</span>');

								var data = {
									'action'	  : 'sync_custom_fields',
									'_ajax_nonce' : wpf_admin.nonce,
								}

								$.post(ajaxurl, data, function(response) {

									$('#wpf-select4-tags-loading').remove();
									$resync.after('<span>' + wpf_admin.strings.resyncComplete + '</span>');

									$element.append(response);
									$element.trigger('change');

								});

							});

							return $resync;
						}
					},
					escapeMarkup: function (markup) {
						return markup;
					},
				});

			}

		}

	}

	if( typeof(wpf_admin) !== 'undefined' ) {

		initializeCRMFieldSelect();

	}

	//
	// Meta boxes
	//

	$('#wpf-meta [data-unlock], .wpf-meta [data-unlock]').on( "click", function() {

		var targets = $(this).data('unlock').split(" ");
		var ischecked = $(this).prop('checked');

		$.each(targets, function( index, target ) {
			$('label[for="' + target + '"]').toggleClass('disabled');
			$('#' + target).prop('disabled', !ischecked); 
		});

	});

	$('#wpf-meta select').on( "change", function() {

		if ( 0 == $( '#wpf-settings-allow_tags' ).find('option:selected').length && 0 == $( '#wpf-settings-allow_tags_all' ).find('option:selected').length ) {

			$('#wpf-check-tags').attr( 'disabled', true );
			$('#wpf-check-tags').prop( 'checked', false );
			$('#wpf-check-tags-label').addClass( 'disabled' );

		} else {

			$('#wpf-check-tags').attr( 'disabled', false );
			$('#wpf-check-tags-label').removeClass( 'disabled' );

		}

	} );

	// Users list

	$( 'div.wpf-users-tags' ).on( "click", function() {
		$(this).addClass( 'expanded' )
	});

	// Warn on linked tag change

	$('select[name*="tag_link"], select[name*="link_tag"]').on( 'change', function(event) {

		$(this).next( 'span.select4' ).next( '.wpf-tags-notice' ).remove(); // Remove if there is already one

		var selected = $('option:selected', this ).text();

		if ( selected.length == 0 ) {
			return;
		}

		var content = '<div class="notice notice-warning wpf-tags-notice"><p>' + wpf_admin.strings.linkedTagChanged.replaceAll( 'TAGNAME', selected ) + '</p></div>';

		$(this).next( 'span.select4' ).after( content );

	});

	// Remove "disabled" on select so the data still gets posted
	$('form').on('submit', function() {
		$(this).find('#wpf-meta :input').removeAttr('disabled');
	});

	//
	// User Filter
	//

	if($('#wpf-user-filter').length) {
		$('.tablenav.bottom #wpf-user-filter').remove();
	}

	//
	// Admin Widgets View
	//

	// Toggle tag select visibility
	$('.widget-filter-by-tag').on('click', function(event) {

		if($(this).prop('checked') === true) {
			$(this).parent().next('.tags-container').show();
		} else{
			$(this).parent().next('.tags-container').hide();
		}
	});

	// Widget added
	$( document ).on( 'widget-added', function( event, target ) {
		$(target).find('.select4-container, .select4-selection__rendered').remove();
		initializeTagsSelect('#' + target[0].id);
	} );

	// Re-initialize tag select after widget save
	$( document ).on( 'widget-updated', function( event, target ) {
		initializeTagsSelect('#' + target[0].id);
	});

	//
	// Admin menu editor
	//

	// on in/out/role change, hide/show the roles
	$('#menu-to-edit').on('change', 'select.wpf-nav-menu,.nav_item_options-which_users select', function() {

		if( $(this).val() === '1' || $(this).val() === 'logged_in' ){
			initializeTagsSelect('#menu-to-edit');
			$(this).parents('li.menu-item').find('.wpf_nav_menu_tags_field').slideDown();

		} else {

			$(this).parents('li.menu-item').find('.wpf_nav_menu_tags_field').slideUp();

		}
	});

	

	// Event tracking.
	if($('.wpf-event-tracking').length > 0){
		
		//Reserved events keys.
		if(wpf_admin.reserved_events_keys.length > 0){
			$(document).on('input','.wpf-event-tracking .wpf-et-key', wpf_reserve_events_keys);
		}
	}

	/**
	 * Show tooltip warning for users if they are using reserved events keys.
	 */
	function wpf_reserve_events_keys () {
		let input = $(this);
		let input_value = $(this).val();
		let found_reserved_key = false;

		$.each(wpf_admin.reserved_events_keys, function (index, key) {
			if (input_value.indexOf(key) != -1) {
				found_reserved_key = true

				let event_key_notice = wpf_admin.strings.reserved_keys_warning.replace('{key_name}',key);

				input.addClass('wpf-tip wpf-tip-bottom').attr('data-tip', event_key_notice);

				$('.wpf-tip.wpf-tip-bottom').tipTip({
					attribute: 'data-tip',
					delay: 0,
					activation: 'focus',
					defaultPosition: 'top'
				})
				input.focus();
			}
		})

		if (found_reserved_key) {
			input.css('color', '#cc0000')
		} else {
			input.css('color', '#000')
			$('#tiptip_holder').remove()
		}
	}

 
	//
	// WooCommerce functions
	//

	if ($('body').hasClass('post-type-product')) {
        const targetNode = document.querySelector('#wpbody #variable_product_options');
        const config = { childList: true, subtree: true };

        const callback = function(mutationsList, observer) {
            for (let mutation of mutationsList) {
                mutation.addedNodes.forEach(node => {
                    if (node.classList && node.classList.contains('woocommerce_variation')) {
                        initializeTagsSelect('#wpbody #variable_product_options');

                        if ($("#wpbody #variable_product_options select.select4-search").length) {
                            $("#wpbody #variable_product_options select.select4-search").select4({
                                allowClear: true
                            });
                        }
                    }
                });
            }
        };

        const observer = new MutationObserver(callback);
        observer.observe(targetNode, config);
    }

    // Advanced Ads functions

    if ($('body').hasClass('post-type-advanced_ads')) {
        const targetNodeAds = document.querySelector('#wpbody .advads-conditions-table');
        const configAds = { childList: true, subtree: true };

        const callbackAds = function(mutationsList, observer) {
            for (let mutation of mutationsList) {
                mutation.addedNodes.forEach(node => {
                    if (node.querySelector && node.querySelector('input.wp-fusion')) {
                        initializeTagsSelect('#wpbody .advads-conditions-table');
                    }
                });
            }
        };

        const observerAds = new MutationObserver(callbackAds);
        observerAds.observe(targetNodeAds, configAds);
    }

	//
	// User profile page
	//

	// Resync Contact

	if($( "#resync-contact" ).length) {

		$( "#resync-contact" ).on('click', function (event) {

			event.preventDefault();

			$(this).html( wpf_admin.strings.syncing ); 
			$(this).attr('disabled', 'disabled');

			var button = $(this);

			var data = {
				'action'	 : 'resync_contact',
				'user_id' 	 : $(this).data('user_id'),
				'_ajax_nonce': wpf_admin.nonce,
			};

			$.post(ajaxurl, data, function(response) {

				if(response.success == false) {

					$('td#contact-id').html( wpf_admin.strings.noContact );
					$('#wpf-tags-row').remove();

				} else {

					// Set contact ID
					$('td#contact-id').html(response.contact_id);

					// If no tags
					if(response.user_tags == false) {
						$('td#wpf-tags-td').html( wpf_admin.strings.noTags );
					} else {
						$('td#wpf-tags-td').html( wpf_admin.strings.foundTags );
					}

				}

				button.html( wpf_admin.strings.resyncContact );
				button.removeAttr('disabled');

			});

		});

	}

	// Edit Tags

	if($( "#wpf-profile-edit-tags" ).length) {

		$( "#wpf-profile-edit-tags" ).on('click', function (event) {

			event.preventDefault();

			$('select.select4-wpf-tags').prop('disabled', false);

			$( "#wpf-tags-field-edited").val(true);

		});

	};

	// Tribe Events

	$('.ticket_edit').on( "click", function(event) {

		var ticketID = $(this).attr('attr-ticket-id');

		$.each($('#ticket_form_table tr.wpf-ticket-wrapper'), function(index, val) {

			if($(this).attr('data-id') != ticketID || $(this).hasClass('no-id')) {
				$(this).remove();
			}

		});

	});

	$('#ticket_form_toggle').on( "click", function(event) {
		
		$(this).parentsUntil('table').find('tr.wpf-ticket-wrapper.has-id').remove();

	});

	$( '#tribetickets' ).on('spin.tribe', function( event, action ) {

		if(action == 'start') {
			$('#ticket_form_table tr.wpf-ticket-wrapper.has-id').remove();
		}

	});


	// EDD

	if($('body').hasClass('post-type-download')) {


		// Variable pricing

		$( document.body ).on( 'click', '#edd_price_fields button.edd_add_repeatable', function(e) {

			$('#edd_price_fields .edd-price-option-fields .edd_repeatable_row').last().find('span.select4').remove();
			initializeTagsSelect( '#edd_price_fields .edd-price-option-fields' );

		});


		// Recurring payments

		if($( ".edd-recurring-enabled" ).length) {

			$('.edd-recurring-enabled select, select#edd_recurring').on( 'change', function(event) {
				
				var recurring = false;

				if($(this).val() == 'yes') {
					recurring = true;
				}

				if(recurring == true) {
					$('.wpf-edd-recurring-options').slideDown();
				} else {
					$('.wpf-edd-recurring-options').slideUp();
				}

			});

		}
	}

	// If So
	function wpf_ifso_init_select(new_item=false){
		if($('.wpf_ifso_select').length > 0){
			jQuery('.wpf_ifso_select').each(function(){
				var select4_attr = jQuery(this).attr('data-select4-id');
				if (typeof select4_attr === 'undefined' || select4_attr === false) {
					var hide_container = false;
					if(jQuery(this).is(':hidden')){
						hide_container = true;
					}
					jQuery(this).select4();
					jQuery(this).next().attr('data-field','wpf-tags');
					if(hide_container === true){
						jQuery(this).next().hide();
					}

				}
				if(new_item === true){
					jQuery('.ifso-versions-sortable li:last-child .select4-container').remove();
					jQuery(this).select4();
					jQuery(this).next().attr('data-field','wpf-tags').hide();
				}

			});
		}
	}

	wpf_ifso_init_select();
	jQuery(document).on( 'versionAdded', function(e){
		wpf_ifso_init_select(true);
	});



	// LifterLMS

	if( $('body').hasClass('admin_page_llms-course-builder') ) {

		$( document ).ajaxComplete(function( event, xhr, settings ) {

			if( typeof(xhr.responseJSON) !== 'undefined' && typeof(xhr.responseJSON.type) !== 'undefined' && xhr.responseJSON.type == 'llms_quiz' ) {

				$( '#llms-quiz-settings-fields .settings-group--wp_fusion' ).find('select').each(function(index, el) {
					$(this).addClass('select4-wpf-tags');
				});

				initializeTagsSelect( '#llms-quiz-settings-fields' );

			}

		});

	}

	// LifterLMS

	if( $('body').hasClass('post-type-course') || $('body').hasClass('post-type-llms_membership') ) {

		$( document ).ajaxComplete(function( event, xhr, settings ) {

			if( typeof(xhr.responseText) !== 'undefined' && xhr.responseText.indexOf('llms-product-options-access-plans') >= 0 ) {

				initializeTagsSelect( '#llms-product-options-access-plans' );

				$("#llms-product-options-access-plans select.select4-search").select4({
					allowClear: true
				});

			}

		});

	}

	// WPForms

	if( $('body').hasClass('wpforms_page_wpforms-builder') ) {

		$( document ).ajaxComplete(function( event, xhr, settings ) {

			var data = settings.data.split('&');

			const isNewConnection = data.includes('provider=wp-fusion') && data.includes('task=new_connection');
			if (isNewConnection) {
				initializeTagsSelect( '.wpforms-provider-connections' );
				initializeCRMFieldSelect();
			}

		});

		// Make sure only one checkbox is checked in all connections.
		$(document).on('change', 'input[name^="providers[wp-fusion]"][name$="[options][main_form_fields]"]', function() {
			if ($(this).is(':checked')) {
				// Uncheck other checkboxes
				$('input[name^="providers[wp-fusion]"][name$="[options][main_form_fields]"]').not(this).prop('checked', false);
				
				// Hide all tables except the one in the current container
				$('.wpforms-provider-fields').hide();
				
				// Show the table in the current container
				$(this).closest('.wpforms-provider-fields').show();
			} else {
				$('.wpforms-provider-fields').show();
			}
		});
		

	}

	// Formidable Forms

	if( $('body').hasClass('toplevel_page_formidable') ) {

		$( document ).ajaxComplete(function( event, xhr, settings ) {

			initializeTagsSelect( 'div.frm_single_wpfusion_settings' );
			initializeCRMFieldSelect();

		});

	}

	// Coursepress

	if( $('body').hasClass('post-type-course') ) {

		$('a#wpf-coursepress-update').on( "click", function(event) {
			
			event.preventDefault();

			var meta_items = $( '#wpf-coursepress-meta [name^="wpf_settings_coursepress"]' ).serializeArray();

			var data = {
				'action'	: 'wpf_coursepress_save',
				'data'		: meta_items,
				'id'		: $('#wpf-coursepress-postid').val()
			}

			jQuery.post(ajaxurl, data);


		});

	}

	// Popup Maker

	if( $('body').hasClass('post-type-popup') ) {

		if( $( 'div.select4-wpf-tags-wrapper' ).length ) {

			$.each( $( 'div.select4-wpf-tags-wrapper' ), function(index, val) {

				$( this ).find( 'select' ).addClass( 'select4-wpf-tags' );
				$( this ).find( '.pumselect2-container' ).remove();

			});

			initializeTagsSelect( 'div.select4-wpf-tags-wrapper' );

		}

		$(document).on('pum_init', function () {

			$.each( $( 'div.select4-wpf-tags-wrapper' ), function(index, val) {

				if( ! $( this ).find( 'select' ).hasClass('select4-wpf-tags') ) {

					$( this ).find( 'select' ).addClass('select4-wpf-tags');
					$( this ).find( '.pumselect2-container' ).remove();
					initializeTagsSelect( 'div.select4-wpf-tags-wrapper' );

				}

			});

		});

	}

	// Gamipress

	if ( $('body').hasClass('gamipress-post-type') ) {

		// Listen for our change to our trigger type selectors
		$('.requirements-list').on( 'change', '.select-trigger-type', function() {

			// Grab our selected trigger type and achievement selector
			var trigger_type = $(this).val();
			var form_selector = $(this).siblings('.select-wp-fusion-tag');

			if( trigger_type === 'wp_fusion_specific_tag_applied' || trigger_type === 'wp_fusion_specific_tag_removed' ) {
				form_selector.show();
			} else {
				form_selector.hide();
			}

		});

		// Loop requirement list items to show/hide form select on initial load
		$('.requirements-list li').each(function() {

			// Grab our selected trigger type and achievement selector
			var trigger_type = $(this).find('.select-trigger-type').val();
			var form_selector = $(this).find('.select-wp-fusion-tag');

			if( trigger_type === 'wp_fusion_specific_tag_applied'
				|| trigger_type === 'wp_fusion_specific_tag_removed') {
				form_selector.show();
			} else {
				form_selector.hide();
			}

		});

		$('.requirements-list').on( 'update_requirement_data', '.requirement-row', function(e, requirement_details, requirement) {

			if( requirement_details.trigger_type === 'wp_fusion_specific_tag_applied'
				|| requirement_details.trigger_type === 'wp_fusion_specific_tag_removed') {
				requirement_details.wp_fusion_tag = requirement.find( '.select-wp-fusion-tag' ).val();
			}

		});

	}

	// AccessAlly

	if ( $( 'body' ).hasClass( 'accessally_page_accessally-wpf' ) ) {

		$( 'select.select4-wpf-tags' ).change( function() {

			if( $( this ).find('option:selected').length ) {
				$( this ).closest( 'tr' ).find( 'input.checkbox' ).prop( 'checked', true );
				$( this ).closest( 'tr' ).addClass( 'success' );
			} else {
				$( this ).closest( 'tr' ).find( 'input.checkbox' ).prop( 'checked', false );
				$( this ).closest( 'tr' ).removeClass( 'success' );
			}

		});

		$( 'input.checkbox' ).change( function() {

			if( $( this ).is(':checked') ) {
				$( this ).closest( 'tr' ).addClass( 'success' );
			} else {
				$( this ).closest( 'tr' ).removeClass( 'success' );
			}

		} );

	}

	// Upsell

	if ( $( '.wpf-upsell-tags-select' ).length ) {

		$( '.wpf-upsell-tags-select select' ).each(function(index, el) {
			$(el).addClass( 'select4-wpf-tags' );
		});

		$( '.wpf-upsell-tags-select span.select2' ).each(function(index, el) {
			$(el).remove();
		});

		initializeTagsSelect( 'div.acf-postbox' );

	}


	// GIve WP - Offline Donations
	if ( $( 'input[name="_give_customize_offline_donations"]' ).length ) {
		$('input[name="_give_customize_offline_donations"]').on('change',function(){
			if($(this).val() == 'disabled'){
				$('.give-field-wrap.apply_tags_offline_field').hide();
			}else{
				$('.give-field-wrap.apply_tags_offline_field').show();
			}
		});

		if($('input[name="_give_customize_offline_donations"]:checked').val() == 'disabled'){
			$('.give-field-wrap.apply_tags_offline_field').hide();
		}
	}

	// Sync tags and custom fields

	var syncTagsAndFields = function(button, total, crmContainer) {

		button.addClass('button-primary');
		button.find('span.dashicons').addClass('wpf-spin');
		button.find('span.text').html( wpf_admin.strings.syncTags );

		var data = {
			'action'	  : 'sync_tags',
			'_ajax_nonce' : wpf_admin.nonce,
		};

		$.post(ajaxurl, data, function(response) {
			
			if(true == wpf_admin.connected) {
					
				if(response &&  jQuery( "#wpbody select.select4-wpf-tags").length && wpf_admin.tagSelect4 == 1 ) {

					jQuery( "#wpbody select.select4-wpf-tags").each(function(index, el) {
						
						jQuery(el).append(response);
						jQuery(el).trigger('change');

					});

				}

				// Syncing custom fields.
				button.find('span.text').html( wpf_admin.strings.loadingFields );

				var data = {
					'action'	  : 'sync_custom_fields',
					'_ajax_nonce' : wpf_admin.nonce,
				}
		
				$.post(ajaxurl, data, function(response) {
					if(response &&  jQuery( "#wpbody select.select4-crm-field").length) {
		
						jQuery( "#wpbody select.select4-crm-field").each(function(index, el) {
		
							jQuery(el).append(response);
							jQuery(el).trigger('change');
				
						});
		
					}
		
					button.trigger('wpf_sync_complete');
					button.find('span.dashicons').removeClass('wpf-spin');
					button.find('span.text').html( 'Complete' );
				});
		
			}

		});

	}



	// WPF Admin Bar.
	$('#wp-admin-bar-wpfusion-refresh-tags a').on('click',function(e){

		e.preventDefault();
		$('#wp-admin-bar-wpfusion .ab-sub-wrapper').css('display','block');
		$('#wp-admin-bar-wpfusion .ab-item').addClass('override_hover');
		
		var button = $(this);
		var container = $('#wp-admin-bar-wpfusion');

		button.wrapInner('<span class="text"></span>');
		button.find('span.text').html( wpf_admin.strings.connecting );
		if(button.find('span.dashicons').length <= 0){
			button.prepend('<span class="dashicons dashicons-update-alt wpf-spin"></span>');
		}

		syncTagsAndFields(button, 0, container);

		$(button).on('wpf_sync_complete',function(){
			setTimeout(() => {
				$('#wp-admin-bar-wpfusion .ab-sub-wrapper').css('display','');
				$('#wp-admin-bar-wpfusion .ab-item').removeClass('override_hover');
			}, 1000);
		});

	});


});

// Fix tribe tickets select2 issued
jQuery(document).on('click','#ticket_form_toggle,#rsvp_form_toggle',function(){
	initializeTagsSelect( '#ticket_form_table' );
});

// For some reason this doesn't work inside of document.ready().
jQuery(document).on('click','#edd_price_fields .edd-add-repeatable-row > button', function(e){
	
	initializeTagsSelect('#wpbody');

});