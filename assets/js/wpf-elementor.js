var wpfElementor = {
	init: function () {
		jQuery(window).on('elementor:init', function () {
			elementor.on('frontend:init', () => {
				elementorFrontend.on('components:init', () => {
					var iFrameDOM = jQuery('iframe#elementor-preview-iframe').contents();
					if (window.elementorFrontend) {
						// Add classes when elements are first loaded.
						jQuery.each(elementorFrontend.config.elements.data, function (cid, element) {
							var eid = wpfElementor.getElementFromCid(cid);
							if (wpfElementor.visibilityIsHidden(cid)) {
								return iFrameDOM
									.find('.elementor-element[data-id=' + eid + ']')
									.addClass('wpf-visibility-hidden');
							}
						});

						// Re add classes when elements are moved.
						// There's no hook to know when sortable has be initialized so we wait until it does.
						var interval = setInterval(function () {
							if (iFrameDOM.find('.ui-sortable').length > 0) {
								wpfElementor.attachSortStopEvent(iFrameDOM);
								clearInterval(interval);
							}
						}, 1000);
					}
				});
			});

			elementor.hooks.addAction('panel/open_editor/section', wpfElementor.setModelCID);
			elementor.hooks.addAction('panel/open_editor/column', wpfElementor.setModelCID);
			elementor.hooks.addAction('panel/open_editor/widget', wpfElementor.setModelCID);

			jQuery(document).on('change', '.elementor-control-wpf_visibility select', function () {
				wpfElementor.toggleVisibilityClass();
			});

			jQuery(document).on(
				'change',
				'.elementor-control-wpf_tags select,.elementor-control-wpf_tags_all select,.elementor-control-wpf_tags_not select',
				function () {
					wpfElementor.toggleVisibilityClass();
				}
			);
		});
	},

	attachSortStopEvent: function (iFrameDOM) {
		iFrameDOM.find('.ui-sortable').on('sortstop', function (event, ui) {
			jQuery.each(jQuery(this).find('.elementor-element'), function () {
				if (wpfElementor.visibilityIsHidden(jQuery(this).attr('data-model-cid'))) {
					jQuery(this).addClass('wpf-visibility-hidden');
				}
			});
		});
	},

	setModelCID: function (panel, model, view) {
		wpf_model_cid = model.cid;
	},

	toggleVisibilityClass: function () {
		var cid = wpf_model_cid;
		var eid = wpfElementor.getElementFromCid(cid);
		var iFrameDOM = jQuery('iframe#elementor-preview-iframe').contents();
		var visibility_field_value = jQuery('.elementor-control-wpf_visibility select').val();

		if (visibility_field_value == 'everyone' && wpfElementor.tagFieldsHasValue()) {
			return iFrameDOM.find('.elementor-element[data-id=' + eid + ']').addClass('wpf-visibility-hidden');
		}

		if (visibility_field_value != 'everyone') {
			return iFrameDOM.find('.elementor-element[data-id=' + eid + ']').addClass('wpf-visibility-hidden');
		}

		iFrameDOM.find('.elementor-element[data-id=' + eid + ']').removeClass('wpf-visibility-hidden');
	},

	tagFieldsHasValue: function () {
		let has_value = true;
		if (
			jQuery('.elementor-control-wpf_tags select').val().length < 1 &&
			jQuery('.elementor-control-wpf_tags_all select').val().length < 1 &&
			jQuery('.elementor-control-wpf_tags_not select').val().length < 1
		) {
			has_value = false;
		}

		return has_value;
	},

	getElementFromCid: function (cid) {
		var iFrameDOM = jQuery('iframe#elementor-preview-iframe').contents();
		var eid = iFrameDOM.find('.elementor-element[data-model-cid=' + cid + ']').data('id');
		return eid;
	},

	visibilityIsHidden: function (cid) {
		if (cid && elementorFrontend.config.elements.data[cid]) {
			var settings = elementorFrontend.config.elements.data[cid].attributes;

			if (
				settings['wpf_visibility'] == 'everyone' &&
				(settings['wpf_tags'] != '' || settings['wpf_tags_all'] != '' || settings['wpf_tags_not'] != '')
			) {
				return true;
			}

			if (settings['wpf_visibility'] != '' && settings['wpf_visibility'] != 'everyone') {
				return true;
			}
		}
		return false;
	},
};
wpfElementor.init();
