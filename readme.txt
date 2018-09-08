=== WP Fusion Lite ===
Contributors: verygoodplugins
Tags: infusionsoft, crm, marketing automation, user meta, sync, woocommerce
Requires at least: 4.0
Tested up to: 4.9.8
Stable tag: 3.17
WC tested up to: 3.3.5

WP Fusion connects your website to your CRM or marketing automation system.

== Description ==

WP Fusion Lite connects to leading CRMs and marketing automation systems to add new WordPress users as contacts when they register on your website.

= Features =

* Automaticaly create new contacts in your CRM when new users are added in WordPress
	* Can limit user creation to specified user roles
	* Assign tags to newly-created users
* Configurable synchronization of user meta fields with contact fields
	* Update a contact record in your CRM when a user's profile is updated
* Import contacts from your CRM as new WordPress users

== Installation ==

Upload and activate the plugin, then go to Settings >> WP Fusion. Select your desired CRM, enter your API credentials and click "Test Connection" to verify the connection and perform the first synchronization. This may take some time if you have many user accounts on your site. See our [Getting Started Guide](https://wpfusion.com/documentation/#getting-started-guide) for more information on setting up your application.

== Frequently Asked Questions ==

See our [FAQ](https://wpfusion.com/documentation/).

== Changelog ==

= 3.17 - 9/4/2018 =
* HubSpot integration
* SendinBlue bugfixes
* Zoho authentication bugfixes
* Profile Builder bugfixes
* Added support for Paid Memberships Pro Approvals
* Added option for applying a tag when a contact record is updated
* Support for Gravity Forms applying local tags during auto-login session

= 3.16 - 8/27/2018 =
* Added MailChimp integration
* Added SendinBlue CRM integration
* Easy Digital Downloads 3.0 support
* Profile Builder Pro bugfixes

= 3.15.3 - 8/23/2018 =
* Added Profile Builder Pro integration
* AccessAlly integration
* WPML integration
* Added "wpf_crm_object_type" filter for Salesforce / Zoho / Ontraport
* Fix for date fields with Salesforce
* Improvements to logging display for API errors
* Added Elementor controls to sections and columns
* Support for multi-checkbox fields with Formidable Forms

= 3.15.2 - 8/12/2018 =
* Fix for applying tags via Gravity Form submissions with ConvertKit
* Fixed authentication error caused by resyncing tags with Salesforce
* Added Job Alerts support for WP Job Manager
* Auto-login session will now end on WooCommerce cart or checkout

= 3.15.1 - 8/3/2018 =
* WooCommerce memberships bugfixes
* Fixed PeepSo groups table limit of 10 groups
* Option to sync expiry date for WooCommerce Memberships
* Beaver Builder fix for visibility issues
* WooCommerce Checkout Field Editor Integration
* Added "remove tags" checkbox for EDD recurring price variations
* Maropost CRM integration

= 3.15 - 7/23/2018 =
* Tubular CRM integration
* Flexie CRM integration
* Added tag links for PeepSo groups
* Elementor integration
* WishList Member bugfixes

= 3.14.2 - 7/15/2018 =
* Added WPLMS support
* Improved syncing of multi-checkboxes with ActiveCampaign
* Added support for Paid Memberships Pro Registration Fields Helper add-on

= 3.14.1 - 7/3/2018 =
* Auto-login tweaks for Gravity Forms
* Added option to apply tags on LearnDash quiz fail
* LearnDash bugfixes
* Improvements to AgileCRM imports by tag
* Kartra API updates
* Allowed loading PMPro membership start date and end date from CRM
* MemberMouse syncing updates from admin edit member profile

= 3.14 - 6/23/2018 =
* UserEngage CRM integration
* Fix for auto-login links with AgileCRM
* Added refund tags for price IDs in Easy Digital Downloads
* Added leadsource tracking support for Gravity Forms form submissions
* Added "not" option for Beaver Builder content visibility
* Added access controls to bbPress topics

= 3.13.2 - 6/17/2018 =
* Added support for tagging on subscription status changes for EDD product variations
* Added support for syncing WooCommerce Smart Coupons coupon codes
* Fixed Salesflare address fields not syncing
* Improvements on handling for changed email addresses in MailerLite
* Fix for LifterLMS access plan tags not displaying correctly
* Fix for foreign characters in state names with Mautic

= 3.13.1 - 6/10/2018 =
* Gravity Forms bugfix

= 3.13 - 6/10/2018 =
* Salesflare CRM integration
* Corrected Kartra App ID
* Added option to show excerpts of restricted content to search engines
* Fix for refund tags not being applied in WooCommerce for guest checkouts
* Fix for issues with linked tags not triggering enrollments while running batch processes
* Ability to pause a MemberMouse membership by removing a linked tag
* Bugfixes for empty tags showing up in select
* Better handling for email address changes with MailerLite
* Salesforce bugfixes

= 3.12.9 - 6/2/2018 =
* Added "apply tags" functionality for Restrict Content Pro
* Added tag link for Gamipress achievements
* Added points syncing for Gamipress
* Added support for WooCommerce Smart Coupons
* Fix for "refund" tags getting applied when a WooCommerce order is set to Cancelled
* Fix for LifterLMS "Tag Link" adding a blank tag
* Removed ability to add tags from within WP for Ontraport
* Gravity Forms bugfix for creating new contacts from form submissions while users are logged in
* Support for Tribe Tickets v4.7.2

= 3.12.8 - 5/27/2018 =
* Added GDPR "Agree to terms" tagging for WooCommerce
* BuddyPress bugfixes
* Added ability to apply tags when a coupon is used in Paid Memberships Pro
* Ultimate Member 2.0 fix for tags not being applied at registration
* Bugfix for tags sometimes not saving correctly on widget controls

= 3.12.7 - 5/19/2018 =
* Beaver Builder integration
* Ultimate Member 2.0 bugfixes
* Added delay to Kartra contact creation to deal with slow API performance
* Fix for Kartra applying tags to non-registered users
* Support creating tags from within WP Fusion for Ontraport
* Added delay in WooCommerce Subscriptions renewal processing so tags aren't removed and reapplied during renewals
* Changed template_redirect priority to 15 so it runs after Force Login plugin

= 3.12.6 - 5/16/2018 =
* Bugfix for errors showing when auto login session starts

= 3.12.5 - 5/15/2018 =
* Added support for WooCommerce Deposits
* Added event location syncing for Tribe Tickets Plus
* Added BadgeOS points syncing
* WP Courseware settings page fix for version 4.3.2
* Added option to only log errors (instead of all activity)
* Bugfix for WooCommerce checkout not working properly during an auto-login session

= 3.12.4 - 5/6/2018 =
* Added event date syncing for Tribe Tickets Plus events with WooCommerce
* Fix for Zoho customers with EU accounts
* Support for syncing passwords automatically generated by LearnDash
* Restrict Content Pro bugfixes
* UM 2.0 bugfixes
* Allowed for auto-login using Drip's native ?__s= tracking link query var
* Fix for syncing to date type custom fields in Ontraport

= 3.12.3 - 4/28/2018 =
* Bugfix for "undefined constant" message on admin dashboard

= 3.12.2 - 4/28/2018 =
* Better support for query filtering for restricted posts
* Fixed a bug that caused tags not to be removed properly in Ontraport
* Fixed a bug that caused tags not to apply properly on LifterLMS membership registration
* Fixed a bug with applying tags when achievements are earned in Gamipress
* Fixed a bug with syncing password fields on ProfilePress registration forms
* Additional error handling for import functions

= 3.12.1 - 4/12/2018 =
* ProfilePress integration
* Added option to apply tags when a user is deleted
* Added setting for widgets to *hide* a widget if a user has a tag
* Added option to apply tags when a LifterLMS access plan is purchased
* More robust API error handling and reporting
* Fixed a bug in MailerLite where contact IDs wouldn't be returned for new users

= 3.12 - 3/28/2018 =
* Added Zoho CRM integration
* Added Kartra CRM integration
* Added ConvertFox CRM integration
* Added WP Courseware integration
* Changed WooCommerce order locking to use transients instead of post meta values
* Added membership role syncing to PeepSo integration
* Added User ID as an available field for sync

= 3.11.1 - 3/21/2018 =
* Added GamiPress integration
* Added PeepSo integration
* Added option to just return generated passwords on import, without requiring ongoing password sync
* "Push user meta" batch operation now pushes Paid Memberships Pro meta data correctly
* Fixed bug where ampersands would fail to send in Infusionsoft contact updates
* Cleaned up scripts and styles in admin settings pages

= 3.11 - 3/15/2018 =
* Capsule CRM integration
* Added LearnPress LMS integration
* Added batch-resync tool for LifterLMS memberships
* Tags linked to LearnDash courses will now be applied / removed when a user is manually added to / removed from a course
* Bugfixes for export batch operation
* Added "Pending Cancellation" tags for WooCommerce Subscriptions
* Improved handling for displaying user meta when using auto-login links
* Fix for AWeber API configuration errors breaking setup tab
* Improved AgileCRM handling for custom fields
* Added filter for overriding WPEP course buttons for restricted courses

= 3.10.1 - 3/3/2018 =
* Fixed a bug where sometimes a contact ID wouldn't be associated with an existing contact when a new user registers
* Added start date syncing for Paid Memberships Pro

= 3.10 - 2/24/2018 =
* MailerLite CRM integration
* Bugfixes for auto-login links with Gravity Forms
* MemberMouse bugfixes

= 3.9.3 - 2/19/2018 =
* Added option for auto-login after Gravity Form submission
* Changed auto-login links to use cookies instead of sessions
* Allowed the [user_meta] shortcode to work with auto-login links
* Modified Infusionsoft contact ID lookup to just use primary email field

= 3.9.2 - 2/15/2018 =
* Proper state and country field handling for Mautic
* Fix for malformed saving of Tag Link field in LifterLMS course settings

= 3.9.1 - 2/12/2018 =
* Added "Apply Tags - Cancelled" to Paid Memberships Pro settings
* Added Ontraport affiliate tracking
* Added Ontraport page tracking
* Improved LearnDash content restriction filtering
* Optimized unnecessary contact ID lookups when Push All User Meta was enabled

= 3.9 - 1/31/2018 =
* Added AWeber CRM integration
* Linked tags now automatically added / removed on LearnDash group assignment
* Added auto-enrollment for LifterLMS courses
* Added post-checkout process locking for WooCommerce to reduce duplicate transactions

= 3.8.1 - 1/21/2018 =
* Added [else] method to shortcodes
* Added loggedout method to shortcodes
* Performance enhancements
* ConvertKit now auto-removes webhook tags
* Added option to apply tags when a WooCommerce subscription converts from free to paid

= 3.8 - 1/8/2018 =
* Intercom CRM integration
* myCRED integration
* Added bulk import for Salesforce
* Added batch processing for s2Member
* Fixed bug with administrators not being able to view content in a tag-restricted taxonomy

= 3.7.6 - 12/30/2016 =
* Added batch processing tool for MemberPress subscriptions
* Added setting to exclude restricted posts from archives / indexes
* Added ActiveCampaign site tracking
* Added Infusionsoft site tracking
* Added Drip site tracking

= 3.7.5 - 12/21/2017 =
* WooCommerce bugfixes

= 3.7.4 - 12/15/2017 =
* Improvements to tag handling with ConvertKit
* Added collapsible table headers to Contact Fields table
* Fixed bug in Mautic with applying tags to new contacts
* UserPro bugfixes

= 3.7.3 =
* Added global setting for tags to apply for all WooCommerce customers
* Fixed issue with restricted WooCommerce variations not being hidden
* Fixed bug with syncing Ultimate Member password updates from the Account screen
* Fixed LifterLMS account updates not being synced

= 3.7.2 =
* UserPro bugfixes
* Fixed hidden Import tab

= 3.7.1 =
* Fix for email addresses not updating on CRED profile forms
* Fix for Hold / Failed / Cancelled tags not being removed on WooCommerce subscription renewal

= 3.7 =
* Added support for the Mautic marketing automation platform
* Toolset CRED integration (for custom registration / profile forms)
* Fix for newly added tags not saving to WooCommerce variations

= 3.6.1 =
* Updated for compatibility with Ontraport API changes

= 3.6 =
* WishList Member integration
* Fixed tag fields sometimes not saving on WooCommerce variations
* Added async checkout for EDD purchases

= 3.5.2 =
* Improvements to filtering products in WooCommerce shop
* Significantly sped up and increased reliability of WooCommerce Asynchronous Checkout functionality
* Added ability to apply tags when refunded in EDD
* Better Tribe Events integration

= 3.5.1 =
* Improvements to auto login link system
* Added duplicating Gravity Forms feeds
* Restrict Content Pro bugfixes
* Added admin tools for resetting wpf_complete hooks on WooCommerce / EDD orders

= 3.5 =
* Added support for Ultimate Member 2.0 beta
* Added Tribe Events Calendar support (including support for Event Tickets and Event Tickets Plus)
* Added list selection options for Gravity Forms with ActiveCampaign
* Fixed variable tag fields not saving in WooCommerce
* Fixed new user notification emails sometimes not going out
* ActiveCampaign API performance enhancements

= 3.4.1 =
* Bugfixes

= 3.4 =
* Added access controls for widgets
* Improved "Preview with Tag" reliability
* WooCommerce now sends country name correctly to Infusionsoft
* Added logging support for Woo Subscriptions
* Support for additional BadgeOS achievement types
* Support for switching subscriptions with Woo Subscriptions
* Added batch processing options for Paid Memberships Pro
* Fixed issue with shortcodes using some visual page builders

= 3.3.3 =
* Added BadgeOS integration
* Staging mode now works with logging tool
* "Apply to children" now applies to nested children
* Added backwards compatibility support for WC < 3.0
* Passwords auto-generated by WooCommerce can now be synced
* Fixed issues with MemberPress non-recurring products
* Updated EDDSL plugin updater
* Fixes for Gravity Forms User Registration add-on
* Cleaned up internal fields from Contact Fields screen
* Sped up Import tool for Drip
* Option to disable API queue framework for debugging

= 3.3.2 =
* ConvertKit imports no longer limited to 50 contacts
* Restrict Content Pro improvements
* Fixed bug when adding new tags via tag select dropdown
* Fixed bug with using tag names in wpf shortcode on some CRMs
* Importing users now respects specified role
* Fixed error saving user profile when running BuddyPress with Groups disabled

= 3.3.1 =
* 3.3 bugfixes

= 3.3 =
* New features:
	* Added new logging / debugging tools
	* Contact Fields list is now organized by related integration
	* Added options for filtering users with no contact ID or no tags
	* Added ability to restrict WooCommerce variations by tag
* New Integrations:
	* WooCommerce Memberships
	* Simple Membership plugin integration
	* WP Execution Plan LMS integration
* New Integration Features:
	* MemberMouse memberships can now be linked with a tag
	* Expiration Date field syncing for Restrict Content Pro subscriptions
	* BuddyPress groups can now be linked with a tag
	* Added Payment Method field for sync with Paid Memberships Pro
	* Expiration Date can now be synced for Paid Memberships Pro
	* Added registration date, expiration date, and payment method for MemberPress subscriptions
	* Added "Apply tags when cancelled" field to MemberPress subscriptions
* Bug fixes:
	* Fixed bugs with editing tags via the user profile
	* user_meta Shortcode now pulls data from wp_users table correctly
	* "Apply on view" tags will no longer be applied if the page is restricted
	* Link with Tag fields no longer allow overlap with Apply Tags fields in certain membership integrations
	* AgileCRM fixes for address fields
* Enhancements:
	* Optimized many duplicate API calls
	* Added Dutch and Spanish translation files

= 3.2.1 =
* Bugfixes

= 3.2 =
* Salesforce integration
* Fixed issue with automatically assigning membership levels in MemberPress via webhook
* Fixed incompatibility with Infusionsoft Form Builder plugin
* Improvements to Drip integration
* Improvements to WooCommerce order batch processing tools
* Numerous bugfixes and performance enhancements

= 3.1.3 =
* Drip CRM can now trigger new user creation via webhook
* User roles now update properly when changed via webhook
* Import tool can now import more than 1000 contacts from Infusionsoft
* Gravity Forms bugfixes
* WP Engine compatibility bugfixes

= 3.1.2 =
* Added filter by tag option in admin Users list
* Added ability to restrict all posts within a restricted category or taxonomy term
* Added ability to restrict all bbPress forums at a global level
* Fixed bug with Ultimate Member's password reset process with Infusionsoft
* Added additional Google Analytics fields to contact fields list
* Bugfix to prevent looping when restricted content is set to redirect to itself

= 3.1.1 =
* Fixed inconsistencies with syncing user roles
* Additional bugfixes for WooCommerce 3.0.3

= 3.1.0 =
* Added built in user meta shortcode system
* Added support for webhooks with ConvertKit
* Updates for WooCommerce 3.0
* Additional built in fields for Agile CRM users
* Fixed bug where incorrect tags would be applied during automated payment renewals
* Fixed debugging log not working

= 3.0.9 =
* Added leadsource tracking to new user registrations for Google Analytics campaigns or custom lead sources
* Link click tracking can now be used on other elements in addition to links
* Agile CRM API improvements
* Misc. bugfixes

= 3.0.8 =
* Drip bugfixes
* Agile CRM improvements and bugfixes
* Added EDD payments to batch processing tools
* Added EDD Recurring Payments to batch processing tools
* Misc. UI improvements
* Bugfixes and speed improvements to batch operations

= 3.0.7 =
* Integration with User Meta plugin
* Fixed bug where restricted page would be shown if no redirect was specified
* Better support for Ultimate Member "checkboxes" fields

= 3.0.6 =
* Import tool has been updated to use new background processing system
* Added WordPress user role to list of meta fields for sync
* Support for additional Webhooks with Agile CRM
* Bugfix for long load times when getting user tags

= 3.0.5 =
* New tags will be loaded from the CRM if a user is given a tag that doesn't exist locally
* Resync contact IDs / Tags moved from Resynchronize button process to Batch Operations
* ActiveCampaign integration can now load all tags from account (no longer limited to first 100)
* Bugfix for LifterLMS memberships tag link

= 3.0.4 =
* Paid Memberships Pro bugfixes

= 3.0.3 =
* WP Job Manager integration
* Added category / taxonomy archive access restrictions
* Tags can now be added/removed from the edit user screen
* Added tooltips with additional information to batch processing tools
* Batch processes now update in real time after reloading WPF settings page

= 3.0.2 =
* Bugfixes for version 3.0

= 3.0.1 =
* Bugfixes for version 3.0

= 3.0 =
* Added Formidable Forms integration
* Added bulk editing tools for content protection
* New admin column for showing restricted content
* New background worker for batch operations on sites with a large number of users
* Tags are now removed properly when WooCommerce order refunded / cancelled
* Added option to remove tags when LifterLMS membership cancelled
* Added "Tag Link" capability for Paid Memberships Pro membership levels
* User roles can now be updated via the Update method in a webhook or HTTP Post
* Introduced beta support for Drip webhooks
* Initial sync process for Drip faster and more comprehensive
* All integration functions are now available via wp_fusion()->integrations
* Updated and improved automatic updates
* Numerous speed optimizations and bugfixes

= 2.9.6 =
* Improved integration with Paid Memberships Pro and Contact Form 7
* Bugfix for Radio type fields with Ultimate Member

= 2.9.5 =
* Added "Staging Mode" - all WP Fusion functions available, but no API calls will be sent
* Added Advanced settings pane with debugging tools

= 2.9.4 =
* LifterLMS bugfixes
* Deeper MemberPress integration

= 2.9.3 =
* Support for Asian character encodings with Infusionsoft
* Improvements to Auto-login links for hosts that don't support SESSION variables

= 2.9.2 =
* Misc. bugfixes

= 2.9.1 =
* Added support for MemberPress
* Updates for WooCommerce Subscriptions 2.x

= 2.9 =
* AgileCRM CRM support
* Added support for Thrive Themes Apprentice LMS
* Added support for auto-login links
* Added ability to apply tags when a link is clicked

= 2.8.3 =
* Allows shortcodes in restricted content message

= 2.8.2 =
* Fix for users being logged out when syncing password fields
* Ontraport bugifxes and performance tweaks
* Better error handling and debugging information for webhooks

= 2.8.1 =
* Added option for customizing restricted product add to cart message
* Misc. bug fixes

= 2.8 =
* ConvertKit CRM support
* LifterLMS updates to support LLMS 3.0+
* Ability to apply tags for LifterLMS membership levels
* Restricted Woo products can no longer be added to cart via URL

= 2.7.5 =
* Fixed Infusionsoft character encoding for foreign characters
* Fixed default field mapping overriding custom field selections

= 2.7.4 =
* Fixed bug where tag select boxes on LearnDash courses were limited to one selection

= 2.7.3 =
* Fixed bugs where ActiveCampaign lists would be overwritten on contact updates
* Restricted menu items no longer hidden in admin menu editor
* Improved s2Member support
* Fix for applying tags with variable WooCommerce subscriptions

= 2.7.2 =
* Added s2Member integration
* Added support for applying tags when WooCommerce coupons are used
* Added support for syncing AffiliateWP affiliate information
* Fixed returning passwords for imported contacts
* Updates for compatibility with plugin integrations

= 2.7.1 =
* Added LifterLMS support
* Fix for password updates not syncing from UM Account page

= 2.7 =
* Added Restrict Content Pro Integration
* Tag mapping for LearnDash Groups
* Can now sync user password from Ultimate Member reset password page

= 2.6.8 =
* Fix for contact fields not getting correct defaults on first install
* Fixed wrong lists getting assigned when updating AC contacts
* Significant API performance optimizations

= 2.6.7 =
* Enabled webhooks from Ontraport

= 2.6.6 =
* Fixed error in GForms integration

= 2.6.5 =
* Added support for syncing PMPro membership level name
* Fixed tags not applying when WooCommerce orders refunded
* Bugfixes and performance optimizations

= 2.6.4 =
* Batch processing tweaks

= 2.6.3 =
* Admin performance optimizations
* Batch processing / export tool

= 2.6.2 =
* Fix for tag select not appearing under Woo variations
* Formatting filters for date fields in ActiveCampaign
* Added quiz support to Gravity Forms
* Optimizations and performance tweaks

= 2.6.1 =
* Drip bugfixes
* Fix for restricted WooCommerce products not being hidden on some themes

= 2.6 =
* Added Drip CRM support
* Option to run Woo checkout actions asynchronously

= 2.5.5 =
* Updates to support Media Tools Addon

= 2.5.4 =
* Added option to push generated passwords back to CRM
* Added ability to apply tags in LearnDash when a quiz is marked complete
* Added ability to link a tag with an Ultimate Member role for automatic role assignment

= 2.5.3 =
* Fixed bug with WooCommerce variations and user-entered tags
* Fixed BuddyPress error when XProfile was disabled

= 2.5.2 =
* Fix for license activations / updates on hosts with outdated CURL
* Updates to support WPF addons
* Re-introduced import tool for ActiveCampaign users
* PHP 7 optimizations

= 2.5.1 =
* Improvements to initial ActiveCampaign sync
* Added instructions for AC import

= 2.5 =
* Added Paid Memberships Pro support
* Added course / tag relationship mapping for LearnDash courses
* Added automatic detection and mapping for BuddyPress profile fields
* Added "Apply tags when refunded" option for WooCommerce products
* Updated HTTP status codes on HTTP Post responses
* Tweaks to Import function for Ontraport users
* Fix for duplicate contacts being created on email address change with ActiveCampaign
* Fix for resyncing contacts with + symbol in email address

= 2.4.1 =
* Bugfixes for Ontraport integration
* Added Contact Type field mapping for Infusionsoft

= 2.4 =
* Added Ontraport CRM integration

= 2.3.2 =
* MemberMouse beta integration
* Fix for license activation for users on outdated versions of CURL / SSL
* Fix for BuddyPress pages not locking properly

= 2.3.1 =
* Fixed error in bbPress integration on old PHP versions

= 2.3 =
* Added Contact Form 7 support
* All bbPress topics now inherit permissions from their forum
* Added ability to lock bbPress forums archive
* Fixed bug with importing users by tag
* Fixed error with shortcodes using Thrive Content Builder
* Removed Add to Cart links for restricted products on the Woo store page
* Added option to hide restricted products from Woo store page entirely
* Added support for applying tags based on EDD variations

= 2.2.2 =
* Fix for tag shortcodes on AC
* Improvements to tag selection on Woo subscriptions / variations
* Woo Subscription fields now show on variable subscriptions as well
* Updated included Select2 libraries
* Restricted content with no tags specified will now be restricted for non-logged-in-users

= 2.2.1 =
* Fixed fatal error with GForms integration on lower PHP versions

= 2.2 =
* Added support for re-syncing contacts in batches for sites with large numbers of users
* Added support for ActiveCampaign webhooks
* Added support for EDD Recurring Payments
* Simplified URL structure for HTTP POST actions and added debugging output
* Fix for "0" tag appearing with ActiveCampaign tags

= 2.1.2 =
* Fixed bug where AC profiles wouldn't update if email address wasn't present in the form
* Fix for redirect rules not being respected for admins
* Fix for user_email and display_name not updating via HTTP Post

= 2.1.1 =
* Fixed bug affecting [wpf] shortcodes with users who had no tags applied

= 2.1 =
* Added support for applying tags in Woo when a subscription expires, is cancelled, or is put on hold
* Added "Push All" option for incompatible plugins and "user_meta" updates triggered via functions
* Fix for ActiveCampaign accounts with no tags
* Isolated AC API to prevent conflicts with plugins using outdated versions of the same API

= 2.0.10 =
* Bugfix when using tag label in shortcode

= 2.0.9 =
* Fix for tag checking logic with shortcode

= 2.0.8 =
* Fix for has_tag() function when using tag label
* Fixes for conflicts with other plugins using older versions of Infusionsoft API
* Support for re-adding contacts if they've been deleted in the CRM

= 2.0.7 =
* Resync contact now deletes local data if contact was deleted in the CRM
* Update license handler to latest version
* Resynchronize now force resets all tags
* Moved upgrade hook to later in the admin load process

= 2.0.6 =
* Support for manually marking WooCommerce payments as completed
* Improved support for servers with limited API tools
* Fixed wp_fusion()->user->get_tag_id() function to work with ActiveCampaign
* Bugfixes to shortcode content restriction system
* Fix for fields with subfields occasionally not showing up in GForms mapping
* Fix for new Ultimate Member field formats

= 2.0.5 =
* Fix for user accounts not created properly when WooCommerce and WooSubscriptions were both installed
* Added "apply to related lessons" feature to Sensei integration
* WooCommerce will now track leadsources and save them to a customer's contact record

= 2.0.4 =
* Bugfix for PHP notices appearing when shortcodes were in use and current user had no CRM tags
* Added SQL escaping for imported tag labels and categories
* Fix for contact address not updating existing contacts on guest checkout
* Fix for ACF not pulling / pushing field data properly

= 2.0.3 =
* Bugfix for importing users where CRM fields were mapped to multiple local fields
* Bugfix for Setup tab not appearing on initial install

= 2.0.2 =
* Bugfix for notices appearing for admins when admin bar was in use

= 2.0.1 =
* Bugfix for "update" action in HTTP Posts

= 2.0 =
* Complete rewrite and refactoring of core code
* Integration with ActiveCampaign, supporting all of the same features as Infusionsoft
* Custom fields are now available as a dynamic dropdown
* Ability to re-sync tags and custom fields within the plugin
* Integration with Sensei LMS
* Infusionsoft integration upgraded to use XMLRPC 4.0
* 100's of bug fixes, performance enhancements, and other improvements

= 1.6.4 =
* Improved compatibility with other plugins that use the iSDK class
* Changes to options framework to support 3rd party addons
* Added backwards compatibility for PHP versions less than 5.3

= 1.6.3 =
* Fix for registering contacts that already exist in Infusionsoft

= 1.6.2 =
* Fix for saving WooCommerce variation configuration
* Added automatic detection for when contacts are merged
* Improvements to wpf_template_redirect filter
* Added ability to apply tags per Ultimate Member registration form
* Ability to defer adding the contact until after the UM account has been activated
* Fixed bug with tags not appearing on admin user profile page
* Added filters for unsetting post types
* Added wpf_tags_applied and wpf_tags_removed actions

= 1.6.1 =
* Added has_tag function
* Added wpf_template_redirect filter
* Improved detection of registration form fields
* Fixed PHP notices appearing when using ACF
* Updates for compatibility with WP 4.3.1

= 1.6 =
* Can feed Gravity Forms data to Infusionsoft even if the user isn't logged in on your site
* Added support for Easy Digital Downloads
* Fixed bug with pulling date fields into Ultimate Member

= 1.5.2 =
* Fixed a bug with the "any" shortcode method
* More robust handling for user creation

= 1.5.1 =
* Fixed bug with account creation and Ultimate Member user roles

= 1.5 =
* LearnDash integration: can now apply tags on course/lesson/topic completion
* Content restrictions can now apply to child content
* New Ultimate Member fields are detected automatically
* Added ability to set user role via HTTP Post 'add'
* Added 'any' option to shortcodes

= 1.4.5 =
* Fixed global redirects not working properly
* Fixed issue with Preview As in admin bar
* Added 'wpf_create_user' filter
* Allowed for creating / updating users manually
* API improvements

= 1.4.4 =
* Misc. bugfixes with last release

= 1.4.3 =
* Improved compatibility of WooCommerce checkout with caching plugins
* Fixed bug with static page redirects
* Improved Ultimate Member integration
* Added support for combining "tag" and "not" in the WPF shortcode
* Added support for separating multiple shortcode tags with a comma
* Reduced API calls when profiles are updated
* Fixed bugs with guest checkout in WooCommerce

= 1.4.2 =
* Fixed bug with Ultimate Member integration in last release

= 1.4.1 =
* "Resync Contact" now pulls meta data as well
* Can now validate custom fields by name as well as label
* Added warning messages for WP Engine users
* Improved support for Ultimate Member membership plugin
* Fixed bug with redirects on Blog page / archive pages

= 1.4 =
* Added support for locking bbPress forums based on tags
* Added wpf_update_tags and wpf_update_meta shortcodes
* Support for overriding the new user welcome email with plugins
* Fixed bug with API Key generation
* Fixed bug with tags not applying after the specified delay
* Improved integration with WooCommerce checkout

= 1.3.5 =
* Added integration with Ultimate Member plugin

= 1.3.4 =
* Added "User Role" selection to import tool
* Added actions for user added and user updated
* Added "lock all" button to preview bar dropdown
* Fixed bug where tag preview wouldn't work on a static home page
* Fixed bug where shortcodes within the `[wpf]` shortcode wouldn't execute

= 1.3.3 =
* Improved integration support for user meta / profile plugins

= 1.3.2 =
* Tags will be removed when a payment is refunded
* Added support for applying tags with product variations
* Fixed bug with pushing ACF meta data on profile save
* Added support for pulling ACF meta data on profile load

= 1.3.1 =
* Added wpf_woocommerce_payment_complete action
* Added search filter to redirect page select dropdown
* Fixed "Class 'WPF_WooCommerce_Integration'" not found bug

= 1.3 =
* Added ability to import contacts from Infusionsoft as new WordPress users
* Added new plugin API methods for updating meta data and creating new users (see the documentation for more information)
* Added "unlock all" option to frontend admin toolbar
* Tags applied by a WooCommerce subscription can be removed when the subscription fails to charge, a trial period ends, or the subscription is put on hold
* Added support for syncing password and username fields
* Fixed a bug with applying tags at WooCommerce checkout when the user isn't logged in

= 1.2.1 =
* Added pull_user_meta() template tag
* Fixed bug with pushing user meta when no contact ID is found

= 1.2 =
* Added support for syncing multiselect fields with a contact record
* Added ability to trigger a campaign goal when a user profile is updated
* Added ability to manually resync a user profile if a contact record is deleted / recreated
* Now supports syncing with Infusionsoft built in fields. See the Infusionsoft "Table Documentation" for field name reference
* Users registered through a UserPro registration form will now have their password saved in Infusionsoft
* Fixed several bugs with user account creation using a UserPro registration form
* Fixed bug where tag categories with over 1,000 tags wouldn't import fully
* Fixed a bug that would cause checkout to fail with WooCommerce if a user is in guest checkout mode
* Numerous other bugfixes, optimizations, and improvements

= 1.1.5 =
* Fixed bug that would cause a user profile to fail to load when an IS contact wasn't found
* "Preview with tag" dropdown now groups tags by category and sorts alphabetically
* Fixed a bug with applying tags at WooCommerce checkout
* Notices for inactive / expired licenses

= 1.1.4 =
* Check for UserPro header on initial sync bug fixed
* Removed PHP notices on meta box when no tags are present
* "Preview with tag" has been removed from admin screens

= 1.1.3 =
* Automatic update bug fixed

= 1.1.2 =
* Fixed bug where users without email address would kill initial sync

= 1.1.1 =
* Changed name to WP Fusion

= 1.1 =
* EDD software licensing added

= 1.0.3 =
* Cleaned up apply_tags function

= 1.0.2 =
* Misc. bugfixes
* Added ability to apply tags to contact on WooCommerce purchase

= 1.0.1 =
* Misc. bugfixes
* Added content selection dropdown on post meta box

= 1.0 =
* Initial release


== Shortcodes ==

To restrict content based on a user's Infusionsoft tags, wrap the desired content in the WP Fusion shortcode, like so:

`[wpf tag=45] Restricted Content [/wpf]`

You can also specify the tag name, like so:

`[wpf tag="New Customer"] Restricted Content [/wpf]`

To show content only if a user _doesn't_ have a certain tag, use the following syntax:

`[wpf not=45] Restricted Content [/wpf]`

To force an update of the current user's tags before loading the rest of the page, use:

`[wpf_update_tags]`

To force an update of the current user's meta data before loading the rest of the page, use:

`[wpf_update_meta]`
