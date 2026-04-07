/* global ajaxurl, wpf_batch_ajax, jQuery, confirm, alert */
/* eslint-disable camelcase */

// Batch Processing Module
const BatchProcessing = ( ( $ ) => {
	let completed = 0;
	let attempts = 0;

	const doAltBatch = () => {
		if ( attempts === 0 ) {
			return;
		}

		// eslint-disable-next-line no-console
		console.log(
			'Doing alternate batch request with completed ' + completed
		);

		const data = {
			action: 'wpf_background_process',
			_ajax_nonce: wpf_batch_ajax.nonce,
		};

		$.post( ajaxurl, data, doAltBatch );
	};

	const getBatchStatus = ( total, title ) => {
		if ( $( '#wpf-batch-status' ).hasClass( 'hidden' ) ) {
			$( 'html, body' ).animate( { scrollTop: 0 }, 'slow' );

			if ( total === 0 || isNaN( total ) ) {
				handleNoBatchResults( title );
				return;
			}

			initializeBatchStatus( total, title );
		}

		const key = $( '#wpf-batch-status' ).attr( 'data-key' );
		const data = {
			action: 'wpf_batch_status',
			_ajax_nonce: wpf_batch_ajax.nonce,
			key,
		};

		$.post( ajaxurl, data, handleBatchResponse );
	};

	const handleNoBatchResults = ( title ) => {
		$( '#wpf-batch-status' )
			.removeClass( 'notice-info' )
			.addClass( 'notice-error' )
			.find( 'span.title' )
			.html( '' );
		$( '#wpf-batch-status #cancel-batch' ).remove();
		$( '#wpf-batch-status span.status' ).html(
			`No eligible ${ title } found. Aborting...`
		);
		$( '#wpf-batch-status' )
			.removeClass( 'hidden' )
			.slideDown( 'slow' )
			.delay( 6000 )
			.slideUp( 'slow' )
			.queue( function () {
				$( this ).addClass( 'hidden' ).dequeue();
			} );
	};

	const initializeBatchStatus = ( total, title ) => {
		$( '#wpf-batch-status span.status' ).html(
			`${ wpf_batch_ajax.strings.processing } ${ total } ${ title }`
		);
		$( '#wpf-batch-status' ).slideDown( 'slow' ).removeClass( 'hidden' );
		$( '#cancel-batch' ).prop( 'disabled', false );
	};

	const handleBatchResponse = ( response ) => {
		response = JSON.parse( response );
		attempts++;

		// eslint-disable-next-line no-console
		console.log( 'BATCH step:' );
		// eslint-disable-next-line no-console
		console.dir( response );

		if ( response === null ) {
			attempts = 0;
			// eslint-disable-next-line no-console
			console.log( 'IS NULL' );
			return;
		}

		const remaining = parseInt( response.remaining );
		const errors = parseInt( response.errors );
		const title = response.title;
		const misc =
			errors > 0
				? `- ${ response.errors } ${ wpf_batch_ajax.strings.batchErrorsEncountered }`
				: '';

		if ( remaining === 0 || isNaN( remaining ) ) {
			completeBatchProcess();
			return;
		}

		if ( attempts === 3 && completed === 0 ) {
			// eslint-disable-next-line no-console
			console.log(
				'Background worker failing to start. Starting alternate method.'
			);
			doAltBatch();
		}

		const total = parseInt( response.total );
		setTimeout(
			() => updateBatchStatus( total, remaining, title, misc ),
			5000
		);
	};

	const completeBatchProcess = () => {
		attempts = 0;
		$( '#wpf-batch-status span.title' ).html( '' );
		$( '#wpf-batch-status #cancel-batch' ).remove();
		$( '#wpf-batch-status span.status' ).html(
			wpf_batch_ajax.strings.batchOperationComplete
		);
		$( '#wpf-batch-status' )
			.delay( 3000 )
			.queue( function () {
				$( this ).slideUp( 'slow' ).addClass( 'hidden' ).dequeue();
			} );
	};

	const updateBatchStatus = ( total, remaining, title, misc ) => {
		completed = total - remaining;
		const status = `${ wpf_batch_ajax.strings.processing } ${ completed } / ${ total } ${ title } ${ misc }`;
		$( '#wpf-batch-status span.status' ).html( status );
		getBatchStatus( total, title );
	};

	const startBatch = ( button, action, args = false, object_ids = [] ) => {
		if ( button ) {
			button
				.prop( 'disabled', true )
				.html(
					`<span class="dashicons dashicons-update-alt wpf-spin"></span>${ wpf_batch_ajax.strings.beginningProcessing.replace(
						'ACTIONTITLE',
						action.title
					) }`
				);
		}

		const data = {
			action: 'wpf_batch_init',
			hook: action.action,
			args,
			object_ids,
			_ajax_nonce: wpf_batch_ajax.nonce,
		};

		$.post( ajaxurl, data, ( response ) => {
			// eslint-disable-next-line no-console
			console.log( 'START batch with items:' );
			// eslint-disable-next-line no-console
			console.dir( response.data );

			if ( button ) {
				button.html( 'Background Task Created' );
			}
			getBatchStatus( $( response.data ).length, action.title );
		} );
	};

	return {
		getBatchStatus,
		startBatch,
	};
} )( jQuery );

// Event Handlers
jQuery( document ).ready( ( $ ) => {
	// Batch process status checker
	if ( $( '#wpf-batch-status' ).hasClass( 'active' ) ) {
		BatchProcessing.getBatchStatus(
			$( '#wpf-batch-status' ).attr( 'data-remaining' ),
			'records'
		);
	}

	// Cancel batch
	$( '#cancel-batch' ).on( 'click', function () {
		const button = $( this );
		button.prop( 'disabled', true ).html( 'Cancelling' );

		const data = {
			action: 'wpf_batch_cancel',
			_ajax_nonce: wpf_batch_ajax.nonce,
			key: $( '#wpf-batch-status' ).attr( 'data-key' ),
		};

		$.post( ajaxurl, data );
	} );

	// Export button
	$( '#export-btn' ).on( 'click', function () {
		if ( $( 'input[name=export_options]:checked' ).length === 0 ) {
			return;
		}

		// eslint-disable-next-line no-alert
		if ( ! confirm( wpf_batch_ajax.strings.startBatchWarning ) ) {
			return;
		}

		const button = $( this );
		const action = {
			action: $( 'input[name=export_options]:checked' ).val(),
			title: $( 'input[name=export_options]:checked' ).attr(
				'data-title'
			),
		};
		const args = {
			skip_processed: $(
				'input[name=skip_already_processed]:checked'
			).val(),
		};

		BatchProcessing.startBatch( button, action, args );
	} );

	// Users bulk actions
	$( '.users-php .bulkactions #doaction' ).on( 'click', function ( e ) {
		if ( $( '#bulk-action-selector-top' ).length === 0 ) {
			return;
		}

		const allowedActions = [ 'users_sync', 'users_meta', 'pull_users_meta' ];
		const buttonAction = $( '#bulk-action-selector-top' ).val();

		if ( ! allowedActions.includes( buttonAction ) ) {
			return;
		}

		e.preventDefault();

		const objectIds = $( 'input[name="users[]"]:checked' )
			.map( function () {
				return $( this ).val();
			} )
			.get();

		if ( objectIds.length === 0 ) {
			// eslint-disable-next-line no-alert
			alert( `${ wpf_batch_ajax.strings.atleastOneBatch } users.` );
			return;
		}

		if (
			$( 'body' ).hasClass( 'settings_page_wpf-settings' ) &&
			// eslint-disable-next-line no-alert
			! confirm( wpf_batch_ajax.strings.startBatchWarning )
		) {
			return;
		}

		const action = {
			action: buttonAction,
			title: 'users',
		};

		BatchProcessing.startBatch( false, action, false, objectIds );

		// Reset bulk action selector
		const button = $( this );
		const originalText = button.val();
		button.prop( 'disabled', true ).val( 'Background Task Created' );
		setTimeout( () => {
			button.val( originalText ).prop( 'disabled', false );
			$( '#bulk-action-selector-top' ).val( '-1' );
		}, 2000 );
	} );

	// Gravity Forms entries bulk action
	$( '.forms_page_gf_entries #doaction' ).on( 'click', function ( e ) {
		if ( $( '#bulk-action-selector-top' ).length === 0 ) {
			return;
		}

		const buttonAction = $( '#bulk-action-selector-top' ).val();

		if ( buttonAction !== 'wpf_process_again' ) {
			return;
		}

		e.preventDefault();

		const objectIds = $( 'input[name="entry[]"]:checked' )
			.map( function () {
				return $( this ).val();
			} )
			.get();

		if ( objectIds.length === 0 ) {
			// eslint-disable-next-line no-alert
			alert( `${ wpf_batch_ajax.strings.atleastOneBatch } entries.` );
			return;
		}

		const action = {
			action: 'gravity_forms',
			title: 'entries',
		};

		BatchProcessing.startBatch( false, action, false, objectIds );

		// Reset bulk action selector
		const button = $( this );
		const originalText = button.val();
		button.prop( 'disabled', true ).val( 'Background Task Created' );
		setTimeout( () => {
			button.val( originalText ).prop( 'disabled', false );
			$( '#bulk-action-selector-top' ).val( '-1' );
		}, 2000 );
	} );

	// Skip already processed
	$( '.wpf-export-option input[type="radio"]' ).on( 'change', function () {
		const $skipProcessedContainer = $( '.skip-processed-container' );
		const $checkbox = $skipProcessedContainer.find(
			'input[type="checkbox"]'
		);

		if ( parseInt( $( this ).attr( 'process_again' ) ) !== 1 ) {
			$checkbox.prop( 'checked', true );
			$skipProcessedContainer.hide();
			return;
		}

		const $label = $skipProcessedContainer.find( 'label' );
		const $tooltip = $skipProcessedContainer.find( 'i' );
		const optionTitle = $( this ).attr( 'data-title' ).toLowerCase();
		$label.html( $label.html().replace( '[placeholder]', optionTitle ) );
		$tooltip.attr(
			'data-tip',
			$tooltip.attr( 'data-tip' ).replace( '[placeholder]', optionTitle )
		);

		$tooltip.tipTip( {
			content: $tooltip.attr( 'data-tip' ),
			fadeIn: 50,
			fadeOut: 50,
			delay: 200,
			defaultPosition: 'right',
		} );

		$skipProcessedContainer.css( 'display', 'inline-block' );
	} );

	// Start import
	$( '#import-users-btn' ).on( 'click', function () {
		const $select = $( '[name^="wpf_options[import_users]"]' );
		if ( $select.find( 'option:selected' ).length === 0 ) {
			$select.next().addClass( 'error' );
			setTimeout( () => $select.next().removeClass( 'error' ), 1000 );
			return;
		}

		const button = $( this ).prop( 'disabled', true );
		const action = {
			action: 'import_users',
			_ajax_nonce: wpf_batch_ajax.nonce,
			title: 'Contacts',
		};
		const args = {
			tag: $( 'select#wpf_options-import_users option:selected' ).val(),
			role: $( '#import_role' ).val(),
			notify: $( '#email_notifications' ).is( ':checked' ),
			update_existing_users: $( '#update_existing_users' ).is(
				':checked'
			),
		};

		BatchProcessing.startBatch( button, action, args );
	} );
} );
