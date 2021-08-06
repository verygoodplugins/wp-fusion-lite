<?php

class WPF_Autonami_Admin {

	/**
	 * The CRM slug
	 *
	 * @var string
	 * @since 3.37.14
	 */

	private $slug;

	/**
	 * The CRM name
	 *
	 * @var string
	 * @since 3.37.14
	 */

	private $name;

	/**
	 * The CRM object
	 *
	 * @var object
	 * @since 3.37.14
	 */

	private $crm;

	/**
	 * Get things started
	 *
	 * @since 3.37.14
	 */
	public function __construct( $slug, $name, $crm ) {

		$this->slug = $slug;
		$this->name = $name;
		$this->crm  = $crm;

		// Settings
		add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
		add_action( 'show_field_autonami_header_begin', array( $this, 'show_field_autonami_header_begin' ), 10, 2 );

		// AJAX
		add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

		if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
			$this->init();
		}

		// OAuth
		add_action( 'admin_init', array( $this, 'handle_rest_authentication' ) );
		add_action( 'wpf_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

	}

	/**
	 * Hooks to run when this CRM is selected as active
	 *
	 * @since 3.37.14
	 */

	public function init() {

		add_filter( 'wpf_initialize_options', array( $this, 'add_default_fields' ), 10 );

	}

	public function enqueue_scripts() {

		?>
		<script>
			var bwf_wp_fusion_params = {
				optionsurl: '<?php echo admin_url( 'options-general.php?page=wpf-settings' ); ?>',
				sitetitle: '<?php echo urlencode( get_bloginfo( 'name' ) ); ?>',
			};
			
			jQuery(document).ready(function ($) {
				$('#autonami_url').on('input', function (event) {

					if ($(this).val().length && $(this).val().includes('https://')) {
						var url = $(this).val();
						url = url.replace(/\/$/, "");
						url = url + '/wp-admin/authorize-application.php?app_name=WP+Fusion+-+' + bwf_wp_fusion_params.sitetitle + '&success_url=' + bwf_wp_fusion_params.optionsurl + '%26crm=autonami';

						$("a#autonami-auth-btn").attr('href', url);

						$("a#autonami-auth-btn").removeClass('button-disabled').addClass('button-primary');

					} else {

						$("a#autonami-auth-btn").removeClass('button-primary').addClass('button-disabled');

					}

				});
			});
		</script>
		<?php

	}

	/**
	 * Handle REST API authentication.
	 *
	 * @since 3.37.14
	 */
	public function handle_rest_authentication() {

		if ( isset( $_GET['site_url'] ) && isset( $_GET['crm'] ) && 'autonami' == $_GET['crm'] ) {

			$url      = esc_url( urldecode( $_GET['site_url'] ) );
			$username = sanitize_text_field( urldecode( $_GET['user_login'] ) );
			$password = sanitize_text_field( urldecode( $_GET['password'] ) );

			wp_fusion()->settings->set( 'autonami_url', $url );
			wp_fusion()->settings->set( 'autonami_username', $username );
			wp_fusion()->settings->set( 'autonami_password', $password );
			wp_fusion()->settings->set( 'crm', $this->slug );

			wp_redirect( admin_url( 'options-general.php?page=wpf-settings#setup' ) );
			exit;

		}

	}


	/**
	 * Registers Autonami API settings.
	 *
	 * @param array $settings The registered settings on the options page.
	 * @param array $options The options saved in the database.
	 *
	 * @return array $settings The settings.
	 * @since  3.37.14
	 *
	 */
	public function register_connection_settings( $settings, $options ) {

		$new_settings = array();

		$new_settings['autonami_header'] = array(
			'title'   => __( 'Autonami Configuration', 'wp-fusion-lite' ),
			'type'    => 'heading',
			'section' => 'setup',
		);

		$new_settings['autonami_url'] = array(
			'title'   => __( 'URL', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
			'desc'    => __( 'Enter the URL to your website where Autonami is installed (must be https://).', 'wp-fusion-lite' ),
		);

		if ( class_exists( 'BWFAN_Core' ) ) {
			$new_settings['autonami_url']['desc'] .= '<br /><br /><strong>' . sprintf( __( 'If you are trying to connect to Autonami on this site, enter %s for the URL.', 'wp-fusion-lite' ), '<code>' . home_url() . '</code>' ) . '</strong>';
		}

		// TODO here add additional desc

		if ( empty( $options['autonami_url'] ) ) {
			$href  = '#';
			$class = 'button button-disabled';
		} else {
			$href  = trailingslashit( $options['autonami_url'] ) . 'wp-admin/authorize-application.php?app_name=WP+Fusion+-+' . urlencode( get_bloginfo( 'name' ) ) . '&success_url=' . admin_url( 'options-general.php?page=wpf-settings' ) . '%26crm=autonami';
			$class = 'button';
		}

		$new_settings['autonami_url']['desc'] .= '<br /><br /><a id="autonami-auth-btn" class="' . $class . '" href="' . $href . '">' . __( 'Authorize with Autonami', 'wp-fusion-lite' ) . '</a>';
		$new_settings['autonami_url']['desc'] .= '<span class="description">' . __( 'You can click the Authorize button to be taken to the Autonami site and generate an application password automatically.', 'wp-fusion-lite' ) . '</span>';

		$new_settings['autonami_username'] = array(
			'title'   => __( 'Application Username', 'wp-fusion-lite' ),
			'type'    => 'text',
			'section' => 'setup',
		);

		$new_settings['autonami_password'] = array(
			'title'       => __( 'Application Password', 'wp-fusion-lite' ),
			'type'        => 'api_validate',
			'section'     => 'setup',
			'class'       => 'api_key',
			'post_fields' => array( 'autonami_url', 'autonami_username', 'autonami_password' ),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

		return $settings;

	}

	/**
	 * Loads standard Autonami_REST field names and attempts to match them up
	 * with standard local ones.
	 *
	 * @param array $options The options.
	 *
	 * @return array The options.
	 * @since  3.37.14
	 *
	 */

	public function add_default_fields( $options ) {

		if ( true == $options['connection_configured'] ) {

			$standard_fields = $this->get_default_fields();

			foreach ( $options['contact_fields'] as $field => $data ) {

				if ( isset( $standard_fields[ $field ] ) && empty( $options['contact_fields'][ $field ]['crm_field'] ) ) {
					$options['contact_fields'][ $field ] = array_merge( $options['contact_fields'][ $field ], $standard_fields[ $field ] );
				}
			}
		}

		return $options;

	}

	/**
	 * Gets the default fields.
	 *
	 * @return array The default fields.
	 * @since  3.37.14
	 */
	public static function get_default_fields() {

		return array(
			'first_name'     => array(
				'crm_label' => 'First Name',
				'crm_field' => 'f_name',
			),
			'last_name'      => array(
				'crm_label' => 'Last Name',
				'crm_field' => 'l_name',
			),
			'user_email'     => array(
				'crm_label' => 'Email',
				'crm_field' => 'email',
			),
			'billing_phone'  => array(
				'crm_label' => 'Phone',
				'crm_field' => 'contact_no',
			),
			'billing_state'  => array(
				'crm_label' => 'State',
				'crm_field' => 'state',
			),
			'billing_counry' => array(
				'crm_label' => 'Country',
				'crm_field' => 'country',
			),
		);

	}

	/**
	 * Puts a div around the CRM configuration section so it can be toggled.
	 *
	 * @param string $id The ID of the field.
	 * @param array  $field The field properties.
	 *
	 * @return mixed HTML output.
	 * @since 3.37.14
	 */
	public function show_field_autonami_header_begin( $id, $field ) {

		echo '</table>';
		$crm = wp_fusion()->settings->get( 'crm' );
		echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

	}


	/**
	 * Verify connection credentials.
	 *
	 * @return mixed JSON response.
	 * @since 3.37.14
	 *
	 */
	public function test_connection() {

		$url      = esc_url( $_POST['autonami_url'] );
		$username = sanitize_text_field( $_POST['autonami_username'] );
		$password = sanitize_text_field( $_POST['autonami_password'] );

		$connection = $this->crm->connect( $url, $username, $password, true );

		if ( is_wp_error( $connection ) ) {

			wp_send_json_error( $connection->get_error_message() );

		} else {

			$options                          = wp_fusion()->settings->get_all();
			$options['autonami_url']          = $url;
			$options['autonami_username']     = $username;
			$options['autonami_password']     = $password;
			$options['crm']                   = $this->slug;
			$options['connection_configured'] = true;

			wp_fusion()->settings->set_all( $options );

			wp_send_json_success();

		}

		die();

	}


}
