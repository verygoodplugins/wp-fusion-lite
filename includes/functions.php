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

	function wpf_has_tag( $tag, $user_id = false ) {

		return wp_fusion()->user->has_tag( $tag, $user_id );

	}
}

/**
 * Gets the current user ID, with support for auto-logged-in users
 *
 * @return int User ID
 */

if ( ! function_exists( 'wpf_get_current_user_id' ) ) {

	function wpf_get_current_user_id() {

		return wp_fusion()->user->get_current_user_id();

	}
}

/**
 * Checks if user is logged in, with support for auto-logged-in users
 *
 * @return bool Logged In
 */

if ( ! function_exists( 'wpf_is_user_logged_in' ) ) {

	function wpf_is_user_logged_in() {

		return wp_fusion()->user->is_user_logged_in();

	}
}