<?php
/**
 * Klick-Tipp REST connector
 * @author Klick-tipp Ltd.
 * @version 3.3.1
 *
 * Classes providing connections with the Klick-tipp REST server.
 */
/*

WPF_KlicktippConnector
A class using session authentication for api access.

Example: Login, subscribe, update, unsubscribe, delete, logout.

require ("klicktipp.api.inc"); // this file

$connector = new WPF_KlicktippConnector(); 
$result = $connector->login('username', 'password');
$subscriber = $connector->subscribe('example@example.com');
$result = $connector->subscriber_update($subscriber->id, array('fieldFirstName' => 'John'));
$result = $connector->unsubscribe('example@example.com');
$result = $connector->subscriber_delete($subscriber->id);
$connector->logout();
*/

class WPF_KlicktippConnector {
  protected $host;
  protected $port;
  protected $path;
  private $sessionId;
  private $sessionName;
  protected $error;

  /**
   * Instantiates a WPF_KlicktippConnector
   * The service URL will be tested: use get_last_error for any errors detected.
   *
   * @param string $service (optional) Path to the REST server
   *
   * @return the connector object
   */
  public function __construct($service = 'http://api.klick-tipp.com') {
    $uri = parse_url($service);

    if ($uri == FALSE) {
      $this->error = 'Invalid Constructor Arguments';
      return;
    }

    if (!isset($uri['scheme'])) {
      $this->error = 'Invalid Constructor Arguments';
      return;
    }

    switch ($uri['scheme']) {
      case 'http':
        $port = 80;
        break;
      case 'https':
        // Note: Only works for PHP 4.3 compiled with OpenSSL.
        $port = 443;
        break;
      default:
        $this->error = 'Invalid Constructor Arguments';
        return;
    }
    $host = $uri['host'];

    // Make sure the socket opens properly.
    $fp = @fsockopen($port == 443 ? 'ssl://' . $host : $host, $port, $errno, $errstr, 20);
    if (!$fp) {
      $this->error = 'Connection Error: ' . $errno . ' ' . trim($errstr);
      return;
    }
    fclose($fp);

    $this->port = $port;
    $this->host = $host;
    $this->path = isset($uri['path']) ? $uri['path'] : '';
  }

  /**
   * Get last error
   *
   * @return string an error description of the last error
   */
  public function get_last_error() {
    $last_error = empty($this->error) ? '' : $this->error;
    unset($this->error); // reset
    return $last_error;
  }

  /**
   * login
   *
   * @param mixed $username The login name of the user to login.
   * @param mixed $password The password of the user.
   * @return TRUE on success
   */
  public function login($username, $password) {
    if (empty($username) || empty($password)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // Login
    $data = array(
      'username' => $username,
      'password' => $password,
    );
    $response = $this->_http_request('/account/login', 'POST', $data, FALSE);

    if (empty($response->error)) {
      if (isset($response->data)) {
        if (isset($response->data->sessid)) {
          $this->sessionId = $response->data->sessid;
          $this->sessionName = $response->data->session_name;
        }
      };
      return TRUE;
    }
    else {
      $this->error = 'Login failed: ' . $response->error;
      return FALSE;
    };
  }

  /**
   * Logs out the user currently logged in.
   *
   * @return TRUE on success
   */
  public function logout() {
    $response = $this->_http_request('/account/logout', 'POST');

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Logout failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get all subscription processes (lists) of the logged in user. Requires to be logged in.
   *
   * @return A associative array <list id> => <list name>
   */
  public function subscription_process_index() {
    $response = $this->_http_request('/list');

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscription process index failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get subscription process (list) definition. Requires to be logged in.
   *
   * @param mixed $listid The id of the subscription process
   *
   * @return An object representing the Klicktipp subscription process.
   */
  public function subscription_process_get($listid) {
    if (!$listid) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // retrieve
    $response = $this->_http_request('/list/' . urlencode($listid));

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscription process get failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get subscription process (list) redirection url for given subscription.
   *
   * @param mixed $listid The id of the subscription process.
   * @param mixed $email The email address of the subscriber.
   *
   * @return A redirection url as defined in the subscription process.
   */
  public function subscription_process_redirect($listid, $email) {
    if (empty($listid) || empty($email)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // update
    $data = array(
      'listid' => $listid,
      'email' => $email,
    );
    $response = $this->_http_request('/list/redirect', 'POST', $data);

    if (empty($response->error)) {
      return !isset($response->data) ? '' : $response->data;
    }
    else {
      $this->error = 'Subscription process get redirection url failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get all manual tags of the logged in user. Requires to be logged in.
   *
   * @return A associative array <tag id> => <tag name>
   */
  public function tag_index() {
    $response = $this->_http_request('/tag');

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Tag index failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get a tag definition. Requires to be logged in.
   *
   * @param mixed $tagid The tag id.
   *
   * @return An object representing the Klicktipp tag object.
   */
  public function tag_get($tagid) {
    if (!$tagid) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // retrieve
    $response = $this->_http_request('/tag/' . urlencode($tagid));

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Tag get failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Create a new manual tag. Requires to be logged in.
   *
   * @param string $name The name of the tag.
   * @param mixed $text (optional) An additional description of the tag.
   *
   * @return mixed The id of the newly created tag.
   */
  public function tag_create($name, $text = '') {
    if (empty($name)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // create tag
    $data = array(
      'name' => $name,
    );
    if (!empty($text)) {
      $data['text'] = $text;
    }
    $response = $this->_http_request('/tag', 'POST', $data);

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Tag creation failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Updates a tag. Requires to be logged in.
   *
   * @param mixed $tagid The tag id used to identify which tag to modify.
   * @param mixed $name (optional) The new tag name. Set empty to leave it unchanged.
   * @param mixed $text (optional) The new tag description. Set empty to leave it unchanged.
   *
   * @return TRUE on success
   */
  public function tag_update($tagid, $name = '', $text = '') {
    if (empty($tagid) || (empty($name) && empty($text))) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // update tag
    $data = array();
    if (!empty($name)) {
      $data['name'] = $name;
    }
    if (!empty($text)) {
      $data['text'] = $text;
    }
    $response = $this->_http_request('/tag/' . urlencode($tagid), 'PUT', $data);

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Tag update failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Deletes a tag. Requires to be logged in.
   *
   * @param mixed $tagid The user id of the user to delete.
   *
   * @return TRUE on success
   */
  public function tag_delete($tagid) {
    if (empty($tagid)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // delete tag
    $response = $this->_http_request('/tag/' . urlencode($tagid), 'DELETE');

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Tag deletion failed: ' . $response->error;
      return FALSE;
    }
  }

  ////////////////////////////////////
  /**
   * Get all contact fields of the logged in user. Requires to be logged in.
   *
   * @return A associative array <field id> => <field name>
   */
  public function field_index() {
    $response = $this->_http_request('/field');

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Field index failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Subscribe an email. Requires to be logged in.
   *
   * @param mixed $email The email address of the subscriber.
   * @param mixed $listid (optional) The id subscription process.
   * @param mixed $tagid (optional) The id of the manual tag the subscriber will be tagged with.
   * @param mixed $fields (optional) Additional fields of the subscriber.
   *
   * @return An object representing the Klicktipp subscriber object.
   */
  public function subscribe($email, $listid = 0, $tagid = 0, $fields = array(), $smsnumber = '') {
    if (empty($email) && empty($smsnumber)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // subscribe
    $data = array(
      'email' => $email,
    );
    if (!empty($smsnumber)) {
      $data['smsnumber'] = $smsnumber;
    }
    if (!empty($listid)) {
      $data['listid'] = $listid;
    }
    if (!empty($tagid)) {
      $data['tagid'] = $tagid;
    }
    if (!empty($fields)) {
      $data['fields'] = $fields;
    }
    $response = $this->_http_request('/subscriber', 'POST', $data);

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscription failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Unsubscribe an email. Requires to be logged in.
   *
   * @param mixed $email The email address of the subscriber.
   *
   * @return TRUE on success
   */
  public function unsubscribe($email) {
    if (empty($email)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // unsubscribe
    $data = array(
      'email' => $email,
    );
    $response = $this->_http_request('/subscriber/unsubscribe', 'POST', $data);

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Unsubscription failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Tag an email. Requires to be logged in.
   *
   * @param mixed $email The email address of the subscriber.
   * @param mixed $tagids The id (or an array) of the manual tag(s) the subscriber will be tagged with.
   *
   * @return TRUE on success
   */
  public function tag($email, $tagids) {
    if (empty($email) || empty($tagids)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // make an array
    if (is_numeric($tagids)) {
      $tagids = array($tagids);
    }

    // tag
    $data = array(
      'email' => $email,
      'tagids' => $tagids,
    );
    $response = $this->_http_request('/subscriber/tag', 'POST', $data);

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Tagging failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Untag an email. Requires to be logged in.
   *
   * @param mixed $email The email address of the subscriber.
   * @param mixed $tagid The id of the manual tag that will be removed from the subscriber.
   *
   * @return TRUE on success.
   */
  public function untag($email, $tagid) {
    if (empty($email) || empty($tagid)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // subscribe
    $data = array(
      'email' => $email,
      'tagid' => $tagid,
    );
    $response = $this->_http_request('/subscriber/untag', 'POST', $data);

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Tagging failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Resend an autoresponder for an email address. Requires to be logged in.
   *
   * @param mixed $email A valid email address
   * @param mixed $autoresponder An id of the autoresponder
   *
   * @return TRUE on success
   */
  public function resend($email, $autoresponder) {
    if (empty($email) || empty($autoresponder)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // resend/reset autoresponder
    $data = array(
      'email' => $email,
      'autoresponder' => $autoresponder,
    );
    $response = $this->_http_request('/subscriber/resend', 'POST', $data);

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Resend failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get all active subscribers. Requires to be logged in.
   *
   * @return An array of subscriber ids.
   */
  public function subscriber_index() {
    $response = $this->_http_request('/subscriber');

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscriber index failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get subscriber information. Requires to be logged in.
   *
   * @param mixed $subscriberid The subscriber id.
   *
   * @return An object representing the Klicktipp subscriber.
   */
  public function subscriber_get($subscriberid) {
    if (!$subscriberid) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // retrieve
    $response = $this->_http_request('/subscriber/' . urlencode($subscriberid));

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscriber get failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get a subscriber id by email. Requires to be logged in.
   *
   * @param mixed $email The email address of the subscriber.
   *
   * @return The id of the subscriber. Use subscriber_get to get subscriber details.
   */
  public function subscriber_search($email) {
    if (empty($email)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // search
    $data = array(
      'email' => $email,
    );
    $response = $this->_http_request('/subscriber/search', 'POST', $data);

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscriber search failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Get all active subscribers tagged with the given tag id. Requires to be logged in.
   *
   * @param mixed $tagid The id of the tag.
   *
   * @return An array with id -> subscription date of the tagged subscribers. Use subscriber_get to get subscriber details.
   */
  public function subscriber_tagged($tagid) {
    if (empty($tagid)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // search
    $data = array(
      'tagid' => $tagid,
    );
    $response = $this->_http_request('/subscriber/tagged', 'POST', $data);

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscriber search failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Updates a subscriber. Requires to be logged in.
   *
   * @param mixed $subscriberid The id of the subscriber to update.
   * @param mixed $fields (optional) The fields of the subscriber to update
   * @param mixed $newemail (optional) The new email of the subscriber to update
   *
   * @return TRUE on success
   */
  public function subscriber_update($subscriberid, $fields = array(), $newemail = '', $newsmsnumber = '') {
    if (empty($subscriberid)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // update
    $data = array();
    if (!empty($fields)) {
      $data['fields'] = $fields;
    }
    if (!empty($newemail)) {
      $data['newemail'] = $newemail;
    }
    if (!empty($newsmsnumber)) {
      $data['newsmsnumber'] = $newsmsnumber;
    }
    $response = $this->_http_request('/subscriber/' . urlencode($subscriberid), 'PUT', $data);

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Subscriber update failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Delete a subscribe. Requires to be logged in.
   *
   * @param mixed $subscriberid The id of the subscriber to update.
   *
   * @return TRUE on success.
   */
  public function subscriber_delete($subscriberid) {
    if (empty($subscriberid)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // delete
    $response = $this->_http_request('/subscriber/' . urlencode($subscriberid), 'DELETE');

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Subscriber deletion failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Subscribe an email. Requires an api key.
   *
   * @param mixed $apikey The api key (listbuildng configuration).
   * @param mixed $email The email address of the subscriber.
   * @param mixed $fields (optional) Additional fields of the subscriber.
   *
   * @return A redirection url as defined in the subscription process.
   */
  public function signin($apikey, $email, $fields = array(), $smsnumber = '') {
    if (empty($apikey) || (empty($email) && empty($smsnumber))) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // subscribe
    $data = array(
      'apikey' => $apikey,
      'email' => $email,
    );
    if (!empty($smsnumber)) {
      $data['smsnumber'] = $smsnumber;
    }
    if (!empty($fields)) {
      $data['fields'] = $fields;
    }
    $response = $this->_http_request('/subscriber/signin', 'POST', $data);

    if (empty($response->error)) {
      return !isset($response->data) ? NULL : $response->data;
    }
    else {
      $this->error = 'Subscription failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Untag an email. Requires an api key.
   *
   * @param mixed $apikey The api key (listbuildng configuration).
   * @param mixed $email The email address of the subscriber.
   *
   * @return TRUE on success
   */
  public function signout($apikey, $email) {
    if (empty($apikey) || empty($email)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // untag
    $data = array(
      'apikey' => $apikey,
      'email' => $email,
    );
    $response = $this->_http_request('/subscriber/signout', 'POST', $data);

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Untagging failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Unsubscribe an email. Requires an api key.
   *
   * @param mixed $apikey The api key (listbuildng configuration).
   * @param mixed $email The email address of the subscriber.
   *
   * @return TRUE on success
   */
  public function signoff($apikey, $email) {
    if (empty($apikey) || empty($email)) {
      $this->error = 'Illegal Arguments';
      return FALSE;
    }

    // unsubscribe
    $data = array(
      'apikey' => $apikey,
      'email' => $email,
    );
    $response = $this->_http_request('/subscriber/signoff', 'POST', $data);

    if (empty($response->error)) {
      return TRUE;
    }
    else {
      $this->error = 'Unsubscription failed: ' . $response->error;
      return FALSE;
    }
  }

  /**
   * Helper function.
   * Establishes the system connection to the website.
   */
  protected function _http_request($path, $method = 'GET', $data = NULL, $usesession = TRUE, $default_header = array()) {

    $result = new stdClass();

    // Make sure the socket opened properly.
    $fp = @fsockopen($this->port == 443 ? 'ssl://' . $this->host : $this->host, $this->port, $errno, $errstr, 20);
    if (!$fp) {
      // When a network error occurs, we use a negative number so it does not
      // clash with the HTTP status codes.
      $result->code = -$errno;
      $result->error = trim($errstr);
      return $result;
    }

    // Build HTTP request.
    $default_header['Host'] = 'Host' . ': ' . $this->host;
    $default_header['Referer'] = 'Referer' . ': //' . $_SERVER['SERVER_NAME'] . $_SERVER['PHP_SELF'];
    $default_header['Content-Type'] = 'Content-Type' . ': ' . 'application/x-www-form-urlencoded';

    // Set session cookie if applicable
    if ($usesession && !empty($this->sessionName)) {
      $default_header['Cookie'] = 'Cookie' . ': ' . $this->sessionName . '=' . $this->sessionId;
    }

    // Urlencode data.
    if (!empty($data)) {
      $data = http_build_query($data, '', '&');

      // Add content length if applicable.
      $content_length = strlen($data);
      if ($content_length > 0 || $method == 'POST' || $method == 'PUT') {
        $default_header['Content-Length'] = 'Content-Length: ' . $content_length;
      }
    }

    // get the response as an php object
    $default_header['Accept'] = 'Accept' . ': ' . 'application/vnd.php.serialized';

    // Write request
    $request = $method . ' ' . $this->path . $path . " HTTP/1.0\r\n";
    $request .= implode("\r\n", $default_header);
    $request .= "\r\n\r\n";
    $request .= empty($data) ? '' : $data;

    $result->request = $request;

    fwrite($fp, $request);

    // Fetch response.
    $response = '';
    while (!feof($fp) && $chunk = fread($fp, 1024)) {
      $response .= $chunk;
    }
    fclose($fp);

    // Parse response.
    list($split, $result->data) = explode("\r\n\r\n", $response, 2);
    $result->data = empty($result->data) ? NULL : unserialize($result->data);
    $split = preg_split("/\r\n|\n|\r/", $split);

    list($protocol, $code, $status_message) = explode(' ', trim(array_shift($split)), 3);
    $result->protocol = $protocol;
    $result->status_message = $status_message;

    $result->headers = array();

    // Parse headers.
    while ($line = trim(array_shift($split))) {
      list($header, $value) = explode(':', $line, 2);
      if (isset($result->headers[$header]) && $header == 'Set-Cookie') {
        // RFC 2109: the Set-Cookie response header comprises the token Set-
        // Cookie:, followed by a comma-separated list of one or more cookies.
        $result->headers[$header] .= ',' . trim($value);
      }
      else {
        $result->headers[$header] = trim($value);
      }
    }

    $responses = array(
      100 => 'Continue',
      101 => 'Switching Protocols',
      200 => 'OK',
      201 => 'Created',
      202 => 'Accepted',
      203 => 'Non-Authoritative Information',
      204 => 'No Content',
      205 => 'Reset Content',
      206 => 'Partial Content',
      300 => 'Multiple Choices',
      301 => 'Moved Permanently',
      302 => 'Found',
      303 => 'See Other',
      304 => 'Not Modified',
      305 => 'Use Proxy',
      307 => 'Temporary Redirect',
      400 => 'Bad Request',
      401 => 'Unauthorized',
      402 => 'Payment Required',
      403 => 'Forbidden',
      404 => 'Not Found',
      405 => 'Method Not Allowed',
      406 => 'Not Acceptable',
      407 => 'Proxy Authentication Required',
      408 => 'Request Time-out',
      409 => 'Conflict',
      410 => 'Gone',
      411 => 'Length Required',
      412 => 'Precondition Failed',
      413 => 'Request Entity Too Large',
      414 => 'Request-URI Too Large',
      415 => 'Unsupported Media Type',
      416 => 'Requested range not satisfiable',
      417 => 'Expectation Failed',
      500 => 'Internal Server Error',
      501 => 'Not Implemented',
      502 => 'Bad Gateway',
      503 => 'Service Unavailable',
      504 => 'Gateway Time-out',
      505 => 'HTTP Version not supported'
    );
    // RFC 2616 states that all unknown HTTP codes must be treated the same as the
    // base code in their class.
    if (!isset($responses[$code])) {
      $code = floor($code / 100) * 100;
    }

    switch ($code) {
      case 200: // OK
      case 304: // Not modified
        break;
      case 301: // Moved permanently
      case 302: // Moved temporarily
      case 307: // Moved temporarily
        $location = $result->headers['Location'];
        $result->redirect_code = $code;
        $result->redirect_url = $location;
        $result->error = 'Moved. Use ' . $location;
        break;
      default:
        $result->error = $status_message;
    }

    $result->code = $code;
    return $result;
  }
}

/*
KlicktippPartnerConnector

A class using http header authentication for api access.

Example: subscribe, update, unsubscribe, delete

require ("klicktipp.api.inc"); // this file

$developer_key = 'abc';
$customer_key = 'cde';

$connector = new KlicktippPartnerConnector($username, $developer_key, $customer_key);
$subscriber = $connector->subscribe('example@example.com');
$result = $connector->subscriber_update($subscriber->id, array('fieldFirstName' => 'John'));
$result = $connector->unsubscribe('example@example.com');
$result = $connector->subscriber_delete($subscriber->id);
*/

class WPF_KlicktippPartnerConnector extends WPF_KlicktippConnector {
  protected $ciphertext;
  protected $username;

  /**
   * Instantiates a KlicktippPartnerConnector
   * The service URL will be tested: use get_last_error for any errors detected.
   *
   * @param string $username of your Klick-Tipp account
   * @param string $developer_key Developer key from your Klick-Tipp account
   * @param string $customer_key Customer key
   * @param string $service (optional) Path to the REST server
   *
   * @return the connector object
   */
  public function __construct($username, $developer_key, $customer_key, $service = 'http://api.klick-tipp.com') {
    parent::__construct($service);

    // create hash with developer key
    $hmac = hash_hmac('sha256', $customer_key, pack('H*', $developer_key), TRUE);
    $this->ciphertext = base64_encode($hmac . $customer_key);
    $this->username = $username;
  }

  /**
   * login
   *
   * overrrides WPF_KlicktippConnector::login
   * there is no login for the partner api access
   */
  public function login($username, $password) {
    return TRUE;
  }

  /**
   * logout
   *
   * overrrides WPF_KlicktippConnector::logout
   * there is no logout for the partner api access
   */
  public function logout() {
    return TRUE;
  }

  /**
   * Helper function.
   * Establishes the system connection to the website.
   */
  protected function _http_request($path, $method = 'GET', $data = NULL, $usesession = TRUE, $default_header = array()) {

    $default_header['X-Un'] = 'X-Un' . ': ' . $this->username;
    $default_header['X-Ci'] = 'X-Ci' . ': ' . $this->ciphertext;

    return parent::_http_request($path, $method, $data, FALSE, $default_header);
  }
}
