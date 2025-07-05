<?php

class WPF_Klaviyo_Admin {

	private $slug;
	private $name;
	private $crm;

	/**
	 * Get things started
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_klaviyo_header_begin', array( $this, 'show_field_klaviyo_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wpf_get_option( 'crm' ) == $this->slug ) {
			$this->init();
		}

		add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );
		add_action( 'admin_init', array( $this, 'maybe_revoke_token' ) );
	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function init() {
		add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
		// add_action( 'wpf_settings_notices', array( $this, 'oauth_warning' ) );
	}


	/**
	 * Check if we need to upgrade to the new OAuth.
	 *
	 * @since 3.46.0
	 */
	public function oauth_warning() {

		if ( ! wpf_get_option( 'klaviyo_token' ) ) {

			echo '<div class="notice notice-warning wpf-notice"><p>';

			echo wp_kses_post( sprintf( __( '<strong>Heads up:</strong> WP Fusion\'s Klaviyo integration has been updated to use OAuth authentication.<br> Please %1$sclick here to re-authorize the connection%2$s and enable a deeper integration with new Klaviyo features.', 'wp-fusion-lite' ), '<a href="' . $this->get_oauth_url() . '">', '</a>' ) );

			echo '</p></div>';

		}
	}


	/**
	 * Loads Klaviyo connection information on settings page
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['klaviyo_header'] = array(
			// translators: %s is the name of the CRM.
			'title'   => sprintf( __( '%s Configuration', 'wp-fusion-lite' ), $this->name ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		if ( has_filter( 'wpf_get_setting_klaviyo_key' ) ) {

			$new_settings['klaviyo_key'] = array(
				'title'       => __( 'API Key', 'wp-fusion-lite' ),
				'desc'        => __( 'Enter your Klaviyo API key. You can generate one in your Klaviyo account under <a href="https://www.klaviyo.com/account#api-keys-tab" target="_blank">API Keys</a>.', 'wp-fusion-lite' ),
				'type'        => 'api_validate',
				'section'     => 'setup',
				'class'       => 'api_key',
				'post_fields' => array( 'klaviyo_key' ),
			);

		} elseif ( empty( $options['klaviyo_token'] ) && ! isset( $_GET['code'] ) ) {
			$new_settings['klaviyo_auth'] = array(
				'title'   => __( 'Authorize', 'wp-fusion-lite' ),
				'type'    => 'oauth_authorize',
				'section' => 'setup',
				'url'     => $this->get_oauth_url(),
				'name'    => $this->name,
				'slug'    => $this->slug,
				// Translators: %s is the name of the CRM.
				'desc'    => sprintf( __( 'You\'ll be taken to %1$s to authorize WP Fusion and generate access keys for this site.', 'wp-fusion-lite' ), $this->crm->name, '<a href="https://www.klaviyo.com/">', '</a>' ),
			);

		} else {
			$new_settings['klaviyo_token'] = array(
				'title'          => __( 'Access Token', 'wp-fusion-lite' ),
				'type'           => 'text',
				'section'        => 'setup',
				'input_disabled' => true,
			);

			$new_settings['klaviyo_refresh_token'] = array(
				'title'          => __( 'Refresh token', 'wp-fusion-lite' ),
				'type'           => 'api_validate',
				'section'        => 'setup',
				'class'          => 'api_key',
				'input_disabled' => true,
				'post_fields'    => array( 'klaviyo_token', 'klaviyo_refresh_token' ),
				'desc'           => '<a href="' . esc_url( $this->get_oauth_url() ) . '">' . sprintf( esc_html__( 'Re-authorize with %s', 'wp-fusion-lite' ), $this->crm->name ) . '</a>. <a href="https://wpfusion.com/oauth/connections/">' . esc_html__( 'Manage connections', 'wp-fusion-lite' ) . '</a>.',
			);

		}

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;
	}

	/**
	 * Completes the OAuth process in the admin.
	 *
	 * @since 3.46.0
	 */
	public function maybe_oauth_complete() {

		if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && 'klaviyo' === $_GET['crm'] ) {

			$code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$code_verifier = get_option( 'wpf_klaviyo_code_verifier' );

			if ( empty( $code_verifier ) ) {
				wp_die( 'OAuth Error: Code verifier not found. Please try the authorization again.' );
			}

			// Validate verifier length against Klaviyo requirements (43-128 characters).
			$verifier_length = strlen( $code_verifier );
			if ( $verifier_length < 43 || $verifier_length > 128 ) {
				wp_die( 'OAuth Error: Code verifier length invalid. Please try the authorization again.' );
			}

			$access_token = $this->crm->authorize( $code, $code_verifier );

			if ( false === $access_token ) {
				wp_die( 'OAuth Error: Failed to get access token from Klaviyo. Please check <a href="' . esc_url( admin_url( 'tools.php?page=wpf-settings-logs' ) ) . '">the logs</a> for more details.' );
			}

			// Clean up the code verifier
			delete_option( 'wpf_klaviyo_code_verifier' );

			// wp_fusion()->settings->set( 'connection_configured', true );

			wp_safe_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}
	}

	/**
	 * Handles the revoke token action from the OAuth Helper plugin.
	 *
	 * @since 3.46.0
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
		$return_url    = isset( $_GET['return_url'] ) ? esc_url_raw( $_GET['return_url'] ) : '';

		// Show confirmation page or process revocation.
		if ( ! isset( $_POST['confirm_revoke'] ) ) {
			$this->show_revoke_confirmation_page( $connection_id, $return_url );
			exit;
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( $_POST['wpf_revoke_nonce'], 'wpf_revoke_token' ) ) {
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
	 * @since 3.46.2
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
				<h1><?php esc_html_e( 'Revoke Klaviyo Connection', 'wp-fusion-lite' ); ?></h1>
				<p><?php esc_html_e( 'Are you sure you want to revoke the connection to Klaviyo? This will:', 'wp-fusion-lite' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Remove all stored access tokens', 'wp-fusion-lite' ); ?></li>
					<li><?php esc_html_e( 'Disable the Klaviyo integration', 'wp-fusion-lite' ); ?></li>
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
	 * @since 3.46.2
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

				// Auto-close after 3 seconds if successful
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
	 * Generates a code verifier for PKCE OAuth flow.
	 *
	 * @since 3.46.0
	 *
	 * @return string The code verifier.
	 */
	private function generate_code_verifier() {

		// Generate 32 random bytes and base64url encode.
		$verifier_bytes = random_bytes( 32 );
		$code_verifier  = rtrim( strtr( base64_encode( $verifier_bytes ), '+/', '-_' ), '=' );

		return $code_verifier;
	}

	/**
	 * Generates a code challenge from a code verifier for PKCE OAuth flow.
	 *
	 * @since 3.46.0
	 *
	 * @param string $verifier The code verifier.
	 * @return string The code challenge.
	 */
	private function generate_code_challenge( $verifier ) {

		// SHA256 hash the UTF-8 encoded code verifier and base64url encode.
		$challenge_bytes = hash( 'sha256', $verifier, true );
		$code_challenge  = rtrim( strtr( base64_encode( $challenge_bytes ), '+/', '-_' ), '=' );

		return $code_challenge;
	}

	/**
	 * Gets the OAuth URL for the initial connection.
	 *
	 * @since  3.46.0
	 *
	 * @return string The URL.
	 */
	public function get_oauth_url() {
		$admin_url = str_replace( 'http://', 'https://', get_admin_url() ); // must be HTTPS for the redirect to work.

		// Check if we already have a stored code verifier.
		$code_verifier = get_option( 'wpf_klaviyo_code_verifier' );

		// Only generate a new verifier if one doesn't exist or is invalid.
		if ( empty( $code_verifier ) || strlen( $code_verifier ) < 43 || strlen( $code_verifier ) > 128 ) {
			// Generate and store the code verifier locally.
			$code_verifier = $this->generate_code_verifier();

			// Validate code verifier length (43-128 characters as per Klaviyo docs).
			$verifier_length = strlen( $code_verifier );
			if ( $verifier_length < 43 || $verifier_length > 128 ) {
				// Regenerate if invalid.
				$code_verifier = $this->generate_code_verifier();
			}

			update_option( 'wpf_klaviyo_code_verifier', $code_verifier );
		}

		// Generate the code challenge from the verifier.
		$code_challenge = $this->generate_code_challenge( $code_verifier );

		$args = array(
			'redirect'              => rawurlencode( $admin_url . 'options-general.php?page=wpf-settings' ),
			'action'                => "wpf_get_{$this->slug}_token",
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		);

		return apply_filters( "wpf_{$this->slug}_auth_url", add_query_arg( $args, 'https://wpfusion.com/oauth/' ) );
	}

	/**
	 * Loads standard Klaviyo field names and attempts to match them up with standard local ones
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function add_default_fields( $options ) {

		if ( $options['connection_configured'] == true ) {

			require_once __DIR__ . '/klaviyo-fields.php';

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $klaviyo_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $klaviyo_fields[ $field ] );
				}
			}
		}

		return $options;
	}


	/**
	 * Puts a div around the Klaviyo configuration section so it can be toggled
	 *
	 * @access  public
	 * @since   1.0
	 */
	public function show_field_klaviyo_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wpf_get_option( 'crm' );
		echo '<div id="' . esc_attr( $this->slug ) . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . esc_attr( $this->name ) . '" data-crm="' . esc_attr( $this->slug ) . '">';
	}

	/**
	 * Verify connection credentials
	 *
	 * @access public
	 * @return bool
	 */
	public function test_connection() {

		check_ajax_referer( 'wpf_settings_nonce' );

		if ( isset( $_POST['klaviyo_token'] ) ) {
			$access_token = sanitize_text_field( wp_unslash( $_POST['klaviyo_token'] ) );
		} else {
			$access_token = sanitize_text_field( wp_unslash( $_POST['klaviyo_key'] ) );
		}

		$connection = $this->crm->connect( $access_token, $test = true );

		if ( is_wp_error( $connection ) ) {
			wp_send_json_error( $connection->get_error_message() );
		}

		$options = array(
			'crm'                   => $this->slug,
			'connection_configured' => true,
		);

		if ( isset( $_POST['klaviyo_token'] ) ) {
			$options['klaviyo_token'] = $access_token;
		} else {
			$options['klaviyo_key'] = $access_token;
		}

		wp_fusion()->settings->set_multiple( $options );

		wp_send_json_success();
	}
}
