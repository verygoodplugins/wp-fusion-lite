(($) => {
    const { input, select } = HollerBox.elements
    const { __ } = wp.i18n

    // Add WPFusion control
    HollerBox._editor.AdvancedDisplayRules.wpf_visibility_button = {
        name: wpf_holler_object.wpf_visibility.label,
        controls: ({
            wpf_visibility = '',
        }) => {

            var wpf_vis_html = '';
            for(var key in wpf_holler_object.wpf_visibility.options) {
                let val = wpf_holler_object.wpf_visibility.options[key];
                wpf_vis_html+= '<option '+(wpf_visibility === key ? 'selected="selected"' : '')+' value="'+key+'">'+val+'</option>';
            }

            return `
                <select style="display:block;" name="wpf_visibility" id="wpf_visibility">
                    ${wpf_vis_html}
                </select>
            `;
        },
        onMount: (trigger, updateTrigger) => {
            var wpf_val = $('#wpf_visibility').val();
            if(wpf_val === 'loggedout'){
                $('[data-id=wpf_show_any]').hide();
                $('[data-id=wpf_hide_any]').hide();
            }

            $('#wpf_visibility').on('change', e => {
                if($('#wpf_visibility').val() === 'loggedout'){
                    $('[data-id=wpf_show_any]').hide();
                    $('[data-id=wpf_hide_any]').hide();
                }else{
                    $('[data-id=wpf_show_any]').show();
                    $('[data-id=wpf_hide_any]').show();
                }
            });

            $('#wpf_visibility').on('change', e => {
                updateTrigger({
                    wpf_visibility: e.target.value,
                })
            });

        },
    };

    HollerBox._editor.AdvancedDisplayRules.wpf_show_any = {
        name: wpf_holler_object.wpf_show_any,
        controls: ({
            wpf_show_any = ''
        }) => {

            var wpf_tags_html = '';
            for(var key in wpf_holler_object.tags) {
                let val = wpf_holler_object.tags[key];
                wpf_tags_html+= '<option '+(wpf_show_any.includes(key) ? 'selected="selected"' : '')+' value="'+key+'">'+val+'</option>';
            }


            return `
            <div class="wpf-holler-box">
                <select class="select4-wpf-tags" multiple name="wpf_show_any" id="wpf_show_any" data-placeholder="${wpf_holler_object.select_placeholder}">
                    ${wpf_tags_html}
                </select>
            </div>
            `;
        },
        onMount: (popup, updateSetting) => {
            initializeTagsSelect('.wpf-holler-box');
            $('#wpf_show_any').on('change', e => {

                updateSetting({
                    wpf_show_any: $(e.target).select4("val"),
                })
            });
        },
    };

    HollerBox._editor.AdvancedDisplayRules.wpf_hide_any = {
        name: wpf_holler_object.wpf_hide_any,
        controls: ({
            wpf_hide_any = ''
        }) => {

            var wpf_tags_html = '';
            for(var key in wpf_holler_object.tags) {
                let val = wpf_holler_object.tags[key];
                wpf_tags_html+= '<option '+(wpf_hide_any.includes(key) ? 'selected="selected"' : '')+' value="'+key+'">'+val+'</option>';
            }


            return `
            <div class="wpf-holler-box">
                <select class="select4-wpf-tags" multiple name="wpf_hide_any" id="wpf_hide_any" data-placeholder="${wpf_holler_object.select_placeholder}">
                    ${wpf_tags_html}
                </select>
            </div>
            `;
        },
        onMount: (popup, updateSetting) => {
            initializeTagsSelect('.wpf-holler-box');
            $('#wpf_hide_any').on('change', e => {

                updateSetting({
                    wpf_hide_any: $(e.target).select4("val"),
                })
            });
        },
    };


})(jQuery)
