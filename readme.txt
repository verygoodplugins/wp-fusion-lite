=== WP Fusion Lite ===
Contributors: verygoodplugins
Tags: infusionsoft, activecampaign, ontraport, convertkit, salesforce, mailchimp, drip, crm, marketing automation, user meta, sync, wpfusion, wp-fusion
Requires at least: 4.0
Requires PHP: 5.6
Tested up to: 5.3
Stable tag: 3.27

WP Fusion integrates your website with your CRM or marketing automation system.

== Description ==

WP Fusion Lite allows you to sync user registrations on your website to leading CRMs and marketing automation systems, keep user profiles in sync with CRM contacts, and protect site content based on CRM tags.

= Features =

* Automaticaly create new contacts in your CRM when users register in WordPress
* Restrict access to site content using tags in your CRM
* Synchronize any WordPress user data with custom fields in your CRM
* Apply tags when users register
* Import contacts from your CRM as new WordPress users
* Export site users to your CRM as contacts

= Lite Version =

This is a free version of [WP Fusion](https://wpfusion.com/?utm_campaign=free-plugin&utm_source=wp-org). It includes support for WordPress core and synchronizing users with contact records, but does not have plugin-specific integrations or advanced tagging features.

For integration with WooCommerce, LearnDash, Gravity Forms, Elementor and over 60 other popular WordPress plugins, check out [one of our paid licenses](https://wpfusion.com/pricing/?utm_campaign=free-plugin&utm_source=wp-org).

= Supported CRMs =

* AWeber
* ActiveCampaign
* AgileCRM
* Autopilot
* BirdSend
* Capsule
* ConvertKit
* Copper
* Customerly
* Drift
* Drip
* Flexie
* GetResponse
* Gist
* Groundhogg
* HubSpot
* Infusionsoft
* Intercom
* Kartra
* Klaviyo
* MailChimp
* MailerLite
* Mailjet
* MailPoet
* Maropost
* Mautic
* NationBuilder
* Ontraport
* Platform.ly
* Salesflare
* Salesforce
* SendinBlue
* Sendlane
* Tubular
* UserEngage
* Zoho

== Screenshots ==

1. Sync any WordPress user fields with contact records in your CRM
2. View and manage contact tags within WordPress
3. Restrict access to content based on a contact's tags
4. Use the Gutenberg block to show and hide content within a page based on a contact's tags

== Installation ==

Upload and activate the plugin, then go to Settings >> WP Fusion. Select your desired CRM, enter your API credentials and click "Test Connection" to verify the connection. See our [Getting Started Guide](https://wpfusion.com/documentation/#getting-started-guide) for more information on setting up your application.

== Frequently Asked Questions ==

See our [FAQ](https://wpfusion.com/documentation/).

== Changelog ==

= 3.27 - 8/24/2019 =

##### New CRMs

* BirdSend
* Klaviyo

##### New Features

* Added Site Lockout feature
* Added Company, GDPR Consent, and Agreed to Terms fields for sync with Groundhogg
* Added signup_type field to NationBuilder
* Added Australia and India data server support to Zoho integration
* Added "capabilties" format for syncing capability fields
* Added "ucwords" formatting option to user_meta shortcode
* Added option to hide WPF meta boxes from non admins

##### Improvements

* Style compatibility updates for WP 5.3
* Improved Mautic site tracking
* Improved handling of merged contacts with Mautic
* Improved site tracking with HubSpot for guests
* Updated ActiveCampaign site tracking scripts
* Updated Intercom integration to use API v1.4
* Enabled Import Users tab for Intercom
* Improved support for Country field with Groundhogg
* All site tracking scripts will now recognize auto login sessions
* Disabled comments during auto login sessions
* wpf_tags_applied and wpf_tags_removed actions will now run when tags are loaded from the CRM
* Enabled welcome email in MailPoet when a contact is subscribed to a list


##### Bug Fixes

* Sendinblue bugfixes
* Copper bugfixes
* Fixed Gist API URL
* Fixed special characters getting escaped on admin profile updates
* Fixed NationBuilder not loading more than 100 available tags
* Fixed fatal error when initializing GetResponse connection
* Fixes for HubSpot accounts with over 250 lists
* Fixed Mailchimp contact ID getting disconnected after email address change
* Fixed NationBuilder Primary address fields not syncing
* Fixed Mailchimp compatibility with other Mailchimp plugins
* Fixed tags not being removed with Ontraport
* Fixed content protection on blog index page
* Fixed "Create tags from value" option for profile updates
* Fixed initial connection to Groundhogg on Groundhogg < 2.0
* Performance improvements and bugfixes for background worker


= 3.25 - 8/24/2019 =

##### New CRMs

* GetResponse
* MailPoet

##### New Features

* Added option to return people to originally requested content after login
* Added ability to create new user meta fields from the Contact Fields list
* Added dynamic tagging based on field values (for supported CRMs)
* Added ability to modify field data formats via the Contact Fields list
* Added option to remove tags when a page is viewed
* Added option to completely hide a taxonomy term based on tags
* Added option to enable HubSpot site tracking scripts
* Added auto-login by email address for MailerLite
* Added "Advanced" mode for Filter Queries setting
* MailerLite will now re-subscribe subscribers when they submit a form
* Added additional monitoring tools for background process worker
* Groundhogg 2.0 compatibility
* Added social media fields to Kartra
* Added date_joined field for Kartra
* Added new default profile fields for Drip
* Added wpf_salesforce_auth_url filter for Salesforce
* Added multiselect support for HubSpot
* Added unsubscribe notifications for ConvertKit
* Added "wpf_salesforce_auth_url" filter for overriding Salesforce authorization URL
* Added details about configured tags to protected content in post list table
* Added Email Changed event for Drip
* Added Is X? fields for NationBuilder
* Added option to disable "Preview With Tag" in admin bar
* Added active lists to list dropdowns with HubSpot
* Added IP address when adding new contacts with Mautic
* Added Organisation field for syncing to Capsule
* Auto login with Mailchimp now works with email address
* Added Portuguese translation (thanks @João Alexandre)

##### Improvements

* Improved OAuth access token refresh process with Salesforce
* Access control meta box now requires the manage_options capability
* Drip site tracking will now auto-identify logged in users
* Groundhogg will now load tags and meta immediately instead of requiring sync
* Improved handling for State fields with Ontraport
* Better handling for missing last names in Salesforce
* Slowed down HubSpot batch operations to get around API limits
* Mautic performance improvements
* Preview With Tag now bypasses Exclude Admins setting
* Compatibility updates for custom field formatting with Mailerlite

##### Bug Fixes

* Fixed typo in NationBuilder fields dropdown
* Fixed email address changes with Infusionsoft causing opt-outs
* Fixed notifications going out when using the built in import tool
* Bugfixes for Preview with Tag feature
* Fixed Infusionsoft API errors with addWithDupCheck method
* Fixed ConvertKit contact ID lookup failing
* Fixed creating a contact in Zoho without a last name
* Fixed slashes getting added to tags with apostrophes in Mautic
* Fixed redirect causing multiple API calls with contact ID lookup in Mautic
* Fixed MailChimp looking up contacts from other lists
* Fixed empty date type fields sending 1/1/1970 dates
* Copper bugfixes
* Capsule bugfixes
* Platform.ly bugfixes


= 3.24 - 4/25/2019 =

##### New CRMs

* Sendlane
* Mailjet

##### New Features

* Added option to return people to originally requested content after login
* Added admin users column showing user tags
* Added AgileCRM site tracking scripts
* Added Organization Name field for ActiveCampaign
* Added merge settings option to bulk edit
* Added setting to remove "Additional Fields" section from settings
* Added date-format parameter to user_meta shortcode
* Added "Required tags (all)" option to post restriction meta box
* Added option for login meta sync
* Added additional status triggers for Mailerlite webhooks
* Added option to embed Mautic site tracking scripts
* Added Mautic mtc_id cookie tracking for known contacts
* Improved Ontraport site tracking script integration
* Improved HubSpot error logging

##### Bug Fixes

* Platform.ly bugfixes
* Mailerlite bugfixes
* ConvertKit fixes for unconfirmed subscribers
* Fix for email addresses with + sign in MailChimp
* Fix for contact ID lookup with HubSpot
* Updated AWeber subscriber ID lookup to only use selected list
* Better AWeber exception handling
* Fix for changing email addresses with Drip
* Limit logging table to 10,000 rows
* Fixes for wpf_user_can_access filter
* Fix for background worker when PHP's memory_limit is set to -1
* Comments are now properly hidden when a post is restricted and no redirects are specified
* Set 1 second sleep time for Drip batch processes to avoid API timeouts
* Fixes for custom objects with Ontraport

= 3.22 - 2/2/2019 =

##### New CRMs

* Drift
* Autopilot
* Customerly
* Copper
* Groundhogg
* NationBuilder

##### New Features

* Added Gutenberg block for content restriction
* Added support for Salesforce Topics
* Added import tool for Mautic
* Added support for updating email addresses in Kartra
* Added handling for changed contact IDs in Infusionsoft
* Added user_registered field for syncing
* Added option for per-post restricted content messages
* Added Pull User Meta batch operation
* Added support for picklist / multiselect fields in Zoho
* Added import by Topic for Salesforce
* Added support for using tag labels in link click tracking
* Added Gist (ConvertFox) webhooks support
* Added custom fields support for Kartra
* Added webhooks support for Platform.ly

##### Bug Fixes

* Capsule bugfixes
* UserEngage bugfixes
* Gist (ConvertFox) bugfixes
* Drift tagging bugfixes
* Fixed bug where bulk-editing pages would remove WPF access rules
* Fix for syncing with unsubscribed subscribers in ConvertKit
* Fix for incomplete address error with MailChimp
* Fix for error creating contacts in Intercom without any custom fields
* Fix for wpf_update_tags shortcode in auto-login sessions
* Fix for imports larger than 50 with Capsule
* Fix for Sendinblue not creating contacts if custom attributes weren't present

= 3.18 - 11/5/2018 =
* Added Platform.ly CRM support
* Added support for Salesforce topics
* More flexible staging mode
* Added logged in / logged out shortcode
* Added option to sync tags on user login
* Capsule bugfixes
* Added option to choose contact layout for new contacts with Zoho
* Added custom fields support for Intercom
* Fix for "restrict access" checkbox not unlocking inputs correctly
* Fix for import button not working in admin

= 3.17.1 - 9/9/2018 =
* Additional sanitizing of user input data

= 3.17 - 9/8/2018 =
* Initial release of Lite version

For previous release notes see the [changelog](https://wpfusion.com/documentation/faq/changelog/?utm_campaign=free-plugin&utm_source=wp-org).