<?php

class WPF_Pipedrive_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.40.33
	 */
	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.40.33
	 */
	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.40.33
	 */
	private $crm;

	/**
	 * Get things started.
	 *
	 * @since 3.40.33
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_pipedrive_header_begin', array( $this, 'show_field_pipedrive_header_begin' ), 10, 2 );

		// AJAX callback to test the connection.
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) === $this->slug ) {
			$this->init();
		}

		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );
		add_action( 'admin_init', array( $this, 'maybe_revoke_token' ) );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.40.33
	 */
	public function init() {

		// Hooks in init() will run on the admin screen when this CRM is active.

		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		add_action( 'validate_field_pipedrive_tag', array( $this, 'validate_tag_type' ), 10, 3 );
		add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

		add_filter( 'validate_field_pipedrive_update_trigger', array( $this, 'validate_update_trigger' ) );
		add_filter( 'validate_field_pipedrive_add_trigger', array( $this, 'validate_add_trigger' ) );

		add_action( 'wpf_resetting_options', array( $this, 'delete_webhooks' ) );
		add_action( 'wpf_resetting_options', array( $this, 'revoke_tokens_on_reset' ) );
	}


	/**
	 * Gets the OAuth URL for the initial connection. Remove if not using OAuth.
	 *
	 * If we're using the WP Fusion app, send the request through wpfusion.com,
	 * otherwise allow a custom app.
	 *
	 * @since  3.40.33
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {

		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		$args = array(
			'redirect' => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings&crm=pipedrive' ),
			'action'   => "wpf_get_{$this->slug}_token",
			'public'   => true,
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );
	}

	/**
	 * Listen for an OAuth response and maybe complete setup. Remove if not using OAuth.
	 *
	 * @since 3.40.33
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && $_GET['crm'] === $this->slug ) {

			$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

			$body = array(
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => "https://wpfusion.com/oauth/?action=wpf_get_{$this->slug}_token",
			);

			$params = array(
				'timeout'    => 30,
				'user-agent' => 'WP Fusion; ' . home_url(),
				'body'       => $body,
				'headers'    => array(
					'Authorization' => 'Basic ' . base64_encode( $this->crm->client_id . ':' . $this->crm->client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
			);

			$response = wp_remote_post( $this->crm->auth_url, $params );

			if ( is_wp_error( $response ) ) {
				wp_fusion()->admin_notices->add_notice( 'Error requesting authorization code: ' . $response->get_error_message() );
				wpf_log( 'error', 0, 'Error requesting authorization code: ' . $response->get_error_message() );
				return false;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ) );

			wp_fusion()->settings->set( "{$this->slug}_refresh_token", $response->refresh_token );
			wp_fusion()->settings->set( "{$this->slug}_token", $response->access_token );
			wp_fusion()->settings->set( 'crm', $this->slug );
			wp_fusion()->settings->set( "{$this->slug}_api_domain", $response->api_domain );

			// Mark this connection as using the new public app since it came from the OAuth handler.
			wp_fusion()->settings->set( "{$this->slug}_public_app", true );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}

	/**
	 * Handles the revoke token action from the OAuth Helper plugin.
	 *
	 * @since 3.46.7
	 */
	public function maybe_revoke_token() {

		if ( ! isset( $_GET['wpf_action'] ) || 'revoke_token' !== $_GET['wpf_action'] ) {
			return;
		}

		if ( ! isset( $_GET['crm'] ) || $this->slug !== $_GET['crm'] ) {
			return;
		}

		// Verify user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		// Get parameters.
		$connection_id = isset( $_GET['connection_id'] ) ? absint( $_GET['connection_id'] ) : 0;
		$return_url    = isset( $_GET['return_url'] ) ? esc_url_raw( wp_unslash( $_GET['return_url'] ) ) : '';

		// Show confirmation page or process revocation.
		if ( ! isset( $_POST['confirm_revoke'] ) ) {
			$this->show_revoke_confirmation_page( $connection_id, $return_url );
			exit;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( wp_unslash( $_POST['wpf_revoke_nonce'] ), 'wpf_revoke_token' ) ) {
			wp_die( 'Security check failed' );
		}

		// Revoke the token.
		$result = $this->crm->revoke_token();

		if ( is_wp_error( $result ) ) {
			$this->send_revoke_response( false, $result->get_error_message(), $return_url );
		} else {
			$this->send_revoke_response( true, 'Connection successfully revoked', $return_url );
		}

		exit;
	}

	/**
	 * Shows the revoke token confirmation page.
	 *
	 * @since 3.46.7
	 *
	 * @param int    $connection_id The connection ID.
	 * @param string $return_url    The return URL.
	 */
	private function show_revoke_confirmation_page( $connection_id, $return_url ) {
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Revoke Connection', 'wp-fusion-lite' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					margin: 0;
					padding: 20px;
					background: #f1f1f1;
				}
				.container {
					max-width: 500px;
					margin: 50px auto;
					background: white;
					padding: 30px;
					border-radius: 8px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.1);
				}
				h1 {
					color: #333;
					margin-bottom: 20px;
					font-size: 24px;
				}
				p {
					color: #666;
					line-height: 1.6;
					margin-bottom: 20px;
				}
				.buttons {
					text-align: center;
					margin-top: 30px;
				}
				.button {
					display: inline-block;
					padding: 12px 24px;
					margin: 0 10px;
					text-decoration: none;
					border-radius: 4px;
					font-weight: 500;
					cursor: pointer;
					border: none;
					font-size: 14px;
				}
				.button-primary {
					background: #dc3232;
					color: white;
				}
				.button-primary:hover {
					background: #c62d2d;
				}
				.button-secondary {
					background: #f7f7f7;
					color: #333;
					border: 1px solid #ccc;
				}
				.button-secondary:hover {
					background: #fafafa;
				}
				.loading {
					display: none;
					text-align: center;
					margin-top: 20px;
				}
			</style>
		</head>
		<body>
			<div class="container">
				<h1><?php esc_html_e( 'Revoke Pipedrive Connection', 'wp-fusion-lite' ); ?></h1>
				<p><?php esc_html_e( 'Are you sure you want to revoke the connection to Pipedrive? This will:', 'wp-fusion-lite' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Remove all stored access tokens', 'wp-fusion-lite' ); ?></li>
					<li><?php esc_html_e( 'Disable the Pipedrive integration', 'wp-fusion-lite' ); ?></li>
					<li><?php esc_html_e( 'Require re-authorization to reconnect', 'wp-fusion-lite' ); ?></li>
				</ul>
				
				<form method="post" id="revoke-form">
					<?php wp_nonce_field( 'wpf_revoke_token', 'wpf_revoke_nonce' ); ?>
					<input type="hidden" name="confirm_revoke" value="1">
					<div class="buttons">
						<button type="submit" class="button button-primary" id="confirm-button">
							<?php esc_html_e( 'Yes, Revoke Connection', 'wp-fusion-lite' ); ?>
						</button>
						<button type="button" class="button button-secondary" onclick="window.close();">
							<?php esc_html_e( 'Cancel', 'wp-fusion-lite' ); ?>
						</button>
					</div>
				</form>
				
				<div class="loading" id="loading">
					<p><?php esc_html_e( 'Revoking connection...', 'wp-fusion-lite' ); ?></p>
				</div>
			</div>

			<script>
				document.getElementById('revoke-form').addEventListener('submit', function() {
					document.getElementById('confirm-button').style.display = 'none';
					document.getElementById('loading').style.display = 'block';
				});
			</script>
		</body>
		</html>
		<?php
	}

	/**
	 * Sends the revoke response to the parent window.
	 *
	 * @since 3.46.7
	 *
	 * @param bool   $success    Whether the revocation was successful.
	 * @param string $message    The response message.
	 * @param string $return_url The return URL.
	 */
	private function send_revoke_response( $success, $message, $return_url ) {
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'Connection Status', 'wp-fusion-lite' ); ?></title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					margin: 0;
					padding: 20px;
					background: #f1f1f1;
				}
				.container {
					max-width: 500px;
					margin: 50px auto;
					background: white;
					padding: 30px;
					border-radius: 8px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.1);
					text-align: center;
				}
				.success {
					color: #46b450;
				}
				.error {
					color: #dc3232;
				}
				.button {
					display: inline-block;
					padding: 12px 24px;
					margin-top: 20px;
					text-decoration: none;
					border-radius: 4px;
					font-weight: 500;
					cursor: pointer;
					border: none;
					font-size: 14px;
					background: #f7f7f7;
					color: #333;
					border: 1px solid #ccc;
				}
				.button:hover {
					background: #fafafa;
				}
			</style>
		</head>
		<body>
			<div class="container">
				<h1 class="<?php echo $success ? 'success' : 'error'; ?>">
					<?php echo $success ? esc_html__( 'Success', 'wp-fusion-lite' ) : esc_html__( 'Error', 'wp-fusion-lite' ); ?>
				</h1>
				<p><?php echo esc_html( $message ); ?></p>
				<button type="button" class="button" onclick="window.close();">
					<?php esc_html_e( 'Close Window', 'wp-fusion-lite' ); ?>
				</button>
			</div>

			<script>
				
				var messageData = {
					wpf_action: 'token_revoked',
					success: <?php echo $success ? 'true' : 'false'; ?>,
					message: <?php echo wp_json_encode( $success ? 'Connection revoked successfully' : 'Failed to revoke connection' ); ?>
				};
				
				window.opener.postMessage(messageData, '*');

				// Auto-close after 3 seconds if successful.
				<?php if ( $success ) : ?>
				setTimeout(function() {
					window.close();
				}, 3000);
				<?php endif; ?>
			</script>
		</body>
		</html>
		<?php
	}

	/**
	 * Resync tags/topics when the tag type is saved and validate the picklist.
	 *
	 * @since  3.40.33
	 *
	 * @param  string      $input   The input.
	 * @param  array       $setting The setting configuration.
	 * @param  WPF_Options $options The options class.
	 * @return string|WP_Error The input or error on validation failure.
	 */
	public function validate_tag_type( $input, $setting, $options ) {

		if ( ! empty( $input ) ) {

			wp_fusion()->settings->options['pipedrive_tag'] = $input;

			$result = $this->crm->sync_tags();

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $input;
	}

	/**
	 * Loads Pipedrive specific settings fields.
	 *
	 * @since 3.46.7
	 *
	 * @param array $settings The settings.
	 * @param array $options  The options.
	 * @return array The settings.
	 */
	public function register_settings( $settings, $options ) {

		if ( wp_fusion()->is_full_version() ) {

			$settings['access_key_desc'] = array(
				'type'    => 'paragraph',
				'section' => 'main',
				'desc'    => __( 'Configuring the fields below allows you to add new users to your site and update existing users based on changes in Pipedrive. Read our <a href="https://wpfusion.com/documentation/webhooks/pipedrive-webhooks/" target="_blank">documentation</a> for more information.', 'wp-fusion-lite' ),
			);

			$settings['access_key_desc']['desc'] .= ' ' . sprintf( __( 'To list all registered webhooks (for debugging purposes), %1$sclick here%2$s.', 'wp-fusion-lite' ), '<a href="' . esc_url( admin_url( 'options-general.php?page=wpf-settings&pd_debug=true' ) ) . '">', '</a>' );

			if ( isset( $_GET['pd_debug'] ) ) {

				$settings['access_key_desc']['desc'] .= ' ' . sprintf( __( 'To <strong>delete</strong> all registered webhooks, %1$sclick here%2$s.', 'wp-fusion-lite' ), '<a href="' . esc_url( admin_url( 'options-general.php?page=wpf-settings&pd_debug=true&pd_destroy_all_webhooks=true' ) ) . '">', '</a>' );

				$webhooks = $this->crm->get_webhooks();

				if ( isset( $_GET['pd_destroy_all_webhooks'] ) ) {

					foreach ( $webhooks as $webhook ) {
						$this->crm->destroy_webhook( $webhook->id );
					}

					$webhooks = 'Destroyed ' . count( $webhooks ) . ' webhooks.';

				}

				$settings['access_key_desc']['desc'] .= '<pre>' . wpf_print_r( $webhooks, true ) . '</pre>';

			}

			$new_settings['pipedrive_update_trigger'] = array(
				'title'   => __( 'Update Trigger', 'wp-fusion-lite' ),
				'desc'    => __( 'When a person is updated in Pipedrive, send their data back to WordPress.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'section' => 'main',
			);

			$new_settings['pipedrive_update_trigger_rule_id'] = array(
				'type'    => 'hidden',
				'section' => 'main',
			);

			$new_settings['pipedrive_add_trigger'] = array(
				'title'   => __( 'Add Trigger', 'wp-fusion-lite' ),
				'desc'    => __( 'When a new person is created in Pipedrive, import them as a new WordPress user.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'section' => 'main',
			);

			$new_settings['pipedrive_add_trigger_rule_id'] = array(
				'type'    => 'hidden',
				'section' => 'main',
			);

			$new_settings['pipedrive_import_notification'] = array(
				'title'   => __( 'Enable Notifications', 'wp-fusion-lite' ),
				'desc'    => __( 'Send a welcome email to new users containing their username and a password reset link.', 'wp-fusion-lite' ),
				'type'    => 'checkbox',
				'section' => 'main',
			);

			$settings = wp_fusion()->settings->insert_setting_before( 'access_key', $settings, $new_settings );

		}

		return $settings;
	}

	/**
	 * Creates or destroys webhooks when the Add Trigger setting is changed.
	 *
	 * @since 3.46.7
	 *
	 * @param bool $input The settings input.
	 * @return bool|WP_Error The settings input or a WP_Error object.
	 */
	public function validate_add_trigger( $input ) {

		// See if we need to destroy an existing webhook before creating a new one.
		$rule_id = wpf_get_option( 'pipedrive_add_trigger_rule_id' );

		if ( ! empty( $rule_id ) ) {
			$this->crm->destroy_webhook( $rule_id );
			add_filter( 'validate_field_pipedrive_add_trigger_rule_id', '__return_false' );
		}

		// Abort if trigger has been disabled.
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save.
		$rule_ids = $this->crm->register_webhooks( 'add' );

		// If there was an error, make the user select the trigger again.
		if ( is_wp_error( $rule_ids ) || empty( $rule_ids ) ) {
			return $rule_ids;
		}

		// Save it.
		add_filter(
			'wpf_initialize_options',
			function ( $options ) use ( &$rule_ids ) {
				$options['pipedrive_add_trigger_rule_id'] = $rule_ids[0];
				return $options;
			}
		);

		return $input;
	}

	/**
	 * Creates or destroys webhooks when the Update Trigger setting is changed.
	 *
	 * @since 3.46.7
	 *
	 * @param bool $input The settings input.
	 * @return bool|WP_Error The settings input or a WP_Error object.
	 */
	public function validate_update_trigger( $input ) {

		// See if we need to destroy existing webhook before creating a new one.
		$rule_id = wpf_get_option( 'pipedrive_update_trigger_rule_id' );

		if ( ! empty( $rule_id ) ) {
			$this->crm->destroy_webhook( $rule_id );
			add_filter( 'validate_field_pipedrive_update_trigger_rule_id', '__return_false' );
		}

		// Abort if trigger has been disabled.
		if ( empty( $input ) ) {
			return $input;
		}

		// Add new rule and save.
		$rule_ids = $this->crm->register_webhooks( 'update' );

		// If there was an error, make the user select the trigger again.
		if ( is_wp_error( $rule_ids ) || empty( $rule_ids ) ) {
			return $rule_ids;
		}

		// Save it.
		add_filter(
			'wpf_initialize_options',
			function ( $options ) use ( &$rule_ids ) {
				$options['pipedrive_update_trigger_rule_id'] = $rule_ids[0];
				return $options;
			}
		);

		return $input;
	}

	/**
	 * Delete webhooks when settings are reset.
	 *
	 * @since 3.46.7
	 *
	 * @param array $options The options.
	 */
	public function delete_webhooks( $options ) {

		if ( ! empty( $options['pipedrive_add_trigger_rule_id'] ) ) {
			$this->crm->destroy_webhook( $options['pipedrive_add_trigger_rule_id'] );
		}

		if ( ! empty( $options['pipedrive_update_trigger_rule_id'] ) ) {
			$this->crm->destroy_webhook( $options['pipedrive_update_trigger_rule_id'] );
		}
	}

	/**
	 * Revoke tokens when settings are reset.
	 *
	 * @since 3.46.7
	 *
	 * @param array $options The options.
	 */
	public function revoke_tokens_on_reset( $options ) {

		// Only revoke if we have a refresh token (indicating an active connection).
		if ( ! empty( $options['pipedrive_refresh_token'] ) ) {
			$result = $this->crm->revoke_token();

			if ( is_wp_error( $result ) ) {
				wpf_log( 'error', 0, 'Failed to revoke Pipedrive tokens on settings reset: ' . $result->get_error_message() );
			} else {
				wpf_log( 'info', 0, 'Pipedrive tokens successfully revoked during settings reset.' );
			}
		}
	}


	/**
	 * Loads CRM connection information on settings page
	 *
	 * @since 3.40.33
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options  The options saved in the database.
	 * @return array $settings The settings.
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['pipedrive_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( empty( $options[ "{$this->slug}_refresh_token" ] ) && ! isset( $_GET['code'] ) ) {

			$new_settings[ "{$this->slug}_auth" ] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
			);

		} else {

			$new_settings['pipedrive_tag'] = array(
				'title'   => __( 'Segmentation Field', 'wp-fusion-lite' ),
				'type'    => 'crm_field',
				'section' => 'setup',
				'desc'    => __( 'Select a field to be used for segmentation with WP Fusion. For more information, see <a href="https://wpfusion.com/documentation/crm-specific-docs/pipedrive-labels/" target="_blank">Tags with Pipedrive</a>.', 'wp-fusion-lite' ),
			);

			$new_settings[ "{$this->slug}_oauth_status" ] = array(
				'title'       => __( 'Connection Status', 'wp-fusion-lite' ),
				'type'        => 'oauth_connection_status',
				'section'     => 'setup',
				'name'        => $this->name,
				'url'         => $this->get_oauth_url(),
				'post_fields' => array( "{$this->slug}_api_domain", "{$this->slug}_token", "{$this->slug}_refresh_token" ),
			);
		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Loads standard CRM field names and attempts to match them up with
	 * standard local ones.
	 *
	 * @since  3.40.33
	 *
	 * @param  array $options The options.
	 * @return array The options.
	 */
	public function add_default_fields( $options ) {

		$standard_fields = $this->crm->get_default_fields();

		foreach ( $options['contact_fields'] as $field => $data ) {

			if ( isset( $standard_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
				$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $standard_fields[ $field ] );
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the CRM configuration section so it can be toggled
	 *
	 * @since 3.40.33
	 *
	 * @param string $id    The ID of the field.
	 * @param array  $field The field properties.
	 * @return mixed HTML output.
	 */
	public function show_field_pipedrive_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm === false || $crm !== $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}


	/**
	 * Verify connection credentials.
	 *
	 * @since 3.40.33
	 *
	 * @return mixed JSON response.
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		$access_token  = isset( $_POST['pipedrive_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pipedrive_token'] ) ) : false;
		$api_domain    = isset( $_POST['pipedrive_api_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['pipedrive_api_domain'] ) ) : false;
		$refresh_token = isset( $_POST['pipedrive_refresh_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pipedrive_refresh_token'] ) ) : false;

		$connection = $this->crm->connect( $access_token, $api_domain, true );

		if ( is_wp_error( $connection ) ) {

			// Connection failed.
			wp_send_json_error( $connection->get_error_message() );

		} else {

			// Save the API credentials.
			$options                            = array();
			$options['pipedrive_token']         = $access_token;
			$options['pipedrive_refresh_token'] = $refresh_token;
			$options['pipedrive_api_domain']    = $api_domain;
			$options['crm']                     = $this->slug;
			$options['connection_configured']   = true;

			wp_fusion()->settings->set_multiple( $options );

			wp_send_json_success();

		}
	}
}
