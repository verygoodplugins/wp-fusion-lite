# WP Fusion Admin System Guide

> For coding standards and general workflow, see the root [CLAUDE.md](../../CLAUDE.md).
> For core class APIs (WPF_User, WPF_Access_Control), see [../CLAUDE.md](../CLAUDE.md).

## Directory Overview

```text
admin/
├── class-settings.php              # Settings management (123KB, central config)
├── class-batch.php                 # Background batch processing
├── class-admin-interfaces.php      # Post metaboxes, settings UI components
├── class-notices.php               # Admin notices
├── class-user-profile.php          # User profile WP Fusion meta box
├── class-updater.php               # License + auto-updates
├── class-upgrades.php              # Version upgrade handlers
├── class-staging-sites.php         # Staging site detection
├── class-tags-select-api.php       # AJAX tag selection API
├── class-lite-helper.php           # Free version upsells
├── admin-functions.php             # Admin helper functions
├── wordpress-fields.php            # WordPress core field mappings
├── batch/
│   ├── class-async-request.php     # Single async request
│   └── class-background-process.php # WP Background Processing library
├── logging/
│   ├── class-log-handler.php       # Log storage + retrieval
│   └── class-log-table-list.php    # Admin log viewer (WP_List_Table)
├── gutenberg/
│   └── class-gutenberg.php         # Block editor integration
└── options/
    └── class-options.php           # Settings page framework (renders fields)
```

## WPF_Settings (`class-settings.php`)

Central settings management. This is the largest file in the plugin (~123KB).

### Key Properties

```php
public $options = array();   // All plugin settings, loaded from DB.
public $batch;               // WPF_Batch instance.
```

### Settings Storage

For performance, large datasets are stored in separate `wp_options` rows:

| Data | Option Key | Accessed Via |
|------|-----------|-------------|
| Main settings | `wpf_options` | `wpf_get_option()` |
| Available tags | `wpf_available_tags` | `wpf_get_option( 'available_tags' )` |
| CRM fields | `wpf_crm_fields` | `wpf_get_option( 'crm_fields' )` |
| Contact fields mapping | `wpf_options` (nested) | `wpf_get_option( 'contact_fields' )` |

### Key Methods

```php
wp_fusion()->settings->get( $key, $default );           // Get single setting.
wp_fusion()->settings->set( $key, $value );             // Set single setting.
wp_fusion()->settings->set_multiple( $options );         // Set multiple settings.
wp_fusion()->settings->get_available_tags_flat( $include_read_only ); // Tags without categories.
wp_fusion()->settings->get_crm_fields_flat();            // CRM fields without categories.
```

### Settings Field Types

Used in `wpf_configure_settings` filter:

```php
$settings['field_key'] = array(
    'title'   => __( 'Field Title', 'wp-fusion' ),
    'desc'    => __( 'Description text.', 'wp-fusion' ),
    'type'    => 'checkbox',       // See types below.
    'section' => 'main',          // main, contact-fields, advanced, integrations, setup.
    'std'     => false,           // Default value.
);
```

| Type | Description |
|------|-------------|
| `text` | Text input |
| `checkbox` | Single checkbox |
| `select` | Dropdown select |
| `multi_select` | Multi-select dropdown |
| `assign_tags` | Tag picker (select2) |
| `contact_fields` | Field mapping table |
| `heading` | Section heading |
| `paragraph` | Informational text |
| `oauth_authorize` | OAuth connect button |
| `api_validate` | API connection test button |

### Settings Registration Pattern

CRM and integration classes add their settings via this filter:

```php
add_filter( 'wpf_configure_settings', array( $this, 'register_settings' ), 10, 2 );

public function register_settings( $settings, $options ) {

    $new_settings = array();

    $new_settings['my_header'] = array(
        'title'   => __( 'My Section', 'wp-fusion' ),
        'type'    => 'heading',
        'section' => 'integrations',
    );

    $new_settings['my_checkbox'] = array(
        'title'   => __( 'Enable Feature', 'wp-fusion' ),
        'type'    => 'checkbox',
        'section' => 'integrations',
    );

    // Insert after a specific existing setting.
    $settings = wp_fusion()->settings->insert_setting_after( 'existing_key', $settings, $new_settings );

    return $settings;
}
```

### AJAX Handlers

- `wpf_sync` — Resync tags and fields from CRM.
- `wpf_add_tag` — Create a new tag.
- `wpf_import_users` — Import CRM contacts as WP users.
- `wpf_edd_activate` / `wpf_edd_deactivate` — License management.
- `wpf_settings_export` / `wpf_settings_import` — Settings import/export.

## WPF_Batch (`class-batch.php`)

Background batch processing for large operations. Built on the WP Background Processing library (`batch/` subdirectory).

### Built-in Operations

| Operation | Description | Step Handler |
|-----------|-------------|-------------|
| `users_sync` | Resync contact IDs + tags for all users | `users_sync_step( $user_id )` |
| `users_register` | Export users without contact IDs to CRM | `users_register_step( $user_id )` |
| `users_register_tags` | Apply registration tags to all users | `users_register_tags_step( $user_id )` |
| `users_meta` | Push all user meta to CRM | `users_meta_step( $user_id )` |
| `pull_users_meta` | Pull user meta from CRM | `pull_users_meta_step( $user_id )` |
| `import_users` | Import CRM contacts as WP users | `import_users_step( $contact_id )` |

### Using Batch Processing

```php
// Queue a single async action.
wp_fusion()->batch->quick_add( 'action_name', $args, $start = true );

// Initialize a batch with an array of items.
wp_fusion()->batch->batch_init( $hook, $args );
```

### Adding Custom Batch Operations

Integrations can add their own batch operations:

```php
// Register the operation.
add_filter( 'wpf_export_options', array( $this, 'export_options' ) );

public function export_options( $options ) {

    $options['my_operation'] = array(
        'label'   => __( 'My Operation', 'wp-fusion' ),
        'title'   => __( 'Running My Operation', 'wp-fusion' ),
        'tooltip' => __( 'Description of what this does.', 'wp-fusion' ),
    );

    return $options;
}

// Initialize items to process.
add_filter( 'wpf_batch_my_operation_init', array( $this, 'batch_init' ) );

public function batch_init( $args ) {
    // Return array of items to process (user IDs, post IDs, etc.).
    return get_users( array( 'fields' => 'ID' ) );
}

// Process each item.
add_action( 'wpf_batch_my_operation', array( $this, 'batch_step' ) );

public function batch_step( $item_id ) {
    // Process a single item.
}
```

## Logging System (`logging/`)

### Writing Logs

```php
wpf_log( $level, $user_id, $message, $context );

// Levels (in order of severity):
// 'error'   — API failures, critical issues.
// 'warning' — Recoverable errors, retries that failed.
// 'notice'  — Informational, auto-recovery events.
// 'info'    — Standard operations (contact created, tags applied).
// 'http'    — Raw HTTP request/response data.

// Context array:
array(
    'source'     => 'hubspot',       // CRM slug or integration name.
    'meta_array' => $update_data,    // Data being synced (displayed as table).
    'tag_array'  => $tags,           // Tags being applied/removed.
)
```

### Log Storage

Logs are stored in a custom DB table: `{prefix}wpf_logging` (created by `WPF_Log_Handler`).

### Viewing Logs

Settings > Advanced > Logs — uses `WPF_Log_Table_List` (extends `WP_List_Table`).

## WPF_Admin_Interfaces (`class-admin-interfaces.php`)

Renders WP Fusion meta boxes on posts, pages, and custom post types.

### Post Meta Box Settings

Stored in `wpf-settings` post meta (see `../CLAUDE.md` for full structure):

```php
array(
    'lock_content'   => true,
    'allow_tags'     => array( 123 ),
    'allow_tags_all' => array(),
    'allow_tags_not' => array(),
    'apply_tags'     => array( 456 ),
    'redirect'       => 42,
    'filter_queries' => true,
    'hide_from_menus' => true,
)
```

### User Profile Meta Box

`class-user-profile.php` adds a WP Fusion section to user profile pages showing:
- CRM contact ID with edit link
- User's current tags
- Resync button

## Performance Notes

- **Large tag lists** (500+): Use AJAX-powered select2 dropdowns via `class-tags-select-api.php`.
- **Batch operations**: Default processes ~10 items per batch cycle, with configurable sleep time.
- **Settings page**: Main options loaded once into `$this->options` property.
- **Separate storage**: `available_tags` and `crm_fields` stored in their own `wp_options` rows to avoid loading them on every page.
- **Transient caching**: Various API responses cached with WordPress transients.
