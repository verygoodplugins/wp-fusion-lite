<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Renders the field select HTML for admin pages
 *
 * @access public
 * @return mixed HTML field
 */
function wpf_render_tag_multiselect( $args = array() ) {

	$defaults = array(
		'setting'     => array(),
		'meta_name'   => null,
		'field_id'    => null,
		'disabled'    => false,
		'placeholder' => __( 'Select tags', 'wp-fusion-lite' ),
		'limit'       => null,
		'no_dupes'    => array(),
		'class'       => '',
		'return'      => false,
		'read_only'   => false, // should read only tags / lists be shown as options.
		'lazy_load'   => false,
	);

	$args = wp_parse_args( $args, $defaults );

	// Allow disabling the output if it causes performance problems.
	$bypass = apply_filters( 'wpf_disable_tag_multiselect', false, $args );

	if ( true === $bypass ) {
		return;
	}

	if ( 1 === $args['limit'] ) {
		$args['placeholder'] = __( 'Select a tag', 'wp-fusion-lite' );
	}

	// Get the field ID
	if ( false === $args['field_id'] ) {
		$field_id = sanitize_html_class( $args['meta_name'] );
	} else {
		$field_id = sanitize_html_class( $args['meta_name'] ) . '-' . $args['field_id'];
	}

	$args = apply_filters( 'wpf_render_tag_multiselect_args', $args );

	$available_tags = (array) wpf_get_option( 'available_tags' );

	// Let's make sure this is an array so we don't get "second parameter is not an array" warnings.
	if ( ! is_array( $args['setting'] ) ) {
		$args['setting'] = (array) $args['setting'];
	}

	$args['setting'] = array_filter( $args['setting'] ); // sometimes empty values end up in here.

	$unknown_tags = array();

	// Maybe convert setting from tag names to IDs if CRM has been switched.
	if ( ! empty( $args['setting'] ) && ! in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

		foreach ( $args['setting'] as $i => $value ) {

			if ( array_key_exists( $value, $available_tags ) ) {
				continue;
			}

			if ( ! is_numeric( $value ) && ! is_array( $value ) ) {

				// If the tag is stored as a name, and we're able to find a numeric ID, update it

				$search = wpf_get_tag_id( $value );

				if ( false !== $search ) {

					$unknown_tags[]        = $value;
					$args['setting'][ $i ] = $search;

				}
			}
		}
	}

	// Check to see if there are unknown tags in the list.

	foreach ( $args['setting'] as $tag ) {

		if ( false !== strpos( wpf_get_tag_label( $tag ), 'Unknown' ) || ( is_numeric( $tag ) && ! array_key_exists( $tag, $available_tags ) ) ) {
			$unknown_tags[] = $tag;
		}
	}

	if ( ! empty( $unknown_tags ) ) {
		$args['class'] .= ' wpf-has-unknown-tags';
	}

	// If there are more than 1000 total tags, we'll lazy-load them.

	if ( count( $available_tags ) > 1000 ) {

		// The currently selected options still needs to be preserved.
		foreach ( $available_tags as $id => $tag ) {
			if ( ! in_array( $id, $args['setting'] ) ) {
				unset( $available_tags[ $id ] );
			}
		}

		$args['lazy_load'] = true;

	}

	// If we're returning instead of echoing.
	if ( $args['return'] ) {
		ob_start();
	}

	// Let's start spitting out some HTML!
	echo '<select';
		echo ( true == $args['disabled'] ? ' disabled' : '' );
		echo ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '"';
		echo ' multiple="multiple"';
		echo ' id="' . esc_attr( $field_id ) . '"';
		echo ' data-limit="' . (int) $args['limit'] . '"';
		echo ( true == $args['lazy_load'] ? ' data-lazy-load="true"' : '' );
		echo ' class="select4-wpf-tags ' . esc_attr( $args['class'] ) . '"';
		echo ' name="' . esc_attr( $args['meta_name'] ) . ( ! is_null( $args['field_id'] ) ? '[' . esc_attr( $args['field_id'] ) . ']' : '' ) . '[]"';
		echo ( ! empty( $args['no_dupes'] ) ? ' data-no-dupes="' . esc_attr( implode( ',', $args['no_dupes'] ) ) . '"' : '' );
	echo '>';

	// Start outputting the tag <option>s.
	if ( is_array( reset( $available_tags ) ) ) {

		// Handling for select with category groupings (like Infusionsoft).
		$tag_categories = array();

		foreach ( $available_tags as $value ) {
			if ( is_array( $value ) ) {
				$tag_categories[] = $value['category'];
			}
		}

		$tag_categories = array_unique( $tag_categories );

		foreach ( $tag_categories as $tag_category ) {

			// (read only) lists with HubSpot.
			if ( false !== strpos( $tag_category, 'Read Only' ) && false === $args['read_only'] ) {
				continue;
			}

			if ( false !== strpos( $tag_category, 'Forms' ) && true === $args['read_only'] ) {
				continue;
			}

			echo '<optgroup label="' . esc_attr( $tag_category ) . '">';

			foreach ( $available_tags as $id => $field_data ) {

				if ( ! is_array( $field_data ) ) {
					continue; // if tag got saved as string somehow.
				}

				// If we are showing read only lists/tags, add a badge to indicate it.

				if ( strpos( $tag_category, 'Read Only' ) !== false ) {
					$field_data['label'] .= '<small>(' . esc_html__( 'read only', 'wp-fusion-lite' ) . ')</small>';
				}

				if ( strpos( $tag_category, 'Forms' ) !== false ) {
					$field_data['label'] .= '<small>(' . esc_html__( 'form', 'wp-fusion-lite' ) . ')</small>';
				}

				if ( $field_data['category'] === $tag_category ) {
					echo '<option value="' . esc_attr( $id ) . '" ' . selected( true, in_array( $id, $args['setting'] ), false ) . '>' . esc_html( $field_data['label'] ) . '</option>';
				}
			}
			echo '</optgroup>';
		}
	} else {

		// Tags without categories / optgroups.
		foreach ( $available_tags as $id => $tag ) {

			// Fix for empty tags created by spaces etc.
			if ( empty( $tag ) || ! is_string( $tag ) ) {
				continue;
			}

			if ( false !== strpos( $tag, 'opt-in to marketing' ) ) {
				// Klaviyo.
				$tag = str_replace( '(', '<small>(', $tag );
				$tag = str_replace( ')', ')</small>', $tag );
			}

			// Added the following is_numeric() check for 3.29.1 to fix "5DD - Customer" tag causing "5" tag to be selected.
			// Tag less than 10 so that tag IDs still show up and can be replaced after switching to a CRM with dynamic tagging
			// if ( in_array( 'add_tags', wp_fusion()->crm->supports ) && is_numeric( $tag ) && $tag < 10 ) {
			// continue;
			// }
			// ^ Removed in v3.34.8 in favor of $id = strval( $id );..
			$id = strval( $id );

			$is_selected = in_array( $id, $args['setting'], $strict = false );

			echo '<option value="' . esc_attr( $id ) . '" ' . selected( true, $is_selected ) . '>' . esc_html( $tag ) . '</option>';

		}

		// Maybe output any new tags that have been entered for this setting, but aren't yet stored with available_tags.
		if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

			foreach ( $args['setting'] as $tag ) {

				if ( ! empty( $tag ) && ! isset( $available_tags[ $tag ] ) ) {
					echo '<option value="' . esc_attr( $tag ) . '" selected>' . esc_html( $tag ) . '</option>';
				}
			}
		}
	}

	echo '</select>';

	if ( ! empty( $unknown_tags ) ) {
		echo '<span class="wpf-unknown-tags">' . esc_html__( 'Unknown tag(s): ', 'wp-fusion-lite' ) . esc_html( implode( ', ', $unknown_tags ) ) . '</span>';
	}

	// ....done!
	if ( $args['return'] ) {
		return ob_get_clean();
	}
}

/**
 * Renders a dropdown with all custom fields for the current CRM
 *
 * @access public
 * @return mixed HTML field
 */
function wpf_render_crm_field_select( $setting, $meta_name, $field_id = false, $field_sub_id = false ) {

	if ( doing_action( 'show_field_crm_field' ) ) {
		// Settings page.
		$name = $meta_name . '[' . $field_id . ']';
	} elseif ( false === $field_id ) {
		$name = $meta_name . '[crm_field]';
	} elseif ( false === $field_sub_id ) {
		$name = $meta_name . '[' . $field_id . '][crm_field]';
	} else {
		$name = $meta_name . '[' . $field_id . '][' . $field_sub_id . '][crm_field]';
	}

	// ID.

	if ( false === $field_id ) {
		$id = sanitize_html_class( $meta_name );
	} else {
		$id = sanitize_html_class( $meta_name ) . '-' . $field_id;
	}

	echo '<select id="' . esc_attr( $id . ( ! empty( $field_sub_id ) ? '-' . $field_sub_id : '' ) ) . '" class="select4-crm-field" name="' . esc_attr( $name ) . '" data-placeholder="Select a field">';

	echo '<option></option>';

	$crm_fields = wpf_get_option( 'crm_fields', array() );

	if ( ! empty( $crm_fields ) ) {

		foreach ( $crm_fields as $group_header => $fields ) {

			// For CRMs with separate custom and built in fields, or using the new data storage.
			if ( is_array( $fields ) ) {

				echo '<optgroup label="' . esc_attr( $group_header ) . '">';

				foreach ( $crm_fields[ $group_header ] as $field => $label ) {

					if ( is_array( $label ) ) {

						if ( isset( $label['label'] ) ) {
							$label = $label['label'];
						} else {
							$label = $label['crm_label']; // new 3.42.5 storage.
						}
					}

					$label = str_replace( '(', '<small>', $label ); // (read only) and (compound field)
					$label = str_replace( ')', '</small>', $label );

					echo '<option ' . selected( $setting, $field, false ) . ' value="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</option>';
				}

				echo '</optgroup>';

			} elseif ( is_string( $fields ) ) {

				$field = $group_header;
				$label = $fields;

				$label = str_replace( '(', '<small>', $label ); // (read only) and (compound field)
				$label = str_replace( ')', '</small>', $label );

				echo '<option ' . selected( $setting, $field, false ) . ' value="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</option>';

			}
		}
	}

	// Save custom added fields to the DB.
	if ( in_array( 'add_fields', wp_fusion()->crm->supports ) ) {

		$field_check = array();

		// Collapse fields if they're grouped.
		if ( isset( $crm_fields['Custom Fields'] ) ) {

			foreach ( $crm_fields as $field_group ) {

				if ( ! empty( $field_group ) ) {

					foreach ( $field_group as $field => $label ) {
						$field_check[ $field ] = $label;
					}
				}
			}
		} else {

			$field_check = $crm_fields;

		}

		// Check to see if new custom fields have been added.
		if ( ! empty( $setting ) && ! isset( $field_check[ $setting ] ) ) {

			echo '<option value="' . esc_attr( $setting ) . '" selected="selected">' . esc_html( $setting ) . '</option>';

			if ( isset( $crm_fields['Custom Fields'] ) ) {

				$crm_fields['Custom Fields'][ $setting ] = $setting;
				asort( $crm_fields['Custom Fields'] );

			} else {
				$crm_fields[ $setting ] = $setting;
				asort( $crm_fields );
			}

			wp_fusion()->settings->set( 'crm_fields', $crm_fields );

			// Save safe crm field to DB.
			$contact_fields                               = wpf_get_option( 'contact_fields' );
			$contact_fields[ $field_sub_id ]['crm_field'] = $setting;
			wp_fusion()->settings->set( 'contact_fields', $contact_fields );

		}
	}

	if ( in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

		echo '<optgroup label="Tagging">';

			echo '<option ' . selected( $setting, 'add_tag_' . $field_id ) . ' value="add_tag_' . esc_attr( $field_id ) . '">+ ' . esc_html__( 'Create tag(s) from value', 'wp-fusion-lite' ) . '</option>';

		echo '</optgroup>';

	}

	echo '</select>';
}


/**
 * WP Fusion SVG logo.
 *
 * Used by WP Simple Pay payment form panel tabs, might be used elsewhere some
 * day.
 *
 * @since  3.37.13
 *
 * @param  int $width  The width in px.
 * @return string The logo.
 */
function wpf_logo_svg( $width = 24 ) {

	return '<svg width="' . esc_attr( $width ) . '" viewBox="0 0 38 39" fill="currentColor">
	    <g id="Page-1" stroke="none" stroke-width="1" fill="currentColor" fill-rule="evenodd">
	        <g id="Mark-Copy">
	            <path d="M8,0.5 L38,0.5 L38,0.5 L38,30.5 C38,34.918278 34.418278,38.5 30,38.5 L0,38.5 L0,38.5 L0,8.5 C-5.41083001e-16,4.081722 3.581722,0.5 8,0.5 Z" id="BG"></path>
	            <path d="M16,13 C16.8284271,13 17.5,13.6715729 17.5,14.5 L17.499,26.5 L29.5,26.5 C30.3284271,26.5 31,27.1715729 31,28 L31,29 C31,29.8284271 30.3284271,30.5 29.5,30.5 L15.5,30.5 C14.9577643,30.5 14.4827278,30.2122862 14.2192253,29.7811934 C13.7877138,29.5172722 13.5,29.0422357 13.5,28.5 L13.5,14.5 C13.5,13.6715729 14.1715729,13 15,13 L16,13 Z" id="Rectangle-Copy-4" fill="#FFFFFF" transform="translate(22.250000, 21.750000) scale(-1, -1) rotate(-450.000000) translate(-22.250000, -21.750000) "></path>
	            <path d="M10.5,8 C11.3284271,8 12,8.67157288 12,9.5 L11.999,21.5 L24,21.5 C24.8284271,21.5 25.5,22.1715729 25.5,23 L25.5,24 C25.5,24.8284271 24.8284271,25.5 24,25.5 L10,25.5 C9.4577643,25.5 8.98272777,25.2122862 8.71922527,24.7811934 C8.28771383,24.5172722 8,24.0422357 8,23.5 L8,9.5 C8,8.67157288 8.67157288,8 9.5,8 L10.5,8 Z" id="Rectangle-Copy-7" fill="#FFFFFF" transform="translate(16.750000, 16.750000) scale(-1, -1) rotate(-270.000000) translate(-16.750000, -16.750000) "></path>
	        </g>
	    </g>
	</svg>';
}


/**
 * Gets the status icon for an entry, order, etc for display in admin columns.
 *
 * @since 3.44.22
 *
 * @param array  $complete_data The complete data.
 * @param string $type          The type of entry (order, form, etc.).
 * @return string The status icon.
 */
function wpf_status_icon( $complete_data, $type = 'entry' ) {

	$url = false;

	if ( $complete_data['contact_id'] ) {

		$class = 'success';

		// Get the contact edit URL.

		$url = wp_fusion()->crm->get_contact_edit_url( $complete_data['contact_id'] );

		if ( $url ) {
			$id_text = '<a href="' . esc_url_raw( $url ) . '" target="_blank">#' . esc_html( $complete_data['contact_id'] ) . '</a>';
		} else {
			$id_text = '#' . esc_html( $complete_data['contact_id'] );
		}

		$show_date = date_i18n( get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ), strtotime( $complete_data['complete'] ) );
		$tooltip   = sprintf(
			// translators: %1$s is the type of entry (order, form, etc.), %2$s is the CRM name, %3$s is the contact ID, %4$s is the date and time.
			__( 'This %1$s was synced to %2$s contact ID %3$s on %4$s.', 'wp-fusion-lite' ),
			esc_html( $type ),
			esc_html( wp_fusion()->crm->name ),
			$id_text,
			esc_html( $show_date )
		);

		if ( isset( $complete_data['ec_complete'] ) ) {

			// Enhanced ecommerce.

			if ( $complete_data['ec_complete'] ) {

				if ( $complete_data['ec_invoice_id'] ) {
					$tooltip .= '<br /><br />' . sprintf( __( 'It was processed by Enhanced Ecommerce with invoice ID #%s.', 'wp-fusion-lite' ), $complete_data['ec_invoice_id'] );
				} else {
					$tooltip .= '<br /><br />' . __( 'It was processed by Enhanced Ecommerce.', 'wp-fusion-lite' );
				}
			} else {

				$class    = 'partial-success';
				$tooltip .= '<br /><br />' . __( 'It was not processed by Enhanced Ecommerce.', 'wp-fusion-lite' );

			}
		}
	} else {
		$class   = 'fail';
		$tooltip = sprintf(
			// translators: %1$s is the type of entry (order, form, etc.), %2$s is the CRM name.
			__( 'This %1$s was not synced to %2$s.', 'wp-fusion-lite' ),
			esc_html( $type ),
			esc_html( wp_fusion()->crm->name )
		);
	}

	if ( $url ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank"><i class="icon-wp-fusion wpf-tip wpf-tip-bottom ' . esc_attr( $class ) . '" data-tip="' . esc_attr( $tooltip ) . '"></i></a>';
	} else {
		return '<i class="icon-wp-fusion wpf-tip wpf-tip-bottom ' . esc_attr( $class ) . '" data-tip="' . esc_attr( $tooltip ) . '"></i>';
	}
}
