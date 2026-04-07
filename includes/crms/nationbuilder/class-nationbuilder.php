<?php
/**
 * WP Fusion - NationBuilder CRM Integration
 *
 * @package WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license GPL-3.0+
 * @since 3.37.14
 */

/**
 * NationBuilder CRM class.
 *
 * @since 3.47.6
 */
class WPF_NationBuilder {

	/**
	 * The CRM slug.
	 *
	 * @var string
	 */
	public $slug = 'nationbuilder';

	/**
	 * The CRM name.
	 *
	 * @var string
	 */
	public $name = 'NationBuilder';

	/**
	 * Lets pluggable functions know which features are supported by the CRM.
	 *
	 * @var array
	 */
	public $supports = array( 'add_tags', 'lists' );

	/**
	 * Contains API params.
	 *
	 * @var array
	 */
	public $params;

	/**
	 * NationBuilder v1 OAuth client ID.
	 *
	 * @var string
	 */
	public $client_id;

	/**
	 * NationBuilder v1 OAuth client secret.
	 *
	 * @var string
	 */
	public $client_secret;

	/**
	 * NationBuilder v2 OAuth client ID (current access tokens app).
	 *
	 * @since 3.47.6
	 * @var string
	 */
	public $client_id_v2;

	/**
	 * NationBuilder v2 OAuth client secret (current access tokens app).
	 *
	 * @since 3.47.6
	 * @var string
	 */
	public $client_secret_v2;

	/**
	 * OAuth access token.
	 *
	 * @var string
	 */
	public $token;

	/**
	 * NationBuilder URL slug.
	 *
	 * @var string
	 */
	public $url_slug;

	/**
	 * Lets us link directly to editing a contact record.
	 *
	 * @since 3.37.30
	 * @var  string
	 */

	public $edit_url = '';

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   2.0
	 */
	public function __construct() {

		// v1 OAuth credentials.
		$this->client_id     = '8c06f23bba8806809b946b0cf07e3bc6788909d806d34fc75d801e32c01f07c0';
		$this->client_secret = '19bb5d590c55e6b1aeabb7f6bd07a14a616e2852b1a55ce6b396aada83c8ea7f';

		// v2 OAuth credentials (current access tokens app).
		$this->client_id_v2     = '_SorYMBZrruOdnFumaNRGCzFSucSM7nJ16eeSjlyRN8';
		$this->client_secret_v2 = 'qrszIb_LcANBOA-mkrCk4UI6g3fc_ywbTURjaSvD2ic';

		// Set up admin options.
		if ( is_admin() ) {
			require_once __DIR__ . '/admin/class-admin.php';
			new WPF_NationBuilder_Admin( $this->slug, $this->name, $this );
		}
	}

	/**
	 * Sets up hooks specific to this CRM
	 *
	 * @access public
	 * @return void
	 */
	public function init() {

		add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 3 );

		// Error handling.
		add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
		add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );

		// Upgrade notice.
		add_action( 'wpf_settings_notices', array( $this, 'api_key_warning' ) );
		add_action( 'admin_notices', array( $this, 'api_key_warning' ) );

		$url_slug = wpf_get_option( 'nationbuilder_slug' );

		if ( ! empty( $url_slug ) ) {
			$this->edit_url = 'https://' . $url_slug . '.nationbuilder.com/admin/signups/%d';
		}
	}

	/**
	 * Display a notice to upgrade to OAuth v2.
	 *
	 * @since 3.47.6
	 *
	 * @return void
	 */
	public function api_key_warning() {

		if ( wpf_get_option( 'crm' ) !== $this->slug ) {
			return;
		}

		if ( empty( wpf_get_option( 'nationbuilder_token' ) ) || $this->use_v2() ) {
			return;
		}

		echo '<div class="notice notice-warning wpf-notice"><p>';

		$message = sprintf(
			// translators: %s is the settings page URL.
			__( '<strong>Heads up:</strong> NationBuilder now uses OAuth for the v2 API. Please re-authorize your connection on the <a href="%s">Setup tab</a> to avoid service interruption.', 'wp-fusion-lite' ),
			esc_url( admin_url( 'options-general.php?page=wpf-settings#setup' ) )
		);

		echo wp_kses_post( $message );

		echo '</p></div>';
	}


	/**
	 * Formats user entered data to match NationBuilder field formats.
	 *
	 * @since  3.40.7
	 * @phpcsSuppress WordPress.CodeAnalysis.UnusedFunctionParameter
	 *
	 * @param  mixed  $value      The value.
	 * @param  string $field_type The field type from the WPF settings.
	 * @param  string $field      The CRM field ID.
	 * @return mixed  The formatted value.
	 */
	public function format_field_value( $value, $field_type, $field ) {

		unset( $field );

		if ( 'date' === $field_type ) {

			if ( ! empty( $value ) && is_numeric( $value ) ) {

				$value = gmdate( 'Y-m-d', $value );

			}
		}

		return $value;
	}


	/**
	 * Formats POST data received from HTTP Posts into standard format
	 *
	 * @access public
	 *
	 * @param array $post_data The post data.
	 * @return array|false The formatted post data or false on failure.
	 */
	public function format_post_data( $post_data ) {

		if ( isset( $post_data['contact_id'] ) ) {
			return $post_data;
		}

		$payload = json_decode( file_get_contents( 'php://input' ) );

		if ( ! is_object( $payload ) ) {
			return false;
		}

		$post_data['contact_id'] = absint( $payload->payload->person->id );

		return $post_data;
	}


	/**
	 * Gets params for API calls.
	 *
	 * @access  public
	 *
	 * @param string|null $access_token Optional access token.
	 * @param string|null $url_slug     Optional URL slug.
	 * @return  array Params
	 */
	public function get_params( $access_token = null, $url_slug = null ) {

		// Get saved data from DB.
		if ( empty( $access_token ) || empty( $url_slug ) ) {
			$access_token = wpf_get_option( 'nationbuilder_token' );
			$url_slug     = wpf_get_option( 'nationbuilder_slug' );
		}

		// Proactively refresh the token if it is about to expire.
		if ( $this->use_v2() && empty( $this->params ) ) {
			$refreshed_token = $this->maybe_refresh_token( $url_slug );

			if ( ! empty( $refreshed_token ) ) {
				$access_token = $refreshed_token;
			}
		}

		$this->params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);

		if ( $this->use_v2() && ! empty( $access_token ) ) {
			$this->params['headers']['Content-Type']  = 'application/vnd.api+json';
			$this->params['headers']['Accept']        = 'application/vnd.api+json';
			$this->params['headers']['Authorization'] = 'Bearer ' . $access_token;
		}

		$this->token    = $access_token;
		$this->url_slug = $url_slug;

		return $this->params;
	}

	/**
	 * Refresh the access token if it is about to expire.
	 *
	 * V2 access tokens expire in 24 hours. This method checks the stored
	 * expiry time and refreshes proactively if within 5 minutes of expiry.
	 *
	 * @since 3.47.6
	 *
	 * @param string|null $url_slug Optional URL slug.
	 * @return string|false The new access token, or false if no refresh needed or on failure.
	 */
	protected function maybe_refresh_token( $url_slug = null ) {

		$token_expires = wpf_get_option( 'nationbuilder_token_expires' );

		if ( empty( $token_expires ) ) {
			return false;
		}

		// Refresh if within 5 minutes of expiry.
		if ( time() < ( $token_expires - 300 ) ) {
			return false;
		}

		$refresh_token = wpf_get_option( 'nationbuilder_refresh_token' );

		if ( empty( $refresh_token ) ) {
			wpf_log( 'error', 0, 'NationBuilder access token expired but no refresh token is available. Please re-authorize.' );
			return false;
		}

		if ( empty( $url_slug ) ) {
			$url_slug = wpf_get_option( 'nationbuilder_slug' );
		}

		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => $this->client_id_v2,
			'client_secret' => $this->client_secret_v2,
		);

		$params = array(
			'timeout'    => 30,
			'user-agent' => 'WP Fusion; ' . home_url(),
			'headers'    => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'       => wp_json_encode( $body ),
		);

		wpf_log( 'info', 0, 'Refreshing NationBuilder access token.' );

		$response = wp_safe_remote_post(
			'https://' . $url_slug . '.nationbuilder.com/oauth/token',
			$params
		);

		if ( is_wp_error( $response ) ) {
			wpf_log( 'error', 0, 'Failed to refresh NationBuilder token: ' . $response->get_error_message() );
			return false;
		}

		$body_raw = wp_remote_retrieve_body( $response );

		// Detect Cloudflare challenge pages (bot protection).
		if ( false !== strpos( $body_raw, 'cf-mitigated' ) || false !== strpos( $body_raw, 'cf_chl_opt' ) ) {
			wpf_log( 'error', 0, 'NationBuilder\'s firewall (Cloudflare) is blocking requests from your server. This usually means your hosting server\'s IP address has a low reputation score. Please contact NationBuilder support and ask them to whitelist your server IP, or try a different hosting provider.' );
			return false;
		}

		$body_response = json_decode( $body_raw );

		if ( empty( $body_response->access_token ) ) {
			wpf_log( 'error', 0, 'NationBuilder token refresh returned an unexpected response. Please re-authorize.' );
			return false;
		}

		// Store the new tokens.
		wp_fusion()->settings->set( 'nationbuilder_token', $body_response->access_token );

		if ( ! empty( $body_response->refresh_token ) ) {
			wp_fusion()->settings->set( 'nationbuilder_refresh_token', $body_response->refresh_token );
		}

		if ( ! empty( $body_response->expires_in ) ) {
			wp_fusion()->settings->set( 'nationbuilder_token_expires', time() + (int) $body_response->expires_in );
		}

		wpf_log( 'info', 0, 'NationBuilder access token refreshed successfully.' );

		return $body_response->access_token;
	}

	/**
	 * Check if the NationBuilder connection is using v2 endpoints.
	 *
	 * @since 3.47.6
	 *
	 * @return bool True if v2 is enabled.
	 */
	public function use_v2() {
		return (bool) wpf_get_option( 'nationbuilder_use_v2' );
	}

	/**
	 * Build an API URL for the current NationBuilder version.
	 *
	 * @since 3.47.6
	 *
	 * @param string $path       Path to append.
	 * @param array  $query_args Optional query arguments.
	 * @return string The full URL.
	 */
	protected function get_api_url( $path, $query_args = array() ) {
		$version = $this->use_v2() ? 'v2' : 'v1';
		$base    = 'https://' . $this->url_slug . '.nationbuilder.com/api/' . $version;

		if ( ! $this->use_v2() ) {
			$query_args['access_token'] = $this->token;
		}

		$url = $base . $path;

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		return $url;
	}

	/**
	 * Check HTTP Response for errors and return WP_Error if found
	 *
	 * @access public
	 *
	 * @param mixed  $response The response or WP_Error.
	 * @param array  $args     The request arguments.
	 * @param string $url      The request URL.
	 * @return mixed HTTP Response or WP_Error.
	 */
	public function handle_http_response( $response, $args, $url ) {

		if ( false !== strpos( $url, 'nationbuilder' ) && 'WP Fusion; ' . home_url() === $args['user-agent'] ) {

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			// Handle expired v2 tokens with a refresh attempt.
			if ( 401 === $response_code && $this->use_v2() ) {

				$new_token = $this->maybe_refresh_token();

				if ( ! empty( $new_token ) ) {

					// Update the Authorization header and retry.
					$args['headers']['Authorization'] = 'Bearer ' . $new_token;
					$this->token                      = $new_token;

					return wp_safe_remote_request( $url, $args );
				}
			}

			if ( $response_code > 204 ) {

				// 422 on path_journeys means duplicate - treat as success.
				if ( 422 === $response_code && false !== strpos( $url, '/path_journeys' ) ) {
					return $response;
				}

				$body = json_decode( wp_remote_retrieve_body( $response ) );

				if ( ! empty( $body->errors ) && is_array( $body->errors ) ) {

					// Check if errors are about unknown/unwritable attributes
					// so we can strip them and retry to save the valid fields.
					$invalid_fields   = array();
					$all_field_errors = true;

					foreach ( $body->errors as $err ) {
						$detail = ! empty( $err->detail ) ? $err->detail : '';

						if ( preg_match( '/data\.attributes\.(\S+)\s+(is an unknown attribute|cannot be written)/', $detail, $matches ) ) {
							$invalid_fields[] = $matches[1];
						} else {
							$all_field_errors = false;
						}
					}

					// If we found invalid fields and no other error types, strip them and retry once.
					if ( ! empty( $invalid_fields ) && $all_field_errors && empty( $args['wpf_retried_unknown_fields'] ) ) {

						$body_data = json_decode( $args['body'], true );

						if ( ! empty( $body_data['data']['attributes'] ) ) {

							foreach ( $invalid_fields as $field_name ) {
								unset( $body_data['data']['attributes'][ $field_name ] );
							}

							wpf_log(
								'notice',
								0,
								'NationBuilder rejected unknown fields: ' . implode( ', ', $invalid_fields ) . '. Retrying without them. Please check your field mappings in Settings &raquo; Contact Fields.',
								array( 'source' => 'nationbuilder' )
							);

							$args['body']                       = wp_json_encode( $body_data );
							$args['wpf_retried_unknown_fields'] = true;

							return wp_safe_remote_request( $url, $args );
						}
					}

					$error = reset( $body->errors );
					$code  = ! empty( $error->code ) ? $error->code : 'error';

					// Concatenate all error details for better debugging.
					$messages = array();
					foreach ( $body->errors as $err ) {
						$messages[] = ! empty( $err->detail ) ? $err->detail : $err->title;
					}

					$response = new WP_Error( $code, implode( ' ', $messages ) );

				} elseif ( ! empty( $body->code ) ) {

					if ( 'no_matches' === $body->code && false !== strpos( $url, 'people/match' ) ) {

						// This one is okay.
						return $response;

					} elseif ( 'validation_failed' === $body->code && 'email has already been taken' === $body->validation_errors[0] ) {

						$response = new WP_Error( 'duplicate', $body->message );

					} else {

						$response = new WP_Error( 'error', $body->message );

					}
			} else {

				$body_raw = wp_remote_retrieve_body( $response );

				// Detect Cloudflare challenge pages (bot protection).
				if ( false !== strpos( $body_raw, 'cf-mitigated' ) || false !== strpos( $body_raw, 'cf_chl_opt' ) ) {
					$response = new WP_Error(
						'cloudflare_blocked',
						__( 'NationBuilder\'s firewall (Cloudflare) is blocking requests from your server. This usually means your hosting server\'s IP address has a low reputation score. Please contact NationBuilder support and ask them to whitelist your server IP, or try a different hosting provider.', 'wp-fusion-lite' )
					);
				} else {
					$response = new WP_Error( 'error', wp_remote_retrieve_response_message( $response ) );
				}

			}
		}
	}

	return $response;
}



	/**
	 * Initialize connection
	 *
	 * @access  public
	 *
	 * @param string|null $access_token Optional access token.
	 * @param string|null $slug         Optional URL slug.
	 * @param bool        $test         Whether to test the connection.
	 * @return  bool
	 */
	public function connect( $access_token = null, $slug = null, $test = false ) {

		if ( ! $this->params ) {
			$this->get_params( $access_token, $slug );
		}

		if ( false === $test ) {
			return true;
		}

		if ( $this->use_v2() ) {
			$request = $this->get_api_url( '/signups', array( 'page[size]' => 1 ) );
		} else {
			$request = $this->get_api_url( '/people' );
		}

		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Performs initial sync once connection is configured
	 *
	 * @access public
	 * @return bool
	 */
	public function sync() {

		$this->connect();

		$this->sync_tags();
		$this->sync_lists();
		$this->sync_crm_fields();

		do_action( 'wpf_sync' );

		return true;
	}


	/**
	 * Gets all available tags and saves them to options
	 *
	 * @access public
	 * @return array Lists
	 */
	public function sync_tags() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return $this->use_v2() ? $this->sync_tags_v2() : $this->sync_tags_v1();
	}

	/**
	 * Sync tags from the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @return array|WP_Error The available tags or error.
	 */
	protected function sync_tags_v2() {

		$available_tags = array();
		$page           = 1;
		$continue       = true;

		while ( $continue ) {

			$request  = $this->get_api_url(
				'/signup_tags',
				array(
					'page[number]' => $page,
					'page[size]'   => 100,
				)
			);
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->data ) ) {
				$continue = false;
				continue;
			}

			foreach ( $response->data as $tag ) {
				if ( ! empty( $tag->id ) && ! empty( $tag->attributes->name ) ) {
					$available_tags[ $tag->id ] = array(
						'label'    => $tag->attributes->name,
						'category' => __( 'Tags', 'wp-fusion-lite' ),
					);
				}
			}

			if ( empty( $response->links->next ) ) {
				$continue = false;
			} else {
				++$page;
			}
		}

		// Sync paths for optgroup UX (Tags vs Paths).
		$page     = 1;
		$continue = true;

		while ( $continue ) {

			$request  = $this->get_api_url(
				'/paths',
				array(
					'page[number]' => $page,
					'page[size]'   => 100,
				)
			);
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				wpf_log( 'error', 0, 'Error fetching NationBuilder paths: ' . $response->get_error_message() );
				break; // Continue with tags we have.
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->data ) ) {
				$continue = false;
				continue;
			}

			foreach ( $response->data as $path ) {
				if ( ! empty( $path->id ) && ! empty( $path->attributes->name ) ) {
					$available_tags[ 'path_' . $path->id ] = array(
						'label'    => $path->attributes->name,
						'category' => __( 'Paths', 'wp-fusion-lite' ),
					);
				}
			}

			if ( empty( $response->links->next ) ) {
				$continue = false;
			} else {
				++$page;
			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}

	/**
	 * Sync tags from the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @return array|WP_Error The available tags or error.
	 */
	protected function sync_tags_v1() {

		$available_tags = array();
		$continue       = true;
		$next_url       = false;

		while ( $continue ) {

			$request = 'https://' . $this->url_slug . '.nationbuilder.com';

			if ( false !== $next_url ) {
				$request .= $next_url;
			} else {
				$request .= '/api/v1/tags?limit=100';
			}

			$request .= '&access_token=' . $this->token;

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->results ) ) {

				foreach ( $response->results as $tag ) {

					$available_tags[ $tag->name ] = $tag->name;

				}
			}

			if ( empty( $response->next ) ) {

				$continue = false;

			} else {

				$next_url = $response->next;

			}
		}

		wp_fusion()->settings->set( 'available_tags', $available_tags );

		return $available_tags;
	}


	/**
	 * Loads all custom fields from CRM and merges with local list
	 *
	 * @access public
	 * @return array CRM Fields
	 */
	public function sync_crm_fields() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		// Load built in fields first.

		require __DIR__ . '/admin/nationbuilder-fields.php';

		$built_in_fields = array();

		foreach ( $nationbuilder_fields as $data ) {
			$built_in_fields[ $data['crm_field'] ] = $data['crm_label'];
		}

		asort( $built_in_fields );

		// Then get custom ones.

		$request  = $this->use_v2()
			? $this->get_api_url( '/signups/me' )
			: $this->get_api_url( '/people/me' );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		$custom_fields = array();

		$use_v2 = $this->use_v2();
		$record = $use_v2 && isset( $response->data->attributes )
			? $response->data->attributes
			: ( isset( $response->person ) ? $response->person : array() );

		foreach ( $record as $field => $value ) {
			if ( $use_v2 && 'custom_values' === $field && is_object( $value ) ) {
				foreach ( $value as $custom_key => $custom_value ) {
					if ( ! isset( $built_in_fields[ $custom_key ] ) ) {
						$custom_fields[ 'custom_values+' . $custom_key ] = $custom_key;
					}
				}
				continue;
			}

			if ( ! isset( $built_in_fields[ $field ] )
				&& ! in_array( $field, $nationbuilder_ignore_fields, true )
			) {
				$custom_fields[ $field ] = $field;
			}
		}

		asort( $custom_fields );

		$crm_fields = array(
			'Standard Fields' => $built_in_fields,
			'Custom Fields'   => $custom_fields,
		);

		wp_fusion()->settings->set( 'crm_fields', $crm_fields );

		return $crm_fields;
	}


	/**
	 * Gets all available lists and saves them to options.
	 *
	 * @since 3.47.6
	 *
	 * @return array|WP_Error The available lists or error.
	 */
	public function sync_lists() {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return $this->use_v2() ? $this->sync_lists_v2() : $this->sync_lists_v1();
	}

	/**
	 * Sync lists from the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @return array|WP_Error The available lists or error.
	 */
	protected function sync_lists_v2() {

		$available_lists = array();
		$page            = 1;
		$continue        = true;

		while ( $continue ) {

			$request  = $this->get_api_url(
				'/lists',
				array(
					'page[number]' => $page,
					'page[size]'   => 100,
				)
			);
			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->data ) ) {
				$continue = false;
				continue;
			}

			foreach ( $response->data as $list ) {
				if ( ! empty( $list->id ) && ! empty( $list->attributes->name ) ) {
					$available_lists[ $list->id ] = $list->attributes->name;
				}
			}

			if ( 100 > count( $response->data ) ) {
				$continue = false;
			}

			++$page;
		}

		natcasesort( $available_lists );

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;
	}

	/**
	 * Sync lists from the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @return array|WP_Error The available lists or error.
	 */
	protected function sync_lists_v1() {

		$available_lists = array();
		$continue        = true;
		$next_url        = false;

		while ( $continue ) {

			$request = 'https://' . $this->url_slug . '.nationbuilder.com';

			if ( false !== $next_url ) {
				$request .= $next_url;
			} else {
				$request .= '/api/v1/lists?limit=100';
			}

			$request .= '&access_token=' . $this->token;

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $response->results ) ) {
				foreach ( $response->results as $list ) {
					$available_lists[ $list->id ] = $list->name;
				}
			}

			if ( empty( $response->next ) ) {
				$continue = false;
			} else {
				$next_url = $response->next;
			}
		}

		natcasesort( $available_lists );

		wp_fusion()->settings->set( 'available_lists', $available_lists );

		return $available_lists;
	}

	/**
	 * Add a contact to a list.
	 *
	 * The V2 API does not have a native JSON:API endpoint for managing
	 * list membership, so we use the V1 endpoint which accepts Bearer
	 * authentication from V2 tokens.
	 *
	 * @since 3.47.6
	 *
	 * @param int $contact_id The contact ID.
	 * @param int $list_id    The list ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function add_contact_to_list( $contact_id, $list_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_id = absint( $contact_id );
		$list_id    = absint( $list_id );

		if ( 0 === $contact_id || 0 === $list_id ) {
			return new WP_Error( 'invalid_parameter', __( 'Invalid list or contact ID.', 'wp-fusion-lite' ) );
		}

		$params           = $this->params;
		$params['method'] = 'POST';
		$params['body']   = wp_json_encode(
			array( 'people_ids' => array( (int) $contact_id ) )
		);

		// The V1 lists/people endpoint works with V2 Bearer auth.
		$request = 'https://' . $this->url_slug
			. '.nationbuilder.com/api/v1/lists/' . $list_id . '/people';

		if ( ! $this->use_v2() ) {
			$request = add_query_arg( 'access_token', $this->token, $request );
		}

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Remove a contact from a list.
	 *
	 * @since 3.47.6
	 *
	 * @param int $contact_id The contact ID.
	 * @param int $list_id    The list ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function remove_contact_from_list( $contact_id, $list_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$contact_id = absint( $contact_id );
		$list_id    = absint( $list_id );

		if ( 0 === $contact_id || 0 === $list_id ) {
			return new WP_Error( 'invalid_parameter', __( 'Invalid list or contact ID.', 'wp-fusion-lite' ) );
		}

		$params           = $this->params;
		$params['method'] = 'DELETE';
		$params['body']   = wp_json_encode(
			array( 'people_ids' => array( (int) $contact_id ) )
		);

		// The V1 lists/people endpoint works with V2 Bearer auth.
		$request = 'https://' . $this->url_slug
			. '.nationbuilder.com/api/v1/lists/' . $list_id . '/people';

		if ( ! $this->use_v2() ) {
			$request = add_query_arg( 'access_token', $this->token, $request );
		}

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Gets contact ID for a user based on email address
	 *
	 * @access public
	 *
	 * @param string $email_address The email address.
	 * @return int Contact ID
	 */
	public function get_contact_id( $email_address ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		$use_v2 = $this->use_v2();

		if ( $use_v2 ) {
			$request = $this->get_api_url(
				'/signups',
				array(
					'filter[with_email_address][eq]' => $email_address,
					'page[size]'                     => 1,
				)
			);
		} else {
			$request = $this->get_api_url(
				'/people/match',
				array(
					'email' => $email_address,
				)
			);
		}
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $use_v2 ) {
			if ( empty( $response->data ) ) {
				return false;
			}

			$first = reset( $response->data );

			return isset( $first->id ) ? $first->id : false;
		}

		if ( isset( $response->code ) && 'no_matches' === $response->code ) {
			return false;
		}

		return $response->person->id;
	}


	/**
	 * Gets all tags currently applied to the user, also update the list of available tags
	 *
	 * @access public
	 *
	 * @param int $contact_id The contact ID.
	 * @return array|WP_Error The tags or error.
	 */
	public function get_tags( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return $this->use_v2()
			? $this->get_tags_v2( $contact_id )
			: $this->get_tags_v1( $contact_id );
	}

	/**
	 * Get tags for a contact via the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @param int $contact_id The contact ID.
	 * @return array|WP_Error The tag IDs or error.
	 */
	protected function get_tags_v2( $contact_id ) {

		$tags           = array();
		$page           = 1;
		$continue       = true;
		$available_tags = wpf_get_option( 'available_tags', array() );
		$needs_update   = false;

		while ( $continue ) {
			$request = $this->get_api_url(
				'/signup_tags',
				array(
					'filter[signup_id]' => $contact_id,
					'page[number]'      => $page,
					'page[size]'        => 100,
				)
			);

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->data ) ) {
				$continue = false;
				continue;
			}

			foreach ( $response->data as $tag ) {
				if ( empty( $tag->id ) ) {
					continue;
				}

				$tags[] = $tag->id;

				if ( ! empty( $tag->attributes->name ) && ! isset( $available_tags[ $tag->id ] ) ) {
					$available_tags[ $tag->id ] = array(
						'label'    => $tag->attributes->name,
						'category' => __( 'Tags', 'wp-fusion-lite' ),
					);
					$needs_update               = true;
				}
			}

			if ( empty( $response->links->next ) ) {
				$continue = false;
			} else {
				++$page;
			}
		}

		// Fetch path journeys for this contact (active paths).
		$path_page     = 1;
		$path_continue = true;

		while ( $path_continue ) {
			$request = $this->get_api_url(
				'/path_journeys',
				array(
					'filter[signup_id]' => $contact_id,
					'page[number]'      => $path_page,
					'page[size]'        => 100,
				)
			);

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				wpf_log( 'error', $contact_id, 'Error fetching path_journeys: ' . $response->get_error_message() );
				break; // Return tags we have.
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->data ) ) {
				$path_continue = false;
				continue;
			}

			foreach ( $response->data as $journey ) {
				if ( empty( $journey->attributes->path_id ) ) {
					continue;
				}

				$path_key = 'path_' . $journey->attributes->path_id;
				$tags[]   = $path_key;

				if ( ! isset( $available_tags[ $path_key ] ) ) {
					$available_tags[ $path_key ] = array(
						'label'    => sprintf(
							/* translators: %s: path ID */
							__( 'Path %s', 'wp-fusion-lite' ),
							$journey->attributes->path_id
						),
						'category' => __( 'Paths', 'wp-fusion-lite' ),
					);
					$needs_update                = true;
				}
			}

			if ( empty( $response->links->next ) ) {
				$path_continue = false;
			} else {
				++$path_page;
			}
		}

		if ( $needs_update ) {
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		}

		return $tags;
	}

	/**
	 * Get tags for a contact via the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @param int $contact_id The contact ID.
	 * @return array|WP_Error The tag names or error.
	 */
	protected function get_tags_v1( $contact_id ) {

		$tags     = array();
		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '/taggings?access_token=' . $this->token;
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response->taggings ) ) {
			return $tags;
		}

		$available_tags = wpf_get_option( 'available_tags', array() );
		$needs_update   = false;

		foreach ( $response->taggings as $tag ) {

			$tags[] = $tag->tag;

			if ( ! in_array( $tag->tag, $available_tags, true ) ) {
				$available_tags[] = $tag->tag;
				$needs_update     = true;
			}
		}

		if ( $needs_update ) {

			asort( $available_tags );
			wp_fusion()->settings->set( 'available_tags', $available_tags );

		}

		return $tags;
	}

	/**
	 * Normalize tag IDs for v2 requests.
	 *
	 * @since 3.47.6
	 *
	 * @param array $tags Tags to normalize.
	 * @return array Tag IDs.
	 */
	protected function normalize_tag_ids( $tags ) {

		$available_tags = wp_fusion()->settings->get_available_tags_flat( true, false );
		$tag_ids        = array();
		$use_v2         = $this->use_v2();

		foreach ( $tags as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}

			$tag = (string) $tag;

			if ( $use_v2 ) {
				if ( 0 === strpos( $tag, 'path_' ) ) {
					$tag_ids[] = $tag;
					continue;
				}

				if ( ctype_digit( $tag ) ) {
					$tag_ids[] = $tag;
					continue;
				}
			} elseif ( isset( $available_tags[ $tag ] ) ) {
				$tag_ids[] = $tag;
				continue;
			}

			// Try exact match by name.
			$tag_id = array_search( $tag, $available_tags, true );

			// Fallback: case-insensitive, trimmed match.
			if ( false === $tag_id ) {
				$tag_lower = strtolower( trim( $tag ) );

				foreach ( $available_tags as $id => $name ) {
					if ( strtolower( trim( $name ) ) === $tag_lower ) {
						$tag_id = $id;
						break;
					}
				}
			}

			if ( false !== $tag_id ) {
				$tag_ids[] = $tag_id;
			}
		}

		return array_values( array_unique( $tag_ids ) );
	}

	/**
	 * Find an existing tag by name or create a new one via the v2 API.
	 *
	 * Used when a tag name cannot be resolved from the local available
	 * tags cache, e.g. after migrating from v1 to v2 or when a tag was
	 * renamed in NationBuilder.
	 *
	 * @since 3.47.9
	 *
	 * @param string $tag_name The tag name.
	 * @return int|false The numeric tag ID, or false on failure.
	 */
	protected function find_or_create_tag_v2( $tag_name ) {

		$tag_name = trim( $tag_name );

		if ( empty( $tag_name ) ) {
			return false;
		}

		// Search the v2 API for an existing tag with this name.
		$request  = $this->get_api_url(
			'/signup_tags',
			array(
				'filter[name][eq]' => $tag_name,
				'page[size]'       => 1,
			)
		);
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			wpf_log(
				'error',
				0,
				'Failed to look up tag "' . $tag_name . '" in NationBuilder: ' . $response->get_error_message(),
				array( 'source' => 'nationbuilder' )
			);
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->data ) ) {
			$first  = reset( $body->data );
			$tag_id = isset( $first->id ) ? $first->id : false;

			if ( $tag_id ) {
				$this->cache_tag( $tag_id, $tag_name );
				return $tag_id;
			}
		}

		// Tag does not exist yet -- create it.
		$params           = $this->params;
		$params['method'] = 'POST';
		$params['body']   = wp_json_encode(
			array(
				'data' => array(
					'type'       => 'signup_tags',
					'attributes' => array(
						'name' => $tag_name,
					),
				),
			)
		);

		$request  = $this->get_api_url( '/signup_tags' );
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			wpf_log(
				'error',
				0,
				'Failed to create tag "' . $tag_name . '" in NationBuilder: ' . $response->get_error_message(),
				array( 'source' => 'nationbuilder' )
			);
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $body->data->id ) ) {
			$this->cache_tag( $body->data->id, $tag_name );

			wpf_log(
				'info',
				0,
				'Created new tag "' . $tag_name . '" in NationBuilder (ID #' . $body->data->id . ').',
				array( 'source' => 'nationbuilder' )
			);

			return $body->data->id;
		}

		return false;
	}

	/**
	 * Add a tag to the local available tags cache.
	 *
	 * @since 3.47.9
	 *
	 * @param int|string $tag_id   The tag ID.
	 * @param string     $tag_name The tag name.
	 */
	protected function cache_tag( $tag_id, $tag_name ) {

		$available_tags = wpf_get_option( 'available_tags', array() );

		if ( ! isset( $available_tags[ $tag_id ] ) ) {
			$available_tags[ $tag_id ] = array(
				'label'    => $tag_name,
				'category' => __( 'Tags', 'wp-fusion-lite' ),
			);
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		} elseif ( $available_tags[ $tag_id ]['label'] !== $tag_name ) {
			// Update the label if NationBuilder has renamed the tag.
			$available_tags[ $tag_id ]['label'] = $tag_name;
			wp_fusion()->settings->set( 'available_tags', $available_tags );
		}
	}

	/**
	 * Applies tags to a contact
	 *
	 * @access public
	 *
	 * @param array $tags       Tags to apply.
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function apply_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return $this->use_v2()
			? $this->apply_tags_v2( $tags, $contact_id )
			: $this->apply_tags_v1( $tags, $contact_id );
	}

	/**
	 * Apply tags via the v2 API.
	 *
	 * Handles tags passed as numeric IDs, path_ prefixed IDs, or tag
	 * names (from v1 migration or dynamic tagging). Tag names that
	 * cannot be resolved from the local cache are looked up or created
	 * via the API.
	 *
	 * @since 3.47.6
	 *
	 * @param array $tags       Tags to apply (may contain IDs or names).
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	protected function apply_tags_v2( $tags, $contact_id ) {

		$normalized = $this->normalize_tag_ids( $tags );

		// Resolve tags that normalize_tag_ids() could not match from the
		// local cache by looking them up or creating them via the API.
		$resolved_names = array();
		$available_tags = wp_fusion()->settings->get_available_tags_flat( true, false );

		foreach ( $normalized as $id ) {
			if ( isset( $available_tags[ $id ] ) ) {
				$resolved_names[] = strtolower( trim( $available_tags[ $id ] ) );
			}
		}

		foreach ( $tags as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}

			$tag = (string) $tag;

			// Skip tags that were already resolved by normalize_tag_ids().
			if ( ctype_digit( $tag ) || 0 === strpos( $tag, 'path_' ) ) {
				continue;
			}

			if ( in_array( strtolower( trim( $tag ) ), $resolved_names, true ) ) {
				continue;
			}

			$tag_id = $this->find_or_create_tag_v2( $tag );

			if ( false !== $tag_id ) {
				$normalized[] = $tag_id;
			} else {
				wpf_log(
					'warning',
					0,
					'Could not resolve tag "' . esc_html( $tag ) . '" to a NationBuilder tag ID. Skipping.',
					array( 'source' => 'nationbuilder' )
				);
			}
		}

		$tag_ids  = array();
		$path_ids = array();

		foreach ( $normalized as $id ) {
			if ( 0 === strpos( (string) $id, 'path_' ) ) {
				$path_ids[] = (int) substr( $id, 5 );
			} elseif ( is_numeric( $id ) ) {
				$tag_ids[] = (int) $id;
			}
		}

		$tag_ids  = array_values( array_unique( $tag_ids ) );
		$path_ids = array_values( array_unique( $path_ids ) );

		// Apply regular signup tags.
		foreach ( $tag_ids as $tag_id ) {
			$params           = $this->params;
			$params['method'] = 'POST';
			$params['body']   = wp_json_encode(
				array(
					'data' => array(
						'type'       => 'signup_taggings',
						'attributes' => array(
							'signup_id' => (int) $contact_id,
							'tag_id'    => $tag_id,
						),
					),
				)
			);

			$request  = $this->get_api_url( '/signup_taggings' );
			$response = wp_safe_remote_request( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		// Add contact to paths (path_journeys). Duplicate = already on path, treat as success.
		foreach ( $path_ids as $path_id ) {
			$params           = $this->params;
			$params['method'] = 'POST';
			$params['body']   = wp_json_encode(
				array(
					'data' => array(
						'type'       => 'path_journeys',
						'attributes' => array(
							'signup_id' => (int) $contact_id,
							'path_id'   => $path_id,
						),
					),
				)
			);

			$request  = $this->get_api_url( '/path_journeys' );
			$response = wp_safe_remote_request( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Apply tags via the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @param array $tags       Tags to apply.
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	protected function apply_tags_v1( $tags, $contact_id ) {

		$body = array( 'tagging' => array( 'tag' => array() ) );

		foreach ( $tags as $tag ) {
			$body['tagging']['tag'][] = $tag;
		}

		$params           = $this->params;
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( $body );

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '/taggings?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Removes tags from a contact
	 *
	 * @access public
	 *
	 * @param array $tags       Tags to remove.
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function remove_tags( $tags, $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return $this->use_v2()
			? $this->remove_tags_v2( $tags, $contact_id )
			: $this->remove_tags_v1( $tags, $contact_id );
	}

	/**
	 * Remove tags via the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @param array $tags       Tags to remove.
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	protected function remove_tags_v2( $tags, $contact_id ) {

		$normalized = $this->normalize_tag_ids( $tags );

		$tag_ids  = array();
		$path_ids = array();

		foreach ( $normalized as $id ) {
			if ( 0 === strpos( (string) $id, 'path_' ) ) {
				$path_ids[] = $id;
			} elseif ( is_numeric( $id ) ) {
				$tag_ids[] = (int) $id;
			}
		}

		if ( ! empty( $path_ids ) ) {
			wpf_log(
				'notice',
				$contact_id,
				__( 'Path journeys cannot be removed via the NationBuilder API. Skipping:', 'wp-fusion-lite' ),
				array( 'tag_array' => $path_ids )
			);
		}

		if ( empty( $tag_ids ) ) {
			return true;
		}

		foreach ( $tag_ids as $tag_id ) {
			$request = $this->get_api_url(
				'/signup_taggings',
				array(
					'filter[signup_id]' => (int) $contact_id,
					'filter[tag_id]'    => $tag_id,
					'page[size]'        => 1,
				)
			);

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->data ) ) {
				continue;
			}

			$tagging    = reset( $response->data );
			$tagging_id = isset( $tagging->id ) ? $tagging->id : false;

			if ( empty( $tagging_id ) ) {
				continue;
			}

			$params           = $this->params;
			$params['method'] = 'DELETE';

			$request  = $this->get_api_url( '/signup_taggings/' . $tagging_id );
			$response = wp_safe_remote_request( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return true;
	}

	/**
	 * Remove tags via the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @param array $tags       Tags to remove.
	 * @param int   $contact_id Contact ID.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	protected function remove_tags_v1( $tags, $contact_id ) {

		$body = array( 'tagging' => array( 'tag' => array() ) );

		foreach ( $tags as $tag ) {
			$body['tagging']['tag'][] = $tag;
		}

		$params           = $this->params;
		$params['method'] = 'DELETE';
		$params['body']   = wp_json_encode( $body );

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '/taggings?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}


	/**
	 * Adds a new contact
	 *
	 * @access public
	 *
	 * @param array $data Contact data.
	 * @return int|WP_Error Contact ID or error.
	 */
	public function add_contact( $data ) {

		if ( ! empty( $data['lists'] ) ) {
			$lists = array_filter( array_map( 'absint', (array) $data['lists'] ) );
			unset( $data['lists'] );
		}

		$data = $this->prepare_contact_data( $data );

		$contact_id = $this->use_v2()
			? $this->add_contact_v2( $data )
			: $this->add_contact_v1( $data );

		if ( is_wp_error( $contact_id ) ) {
			return $contact_id;
		}

		// Add contact to lists.
		if ( ! empty( $lists ) ) {
			foreach ( $lists as $list_id ) {
				$result = $this->add_contact_to_list( $contact_id, $list_id );
				if ( is_wp_error( $result ) ) {
					wpf_log( 'error', 0, 'Failed to add contact ' . $contact_id . ' to list ' . $list_id . ': ' . $result->get_error_message() );
				}
			}
		}

		return $contact_id;
	}

	/**
	 * Prepare contact data before syncing.
	 *
	 * @since 3.47.6
	 *
	 * @param array $data Contact data.
	 * @return array Prepared contact data.
	 */
	protected function prepare_contact_data( $data ) {

		$use_v2 = $this->use_v2();

		// Translate v1 field names to v2 equivalents.
		if ( $use_v2 ) {
			$v2_field_map = array(
				'mobile' => 'mobile_number',
				'phone'  => 'phone_number',
			);

			foreach ( $v2_field_map as $v1_name => $v2_name ) {
				if ( isset( $data[ $v1_name ] ) ) {
					$data[ $v2_name ] = $data[ $v1_name ];
					unset( $data[ $v1_name ] );
				}
			}
		}

		foreach ( $data as $key => $value ) {
			if ( false === strpos( $key, '+' ) ) {
				continue;
			}

			$exploded_address = explode( '+', $key );
			$address_key      = $exploded_address[0];

			// v2 API requires _attributes suffix for writing address data.
			if ( $use_v2 ) {
				$address_key .= '_attributes';
			}

			if ( ! isset( $data[ $address_key ] ) ) {
				$data[ $address_key ] = array();
			}

			if ( ! empty( $value ) ) {
				$data[ $address_key ][ $exploded_address[1] ] = $value;
			}

			unset( $data[ $key ] );
		}

		$data['email_opt_in'] = true;

		if ( isset( $data['mobile'] ) || isset( $data['mobile_number'] ) ) {
			$data['mobile_opt_in'] = true;
		}

		return $data;
	}

	/**
	 * Add a contact via the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @param array $data Contact data.
	 * @return int|WP_Error Contact ID or error.
	 */
	protected function add_contact_v2( $data ) {

		$params           = $this->get_params();
		$params['method'] = 'PATCH';
		$params['body']   = wp_json_encode(
			array(
				'data' => array(
					'type'       => 'signups',
					'attributes' => $data,
				),
			)
		);

		$request  = $this->get_api_url( '/signups/push' );
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $response->data->id ) ) {
			return $response->data->id;
		}

		return new WP_Error( 'error', __( 'Unable to create contact.', 'wp-fusion-lite' ) );
	}

	/**
	 * Add a contact via the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @param array $data Contact data.
	 * @return int|WP_Error Contact ID or error.
	 */
	protected function add_contact_v1( $data ) {

		$params           = $this->get_params();
		$params['body']   = wp_json_encode( array( 'person' => $data ) );
		$params['method'] = 'PUT';

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/push?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return $response->person->id;
	}

	/**
	 * Update contact
	 *
	 * @access public
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $data       Contact data.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	public function update_contact( $contact_id, $data ) {

		if ( ! empty( $data['lists'] ) ) {
			$lists = array_filter( array_map( 'absint', (array) $data['lists'] ) );
			unset( $data['lists'] );
		}

		$data = $this->prepare_contact_data( $data );

		if ( isset( $data['email'] ) ) {
			$data['email_opt_in'] = true;
		}

		if ( isset( $data['mobile'] ) || isset( $data['mobile_number'] ) ) {
			$data['mobile_opt_in'] = true;
		}

		$result = $this->use_v2()
			? $this->update_contact_v2( $contact_id, $data )
			: $this->update_contact_v1( $contact_id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add contact to lists.
		if ( ! empty( $lists ) ) {
			foreach ( $lists as $list_id ) {
				$result = $this->add_contact_to_list( $contact_id, $list_id );
				if ( is_wp_error( $result ) ) {
					wpf_log( 'error', 0, 'Failed to add contact ' . $contact_id . ' to list ' . $list_id . ': ' . $result->get_error_message() );
				}
			}
		}

		return true;
	}

	/**
	 * Update contact via the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $data       Contact data.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	protected function update_contact_v2( $contact_id, $data ) {

		$params           = $this->get_params();
		$params['method'] = 'PATCH';
		$params['body']   = wp_json_encode(
			array(
				'data' => array(
					'id'         => (string) $contact_id,
					'type'       => 'signups',
					'attributes' => $data,
				),
			)
		);

		$request  = $this->get_api_url( '/signups/' . $contact_id );
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Update contact via the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @param int   $contact_id Contact ID.
	 * @param array $data       Contact data.
	 * @return bool|WP_Error True on success or WP_Error on failure.
	 */
	protected function update_contact_v1( $contact_id, $data ) {

		$params           = $this->get_params();
		$params['method'] = 'PUT';
		$params['body']   = wp_json_encode( array( 'person' => $data ) );

		$request  = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/people/' . $contact_id . '?access_token=' . $this->token . '&fire_webhooks=false';
		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Loads a contact and updates local user meta
	 *
	 * @access public
	 *
	 * @param int $contact_id Contact ID.
	 * @return array|WP_Error User meta data or error.
	 */
	public function load_contact( $contact_id ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return $this->use_v2()
			? $this->load_contact_v2( $contact_id )
			: $this->load_contact_v1( $contact_id );
	}

	/**
	 * Load a contact via the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @param int $contact_id Contact ID.
	 * @return array|WP_Error User meta data or error.
	 */
	protected function load_contact_v2( $contact_id ) {

		$request  = $this->get_api_url( '/signups/' . $contact_id );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );
		$record   = isset( $response->data->attributes ) ? $response->data->attributes : array();

		return $this->build_user_meta_from_record( $record, true );
	}

	/**
	 * Load a contact via the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @param int $contact_id Contact ID.
	 * @return array|WP_Error User meta data or error.
	 */
	protected function load_contact_v1( $contact_id ) {

		$request  = $this->get_api_url( '/people/' . $contact_id );
		$response = wp_safe_remote_get( $request, $this->params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );
		$record   = isset( $response->person ) ? $response->person : array();

		return $this->build_user_meta_from_record( $record, false );
	}

	/**
	 * Build user meta from a contact record.
	 *
	 * @since 3.47.6
	 *
	 * @param object|array $record The contact record.
	 * @param bool         $use_v2 Whether the record is from v2.
	 * @return array The user meta.
	 */
	protected function build_user_meta_from_record( $record, $use_v2 ) {

		$loaded_data    = array();
		$contact_fields = wpf_get_option( 'contact_fields' );

		foreach ( $record as $field => $value ) {

			if ( ! empty( $value ) && ! is_object( $value ) ) {

				$loaded_data[ $field ] = $value;

			} elseif ( ! empty( $value ) && is_object( $value ) ) {

				// Address fields.

				if ( $use_v2 && 'custom_values' === $field ) {
					foreach ( $value as $custom_key => $custom_value ) {
						if ( ! empty( $custom_value ) ) {
							$loaded_data[ 'custom_values+' . $custom_key ] = $custom_value;
						}
					}
					continue;
				}

				foreach ( $value as $address_key => $address_value ) {

					if ( ! empty( $address_value ) ) {

						$loaded_data[ $field . '+' . $address_key ] = $address_value;

					}
				}
			}
		}

		$user_meta = array();

		foreach ( $contact_fields as $field_id => $field_data ) {

			if ( true === $field_data['active'] && isset( $loaded_data[ $field_data['crm_field'] ] ) ) {
				$user_meta[ $field_id ] = $loaded_data[ $field_data['crm_field'] ];
			}
		}

		return $user_meta;
	}


	/**
	 * Gets a list of contact IDs based on tag
	 *
	 * @access public
	 *
	 * @param string $tag Tag name or ID.
	 * @return array|WP_Error Contact IDs returned or error.
	 */
	public function load_contacts( $tag ) {

		if ( ! $this->params ) {
			$this->get_params();
		}

		return $this->use_v2()
			? $this->load_contacts_v2( $tag )
			: $this->load_contacts_v1( $tag );
	}

	/**
	 * Load contacts by tag via the v2 API.
	 *
	 * @since 3.47.6
	 *
	 * @param string $tag Tag name or ID.
	 * @return array|WP_Error Contact IDs returned or error.
	 */
	protected function load_contacts_v2( $tag ) {

		$tag_ids = $this->normalize_tag_ids( array( $tag ) );

		if ( empty( $tag_ids ) ) {
			return array();
		}

		$first_id = $tag_ids[0];

		// Load by path: query path_journeys and collect signup_ids.
		if ( 0 === strpos( (string) $first_id, 'path_' ) ) {
			$path_id     = (int) substr( $first_id, 5 );
			$contact_ids = array();
			$page        = 1;
			$continue    = true;

			while ( $continue ) {
				$request = $this->get_api_url(
					'/path_journeys',
					array(
						'filter[path_id]' => $path_id,
						'page[number]'    => $page,
						'page[size]'      => 100,
					)
				);

				$response = wp_safe_remote_get( $request, $this->params );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$response = json_decode( wp_remote_retrieve_body( $response ) );

				if ( empty( $response->data ) ) {
					$continue = false;
					continue;
				}

				foreach ( $response->data as $journey ) {
					if ( ! empty( $journey->attributes->signup_id ) ) {
						$contact_ids[] = $journey->attributes->signup_id;
					}
				}

				if ( empty( $response->links->next ) ) {
					$continue = false;
				} else {
					++$page;
				}
			}

			return $contact_ids;
		}

		// Load by tag: query signups with filter[tag_id].
		$contact_ids = array();
		$page        = 1;
		$continue    = true;

		while ( $continue ) {
			$request = $this->get_api_url(
				'/signups',
				array(
					'filter[tag_id]' => (int) $first_id,
					'page[number]'   => $page,
					'page[size]'     => 100,
				)
			);

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			if ( empty( $response->data ) ) {
				$continue = false;
				continue;
			}

			foreach ( $response->data as $result ) {
				if ( ! empty( $result->id ) ) {
					$contact_ids[] = $result->id;
				}
			}

			if ( empty( $response->links->next ) ) {
				$continue = false;
			} else {
				++$page;
			}
		}

		return $contact_ids;
	}

	/**
	 * Load contacts by tag via the v1 API.
	 *
	 * @since 3.47.6
	 *
	 * @param string $tag Tag name or ID.
	 * @return array|WP_Error Contact IDs returned or error.
	 */
	protected function load_contacts_v1( $tag ) {

		$contact_ids = array();
		$next        = false;
		$proceed     = true;

		while ( true === $proceed ) {

			if ( false === $next ) {
				$request = 'https://' . $this->url_slug . '.nationbuilder.com/api/v1/tags/' . rawurlencode( $tag ) . '/people?limit=100&access_token=' . $this->token;
			} else {
				$request = 'https://' . $this->url_slug . '.nationbuilder.com' . $next . '&access_token=' . $this->token;
			}

			$response = wp_safe_remote_get( $request, $this->params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			foreach ( $response->results as $result ) {
				$contact_ids[] = $result->id;
			}

			if ( empty( $response->next ) ) {
				$proceed = false;
			} else {
				$next = $response->next;
			}
		}

		return $contact_ids;
	}
}
