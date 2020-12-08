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

function wpf_render_tag_multiselect( $args ) {

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
	);

	$args = wp_parse_args( $args, $defaults );

	if ( 1 == $args['limit'] ) {
		$args['placeholder'] = __( 'Select a tag', 'wp-fusion-lite' );
	}

	// Get the field ID
	if ( false == $args['field_id'] ) {
		$field_id = sanitize_html_class( $args['meta_name'] );
	} else {
		$field_id = sanitize_html_class( $args['meta_name'] ) . '-' . $args['field_id'];
	}

	// Allow disabling the output if it causes performance problems
	$bypass = apply_filters( 'wpf_disable_tag_multiselect', false, $args );

	if ( true == $bypass ) {
		return;
	}

	$args = apply_filters( 'wpf_render_tag_multiselect_args', $args );

	$available_tags = wp_fusion()->settings->get( 'available_tags', array() );

	// Let's make sure this is an array so we don't get "second parameter is not an array" warnings
	if ( ! is_array( $args['setting'] ) ) {
		$args['setting'] = (array) $args['setting'];
	}

	// Maybe convert setting from tag names to IDs if CRM has been switched

	if ( ! empty( $args['setting'] ) && is_array( wp_fusion()->crm->supports ) && ! in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

		foreach ( $args['setting'] as $i => $value ) {

			if ( ! is_numeric( $value ) ) {

				// If the tag is stored as a name, and we're able to find a numeric ID, update it

				$search = wp_fusion()->user->get_tag_id( $value );

				if ( false !== $search ) {

					$args['setting'][ $i ] = $search;

				}
			}
		}
	}

	// If we're returning instead of echoing
	if ( $args['return'] ) {
		ob_start();
	}

	// Let's start spitting out some HTML!

	echo '<select';
		echo ( true == $args['disabled'] ? ' disabled' : '' );
		echo ' data-placeholder="' . $args['placeholder'] . '"';
		echo ' multiple="multiple"';
		echo ' id="' . $field_id . '"';
		echo ' data-limit="' . $args['limit'] . '"';
		echo ' class="select4-wpf-tags ' . $args['class'] . '"';
		echo ' name="' . $args['meta_name'] . ( ! is_null( $args['field_id'] ) ? '[' . $args['field_id'] . ']' : '' ) . '[]"';
		echo ( ! empty( $args['no_dupes'] ) ? ' data-no-dupes="' . implode( ',', $args['no_dupes'] ) . '"' : '' );
	echo '>';

	// Start outputting the tag <option>s

	if ( is_array( reset( $available_tags ) ) ) {

		// Handling for select with category groupings (like Infusionsoft)

		$tag_categories = array();

		foreach ( $available_tags as $value ) {
			$tag_categories[] = $value['category'];
		}

		$tag_categories = array_unique( $tag_categories );

		foreach ( $tag_categories as $tag_category ) {

			echo '<optgroup label="' . $tag_category . '">';

			foreach ( $available_tags as $id => $field_data ) {

				if ( $field_data['category'] == $tag_category ) {
					echo '<option value="' . esc_attr( $id ) . '" ' . selected( true, in_array( $id, $args['setting'] ), false ) . '>' . esc_html( $field_data['label'] ) . '</option>';
				}
			}
			echo '</optgroup>';
		}
	} else {

		// Tags without categories / optgroups

		foreach ( $available_tags as $id => $tag ) {

			// Fix for empty tags created by spaces etc
			if ( empty( $tag ) ) {
				continue;
			}

			// Added the following is_numeric() check for 3.29.1 to fix "5DD - Customer" tag causing "5" tag to be selected.
			// Tag less than 10 so that tag IDs still show up and can be replaced after switching to a CRM with dynamic tagging

			// if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_tags', wp_fusion()->crm->supports ) && is_numeric( $tag ) && $tag < 10 ) {
			// continue;
			// }

			// ^ Removed in v3.34.8 in favor of $id = strval( $id );..

			$id = strval( $id );

			$is_selected = in_array( $id, $args['setting'], $strict = false );

			echo '<option value="' . esc_attr( $id ) . '" ' . selected( true, $is_selected ) . '>' . esc_html( $tag ) . '</option>';

		}

		// Maybe output any new tags that have been entered for this setting, but aren't yet stored with available_tags

		if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

			foreach ( $args['setting'] as $tag ) {

				if ( ! empty( $tag ) && ! isset( $available_tags[ $tag ] ) ) {
					echo '<option value="' . esc_attr( $tag ) . '" selected>' . esc_html( $tag ) . '</option>';
				}
			}
		}
	}

	echo '</select>';

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

function wpf_render_crm_field_select( $setting, $meta_name, $field_id = false, $field_sub_id = null ) {

	if ( false == $field_id ) {
		$name = $meta_name . '[crm_field]';
	} elseif ( false == $field_sub_id ) {
		$name = $meta_name . '[' . $field_id . '][crm_field]';
	} else {
		$name = $meta_name . '[' . $field_id . '][' . $field_sub_id . '][crm_field]';
	}

	echo '<select id="' . $field_id . ( isset( $field_sub_id ) ? '-' . $field_sub_id : '' ) . '" class="select4-crm-field" name="' . $name . '" data-placeholder="Select a field">';

	echo '<option></option>';

	$crm_fields = wp_fusion()->settings->get( 'crm_fields' );

	if ( ! empty( $crm_fields ) ) {

		foreach ( $crm_fields as $group_header => $fields ) {

			// For CRMs with separate custom and built in fields
			if ( is_array( $fields ) ) {

				echo '<optgroup label="' . $group_header . '">';

				foreach ( $crm_fields[ $group_header ] as $field => $label ) {

					if ( is_array( $label ) ) {
						$label = $label['label'];
					}

					echo '<option ' . selected( esc_attr( $setting ), $field ) . ' value="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</option>';
				}

				echo '</optgroup>';

			} else {

				$field = $group_header;
				$label = $fields;

				echo '<option ' . selected( esc_attr( $setting ), $field ) . ' value="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</option>';

			}
		}
	}

	// Save custom added fields to the DB
	if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_fields', wp_fusion()->crm->supports ) ) {

		$field_check = array();

		// Collapse fields if they're grouped
		if ( isset( $crm_fields['Custom Fields'] ) ) {

			foreach ( $crm_fields as $field_group ) {

				foreach ( $field_group as $field => $label ) {
					$field_check[ $field ] = $label;
				}
			}
		} else {

			$field_check = $crm_fields;

		}

		// Check to see if new custom fields have been added
		if ( ! empty( $setting ) && ! isset( $field_check[ $setting ] ) ) {

			// Lowercase and remove spaces (for Drip)
			if ( in_array( 'safe_add_fields', wp_fusion()->crm->supports ) ) {

				$setting_value = strtolower( str_replace( ' ', '', $setting ) );

			} else {

				$setting_value = $setting;

			}

			echo '<option value="' . esc_attr( $setting_value ) . '" selected="selected">' . esc_html( $setting ) . '</option>';

			if ( isset( $crm_fields['Custom Fields'] ) ) {

				$crm_fields['Custom Fields'][ $setting_value ] = $setting;

			} else {
				$crm_fields[ $setting_value ] = $setting;
			}

			wp_fusion()->settings->set( 'crm_fields', $crm_fields );

			// Save safe crm field to DB
			$contact_fields                               = wp_fusion()->settings->get( 'contact_fields' );
			$contact_fields[ $field_sub_id ]['crm_field'] = $setting_value;
			wp_fusion()->settings->set( 'contact_fields', $contact_fields );

		}
	}

	if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

		echo '<optgroup label="Tagging">';

			echo '<option ' . selected( esc_attr( $setting ), 'add_tag_' . $field_id ) . ' value="add_tag_' . $field_id . '">+ Create tag(s) from value</option>';

		echo '</optgroup>';

	}

	echo '</select>';
}






