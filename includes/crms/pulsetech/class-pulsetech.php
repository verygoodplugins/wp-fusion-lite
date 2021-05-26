<?php

class WPF_PulseTechnologyCRM
{

    /**
     * Contains API url
     *
     * @var string
     * @since 1.0.0
     */

    public $url = null;
    public $client_secret = null;
    public $client_id = null;
    public $token = null;
    public $url_base = null;
    public $oauth_url_authorize = null;
    public $oauth_url_token = null;

    /**
     * Declares how this CRM handles tags and fields.
     *
     * "add_tags" means that tags are applied over the API as strings (no tag IDs).
     * With add_tags enabled, WP Fusion will allow users to type new tag names into the tag select boxes.
     *
     * "add_fields" means that pulsetech field / attrubute keys don't need to exist first in the CRM to be used.
     * With add_fields enabled, WP Fusion will allow users to type new filed names into the CRM Field select boxes.
     *
     * @var array
     * @since 1.0.0
     */

    public $supports = array();

    /**
     * API parameters
     *
     * @var array
     * @since 1.0.0
     */

    public $params = array();

    /**
     * Get things started
     *
     * @since 1.0.0
     */

    public function __construct()
    {
        $this->slug = 'pulsetech';
        $this->name = 'PulseTechnologyCRM';

        $api_url = wp_fusion()->settings->get('pulsetech_url', '');

        if (strpos($api_url, '.dev.thepulsespot.com') !== false) {
            $portal_url = str_replace('/app.', '/portal.', $api_url);
        } else {
            $portal_url = 'http://portal.thepulsespot.com/';
        }

        $this->url_base = $portal_url . 'api/v1/';
        $this->oauth_url_authorize = $portal_url . 'oauth/authorize';
        $this->oauth_url_token = $portal_url . 'oauth/token';

        // Set up admin options
        if (is_admin()) {
            require_once dirname( __FILE__ ) . '/admin/class-admin.php';
            new WPF_PulseTechnologyCRM_Admin($this->slug, $this->name, $this);
        }

        // Error handling
        add_filter('http_response', array($this, 'handle_http_response'), 50, 3);

    }

    /**
     * Sets up hooks specific to this CRM.
     *
     * This function only runs if this CRM is the active CRM.
     *
     * @since 1.0.0
     */

    public function init()
    {
        add_filter('wpf_format_field_value', array($this, 'format_field_value'), 10, 3);

        add_filter('wpf_crm_post_data', array($this, 'format_post_data'));

    }

    public function format_field_value($value, $field_type, $field)
    {
        if ($field_type == 'multiselect') {
            if (!is_array($value)) {
                $value = explode(',', $value);
                return $value;
            }
        }

        return $value;
    }


    /**
     * Formats POST data received from Webhooks into standard format
     *
     * @access public
     * @return array
     */

    public function format_post_data($post_data)
    {
        wpf_log('info', null, 'Data posted: '.json_encode($post_data));

        if (isset($post_data['contact_id'])) {
            return $post_data;
        }

        if (isset($post_data['id'])) {
            $post_data['contact_id'] = $post_data['id'];
            return $post_data;
        }

        $payload = json_decode(file_get_contents('php://input'));

        if (is_object($payload)) {
            $post_data['contact_id'] = $payload->id;
        }

        return $post_data;
    }


    /**
     * Gets params for API calls.
     *
     * @return array $params The API parameters.
     * @since 1.0.0
     *
     */

    public function get_params($api_url = null, $client_id = null, $client_secret = null)
    {
        if ($this->params) {
            return $this->params;
        }

        // Get saved data from DB
        if (empty($api_url) || empty($client_id) || empty($client_secret)) {
            $api_url = wp_fusion()->settings->get('pulsetech_url');
            $client_secret = wp_fusion()->settings->get('pulsetech_secret');
            $client_id = wp_fusion()->settings->get('pulsetech_client_id');
            $token = wp_fusion()->settings->get('pulsetech_token', null);
        } else {
            $token = null;
        }

        $this->url = $api_url;
        $this->client_secret = $client_secret;
        $this->client_id = $client_id;
        $this->token = $token;

        $this->params = [
            'user-agent' => 'WP Fusion; ' . home_url(),
            'timeout' => 15
        ];

        if ($token) {
            $this->params['headers'] = [
                'Authorization' => 'Bearer ' . $this->token,
            ];
        }

        return $this->params;
    }

    /**
     * Refresh an access token from a refresh token
     *
     * @access  public
     * @return  bool
     */

    public function refresh_token()
    {
        $refresh_token = wp_fusion()->settings->get('pulsetech_refresh_token');

        $params = array(
            'headers' => array(
                'Content-type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token,
            ),
        );

        $response = wp_remote_post($this->oauth_url_token, $params);

        if (is_wp_error($response)) {
            return $response;
        }

        $body_json = json_decode(wp_remote_retrieve_body($response));

        if (isset($body_json->error)) {
            return new WP_Error('error', $body_json->error_description);
        }

        wp_fusion()->settings->set('pulsetech_token', $body_json->access_token);
        wp_fusion()->settings->set('pulsetech_refresh_token', $body_json->refresh_token);

        return $body_json->access_token;

    }

    /**
     * Check HTTP Response for errors and return WP_Error if found
     *
     * @param object $response The HTTP response.
     * @param array $args The HTTP request arguments.
     * @param string $url The HTTP request URL.
     * @return object $response The response.
     * @since 1.0.2
     *
     */

    public function handle_http_response($response, $args, $url)
    {
        if (strpos($url, strval($this->oauth_url_token)) !== false && 'WP Fusion; ' . home_url() == $args['user-agent']) {

            $body_json = json_decode(wp_remote_retrieve_body($response));

            $response_code = wp_remote_retrieve_response_code($response);

            if (isset($body_json->success) && false == $body_json->success) {

                $response = new WP_Error('error', $body_json->message);

            } elseif (500 == $response_code) {

                $response = new WP_Error('error', __('An error has occurred in API server. [error 500]', 'wp-fusion'));

            }
        }

        return $response;
    }

    /**
     * Initialize connection
     *
     * @access  public
     * @return  bool
     */

    public function connect($access_token = null, $test = false)
    {

        if (!$this->params) {
            $this->get_params($access_token);
        }

        if (false === $test) {
            return true;
        }

        $response = $this->pulseApiGet('tags');

        if (is_wp_error($response)) {
            return $response;
        }

        return true;

    }

    /**
     * Performs initial sync once connection is configured.
     *
     * @return bool
     * @since 1.0.0
     *
     */

    public function sync()
    {
        wpf_log('info', null, 'Pulse API - Sync');

        if (is_wp_error($this->connect())) {
            return false;
        }

        $this->sync_tags();
        $this->sync_crm_fields();

        do_action('wpf_sync');

        return true;
    }

    private function pulseApiGet($uri)
    {
        $params = $this->get_params();
        $request = $this->url . 'api/v1/' . $uri;

        $response = wp_remote_get($request, $params);

        if (is_wp_error($response)) {
            wpf_log('error', null, 'Pulse API - error on GET <strong>' . $request . '.</strong>: ' . json_encode($response));
            return $response;
        }

        wpf_log('info', null, 'Pulse API - success on GET <strong>' . $request . '.</strong>: ' . wp_remote_retrieve_body($response));
        return json_decode(wp_remote_retrieve_body($response));
    }

    private function pulseApiPost($uri, $data)
    {
        $params = $this->get_params();
        $request = $this->url . 'api/v1/' . $uri;
        $params['body'] = $data;

        $response = wp_remote_post($request, $params);

        if (is_wp_error($response)) {
            wpf_log('error', null, 'Pulse API - error on POST <strong>' . $request . '.</strong> (body: ' . json_encode($params['body']) . '): ' . json_encode($response));
            return $response;
        }

        wpf_log('info', null, 'Pulse API - success on POST <strong>' . $request . '.</strong>: ' . wp_remote_retrieve_body($response));
        return json_decode(wp_remote_retrieve_body($response));
    }

    private function pulseApiPut($uri, $data)
    {
        $params = $this->get_params();
        $request = $this->url . 'api/v1/' . $uri;
        $params['body'] = $data;
        $params['method'] = 'PUT';

        $response = wp_remote_post($request, $params);

        if (is_wp_error($response)) {
            wpf_log('error', null, 'Pulse PUT - error on PUT <strong>' . $request . '.</strong> (body: ' . json_encode($params['body']) . '): ' . json_encode($response));
            return $response;
        }

        wpf_log('info', null, 'Pulse API - success on PUT <strong>' . $request . '.</strong>: ' . wp_remote_retrieve_body($response));
        return json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * Gets all available tags and saves them to options.
     *
     * @return array|WP_Error Either the available tags in the CRM, or a WP_Error.
     * @since 1.0.0
     *
     */

    public function sync_tags()
    {
        wpf_log('info', null, 'Pulse API - sync_tags');

        $currentPage = 1;
        $lastPage = 1;
        $listTags = [];

        while ($currentPage <= $lastPage) {
            $uri = 'tags';

            if ($currentPage > 1) {
                $uri .= '?page=' . $currentPage;
            }

            $response = $this->pulseApiGet($uri);

            if (is_wp_error($response)) {
                return $response;
            }

            $currentPage++;
            $lastPage = $response->meta->last_page;

            if (isset($response->data) && is_array($response->data)) {
                foreach ($response->data as $tagDef) {
                    $listTags[$tagDef->id] = $tagDef->name;
                }
            }

        }

        // Load available tags into $available_tags like 'tag_id' => 'Tag Label'
        wp_fusion()->settings->set('available_tags', $listTags);

        return $listTags;
    }

    /**
     * Loads all pulsetech fields from CRM and merges with local list.
     *
     * @return array|WP_Error Either the available fields in the CRM, or a WP_Error.
     * @since 1.0.0
     *
     */

    public function sync_crm_fields()
    {
        wpf_log('info', null, 'Pulse API - sync_crm_fields');

        $response = $this->pulseApiGet('contact/available-fields');

        if (is_wp_error($response)) {
            return $response;
        }

        $fields = $response->data;
        $listFields = [];

        $fieldsToHide = [
            'lead_source_id'
            //We can add more fields here to avoid showing them on WPFusion since the api is generic
        ];

        foreach ($fields as $fieldKey => $fieldDetails) {
            if (!in_array($fieldKey, $fieldsToHide)) {
                if (isset($fieldDetails->label)) {
                    $label = $fieldDetails->label;
                } else {
                    $label = ucwords(str_replace('_', ' ', $fieldKey));
                }

                if ($this->strContains($fieldKey, 'address_') && $this->strContains($fieldKey, '.country')) {
                    $fieldKey .= '_name';
                }

                $listFields[$fieldKey] = $label;
            }
        }

        // Load available fields into $crm_fields like 'field_key' => 'Field Label'dd
        asort($listFields);

        wp_fusion()->settings->set('crm_fields', $listFields);

        return $listFields;
    }

    private function strContains($haystack, $needles)
    {
        foreach ((array)$needles as $needle) {
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets contact ID for a user based on email address.
     *
     * @param string $email_address The email address to look up.
     * @return int|WP_Error The contact ID in the CRM.
     * @since 1.0.0
     *
     */

    public function get_contact_id($email_address)
    {
        wpf_log('info', null, 'Pulse API - get_contact_id with email: ' . $email_address);

        $response = $this->pulseApiPost('contact/find/', ['email' => $email_address]);

        if (is_wp_error($response)) {
            return $response;
        }

        return $response->id;
    }


    /**
     * Gets all tags currently applied to the contact in the CRM.
     *
     * @param int $contact_id The contact ID to load the tags for.
     * @return array|WP_Error The tags currently applied to the contact in the CRM.
     * @since 1.0.0
     *
     */

    public function get_tags($contact_id)
    {
        wpf_log('info', null, 'Pulse API - get_tags with contact id: ' . $contact_id);

        $currentPage = 1;
        $lastPage = 1;
        $listTags = [];

        while ($currentPage <= $lastPage) {
            $uri = "contacts/$contact_id/tags";

            if ($currentPage > 1) {
                $uri .= '?page=' . $currentPage;
            }

            $response = $this->pulseApiGet($uri);

            if (is_wp_error($response)) {
                return $response;
            }

            $currentPage++;
            $lastPage = $response->meta->last_page;

            if (isset($response->data) && is_array($response->data)) {
                foreach ($response->data as $tagDef) {
                    $listTags[] = $tagDef->id;
                }
            }
        }

        // Parse response to create an array of tag ids. $tags = array(123, 678, 543); (should not be an associative array)
        return $listTags;
    }

    /**
     * Applies tags to a contact.
     *
     * @param array $tags A numeric array of tags to apply to the contact.
     * @param int $contact_id The contact ID to apply the tags to.
     * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
     * @since 1.0.0
     *
     */

    public function apply_tags($tags, $contact_id)
    {
        wpf_log('info', null, 'Pulse API - apply_tags <br/>tags:' . implode($tags) . ' <br/>contact_id: ' . $contact_id);

        $response = $this->pulseApiPost("contacts/$contact_id/tag-apply", ['tag_id' => $tags]);

        if (is_wp_error($response)) {
            return $response;
        }

        return true;
    }

    /**
     * Removes tags from a contact.
     *
     * @param array $tags A numeric array of tags to remove from the contact.
     * @param int $contact_id The contact ID to remove the tags from.
     * @return bool|WP_Error Either true, or a WP_Error if the API call failed.
     * @since 1.0.0
     *
     */

    public function remove_tags($tags, $contact_id)
    {
        wpf_log('info', null, 'Pulse API - remove_tags <br/>tags:' . implode($tags) . ' <br/>contact_id: ' . $contact_id);

        $response = $this->pulseApiPost("contacts/$contact_id/untag", ['tag_id' => $tags]);

        if (is_wp_error($response)) {
            return $response;
        }

        return true;

    }

    /**
     * Adds a new contact.
     *
     * @param array $contact_data An associative array of contact fields and field values.
     * @param bool $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
     * @return int|WP_Error Contact ID on success, or WP Error.
     * @since 1.0.0
     *
     */
    public function add_contact($contact_data, $map_meta_fields = true)
    {
        wpf_log('info', null, 'Pulse API - add_contact <br/>contact_data:' . json_encode($contact_data) . ' <br/>map_meta_fields: ' . $map_meta_fields);

        if (true == $map_meta_fields) {
            $contact_data = wp_fusion()->crm_base->map_meta_fields($contact_data);
        }

        $contact_data['is_marketable'] = true;

        wpf_log('info', null, 'Pulse API - add_contact formatted <br/>contact_data:' . json_encode($contact_data));

        $response = $this->pulseApiPost('contacts', $contact_data);

        if (is_wp_error($response)) {
            return $response;
        }

        wpf_log('info', null, 'Pulse API - new contact on Pulse: : ' . $response->id);

        // Get new contact ID out of response
        return $response->id;

    }

    /**
     * Updates an existing contact record.
     *
     * @param int $contact_id The ID of the contact to update.
     * @param array $contact_data An associative array of contact fields and field values.
     * @param bool $map_meta_fields Whether to map WordPress meta keys to CRM field keys.
     * @return bool|WP_Error Error if the API call failed.
     * @since 1.0.0
     *
     */

    public function update_contact($contact_id, $contact_data, $map_meta_fields = true)
    {
        wpf_log('info', null, 'Pulse API - update_contact <br/>contact_id: ' . $contact_id . '<br/>contact_data:' . json_encode($contact_data) . ' <br/>map_meta_fields: ' . $map_meta_fields);

        if (true == $map_meta_fields) {
            $contact_data = wp_fusion()->crm_base->map_meta_fields($contact_data);
        }

        $response = $this->pulseApiPut("contacts/$contact_id", $contact_data);

        if (is_wp_error($response)) {
            return $response;
        }

        return true;
    }

    /**
     * Loads a contact record from the CRM and maps CRM fields to WordPress fields
     *
     * @param int $contact_id The ID of the contact to load.
     * @return array|WP_Error User meta data that was returned.
     * @since 1.0.0
     *
     */

    public function load_contact($contact_id)
    {
        wpf_log('info', null, 'Pulse API - load_contact <br/>contact_id: ' . $contact_id);

        $response = $this->pulseApiGet("contacts/$contact_id");

        if (is_wp_error($response)) {
            return $response;
        }

        $fields = (array)$response;

        $user_meta = array();
        $contact_fields = wp_fusion()->settings->get('contact_fields');

//        wpf_log('info', null, 'Pulse API - load_contact FIELDS: ' . json_encode($contact_fields));

        foreach ($contact_fields as $field_id => $field_data) {
//            wpf_log('info', null, 'Pulse API - load_contact $field_id: '.$field_id.' data ' . json_encode($field_data).' crm field: '.$field_data['crm_field']);

            if ($field_data['active'] && isset($fields[$field_data['crm_field']])) {
                $user_meta[$field_id] = $fields[$field_data['crm_field']];
            }
        }

//        wpf_log('info', null, 'Pulse API - load_contact READY: ' . json_encode($user_meta));

        return $user_meta;
    }


    /**
     * Gets a list of contact IDs based on tag
     *
     * @param string $tag The tag ID or name to search for.
     * @return array Contact IDs returned.
     * @since 1.0.0
     *
     */

    public function load_contacts($tag)
    {
        wpf_log('info', null, 'Pulse API - load_contacts <br/>tag: ' . $tag);

        if (is_integer($tag)) {
            $urlBase = "contacts/tagged/?tag_id=" . urlencode($tag);
        } else {
            $urlBase = "contacts/tagged/?tag_name=" . urlencode($tag);
        }

        $currentPage = 1;
        $lastPage = 1;
        $listContacts = [];

        while ($currentPage <= $lastPage) {
            $uri = $urlBase;

            if ($currentPage > 1) {
                $uri .= '?page=' . $currentPage;
            }

            $response = $this->pulseApiGet($uri);

            if (is_wp_error($response)) {
                return $response;
            }

            $currentPage++;
            $lastPage = $response->meta->last_page;

            if (isset($response->data) && is_array($response->data)) {
                foreach ($response->data as $contact) {
                    $listContacts[] = $contact->id;
                }
            }
        }

        if (is_wp_error($response)) {
            return $response;
        }

        return $listContacts;
    }
}
