# WP Fusion Core Classes Reference

> For coding standards and general workflow, see the root [CLAUDE.md](../CLAUDE.md).
> For CRM-specific development, see [crms/CLAUDE.md](crms/CLAUDE.md).
> For plugin integrations, see [integrations/CLAUDE.md](integrations/CLAUDE.md).
> For admin/settings/batch, see [admin/CLAUDE.md](admin/CLAUDE.md).

## Directory Overview

```text
includes/
├── class-user.php                  # User ↔ CRM sync, tags, meta
├── class-access-control.php        # Tag-based content restriction
├── class-api.php                   # Public REST API
├── class-ajax.php                  # AJAX handlers
├── class-auto-login.php            # Auto-login sessions via URL
├── class-lead-source-tracking.php  # UTM/referral tracking
├── class-shortcodes.php            # [wpf] shortcodes
├── class-iso-regions.php           # Country/region data
├── functions.php                   # Global helper functions
├── crms/                           # CRM integrations → crms/CLAUDE.md
├── integrations/                   # Plugin integrations → integrations/CLAUDE.md
└── admin/                          # Admin system → admin/CLAUDE.md
```

## Global Access Pattern

```php
wp_fusion()                    // WP_Fusion singleton.
wp_fusion()->user              // WPF_User instance.
wp_fusion()->access            // WPF_Access_Control instance.
wp_fusion()->crm               // Active CRM instance (via WPF_CRM_Base).
wp_fusion()->crm_base          // WPF_CRM_Base instance.
wp_fusion()->settings          // WPF_Settings instance.
wp_fusion()->batch             // WPF_Batch instance.
wp_fusion()->logger            // WPF_Log_Handler instance.
wp_fusion()->auto_login        // WPF_Auto_Login instance.
wp_fusion()->lead_source_tracking  // WPF_Lead_Source_Tracking instance.
wp_fusion()->integrations      // stdClass holding all integration instances.
wp_fusion()->integrations->woocommerce  // Example: access a specific integration.
```

## WPF_User (`class-user.php`)

Handles WordPress user ↔ CRM contact synchronization.

### User Meta Keys (constants, set based on active CRM)

```php
WPF_CONTACT_ID_META_KEY  // e.g. 'hubspot_contact_id'
WPF_TAGS_META_KEY        // e.g. 'hubspot_tags'
```

### Key Methods

```php
// Push/pull user data.
wp_fusion()->user->push_user_meta( $user_id, $update_data );  // WP → CRM.
wp_fusion()->user->pull_user_meta( $user_id );                 // CRM → WP.
wp_fusion()->user->get_user_meta( $user_id );                  // Collect all syncable meta.

// Tag management.
wp_fusion()->user->apply_tags( $tags, $user_id );    // Apply tags in CRM + local.
wp_fusion()->user->remove_tags( $tags, $user_id );   // Remove tags in CRM + local.
wp_fusion()->user->has_tag( $tag, $user_id );         // Check if user has tag (local).
wp_fusion()->user->get_tags( $user_id, $force );      // Get user's tags (local, or force API).
wp_fusion()->user->set_tags( $tags, $user_id );        // Save tags to user meta only (no API).

// Contact ID.
wp_fusion()->user->get_contact_id( $user_id, $force_update );  // Get or lookup CRM contact ID.
wp_fusion()->user->has_contact_id( $user_id );                  // Bool check.

// Lookup.
wp_fusion()->user->get_user_id( $contact_id );        // WP user ID from CRM contact ID.
wp_fusion()->user->get_tag_id( $tag_name );            // Tag ID from name.
wp_fusion()->user->get_tag_label( $tag_id );           // Tag name from ID.
wp_fusion()->user->get_users_with_tag( $tag );         // All WP user IDs with a tag.

// Registration and import.
wp_fusion()->user->user_register( $user_id, $post_data, $force );  // Sync new user to CRM.
wp_fusion()->user->import_user( $contact_id, $send_notification, $role );  // Import CRM contact as WP user.
```

### Key Hooks

```php
// Filters (modify data before sync).
add_filter( 'wpf_user_register', function( $post_data, $user_id ) { return $post_data; }, 10, 2 );
add_filter( 'wpf_user_update', function( $update_data, $user_id ) { return $update_data; }, 10, 2 );
add_filter( 'wpf_get_user_meta', function( $user_meta, $user_id ) { return $user_meta; }, 10, 2 );

// Actions (after sync).
add_action( 'wpf_user_created', function( $user_id, $contact_id, $post_data ) {}, 10, 3 );
add_action( 'wpf_user_updated', function( $user_id, $update_data ) {}, 10, 2 );
add_action( 'wpf_tags_applied', function( $user_id, $tags ) {}, 10, 2 );
add_action( 'wpf_tags_removed', function( $user_id, $tags ) {}, 10, 2 );
add_action( 'wpf_tags_modified', function( $user_id, $tags ) {}, 10, 2 ); // Fires for both apply + remove.
add_action( 'wpf_user_imported', function( $user_id, $contact_id ) {}, 10, 2 );
```

## WPF_Access_Control (`class-access-control.php`)

Tag-based content restriction for posts, pages, archives, terms, widgets, and menus.

### Post Access Meta

Stored in `wpf-settings` post meta:

```php
$settings = get_post_meta( $post_id, 'wpf-settings', true );

// Structure:
array(
    'lock_content'    => true,              // Enable restriction.
    'allow_tags'      => array( 123, 456 ), // Required tags (OR — any one grants access).
    'allow_tags_all'  => array( 789 ),      // Required tags (AND — all required).
    'allow_tags_not'  => array( 111 ),      // Deny if user has these tags.
    'redirect'        => 42,                // Post ID to redirect to (or URL).
    'redirect_url'    => '',                // Deprecated, use 'redirect'.
    'check_tags'      => true,              // Refresh tags from CRM before denying.
)
```

### Key Methods

```php
wp_fusion()->access->user_can_access( $post_id, $user_id );      // Bool: can user see this post?
wp_fusion()->access->user_can_access_archive( $term_id, $user_id ); // Archive access.
wp_fusion()->access->get_post_access_meta( $post_id );            // Get wpf-settings for post.
wp_fusion()->access->get_redirect( $post_id );                    // Get redirect URL/post ID.
wp_fusion()->access->get_restricted_posts( $post_types, $in );    // IDs of all restricted posts.
wp_fusion()->access->get_taxonomy_rules();                        // All taxonomy restriction rules.
```

### Key Hooks

```php
// Override access decisions.
add_filter( 'wpf_user_can_access', function( $can_access, $post_id, $user_id ) {
    return $can_access;
}, 10, 3 );

// Modify post access settings.
add_filter( 'wpf_post_access_meta', function( $settings, $post_id ) {
    return $settings;
}, 10, 2 );

// Override redirect.
add_filter( 'wpf_redirect_post_id', function( $post_id ) {
    return $post_id;
} );
```

### Query Filtering

When `filter_queries` is enabled on a post, it's automatically hidden from:
- Archive pages and loops
- Search results
- `WP_Query` results
- Navigation menus (if `hide_from_menus` is set)
- Widgets (via `widget_display_callback`)

## Global Helper Functions (`functions.php`)

```php
// Settings.
wpf_get_option( $key, $default );          // Get WP Fusion setting.
wpf_update_option( $key, $value );         // Update WP Fusion setting.

// User/contact.
wpf_get_contact_id( $user_id, $force );    // CRM contact ID from WP user ID.
wpf_get_tags( $user_id, $force );          // User's CRM tags.
wpf_has_tag( $tags, $user_id );            // Check if user has tag(s).
wpf_get_tag_id( $tag_name );              // Tag ID from label.
wpf_get_tag_label( $tag_id );             // Tag label from ID.
wpf_get_user_id( $contact_id );           // WP user ID from CRM contact ID.
wpf_get_users_with_tag( $tag );           // All WP user IDs with a tag.

// Access.
wpf_user_can_access( $post_id, $user_id ); // Can user access post?
wpf_is_user_logged_in();                    // Includes auto-login sessions.
wpf_admin_override();                       // Is admin excluded from restrictions?
wpf_get_current_user_id();                  // Supports auto-login sessions.
wpf_get_current_user_email();               // Supports auto-login + guest cookies.

// Field mapping.
wpf_get_crm_field( $meta_key, $default );  // CRM field ID for WP meta key.
wpf_is_field_active( $meta_key );          // Is field enabled for sync?
wpf_get_field_type( $meta_key, $default ); // Field type (text, date, etc.).

// Logging.
wpf_log( $level, $user_id, $message, $context );
// Levels: 'error', 'warning', 'notice', 'info', 'http'
// Context: array( 'source' => 'crm-slug', 'meta_array' => $data )

// Utilities.
wpf_clean( $var );                         // Recursive sanitization.
wpf_print_r( $expression, $return );       // Safe print_r.
wpf_is_staging_mode();                     // Is staging mode active?
doing_wpf_auto_login();                    // In auto-login session?
doing_wpf_webhook();                       // Handling a webhook?
wpf_disable_api_queue();                   // Disable queue for this request.
wpf_get_iso8601_date( $timestamp );        // ISO 8601 date for API calls.
wpf_phone_number_to_e164( $phone, $country ); // E.164 phone format.
wpf_get_name_from_full_name( $full_name ); // Split into first/last.
wpf_clean_tags( $tags );                   // Sanitize tag array.
```

## WPF_Auto_Login (`class-auto-login.php`)

Allows users to be "logged in" via a URL parameter without actual WordPress authentication.

**URL format**: `https://example.com/?cid={contact_id}`

- Creates a temporary session that mimics a logged-in user.
- `wpf_get_current_user_id()` and `wpf_is_user_logged_in()` return values as if the user is logged in.
- Tags and access control work normally during auto-login.
- Filter: `wpf_auto_login_contact_id` — modify the contact ID before login.
- Action: `wpf_started_auto_login` — fires when auto-login session starts.

## WPF_Lead_Source_Tracking (`class-lead-source-tracking.php`)

Tracks UTM parameters and lead source data, syncs to CRM on registration.

**Tracked fields** (stored in cookies, synced as user meta):
- `leadsource` — first HTTP referrer
- `utm_campaign`, `utm_medium`, `utm_source`, `utm_term`, `utm_content`
- `gclid` — Google Ads click ID
- `fbclid` — Facebook click ID
- `original_ref` — original referrer URL

**Sync function**: `wpf_sync_lead_source_data( $user_id, $contact_id )`

## Shortcodes (`class-shortcodes.php`)

```text
[wpf tag="123" method="any"]Content[/wpf]     Show if user has any of the tags.
[wpf tag="123,456"]Content[/wpf]              Show if user has ALL tags (default).
[wpf not="123"]Content[/wpf]                  Show if user does NOT have tag.
[wpf logged_out]Content[/wpf]                 Show to logged-out users.
[wpf_loggedin]Content[/wpf_loggedin]          Show if logged in.
[wpf_loggedout]Content[/wpf_loggedout]        Show if logged out.
[wpf_user_can_access id="42"]Content[/wpf_user_can_access]  Show if user can access post 42.
[wpf_update_tags add="123" remove="456"]      Apply/remove tags when page loads.
[wpf_update_meta key="field" value="val"]     Update user meta when page loads.
[user_meta field="first_name"]                Display user meta value.
[user_meta_if field="role" value="subscriber"]Content[/user_meta_if]  Conditional on meta.
```

## Data Flow

```text
WordPress Event (registration, profile update, plugin action)
    ↓
Integration Class (or core hook)
    ↓ wp_fusion()->user->push_user_meta()
WPF_User — collects meta, applies filters
    ↓ wp_fusion()->crm->update_contact()
WPF_CRM_Base::__call() — maps fields, queues or executes
    ↓
CRM Class — wp_remote_request() to API
    ↓
API Response
    ↓ handle_http_response() — error handling
    ↓ Automatic recovery if needed
    ↓ wpf_api_did_{method} action
Local Cache / User Meta updated
```
