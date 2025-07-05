<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


if ( ! function_exists( 'wpf_log' ) ) {

	/**
	 * Handle a log entry.
	 *
	 * @since unknown
	 *
	 * @param string $level   The log level.
	 * @param int    $user    The user ID.
	 * @param string $message The log message.
	 * @param array  $context {
	 *      Additional information for log handlers.
	 *
	 *     @type string $source Optional. Source will be available in log table.
	 *                  If no source is provided, attempt to provide sensible default.
	 * }
	 *
	 * @see WPF_Log_Handler::get_log_source() for default source.
	 *
	 * @return bool False if value was not handled and true if value was handled.
	 */
	function wpf_log( $level, $user, $message, $context = array() ) {

		return wp_fusion()->logger->handle( $level, $user, $message, $context );
	}
}

if ( ! function_exists( 'wpf_has_tag' ) ) {

	/**
	 * Checks to see if a user has a given tag
	 *
	 * @since unknown
	 *
	 * @param array|string $tags The tag or tags to check.
	 * @param int          $user_id The user ID.
	 * @return bool Whether the user has the tag.
	 */
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
			$post_id = get_the_ID();
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

		if ( wp_fusion()->user ) {

			return wp_fusion()->user->get_current_user();

		} else {

			return wp_get_current_user();

		}
	}
}

/**
 * Gets the current user's email address, with support for auto-logged-in
 * users, and guests that are being tracked via cookie.
 *
 * @since  3.38.23
 *
 * @return string|bool Email address or false.
 */
function wpf_get_current_user_email() {

	return wp_fusion()->user->get_current_user_email();
}

/**
 * Gets the WordPress user ID from an email address.
 *
 * @since 3.45.7
 *
 * @param string $email The email address to search by.
 * @return int The user ID, or 0 if not found.
 */
function wpf_get_user_id_by_email( $email ) {

	$user = get_user_by( 'email', $email );

	if ( $user ) {
		$user_id = $user->ID;
	} else {
		$user_id = 0;
	}

	return $user_id;
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
function wpf_get_contact_id( $user_id = false, $force_update = false ) {

	return wp_fusion()->user->get_contact_id( $user_id, $force_update );
}

/**
 * Gets the CRM tags from WordPress user ID.
 *
 * @since  3.36.26
 * @since  3.39.2 Added second parameter $force.
 *
 * @param  int  $user_id    The user ID to search by.
 * @param  bool $force      Whether or not to force-refresh the tags via an API call.
 * @param  bool $lookup_cid Whether or not to lookup the contact ID from the user ID.
 * @return array The user's tags in the CRM.
 */
function wpf_get_tags( $user_id = false, $force = false, $lookup_cid = true ) {

	return wp_fusion()->user->get_tags( $user_id, $force, $lookup_cid );
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

		if ( is_object( wp_fusion()->user ) ) {

			// Avoid errors if WP Fusion isn't connected to a CRM.

			return wp_fusion()->user->get_tag_id( $tag_name );

		} else {

			return false;

		}
	}
}

/**
 * Get tag name from ID
 *
 * @return bool / string Tag label or false if not found
 */

if ( ! function_exists( 'wpf_get_tag_label' ) ) {

	function wpf_get_tag_label( $tag_id ) {

		if ( is_object( wp_fusion()->user ) ) {

			// Avoid errors if WP Fusion isn't connected to a CRM.

			return wp_fusion()->user->get_tag_label( $tag_id );

		} else {

			return false;

		}
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

	return wp_fusion()->crm->get_crm_field( $meta_key, $default );
}

/**
 * Is a WordPress meta key enabled for sync with the CRM?
 *
 * @since 3.35.14
 *
 * @param string|array $meta_key The meta key or array of meta keys to look up.
 * @return bool Whether or not the field is active
 */
function wpf_is_field_active( $meta_key ) {

	return wp_fusion()->crm->is_field_active( $meta_key );
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

	return wp_fusion()->crm->get_field_type( $meta_key, $default );
}


/**
 * Gets a remote fields type from the field ID.
 *
 * @since 3.42.5
 *
 * @param string $remote_id The field ID to look up.
 * @param string $default   The default value to return if no type is found.
 * @return string The field type.
 */
function wpf_get_remote_field_type( $field_id, $default = 'text' ) {

	return wp_fusion()->crm->get_remote_field_type( $field_id, $default );
}

/**
 * Tries to get a remote field's option ID from a value.
 *
 * @since 3.42.5
 *
 * @param string $value    The value to search for.
 * @param string $field_id The CRM field ID.
 * @return string|bool The value or false if not found.
 */
function wpf_get_remote_option_value( $value, $field_id ) {

	return wp_fusion()->crm->get_remote_option_value( $value, $field_id );
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

	return wp_fusion()->crm->is_pseudo_field( $meta_key );
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

	return wp_fusion()->crm->get_lookup_field();
}

/**
 * Helper for sorting an array of CRM fields based on their label.
 *
 * @since  3.42.5
 *
 * @param  array $a The first field.
 * @param  array $b The second field.
 * @return int   The comparison result.
 */
function wpf_sort_remote_fields( $a, $b ) {

	return strcmp( $a['crm_label'], $b['crm_label'] );
}

/**
 * Helper for sorting an array of arrays based on their label.
 *
 * @since  3.43.0
 *
 * @param  array $a The first field.
 * @param  array $b The second field.
 * @return int   The comparison result.
 */
function wpf_sort_by_label( $a, $b ) {

	return strcmp( $a['label'], $b['label'] );
}


/**
 * Is WP Fusion currently in staging mode?
 *
 * @since  3.39.5
 *
 * @see WPF_Staging_Sites::maybe_activate_staging_mode()
 *
 * @return bool  Whether or not we're in staging mode.
 */
function wpf_is_staging_mode() {

	return wpf_get_option( 'staging_mode' );
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

	if ( defined( 'WPF_DOING_WEBHOOK' ) && true === WPF_DOING_WEBHOOK ) {
		return true;
	} else {
		return false;
	}
}

/**
 * Disables the API queue for the current request.
 *
 * @since 3.44.15
 */
function wpf_disable_api_queue() {

	if ( ! defined( 'WPF_DISABLE_QUEUE' ) ) {
		define( 'WPF_DISABLE_QUEUE', true );
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
 * Converts an input number to an E.164 phone number format.
 *
 * @since 3.42.14
 *
 * @param string $phone_number The input number.
 * @return string The E.164 formatted number.
 */
function wpf_phone_number_to_e164( $phone_number, $country = 'US' ) {

	// If the number already has a country code, return it.
	if ( 0 === strpos( $phone_number, '+' ) ) {
		return $phone_number;
	}

	$country_codes = array(
		'CN' => '86', // China
		'IN' => '91', // India
		'US' => '1',  // United States
		'GB' => '44', // United Kingdom
		'AU' => '61', // Australia
		'CA' => '1',  // Canada
		'ID' => '62', // Indonesia
		'PK' => '92', // Pakistan
		'BR' => '55', // Brazil
		'NG' => '234', // Nigeria
		'BD' => '880', // Bangladesh
		'RU' => '7',  // Russia
		'MX' => '52', // Mexico
		'JP' => '81', // Japan
		'ET' => '251', // Ethiopia
		'PH' => '63', // Philippines
		'EG' => '20', // Egypt
		'VN' => '84', // Vietnam
		'CD' => '243', // Democratic Republic of the Congo
		'TR' => '90', // Turkey
		'IR' => '98', // Iran
		'DE' => '49', // Germany
		'TH' => '66', // Thailand
	);

	// Remove any non-numeric characters from the phone number
	$clean_number = preg_replace( '/\D/', '', $phone_number );

	$clean_number = ltrim( $clean_number, '0' ); // remove leading 0s.

	// Check if a country code is already present
	$has_country_code = strlen( $clean_number ) > 10 || ( strlen( $clean_number ) === 10 && ! isset( $country_codes[ strtoupper( $country ) ] ) );

	if ( ! $has_country_code ) {
		// Prepend the country code based on the provided country
		$country_code = isset( $country_codes[ strtoupper( $country ) ] ) ? $country_codes[ strtoupper( $country ) ] : '';
		if ( ! empty( $country_code ) ) {
			$clean_number = $country_code . $clean_number;
		}
	}

	// Ensure the number starts with a '+'
	if ( ! empty( $clean_number ) && false === strpos( $clean_number, '+' ) ) {
		$clean_number = '+' . $clean_number;
	}

	return apply_filters( 'wpf_phone_number_to_e164', $clean_number, $country );
}

/**
 * Is the current user an admin, and admins are excluded from restrictions?
 *
 * @since  3.6.26
 *
 * @return bool  Whether or not to do an admin override.
 */
function wpf_admin_override() {

	// Don't use user_can() here, it creates a memory leak with WPML for some reason.

	if ( wpf_get_option( 'exclude_admins' ) && current_user_can( 'manage_options' ) ) {
		$override = true;
	} else {
		$override = false;
	}

	return apply_filters( 'wpf_admin_override', $override );
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
 * Updates an option in the WP Fusion settings.
 *
 * @since 3.44.12
 *
 * @param string $key   The settings key.
 * @param mixed  $value The value to update.
 * @return bool Whether the option was updated.
 */
function wpf_update_option( $key, $value ) {
	return wp_fusion()->settings->set( $key, $value );
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
	} elseif ( is_bool( $var ) ) {
		return $var;
	} else {

		$allowed_html = apply_filters( 'wpf_wp_kses_allowed_html', wp_kses_allowed_html( 'post' ) );

		if ( is_scalar( $var ) ) {
			$var = wp_kses( $var, $allowed_html );
			$var = htmlspecialchars_decode( $var ); // we need special characters to be left alone.
		}

		return $var;
	}
}

/**
 * Validates a phone number.
 *
 * @since 3.41.24
 *
 * @param string $input The number.
 * @return bool Whether or not the number is valid.
 */
function wpf_validate_phone_number( $input ) {

	// Remove all non-digit characters from the input.
	$cleaned_input = preg_replace( '/[^0-9+]/', '', $input );

	// Validate the phone number format
	// The pattern allows for an optional '+' at the start, followed by digits, spaces, dashes, and parentheses.
	$pattern = '/^\+?\d[\d\s\-()]*$/';

	return preg_match( $pattern, $cleaned_input ) === 1;
}

/**
 * Sanitizes an array of tags while preserving special characters.
 *
 * @since  3.38.15
 *
 * @param  array $tags   The tags.
 * @return array The tags.
 */
function wpf_clean_tags( $tags ) {

	if ( ! is_array( $tags ) ) {
		$tags = array( $tags );
	}

	$tags = array_values( array_filter( $tags ) ); // Remove any empties.

	$tags = array_map( 'sanitize_text_field', $tags ); // Tags should be treated as an array of strings.

	$tags = array_map( 'htmlspecialchars_decode', $tags ); // sanitize_text_field removes HTML special characters so we'll add them back.

	// If we've switched from a CRM with add_tags to one without, we can convert labels to IDs automatically.
	if ( ! empty( $tags ) && wp_fusion()->crm && ! wp_fusion()->crm->supports( 'add_tags' ) && ! isset( wpf_get_option( 'available_tags' )[ $tags[0] ] ) ) {
		$tags = array_map( 'wpf_get_tag_id', $tags );
	}

	return array_filter( $tags );
}

/**
 * Shortcodes added to the block editor sometimes have their quotes encoded. This fixes that.
 *
 * @since  3.41.39
 *
 * @param  array<string,string> $atts The attributes.
 * @return array<string,string> The attributes.
 */
function wpf_shortcode_atts( $atts ) {

	foreach ( $atts as $key => $value ) {

		// Remove encoded quotes.
		$atts[ $key ] = str_replace( array( '&#8220;', '&#8221;', '&#8243;', '&#8242;' ), array( '', '', '', '' ), $value );

	}

	$atts = array_map( 'sanitize_text_field', $atts );

	return $atts;
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

/**
 * Get ISO 3166-1 alpha-3 and alpha-2 codes.
 *
 * @since 3.44.3
 *
 * @param string $name_or_abbreviation Country name or abbreviation.
 * @param string $format               alpha-3 or alpha-2.
 * @return string|false ISO 3166-1 alpha-3 or alpha-2 code or false if not found.
 */
function wpf_country_to_iso3166( $name_or_abbreviation, $format = 'alpha-3' ) {
	return wp_fusion()->iso_regions->country_to_alpha( $name_or_abbreviation, $format );
}

/**
 * Get ISO 3166-2 code.
 *
 * @since 3.44.3
 *
 * @param string $name State name.
 * @return string|false ISO 3166-2 code or false if not found.
 */
function wpf_state_to_iso3166( $name ) {
	return wp_fusion()->iso_regions->state_name_to_alpha( $name );
}

/**
 * Get ISO 8601 date.
 *
 * Takes an input string and returns an ISO 8601 compatible date for API calls,
 * for example 2024-04-24T00:18:01+08:00.
 *
 * @since 3.43.6
 *
 * @param int|string $timestamp      The timestamp to convert (in GMT by default).
 * @param bool       $convert_to_gmt Whether the input date is local and needs to be converted to GMT.
 * @return string|false The ISO 8601 date and time in GMT, or false if an error occurred.
 */
function wpf_get_iso8601_date( $timestamp = null, $convert_to_gmt = false ) {

	try {

		if ( empty( $timestamp ) ) {
			$timestamp = gmdate( 'U' ); // If no timestamp is provided, use the current time.
		} else {
			$timestamp = intval( $timestamp ); // Ensure the timestamp is an integer.
		}

		$offset = get_option( 'gmt_offset' );

		// Convert date to GMT.
		if ( $convert_to_gmt ) {
			$timestamp -= $offset * 60 * 60;
		}

		$datetime = new DateTime( gmdate( 'c', $timestamp ) );

		// DateTimeZone throws an error with 0 as the timezone.
		if ( $offset >= 0 ) {
			$offset = '+' . $offset;
		}

		$datetime->setTimezone( new DateTimeZone( $offset ) );

		if ( '+0' === $offset ) {
			return $datetime->format( 'Y-m-d\TH:i:s\Z' );
		} else {
			return $datetime->format( 'c' );
		}
	} catch ( Exception $e ) {

		wpf_log( 'error', wpf_get_current_user_id(), 'Error getting ISO 8601 date from timestamp: ' . $timestamp . '. Error: ' . $e->getMessage() );
		return false;

	}
}

/**
 * Splits a full name into first and last name components
 *
 * @since 3.44.26
 *
 * @param string $full_name The full name to split.
 * @return array Array containing first_name and last_name.
 */
function wpf_get_name_from_full_name( $full_name ) {

	$full_name = trim( $full_name );

	if ( empty( $full_name ) || false === strpos( $full_name, ' ' ) ) {
		return array(
			'first_name' => $full_name,
			'last_name'  => '',
		);
	}

	$parts = explode( ' ', $full_name );

	// First name is always the first part.
	$first_name = array_shift( $parts );

	// Last name is everything else joined together.
	$last_name = implode( ' ', $parts );

	return array(
		'first_name' => $first_name,
		'last_name'  => $last_name,
	);
}

/**
 * Retrieve the metadata from the provided asset map.
 *
 * @since 3.44.23
 *
 * @param string $path The path to the asset map.
 *
 * @return array The asset map metadata.
 */
function wpf_get_asset_meta( $path ) {
	return file_exists( $path ) ? require $path : array(
		'dependencies' => array(),
		'version'      => WP_FUSION_VERSION,
	);
}
