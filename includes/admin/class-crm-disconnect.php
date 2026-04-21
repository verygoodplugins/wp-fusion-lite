<?php
/**
 * WP Fusion - Shared CRM disconnect flow.
 *
 * Consolidates the three disconnect trigger points into one class:
 *
 * 1. The "Disconnect" button on the Setup tab (AJAX -> handle_disconnect_crm
 *    -> fires wpf_resetting_options -> wipes wpf_options).
 * 2. The Advanced-tab reset (same hook).
 * 3. The wpfusion.com OAuth Helper popup URL:
 *    ?page=wpf-settings&crm={slug}&wpf_action=revoke_token&connection_id=...&return_url=...
 *
 * A CRM participates by adding 'disconnect' to its $supports array and
 * implementing a public disconnect() method that returns true|WP_Error.
 *
 * External contracts (wpfusion.com depends on these; do not change):
 *   - URL parameter literal: wpf_action=revoke_token
 *   - Nonce action: wpf_revoke_token
 *   - postMessage payload: { wpf_action: 'token_revoked', success, message }
 *
 * @package   WP Fusion
 * @copyright Copyright (c) 2026, Very Good Plugins, https://verygoodplugins.com
 * @license   GPL-3.0+
 * @since     3.47.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the shared CRM disconnect flow.
 *
 * @since 3.47.10
 */
class WPF_CRM_Disconnect {

	/**
	 * Constructor.
	 *
	 * @since 3.47.10
	 */
	public function __construct() {

		add_action( 'admin_init', array( $this, 'maybe_handle_revoke_url' ) );
		add_action( 'wpf_resetting_options', array( $this, 'handle_reset' ) );
	}

	/**
	 * Returns the active CRM instance if it supports disconnect.
	 *
	 * @since 3.47.10
	 *
	 * @return object|false The active CRM object, or false.
	 */
	private function get_crm() {

		if ( ! isset( wp_fusion()->crm ) || false === wp_fusion()->crm->crm ) {
			return false;
		}

		if ( ! wp_fusion()->crm->supports( 'disconnect' ) ) {
			return false;
		}

		return wp_fusion()->crm->crm;
	}

	/**
	 * Handles the wpfusion.com OAuth Helper revoke URL.
	 *
	 * @since 3.47.10
	 */
	public function maybe_handle_revoke_url() {

		if ( ! isset( $_GET['wpf_action'] ) || 'revoke_token' !== $_GET['wpf_action'] ) {
			return;
		}

		$crm = $this->get_crm();

		if ( ! $crm ) {
			return;
		}

		if ( ! isset( $_GET['crm'] ) || $crm->slug !== $_GET['crm'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'wp-fusion-lite' ) );
		}

		$return_url = isset( $_GET['return_url'] ) ? esc_url_raw( wp_unslash( $_GET['return_url'] ) ) : '';

		if ( ! isset( $_POST['confirm_revoke'] ) ) {
			$this->show_confirmation_page( $crm );
			exit;
		}

		if ( ! isset( $_POST['wpf_revoke_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpf_revoke_nonce'] ) ), 'wpf_revoke_token' ) ) {
			wp_die( esc_html__( 'Security check failed', 'wp-fusion-lite' ) );
		}

		$result = $crm->disconnect();

		if ( is_wp_error( $result ) ) {
			$this->send_response( false, $result->get_error_message() );
		} else {
			$this->send_response( true, __( 'Connection successfully revoked', 'wp-fusion-lite' ) );
		}

		exit;
	}

	/**
	 * Calls disconnect() when settings are being reset.
	 *
	 * Fired from handle_disconnect_crm() (Setup tab button / Advanced tab reset).
	 * On this path the caller will also delete wpf_options entirely, so clearing
	 * individual token keys is redundant but harmless.
	 *
	 * @since 3.47.10
	 *
	 * @param array $options The current WP Fusion options (unused).
	 */
	public function handle_reset( $options ) {

		$crm = $this->get_crm();

		if ( ! $crm ) {
			return;
		}

		$result = $crm->disconnect();

		if ( is_wp_error( $result ) ) {
			wpf_log( 'error', 0, sprintf( 'Failed to disconnect %s during settings reset: %s', $crm->name, $result->get_error_message() ) );
		} else {
			wpf_log( 'info', 0, sprintf( '%s successfully disconnected during settings reset.', $crm->name ) );
		}
	}

	/**
	 * Renders the revoke confirmation page shown in the wpfusion.com popup.
	 *
	 * @since 3.47.10
	 *
	 * @param object $crm The active CRM instance.
	 */
	private function show_confirmation_page( $crm ) {
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
				<?php /* translators: %s is the name of the CRM. */ ?>
				<h1><?php printf( esc_html__( 'Revoke %s Connection', 'wp-fusion-lite' ), esc_html( $crm->name ) ); ?></h1>
				<?php /* translators: %s is the name of the CRM. */ ?>
				<p><?php printf( esc_html__( 'Are you sure you want to revoke the connection to %s? This will:', 'wp-fusion-lite' ), esc_html( $crm->name ) ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Remove all stored access tokens', 'wp-fusion-lite' ); ?></li>
					<?php /* translators: %s is the name of the CRM. */ ?>
					<li><?php printf( esc_html__( 'Disable the %s integration', 'wp-fusion-lite' ), esc_html( $crm->name ) ); ?></li>
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
	 * Renders the success/error page and posts back to the parent window.
	 *
	 * @since 3.47.10
	 *
	 * @param bool   $success Whether the revocation was successful.
	 * @param string $message The response message (unused in the rendered
	 *                        page body itself; the payload text is hardcoded
	 *                        in the postMessage contract below).
	 */
	private function send_response( $success, $message ) {
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
}
