<?php

class WPF_PulseTechnologyCRM_Admin {

    /**
     * The CRM slug
     *
     * @var string
     * @since 1.0.0
     */

    private $slug;

    /**
     * The CRM name
     *
     * @var string
     * @since 1.0.0
     */

    private $name;

    /**
     * The CRM object
     *
     * @var object
     * @since 1.0.0
     */

    private $crm;

    /**
     * Get things started
     *
     * @since 1.0.0
     */

    public function __construct( $slug, $name, $crm ) {

        $this->slug = $slug;
        $this->name = $name;
        $this->crm  = $crm;

        add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
        add_action( 'show_field_pulsetech_header_begin', array( $this, 'show_field_pulsetech_header_begin' ), 10, 2 );
        add_action( 'show_field_pulsetech_footer_end', array( $this, 'show_field_pulsetech_footer_end' ), 10, 2 );

        // AJAX callback to test the connection
        add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

        if ( wp_fusion()->settings->get( 'crm' ) == $this->slug ) {
            $this->init();
        }

        // OAuth
        add_action( 'admin_init', array( $this, 'maybe_oauth_complete' ) );

    }

    /**
     * Hooks to run when this CRM is selected as active
     *
     * @since 1.0.0
     */

    public function init() {

        // Hooks in init() will run on the admin screen when this CRM is active
    }

    /**
     * Hooks to run when this CRM is selected as active
     *
     * @access  public
     * @since   1.0
     */

    public function maybe_oauth_complete()
    {
        if ( isset( $_GET['code'] ) && isset( $_GET['crm'] ) && 'pulsetech' == $_GET['crm'] )
        {
            $client_id = wp_fusion()->settings->get('pulsetech_client_id');
            $secret = wp_fusion()->settings->get('pulsetech_secret');

            $body = array(
                'grant_type'    => 'authorization_code',
                'code'          => $_GET['code'],
                'client_id'     => $client_id,
                'client_secret' => $secret,
                'redirect_uri'  => admin_url('options-general.php?page=wpf-settings&crm=pulsetech'),
            );

            $params = array(
                'timeout'    => 30,
                'user-agent' => 'WP Fusion; ' . home_url(),
                'headers'    => array(
                    'Content-type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ),
                'body'       => $body,
            );

            $response = wp_remote_post( $this->crm->oauth_url_token, $params );

            if ( is_wp_error( $response ) ) {
                return false;
            }

            $response = json_decode( wp_remote_retrieve_body( $response ) );

            wp_fusion()->settings->set( 'pulsetech_refresh_token', $response->refresh_token );
            wp_fusion()->settings->set( 'pulsetech_token', $response->access_token );
            wp_fusion()->settings->set( 'crm', $this->slug );

            wp_redirect( get_admin_url() . 'options-general.php?page=wpf-settings' );
            exit;

        }

    }


    /**
     * Loads CRM connection information on settings page
     *
     * @since 1.0.0
     *
     * @param array $settings The registered settings on the options page.
     * @param array $options  The options saved in the database.
     * @return array $settings The settings.
     */

    public function register_connection_settings( $settings, $options ) {

        $new_settings = array();

        $new_settings['pulsetech_header'] = array(
            'title'   => __( 'Pulse - Connection', 'wp-fusion' ),
            'type'    => 'heading',
            'section' => 'setup',
            'desc' => 'Use this URL when create the client on your Pulse application: <br/><strong>'.admin_url('options-general.php?page=wpf-settings&crm=pulsetech').'</strong>'
        );

        $new_settings['pulsetech_url'] = array(
            'title'   => __( 'URL', 'wp-fusion' ),
            'desc'    => __( 'URL to your Pulse application', 'wp-fusion' ),
            'type'    => 'text',
            'section' => 'setup',
        );

        $new_settings['pulsetech_client_id'] = array(
            'title'       => __( 'Client ID', 'wp-fusion' ),
            'std'     => '',
            'type'    => 'text',
            'section' => 'setup'
        );

        $new_settings['pulsetech_secret'] = array(
            'title'       => __( 'Secret', 'wp-fusion' ),
            'std'         => '',
            'type'        => 'text',
            'section'     => 'setup',
        );

        if ( !empty($options['pulsetech_client_id']) && !empty($options['pulsetech_secret']))
        {
            if (empty($options['pulsetech_refresh_token']))
            {
                $query = http_build_query([
                    'client_id' => $options['pulsetech_client_id'],
                    'redirect_uri' => admin_url('options-general.php?page=wpf-settings&crm=pulsetech'),
                    'response_type' => 'code',
                    'scope' => '',
                    'state' => '123',
                ]);

                $buttonUrl = $options['pulsetech_url'];

                if (strpos($buttonUrl, '.dev.thepulsespot.com') !== false) {
                    $buttonUrl = str_replace('/app.', '/portal.', $buttonUrl).'oauth/authorize';
                } else {
                    $buttonUrl = $this->crm->oauth_url_authorize;
                }

                $new_settings['pulsetech_authorize'] = array(
                    'title' => __('Authorize', 'wp-fusion'),
                    'type' => 'heading',
                    'section' => 'setup',
                    'desc' => '<a class="button" href="' . $buttonUrl . '?' . $query . '">Click here to Authorize</a><br /><span class="description">You\'ll be taken to Pulse to authorize WP Fusion and generate access keys for this site.'
                );

                $new_settings['pulsetech_footer'] = array(
                    'title'   => __( '', 'wp-fusion' ),
                    'type'    => 'heading',
                    'section' => 'setup',
                );
            } else {
                $new_settings['pulsetech_connected'] = array(
                    'title'   => __( 'Pulse - Already Connected', 'wp-fusion' ),
                    'type'    => 'heading',
                    'section' => 'setup',
                );

                $new_settings['pulsetech_token'] = array(
                    'title'   => __( 'Access Token', 'wp-fusion-lite' ),
                    'type'    => 'text',
                    'section' => 'setup',
                );

                $new_settings['pulsetech_refresh_token'] = array(
                    'title'       => __( 'Refresh token', 'wp-fusion-lite' ),
                    'type'        => 'api_validate',
                    'section'     => 'setup',
                    'class'       => 'api_key',
                    'desc'        => sprintf( __( 'If your connection with %s is broken you can erase the refresh token and save the settings page to re-authorize with %s.', 'wp-fusion-lite' ), wp_fusion()->crm->name, wp_fusion()->crm->name ),
                    'post_fields' => array( 'pulsetech_token', 'pulsetech_refresh_token' ),
                );
            }
        }else{
            $new_settings['pulsetech_footer'] = array(
                'title'   => __( '', 'wp-fusion' ),
                'type'    => 'heading',
                'section' => 'setup',
            );
        }

        $settings = wp_fusion()->settings->insert_setting_after( 'crm', $settings, $new_settings );

        return $settings;

    }

    /**
     * Puts a div around the CRM configuration section so it can be toggled
     *
     * @since 1.0.0
     *
     * @param string $id    The ID of the field.
     * @param array  $field The field properties.
     * @return mixed HTML output.
     */

    public function show_field_pulsetech_header_begin( $id, $field ) {

        echo '</table>';
        $crm = wp_fusion()->settings->get( 'crm' );
        echo '<div id="' . $this->slug . '" class="crm-config ' . ( $crm == false || $crm != $this->slug ? 'hidden' : 'crm-active' ) . '" data-name="' . $this->name . '" data-crm="' . $this->slug . '">';

    }

    public function show_field_pulsetech_footer_end($id, $field){
        echo '</table>';
        echo '</div>';
    }

    /**
     * Verify connection credentials.
     *
     * @since 1.0.0
     *
     * @return mixed JSON response.
     */

    public function test_connection()
    {
        $access_token = sanitize_text_field( $_POST['pulsetech_token'] );

        $connection = $this->crm->connect( $access_token, true );

        if ( is_wp_error( $connection ) ) {

            wp_send_json_error( $connection->get_error_message() );

        } else {

            $options                          = wp_fusion()->settings->get_all();
            $options['pulsetech_token']       = $access_token;
            $options['crm']                   = $this->slug;
            $options['connection_configured'] = true;

            wp_fusion()->settings->set_all( $options );

            wp_send_json_success();

        }
    }
}
