
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
	// Standard select

	if( $("select.select4").length ) {

		$("select.select4").select4({
			minimumResultsForSearch: -1,
			allowClear: true
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
		if($("select.select4-select-page").hasClass('select4-allow-adding')){
			select_page_options.tags = true;
			select_page_options.insertTag = function(data, tag){
				tag.text = tag.text + " (add URL)"
				data.push(tag);
			};
		}
		$("select.select4-select-page").select4(select_page_options);
		
	}

	// Tooltips

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


	// Logs User dropdown
	if( $("select.select4-users-log").length ) {

		$("select.select4-users-log").select4({
			allowClear: true,
			// placeholder: "Search for users",
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
						action: 'wpf_get_log_users'
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
						}
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

 
	//
	// WooCommerce functions
	//

	if($('body').hasClass('post-type-product')) {

		// Update select4's when a new variation is added

		$(document).on('DOMNodeInserted', function(e) {

			if ($(e.target).hasClass('woocommerce_variation')) {

				initializeTagsSelect('#wpbody #variable_product_options');

				if( $("#wpbody #variable_product_options select.select4-search").length ) {

					$("#wpbody #variable_product_options select.select4-search").select4({
						allowClear: true
					});
					
				}

			}
		});

	}

	//
	// Advanced Ads functions
	//

	if($('body').hasClass('post-type-advanced_ads')) {

		// Update select4's when a new condition is added

		$(document).on('DOMNodeInserted', function(e) {

			if( $(e.target).find( 'input.wp-fusion' ).length ) {
				initializeTagsSelect('#wpbody .advads-conditions-table');
			}

		});

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

		$( document.body ).on( 'change', '#edd_variable_pricing', function(e) {
			initializeTagsSelect('#wpbody');
		});

		$( document.body ).on( 'click', '#edd_price_fields a.edd_add_repeatable', function(e) {
			$('#edd_price_fields tbody tr.edd_repeatable_row').last().find('td.wpf-tags-select span.select4').remove();
			initializeTagsSelect('#wpbody');
			$('#edd_price_fields tbody tr.edd_repeatable_row').last().find('td.wpf-tags-select span.select4').css('width', '100%');
			$('#edd_price_fields tbody tr.edd_repeatable_row').last().find('td.wpf-tags-select span.select4 input.select4-search__field').css('width', '120px');
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

			if( data[3] == 'provider=wp-fusion' && data[4] == 'task=new_connection' ) {
				initializeTagsSelect( '.wpforms-provider-connections' );
				initializeCRMFieldSelect();
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

});

// Fix tribe tickets select2 issued
jQuery(document).on('click','#ticket_form_toggle,#rsvp_form_toggle',function(){
	initializeTagsSelect( '#ticket_form_table' );
});