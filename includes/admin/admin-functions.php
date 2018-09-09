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
		'setting' 		=> array(),
		'meta_name'		=> null,
		'field_id'		=> null,
		'field_sub_id' 	=> null,
		'disabled'		=> false,
		'placeholder'	=> 'Select tags',
		'limit'			=> null,
		'no_dupes'		=> array(),
		'prepend'		=> array(),
		'class'			=> ''
	);

	$args = wp_parse_args( $args, $defaults );

	$available_tags = wp_fusion()->settings->get( 'available_tags' );

	// If no tags, set a blank array
	if ( ! is_array( $available_tags ) ) {
		$available_tags = array();
	}

	if ( is_array( reset( $available_tags ) ) ) {

		// Handling for select with category groupings

		$tag_categories = array();
		foreach ( $available_tags as $value ) {
			$tag_categories[] = $value['category'];
		}

		$tag_categories = array_unique( $tag_categories );

		echo '<select ' . ( $args["disabled"] == true ? ' disabled' : '' ) . ' data-placeholder="' . $args["placeholder"] . '" multiple="multiple" ' . ( $args["limit"] != null ? ' data-limit="' . $args["limit"] . '"' : '' ) . ' id="' . $args["field_id"] . ( ! is_null( $args["field_sub_id"] ) ? '-' . $args["field_sub_id"] : '' ) . '" class="select4-wpf-tags ' . $args['class'] . '" name="' . $args["meta_name"] . ( ! is_null( $args["field_id"] ) ? '[' . $args["field_id"] . ']' : '' ) . ( ! is_null( $args["field_sub_id"] ) ? '[' . $args["field_sub_id"] . ']' : '' ) . '[]"' . ( ! empty( $args["no_dupes"] ) ? ' data-no-dupes="' . implode(',', $args["no_dupes"]) . '"' : '' ) . '>';

			if( ! empty( $args['prepend'] ) )  {

				foreach( $args['prepend'] as $id => $tag ) {
					echo '<option value="' . esc_attr( $id ) . '"' . ( is_null( $args["field_sub_id"] ) ? selected( true, in_array( $id, (array) $args["setting"] ), false ) : selected( true, in_array( $id, (array) $args["setting"][ $args["field_sub_id"] ] ), false ) ) . '>' . $tag . '</option>';
				}

			}

			foreach ( $tag_categories as $tag_category ) {

				echo '<optgroup label="' . $tag_category . '">';

				foreach ( $available_tags as $id => $field_data ) {

					if ( $field_data['category'] == $tag_category ) {
						echo '<option value="' . esc_attr( $id ) . '"' . ( is_null( $args["field_sub_id"] ) ? selected( true, in_array( $id, (array) $args["setting"] ), false ) : selected( true, in_array( $id, (array) $args["setting"] [ $args["field_sub_id"] ] ), false ) ) . '>' . esc_html($field_data['label']) . '</option>';
					}

				}
				echo '</optgroup>';
			}

		echo '</select>';

	} else {

		// Handling for single level select (no categories)

		echo '<select ' . ( $args["disabled"] == true ? ' disabled' : '' );
		echo ' data-placeholder="' . $args["placeholder"] . '" multiple="multiple" id="' . $args["field_id"] . ( ! is_null( $args["field_sub_id"] ) ? '-' . $args["field_sub_id"] : '' ) . '" data-limit="' . $args["limit"] . '" class="select4-wpf-tags ' . $args['class'] . '" name="' . $args["meta_name"] . ( ! is_null( $args["field_id"] ) ? '[' . $args["field_id"] . ']' : '' ) . ( ! is_null( $args["field_sub_id"] ) ? '[' . $args["field_sub_id"] . ']' : '' ) . '[]"' . ( ! empty( $args["no_dupes"] ) ? ' data-no-dupes="' . implode(',', $args["no_dupes"]) . '"' : '' ) . '>';

			if( ! empty( $args['prepend'] ) )  {
				
				foreach( $args['prepend'] as $id => $tag ) {
					echo '<option value="' . esc_attr( $id ) . '"' . ( is_null( $args["field_sub_id"] ) ? selected( true, in_array( $id, (array) $args["setting"] ), false ) : selected( true, in_array( $id, (array) $args["setting"][ $args["field_sub_id"] ] ), false ) ) . '>' . esc_html($tag) . '</option>';
				}
			}

			// Check to see if new custom tags have been added
			if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_tags', wp_fusion()->crm->supports ) ) {

				foreach ( (array) $args["setting"] as $i => $tag ) {

					// For settings with sub-ids (like Woo variations)
					if ( is_array( $tag ) ) {

						foreach ( $tag as $sub_tag ) {

							if ( ! in_array( $sub_tag, $available_tags ) && $i == $args['field_sub_id'] ) {

								$available_tags[ $sub_tag ] = $sub_tag;
								wp_fusion()->settings->set( 'available_tags', $available_tags );
							}

						}

					} elseif ( ! isset( $available_tags[ $tag ] ) && ! empty( $tag ) ) {

						$available_tags[ $tag ] = $tag;
						wp_fusion()->settings->set( 'available_tags', $available_tags );

					}

				}

			}

			foreach ( $available_tags as $id => $tag ) {

				// Fix for empty tags created by spaces etc
				if ( empty( $tag ) ) {
					continue;
				}

				echo '<option value="' . esc_attr( $id ) . '"' . ( is_null( $args["field_sub_id"] ) ? selected( true, in_array( $id, (array) $args["setting"] ), false ) : selected( true, in_array( $id, (array) $args["setting"][ $args["field_sub_id"] ] ), false ) ) . '>' . esc_html($tag) . '</option>';

			}


		echo '</select>';


	}

}

/**
 * Renders a dropdown with all custom fields for the current CRM
 *
 * @access public
 * @return mixed HTML field
 */

function wpf_render_crm_field_select( $setting, $meta_name, $field_id, $field_sub_id = null ) {

	echo '<select id="' . $field_id . ( isset( $field_sub_id ) ? '-' . $field_sub_id : '' ) . '" class="select4-crm-field" name="' . $meta_name . '[' . $field_id . ']' . ( isset( $field_sub_id ) ? '[' . $field_sub_id . ']' : '' ) . '[crm_field]" data-placeholder="Select a field">';

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

					echo '<option ' . selected( esc_attr( $setting ), $field ) . ' value="' . esc_attr($field) . '">' . esc_html($label) . '</option>';
				}


				echo '</optgroup>';

			} else {

				$field = $group_header;
				$label = $fields;

				echo '<option ' . selected( esc_attr( $setting ), $field ) . ' value="' . esc_attr($field) . '">' . esc_html($label) . '</option>';


			}

		}

	}

	// Save custom added fields to the DB
	if ( is_array( wp_fusion()->crm->supports ) && in_array( 'add_fields', wp_fusion()->crm->supports ) ) {

		$field_check = array();

		// Collapse fields if they're grouped
		if( isset( $crm_fields['Custom Fields'] ) ) {

			foreach( $crm_fields as $field_group ) {

				foreach( $field_group as $field => $label ) {
					$field_check[ $field ] = $label;
				}

			}

		} else {

			$field_check = $crm_fields;
			
		}

		// Check to see if new custom fields have been added
		if ( ! empty( $setting ) && ! isset( $field_check[ $setting ] ) ) {

			// Lowercase and remove spaces (for Drip)
			if( in_array( 'safe_add_fields', wp_fusion()->crm->supports ) ) {

				$setting_value = strtolower( str_replace( ' ', '', $setting ) );

			} else {

				$setting_value = $setting;

			}

			echo '<option value="' . esc_attr($setting_value) . '" selected="selected">' . esc_html($setting) . '</option>';

			if( isset( $crm_fields['Custom Fields'] ) ) {

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

	echo '</select>';
}






