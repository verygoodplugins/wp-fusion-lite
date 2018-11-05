# WP Fusion Lite #

WP Fusion connects your WordPress website to your CRM or marketing automation system.

This is the Lite version of [WP Fusion](https://wpfusion.com/). It interfaces with WordPress core, but does not include any plugin-specific integrations.


### For end users

All new users who register on your site will be synced to your CRM of choice, with support for any number of custom fields. Tags and/or lists can also be assigned at time of registration.

After registration, future profile updates are kept in sync with their CRM contact records.

### For developers

WP Fusion provides an extensible framework for connecting WordPress to leading CRMs and marketing automation tools.

WP Fusion handles authentication, sanitization of data, and error reporting.

WP Fusion also standardizes many common API operations. For example:

```php
$args = array(
	'user_email'	=> 'newuser@example.com',
	'first_name'	=> 'First Name'
);

$contact_id = wp_fusion()->crm->add_contact( $args );
```

This code will add a new contact to the active CRM and return the contact ID.

For more information see [the WP Fusion User Class](https://wpfusion.com/documentation/advanced-developer-tutorials/wp-fusion-user-class/), the [CRM Class](https://wpfusion.com/documentation/advanced-developer-tutorials/how-wp-fusion-interfaces-with-multiple-crms/) and the [Developer Reference](https://wpfusion.com/documentation/#developer).

### Supported CRMs

* AWeber
* ActiveCampaign
* AgileCRM
* Capsule
* ConvertFox
* ConvertKit
* Drip
* Flexie
* HubSpot
* Infusionsoft
* Intercom
* Kartra
* MailChimp
* MailerLite
* Maropost
* Mautic
* Ontraport
* Platform.ly
* Salesflare
* Salesforce
* SendinBlue
* Tubular
* UserEngage
* Zoho

## Installation ##

For detailed setup instructions, visit the official [Documentation](https://wpfusion.com/documentation/) page.

1. You can clone the GitHub repository: `https://github.com/verygoodplugins/wp-fusion-lite.git`
2. Or download it directly as a ZIP file: `https://github.com/verygoodplugins/wp-fusion-lite/archive/master.zip`

This will download the latest copy of WP Fusion Lite.

## Bugs ##
If you find an issue, let us know [here](https://github.com/verygoodplugins/wp-fusion-lite/issues?state=open)!

## Support ##
This is a developer's portal for WP Fusion Lite and should _not_ be used for support. Please visit the [support page](https://wpfusion.com/support/contact) if you need to submit a support request.