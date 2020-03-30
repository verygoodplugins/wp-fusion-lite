
//
// Select4 Fields
//

// Tags select

function initializeTagsSelect(target) {

	if( typeof(wpf_admin) === 'undefined' ) {
		return;
	}

	if( jQuery( target + " select.select4-wpf-tags").length ) {

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


			if(jQuery.inArray('add_tags', wpf_admin.crm_supports) > -1) {

				// For CRMs that support adding new tags via API

				var limit = jQuery(this).attr('data-limit');

				if(!limit || limit.length == 0)
					limit = -1;

				jQuery(this).select4({
					multiple : true,
					minimumResultsForSearch: -1,
					tags : true,
					maximumSelectionLength: limit,
					insertTag: function(data, tag){
					    tag.text = tag.text + " (add new)"
					    data.push(tag);
					}
				});

			} else {

				// From CRMs that need to resync

				// Initialize selects

				var limit = jQuery(this).attr('data-limit');

				if(!limit || limit == 0)
					var limit = -1;

				jQuery(el).select4({
					multiple : true,
					minimumResultsForSearch: -1,
					maximumSelectionLength: limit,
					language: {
						noResults: function() {
							var jQueryresync = jQuery("<a id='wpf-select4-tags-resync'>No results found: click to resynchronize</a>");

							jQueryresync.on('mouseup', function (evt) {
								evt.stopPropagation();
								jQuery(this).hide();
								jQuery(this).after('<span id="wpf-select4-tags-loading"><i class="fa fa-spinner fa-spin"></i> Loading tags, please wait...</span>');

								var data = {
									'action'	: 'sync_tags'
								}

								jQuery.post(ajaxurl, data, function(response) {

									jQuery('#wpf-select4-tags-loading').remove();
									jQueryresync.after('<span>Resync complete. Please try searching again.</span>');

									jQuery(el).append(response);
									jQuery(el).trigger('change');

								});

							});

							return jQueryresync;
						}
					},
					escapeMarkup: function (markup) {
						return markup;
					},
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

	// Tooltips

	$( '.wpf-tip.right' ).tipTip({
		'attribute': 'data-tip',
		'fadeIn': 50,
		'fadeOut': 50,
		'delay': 200,
		'defaultPosition': 'right',
	});

	$( '.wpf-tip.bottom' ).tipTip({
		'attribute': 'data-tip',
		'fadeIn': 50,
		'fadeOut': 50,
		'delay': 200,
		'defaultPosition': 'bottom',
	});

	// CRM field select

	function initializeCRMFieldSelect() {

		if( $("select.select4-crm-field").length && $("select.select4-crm-field").length <= 300 ) {

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
							var $resync = $("<a id='wpf-select4-tags-resync'>No results found: click to resynchronize</a>");

							$resync.on('mouseup', function (evt) {
								evt.stopPropagation();
								$(this).hide();
								$(this).after('<span id="wpf-select4-tags-loading"><i class="fa fa-spinner fa-spin"></i> Loading fields, please wait...</span>');

								var data = {
									'action'	: 'sync_custom_fields'
								}

								$.post(ajaxurl, data, function(response) {

									$('#wpf-select4-tags-loading').remove();
									$resync.after('<span>Resync complete. Please try searching again.</span>');

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
	// Admin notices
	//

	$('#wpf-woo-warning .notice-dismiss').click(function(event) {
		
		var data = {
			'action'	: 'wpf_dismiss_notice',
			'notice'	: $(this).parent().attr('data-name')
		}

		$.post(ajaxurl, data);

	});

	//
	// Meta boxes
	//

	$('#wpf-meta [data-unlock], .wpf-meta [data-unlock]').click(function() {

		var targets = $(this).data('unlock').split(" ");
		var ischecked = $(this).prop('checked');

		$.each(targets, function( index, target ) {
			$('label[for="' + target + '"]').toggleClass('disabled');
			$('#' + target).prop('disabled', !ischecked); 
		});

	});

	// Warn on linked tag change

	$('select[name*="tag_link"], select[name*="link_tag"]').change(function(event) {

		var selected = $('option:selected', this ).text();

		if ( selected.length == 0 ) {
			return;
		}

		var content = '<div class="notice notice-warning wpf-tags-notice"><p>It looks like you\'ve just changed a linked tag. To manually trigger automated enrollments, run a <em>Resync Tags</em> operation from the <a target="_blank" href="' + wpf_admin.settings_page + '#advanced">WP Fusion settings page</a>. Any user with the <strong>' + selected + '</strong> tag will be enrolled. Any user without the <strong>' + selected + '</strong> tag will be unenrolled.</p></div>';

		$(this).next( 'span.select4' ).after( content );

	});

	// Remove "disabled" on select so the data still gets posted
	$('form').bind('submit', function() {
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
	$('.widget-filter-by-tag').live('click', function(event) {

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
	// Bulk Edit tool
	//

	$( '#bulk_edit' ).live( 'click', function(event) {

		// define the bulk edit row
		var $bulk_row = $( '#bulk-edit' );
		
		// get the selected post ids that are being edited
		var post_ids = new Array();
		$bulk_row.find( '#bulk-titles' ).children().each( function() {
			post_ids.push( $( this ).attr( 'id' ).replace( /^(ttle)/i, '' ) );
		});
		
		// get the custom fields

		var wpf_settings = {};
		$('[name^="wpf-settings"]').each(function(index, el) {

			var name = $(this).attr( 'name' ).replace( 'wpf-settings', '' ).replace(/\[/g, '').replace(/\]/g, '');

			if($(this).is(':checkbox') && $(this).is(':checked')) {
				wpf_settings[name] = 1;
			} else if($(this).is(':checkbox')) {
				wpf_settings[name] = 0;
			} else {
				wpf_settings[name] = $(this).val();
			}

		});
		
		// save the data
		$.ajax({
			url: ajaxurl, // this is a variable that WordPress has already defined for us
			type: 'POST',
			async: false,
			cache: false,
			data: {
				action: 'wpf_bulk_edit_save', // this is the name of our WP AJAX function that we'll set up next
				post_ids: post_ids, // and these are the 2 parameters we're passing to our function
				wpf_settings: wpf_settings,
			}
		});
		
	});


	//
	// Admin menu editor
	//

	// on in/out/role change, hide/show the roles
	$('#menu-to-edit').on('change', 'select.wpf-nav-menu', function() {

		if( $(this).val() === '1' ){

			initializeTagsSelect('#menu-to-edit');
			$(this).closest('.wpf_nav_menu_field').next('.wpf_nav_menu_tags_field').slideDown();

		} else {

			$(this).closest('.wpf_nav_menu_field').next('.wpf_nav_menu_tags_field').slideUp();

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

			$(this).html('Syncing'); 
	        $(this).attr('disabled', 'disabled');

	        var button = $(this);

	        var data = {
				'action'	: 'resync_contact',
				'user_id' 	: $(this).data('user_id')
			};

			$.post(ajaxurl, data, function(response) {

				if(response.success == false) {

					$('td#contact-id').html('No contact record found.');
					$('#wpf-tags-row').remove();

				} else {

					response = $.parseJSON(response);

					// Set contact ID
					$('td#contact-id').html(response.contact_id);

					// If no tags
					if(response.user_tags == false) {
						$('td#wpf-tags-td').html('No tags applied.');
					} else {
						$('td#wpf-tags-td').html('Reload page to see tags.');
					}

				}

				button.html('Resync Contact');
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

	$('.ticket_edit').click(function(event) {

		var ticketID = $(this).attr('attr-ticket-id');

		$.each($('#ticket_form_table tr.wpf-ticket-wrapper'), function(index, val) {

			if($(this).attr('data-id') != ticketID || $(this).hasClass('no-id')) {
			 	$(this).remove();
			}

		});

	});

	$('#ticket_form_toggle').click(function(event) {
		
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

			$('.edd-recurring-enabled select, select#edd_recurring').change(function(event) {
				
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

		$('a#wpf-coursepress-update').click(function(event) {
			
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

});

// Tribe Tickets

function initializeTicketTable( ticketID ) {

	initializeTagsSelect( '#ticket_form_table' );

	jQuery('#ticket_form_table #ticket_wpf_settings-apply_tags').change(function(event) {
		
		var items = [];
		jQuery(this).find('option:selected').each(function(){ items.push(jQuery(this).val()); });

		var data = {
			'action'	: 'wpf_tribe_tickets_save',
			'data'		: items.join(','),
			'id'		: ticketID
		}

		jQuery.post(ajaxurl, data);

	});

}