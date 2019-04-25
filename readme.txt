=== WP Fusion Lite ===
Contributors: verygoodplugins
Tags: infusionsoft, activecampaign, ontraport, convertkit, salesforce, mailchimp, drip, crm, marketing automation, user meta, sync
Requires at least: 4.0
Requires PHP: 5.6
Tested up to: 5.1.1
Stable tag: 3.24

WP Fusion connects your website to your CRM or marketing automation system.

== Description ==

WP Fusion Lite connects to leading CRMs and marketing automation systems to add new WordPress users as contacts when they register on your website, and keep user profiles in sync with CRM contacts.

= Features =

* Automaticaly create new contacts in your CRM when users register in WordPress
* Apply tags when users register
* Synchronize any WordPress user data with custom fields in your CRM
* Restrict access to site content using tags in your CRM
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
* Capsule
* ConvertKit
* Copper
* Customerly
* Drift
* Drip
* Flexie
* Gist
* Groundhogg
* HubSpot
* Infusionsoft
* Intercom
* Kartra
* MailChimp
* MailerLite
* Mailjet
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