# WP Fusion CRM Development Guide

> For coding standards, version management, and general workflow, see the root [CLAUDE.md](../../CLAUDE.md).

## Quick Reference

- **Base class**: `class-base.php` in this directory (`WPF_CRM_Base`, ~1500 lines)
- All CRM classes are plain classes (no `extends`) — the base class wraps them via `__call()` magic method
- **User-agent**: Always `'WP Fusion; ' . home_url()`
- **Admin class required**: `{crm}/admin/class-admin.php`
- **Registration**: Add class to `get_crms()` in `wp-fusion.php`

## Directory Structure

```
{crm-name}/
├── class-{crm-name}.php        # Main CRM class
└── admin/
    ├── class-admin.php          # Settings UI + connection test
    └── {crm-name}-fields.php    # (optional) Default field mappings
```

Some CRMs also have `includes/` for third-party SDKs.

## Required Class Properties

```php
public $slug     = 'crm-slug';                          // Unique identifier.
public $name     = 'CRM Name';                          // Display name.
public $supports = array( 'add_tags', 'add_fields' );   // Feature flags.
public $params;                                          // HTTP request params (set in get_params()).
public $edit_url = '';                                   // sprintf() pattern for contact edit URL, use %d for ID.
```

### `$supports` Flags

| Flag             | Meaning |
|------------------|---------|
| `add_tags`       | Can create new tags by name (dynamic tagging) |
| `add_tags_api`   | Can create tags via a dedicated API endpoint |
| `add_fields`     | Can create custom fields via API |
| `lists`          | Supports lists/audiences/segments |
| `events`         | Supports event tracking |
| `events_multi`   | Events support multiple data fields |
| `auto_oauth`     | Uses WP Fusion's automated OAuth flow |
| `same_site`      | CRM is on same server (e.g. FluentCRM) — disables API queue |
| `web_id`         | Contact edit URL uses a web ID instead of contact ID |
| `combined_updates` | Tags + fields can be sent in a single API call |

## Required Methods

### Constructor

```php
public function __construct() {

    // OAuth credentials (if applicable).
    $this->client_id     = 'xxx';
    $this->client_secret = 'xxx';

    // Load admin class.
    if ( is_admin() ) {
        require_once __DIR__ . '/admin/class-admin.php';
        new WPF_{CRM}_Admin( $this->slug, $this->name, $this );
    }

    // Error handling at priority 50 (base class transient handler is at 80).
    add_filter( 'http_response', array( $this, 'handle_http_response' ), 50, 3 );
}
```

### `init()`

Runs on `init` hook at priority 1. Set up CRM-specific hooks here.

```php
public function init() {
    add_filter( 'wpf_format_field_value', array( $this, 'format_field_value' ), 10, 4 );
    add_filter( 'wpf_crm_post_data', array( $this, 'format_post_data' ) );
    add_filter( 'wpf_batch_sleep_time', array( $this, 'set_sleep_time' ) );

    // Set edit URL.
    $this->edit_url = 'https://app.crm.com/contacts/%d/';
}
```

### `get_params( $access_token = null )`

Returns HTTP request params array. Called before every API request.

```php
public function get_params( $access_token = null ) {

    if ( empty( $access_token ) ) {
        $access_token = wpf_get_option( '{crm}_token' );
    }

    $this->params = array(
        'user-agent' => 'WP Fusion; ' . home_url(),
        'timeout'    => 15,
        'headers'    => array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ),
    );

    return $this->params;
}
```

### `handle_http_response( $response, $args, $url )`

Handles HTTP status code errors. Transient network errors (cURL timeouts, SSL) are handled **automatically** by the base class at priority 80 — this method only handles HTTP response codes.

```php
public function handle_http_response( $response, $args, $url ) {

    if ( strpos( $url, $this->url ) !== false && 'WP Fusion; ' . home_url() === $args['user-agent'] ) {

        if ( is_wp_error( $response ) ) {
            return $response; // cURL error that wasn't recoverable.
        }

        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $response_code && 201 !== $response_code ) {

            $body = json_decode( wp_remote_retrieve_body( $response ) );

            // Use special WP_Error codes to trigger auto-recovery:
            // 'not_found' — base class looks up contact by email and retries.
            // 'duplicate' — base class updates existing contact instead of creating.

            $code = 'error';

            if ( /* contact not found condition */ ) {
                $code = 'not_found';
            } elseif ( /* duplicate contact condition */ ) {
                $code = 'duplicate';
            }

            $message = ! empty( $body->message ) ? $body->message : 'Unknown API error.';
            return new WP_Error( $code, $message );
        }
    }

    return $response;
}
```

### `connect( $access_token = null, $test = false )`

Test API connection. Return `true` on success, `WP_Error` on failure.

### `sync()`

Runs full resync. Should call `$this->sync_tags()`, `$this->sync_crm_fields()`, and optionally `$this->sync_lists()`, then `do_action( 'wpf_sync' )`.

### `sync_tags()`

Fetch all tags/lists from CRM. Store via `wp_fusion()->settings->set( 'available_tags', $tags )`.

Format: `array( tag_id => tag_name )` or `array( tag_id => array( 'label' => name, 'category' => group ) )`.

### `sync_crm_fields()`

Fetch all CRM fields. Store via `wp_fusion()->settings->set( 'crm_fields', $fields )`.

Format: `array( 'Standard Fields' => array( id => label ), 'Custom Fields' => array( id => label ) )`.

### `get_contact_id( $email_address )`

Look up contact by email. Return contact ID string, or `false` if not found, or `WP_Error`.

### `get_tags( $contact_id )`

Get tags for a contact. Return array of tag IDs.

### `apply_tags( $tags, $contact_id )`

Apply tag IDs to contact. Return `true` or `WP_Error`.

### `remove_tags( $tags, $contact_id )`

Remove tag IDs from contact. Return `true` or `WP_Error`.

### `add_contact( $data, $map_meta_fields = false )`

Create a new contact. `$data` is already mapped to CRM field names by the base class (when `$map_meta_fields` is false). Return the new contact ID string, or `WP_Error`.

### `update_contact( $contact_id, $data, $map_meta_fields = false )`

Update an existing contact. `$data` is already mapped. Return `true` or `WP_Error`.

### `load_contact( $contact_id )`

Load all contact data. Return associative array keyed by **WordPress meta keys** (not CRM field IDs). Use `wpf_get_option( 'contact_fields' )` to map CRM fields back to WP keys.

### `load_contacts( $tag )`

Load all contact IDs that have a specific tag. Return array of contact ID strings.

## Automatic Error Recovery (No Code Needed)

The base class handles these automatically — **do not implement recovery for these in your CRM class**:

| Error Type | How It Works | Your Responsibility |
|------------|-------------|---------------------|
| **Transient network errors** (cURL 28, 52, 56, 7, 35) | Base retries once after 1s at priority 80 | None — automatic |
| **`not_found` errors** | Base looks up contact by email, retries with new ID | Return `WP_Error` with code `'not_found'` |
| **`duplicate` errors** | Base updates existing contact instead of creating | Return `WP_Error` with code `'duplicate'` from `add_contact` |

## API Queue System

The base class buffers `apply_tags()`, `remove_tags()`, and `update_contact()` calls and executes them on the `shutdown` hook. This reduces API calls when multiple operations happen in a single request.

- Prevents duplicate tag operations (add + remove same tag = no-op).
- Merges multiple `update_contact` calls into one.
- Disabled when: `WPF_DISABLE_QUEUE` constant is true, CRM supports `same_site`, or during cron.
- CRMs with `combined_updates` support get tags + data in a single `combined_update()` call.

## Field Value Formatting

The base class formats field values at priority 5 on `wpf_format_field_value`. CRM-specific formatting runs at priority 10:

```php
public function format_field_value( $value, $field_type, $field, $update_data = array() ) {

    if ( 'date' === $field_type && ! empty( $value ) ) {
        return gmdate( 'Y-m-d\TH:i:s\Z', intval( $value ) ); // CRM-specific date format.
    }

    if ( is_array( $value ) ) {
        return implode( ';', array_filter( $value ) ); // CRM-specific multiselect separator.
    }

    return $value;
}
```

## Admin Class Pattern

File: `{crm}/admin/class-admin.php`

```php
class WPF_{CRM}_Admin {

    private $slug;
    private $name;
    private $crm;

    public function __construct( $slug, $name, $crm ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->crm  = $crm;

        add_filter( 'wpf_configure_settings', array( $this, 'register_connection_settings' ), 15, 2 );
        add_action( 'show_field_' . $this->slug . '_header_begin', array( $this, 'show_field_header_begin' ), 10, 2 );
        add_action( 'wp_ajax_wpf_test_connection_' . $this->slug, array( $this, 'test_connection' ) );

        if ( wpf_get_option( 'crm' ) === $this->slug ) {
            $this->init();
        }
    }

    public function init() {
        add_filter( 'wpf_initialize_options_contact_fields', array( $this, 'add_default_fields' ), 10 );
        add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );
    }

    // register_connection_settings() — API key fields or OAuth button.
    // add_default_fields() — Load {crm}-fields.php for default field mappings.
    // test_connection() — AJAX handler, calls $this->crm->connect( $credentials, true ).
    // show_field_header_begin() — Output CRM config section wrapper.
}
```

## Key Hooks

### Fired by Base Class (available for CRM methods)

| Hook | When | Args |
|------|------|------|
| `wpf_api_{method}_args` | Before API call | `$args` |
| `wpf_api_{method}` | Short-circuit API call | `null, $args` |
| `wpf_api_did_{method}` | After successful call | `$args, $contact_id, $result` |
| `wpf_api_error` | On any API error | `$method, $args, $contact_id, $result` |
| `wpf_api_error_{method}` | On specific method error | `$args, $contact_id, $result` |
| `wpf_api_{method}_result` | Filter the result | `$result, $args` |
| `wpf_format_field_value` | Format field values | `$value, $type, $crm_field, $update_data` |
| `wpf_map_meta_fields` | After field mapping | `$update_data, $user_meta` |

### Admin Hooks

| Hook | When | Args |
|------|------|------|
| `wpf_configure_settings` | Register settings fields | `$settings, $options` |
| `wpf_configure_sections` | Register settings sections | `$sections, $options` |
| `wpf_initialize_options_contact_fields` | Set default field mappings | `$options` |

## OAuth Token Refresh Pattern

For OAuth CRMs, handle token expiry in `handle_http_response()`:

```php
if ( 401 === $response_code ) {
    $access_token = $this->refresh_token();

    if ( ! is_wp_error( $access_token ) ) {
        $args['headers']['Authorization'] = 'Bearer ' . $access_token;
        return wp_remote_request( $url, $args );
    }
}
```

## Pagination Pattern

Most CRM APIs use offset/limit pagination:

```php
$offset   = 0;
$continue = true;

while ( $continue ) {
    $response = wp_remote_get( $url . '?limit=100&offset=' . $offset, $this->params );
    $body     = json_decode( wp_remote_retrieve_body( $response ) );

    // Process results...

    if ( count( $body->results ) < 100 ) {
        $continue = false;
    }

    $offset += 100;
}
```

## Reference CRMs

| Pattern | CRM | Notes |
|---------|-----|-------|
| Standard REST + OAuth | `hubspot/` | OAuth flow, events, lists as tags |
| Large/complex | `activecampaign/` | Many features, includes/ subdirectory |
| API versioning | `nationbuilder/` | v1/v2 API version switching |
| Simple REST + API key | `bento/` | Minimal, good starting template |
| Same-site (no API) | `fluentcrm/` | Direct PHP, `same_site` support flag |

## Testing Checklist

1. Settings > Setup — test connection via admin AJAX
2. Settings > Setup — run Resync (verifies `sync_tags()` + `sync_crm_fields()`)
3. Create a WordPress user — verify `add_contact()` creates CRM contact
4. Update user profile — verify `update_contact()` syncs fields
5. Apply tags via WP Fusion > Users — verify `apply_tags()`
6. Remove tags — verify `remove_tags()`
7. Settings > Advanced > Logs — verify logs are clean
8. Test with invalid contact ID — should trigger `not_found` auto-recovery
9. Test creating duplicate contact — should trigger `duplicate` auto-recovery
