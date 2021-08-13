<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handle a log entry.
 *
 * @param int $timestamp Log timestamp.
 * @param string $level emergency|alert|critical|error|warning|notice|info|debug
 * @param string $message Log message.
 * @param array $context {
 *     Additional information for log handlers.
 *
 *     @type string $source Optional. Source will be available in log table.
 *                  If no source is provided, attempt to provide sensible default.
 * }
 *
 * @see WPF_Log_Handler::get_log_source() for default source.
 *
 * @return bool False if value was not handled and true if value was handled.
 */

if ( ! function_exists( 'wpf_log' ) ) {

	function wpf_log( $level, $user, $message, $context = array() ) {

		return wp_fusion()->logger->handle( $level, $user, $message, $context );

	}
}

/**
 * Checks to see if a user has a given tag
 *
 * @return bool
 */

if ( ! function_exists( 'wpf_has_tag' ) ) {

	function wpf_has_tag( $tags, $user_id = false ) {

		return wp_fusion()->user->has_tag( $tags, $user_id );

	}
}


/**
 * Checks to see if a user can access a given post
 *
 * @return bool
 */

if ( ! function_exists( 'wpf_user_can_access' ) ) {

	function wpf_user_can_access( $post_id = false, $user_id = false ) {

		if ( false === $post_id ) {
			global $post;
			$post_id = $post->ID;
		}

		return wp_fusion()->access->user_can_access( $post_id, $user_id );

	}
}

/**
 * Gets the current user ID, with support for auto-logged-in users
 *
 * @return int User ID
 */

if ( ! function_exists( 'wpf_get_current_user_id' ) ) {

	function wpf_get_current_user_id() {

		if ( wp_fusion()->user ) {

			return wp_fusion()->user->get_current_user_id();

		} else {

			return get_current_user_id();

		}

	}
}

/**
 * Gets the current user, with support for auto-logged-in users
 *
 * @since 3.37.3
 *
 * @return bool|WP_User The current user.
 */

if ( ! function_exists( 'wpf_get_current_user' ) ) {

	function wpf_get_current_user() {

		return wp_fusion()->user->get_current_user();

	}
}

/**
 * Gets the WordPress user ID from a contact ID.
 *
 * @since 3.35.17
 *
 * @param string $contact_id The contact ID to search by.
 * @return int|bool The user ID, or false if not found.
 */

function wpf_get_user_id( $contact_id ) {

	return wp_fusion()->user->get_user_id( $contact_id );

}

/**
 * Gets the CRM contact ID from WordPress user ID.
 *
 * @since 3.36.1
 *
 * @param int $user_id The user ID to search by.
 * @return string|bool The contact ID, or false if not found,
 */

function wpf_get_contact_id( $user_id = false ) {

	return wp_fusion()->user->get_contact_id( $user_id );

}

/**
 * Gets the CRM tags from WordPress user ID.
 *
 * @since  3.36.26
 *
 * @param  int   $user_id The user ID to search by.
 * @return array The user's tags in the CRM.
 */

function wpf_get_tags( $user_id = false ) {

	return wp_fusion()->user->get_tags( $user_id );

}

/**
 * Gets all users that have saved contact IDs.
 *
 * @since 3.37.22
 *
 * @return array User IDs.
 */
function wpf_get_users_with_contact_ids() {

	if ( is_object( wp_fusion()->user ) ) {
		return wp_fusion()->user->get_users_with_contact_ids();
	}

}

/**
 * Gets all users that have the tag.
 *
 * @since  3.37.27
 *
 * @param  string $tag    The tag.
 * @return array  User IDs.
 */
function wpf_get_users_with_tag( $tag ) {

	if ( is_object( wp_fusion()->user ) ) {
		return wp_fusion()->user->get_users_with_tag( $tag );
	}

}

/**
 * Checks if user is logged in, with support for auto-logged-in users
 *
 * @return bool Logged In
 */

if ( ! function_exists( 'wpf_is_user_logged_in' ) ) {

	function wpf_is_user_logged_in() {

		if ( is_object( wp_fusion()->user ) ) {

			// Avoid errors if WP Fusion isn't connected to a CRM.

			return wp_fusion()->user->is_user_logged_in();

		} else {

			return is_user_logged_in();

		}

	}
}

/**
 * Get tag ID from name
 *
 * @return bool / int Tag ID or false if not found
 */

if ( ! function_exists( 'wpf_get_tag_id' ) ) {

	function wpf_get_tag_id( $tag_name ) {

		return wp_fusion()->user->get_tag_id( $tag_name );

	}
}

/**
 * Get tag name from ID
 *
 * @return bool / string Tag label or false if not found
 */

if ( ! function_exists( 'wpf_get_tag_label' ) ) {

	function wpf_get_tag_label( $tag_id ) {

		return wp_fusion()->user->get_tag_label( $tag_id );

	}
}

/**
 * Get the CRM field ID for a single WordPress meta key
 *
 * @since 3.35.14
 *
 * @param string      $meta_key The meta key to look up
 * @param string|bool $default  The default value to return if no CRM key is found
 * @return string|bool The CRM field
 */

function wpf_get_crm_field( $meta_key, $default = false ) {

	return wp_fusion()->crm_base->get_crm_field( $meta_key, $default );

}

/**
 * Is a WordPress meta key enabled for sync with the CRM?
 *
 * @since 3.35.14
 *
 * @param string $meta_key The meta key to look up
 * @return bool Whether or not the field is active
 */

function wpf_is_field_active( $meta_key ) {

	return wp_fusion()->crm_base->is_field_active( $meta_key );

}

/**
 * Get the field type (set on the Contact Fields list) for a given field
 *
 * @since 3.35.14
 *
 * @param string $meta_key The meta key to look up
 * @param string $default  The default value to return if no type is found
 * @return string The field type
 */

function wpf_get_field_type( $meta_key, $default = 'text' ) {

	return wp_fusion()->crm_base->get_field_type( $meta_key, $default );

}

/**
 * Is a WordPress meta key a pseudo field and should only be sent to the CRM, not loaded
 *
 * @since 3.35.16
 *
 * @param string $meta_key The meta key to look up
 * @return bool Whether or not the field is a pseudo field
 */

function wpf_is_pseudo_field( $meta_key ) {

	return wp_fusion()->crm_base->is_pseudo_field( $meta_key );

}

/**
 * Gets the CRM field ID of the primary field used for contact record
 * lookups (usually email).
 *
 * @since  3.37.29
 *
 * @return string The field name in the CRM.
 */

function wpf_get_lookup_field() {

	return wp_fusion()->crm_base->get_lookup_field();

}

/**
 * Are we currently in an auto-login session?
 *
 * @return bool
 */

function doing_wpf_auto_login() {

	if ( is_object( wp_fusion()->auto_login ) && ! empty( wp_fusion()->auto_login->auto_login_user ) ) {
		return true;
	} else {
		return false;
	}

}

/**
 * Are we currently handing a webhook?
 *
 * @return bool
 */

function doing_wpf_webhook() {

	if ( defined( 'DOING_WPF_WEBHOOK' ) && true == DOING_WPF_WEBHOOK ) {
		return true;
	} else {
		return false;
	}

}

/**
 * Gets the default datetime format for syncing with the CRM.
 *
 * @since  3.37.27
 *
 * @return string The date time format.
 */

function wpf_get_datetime_format() {

	$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

	return apply_filters( 'wpf_datetime_format', $format );

}

/**
 * Is the current user an admin, and admins are excluded from restrictions?
 *
 * @since  3.6.26
 *
 * @param  int   $user_id The user ID.
 * @return bool
 */
function wpf_admin_override( $user_id = false ) {

	if ( false === $user_id ) {
		$user_id = get_current_user_id(); // don't use wpf_get_current_user_id() here since auto-login users don't have permissions anyway.
	}

	if ( wpf_get_option( 'exclude_admins' ) && user_can( $user_id, 'manage_options' ) ) {
		return true;
	} else {
		return false;
	}

}

/**
 * Gets an option from the WP Fusion settings.
 *
 * @since 3.38.0
 *
 * @param string $key     The settings key.
 * @param mixed  $default The default value to return if not set.
 * @return mixed The option value.
 */
function wpf_get_option( $key, $default = false ) {

	return wp_fusion()->settings->get( $key, $default );

}

/**
 * Clean variables using sanitize_text_field. Arrays are cleaned
 * recursively. Non-scalar values are ignored.
 *
 * @since  3.38.0
 *
 * @param  string|array $var    Data to sanitize.
 * @return string|array
 */
function wpf_clean( $var ) {
	if ( is_array( $var ) ) {
		return array_map( 'wpf_clean', $var );
	} else {
		return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
	}
}


/**
 * Prints human-readable information about a variable.
 *
 * Some server environments block some debugging functions. This function provides a safe way to
 * turn an expression into a printable, readable form without calling blocked functions.
 *
 * @since 3.38.0
 *
 * @see wc_print_r() https://woocommerce.github.io/code-reference/namespaces/default.html#function_wc_print_r
 *
 * @param mixed $expression The expression to be printed.
 * @param bool  $return     Optional. Default false. Set to true to return the human-readable string.
 * @return string|bool False if expression could not be printed. True if the expression was printed.
 *     If $return is true, a string representation will be returned.
 */
function wpf_print_r( $expression, $return = false ) {
	$alternatives = array(
		array(
			'func' => 'print_r',
			'args' => array( $expression, true ),
		),
		array(
			'func' => 'var_export',
			'args' => array( $expression, true ),
		),
		array(
			'func' => 'json_encode',
			'args' => array( $expression ),
		),
		array(
			'func' => 'serialize',
			'args' => array( $expression ),
		),
	);

	$alternatives = apply_filters( 'wp_fusion_print_r_alternatives', $alternatives, $expression );

	foreach ( $alternatives as $alternative ) {
		if ( function_exists( $alternative['func'] ) ) {
			$res = $alternative['func']( ...$alternative['args'] );
			if ( $return ) {
				return $res;
			}

			echo $res; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return true;
		}
	}

	return false;
}
