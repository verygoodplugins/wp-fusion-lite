/**
 * This contains functionality that allows the tags and form settings to show in the Thrive Architect editor in the Lead Generation API settings.
 */

 if ( typeof TVE !== 'undefined' ) {
	/* if you re-use this for another API, everything should still work :) */
	const WPF_API_KEY = wpf_thrive_api.api_key,
		$mainEditorContainer = TVE.$( TVE.main );

	/**
	 * In order to add the API Tag functionality, we must add the tag controls to the API controls template.
	 * Same thing for adding the Forms selector.
	 * @param {jQuery} $template
	 * @param {String} apiKey
	 * @param {Object} model
	 */
	TVE.add_action( 'tcb.lead_generation.api_settings_template', ( $template, apiKey, model ) => {
		if ( apiKey === WPF_API_KEY ) {
			/* Functionality for tags */
			$template.append( TVE.tpl( 'lead-generation/apis/default-tag-controls' )( {api: model} ) );
		}
	} );


	/**
	 * Functionality for tags and forms.
	 * When the API settings are saved, also save the selected tags and the selected form.
	 */
	$mainEditorContainer.on( `tve-api-options-${WPF_API_KEY}.tcb`, ( event, params ) => {
		/* 'get_inputs_value' is a Thrive Architect function that reads the selected values */
		params.api.setConfig( TVE.get_inputs_value( params.$container, '.tve-api-extra' ) );
	} );

	/**
	 * Adds the clever-reach logo in the Thrive Architect API list
	 * @param {String} logo
	 * @param {String} apiKey
	 * @returns {String} logo
	 */
	TVE.add_filter( 'tcb.lead_generation.api_logo', ( logo, apiKey ) => {
        console.log(apiKey,WPF_API_KEY);
        if ( apiKey === WPF_API_KEY ) {
			logo = wpf_thrive_api.api_logo;
		}

		return logo;
	} );
}
