<?php

$engagebay_fields = array();

// eb = engagebay
// eb_isroot  = 1 means its in the root AND in properties subarray

$engagebay_fields['first_name'] = array(
	'crm_label' => 'First Name',
	'crm_field' => 'name',
);

$engagebay_fields['last_name'] = array(
	'crm_label' => 'Last Name',
	'crm_field' => 'last_name',
);

$engagebay_fields['user_email'] = array(
	'crm_label' => 'Email',
	'crm_field' => 'email+primary',
);

$engagebay_fields['crm_role'] = array(
	'crm_label' => 'CRM Role',
	'crm_field' => 'role',
);

// PHONE FIELDS
$engagebay_fields['billing_phone'] = array(
	'crm_label' => 'Phone (Work)',
	'crm_field' => 'phone+work',
);

$engagebay_fields['phone_number_home'] = array(
	'crm_label' => 'Phone (Home)',
	'crm_field' => 'phone+home',
);

$engagebay_fields['phone_number_mobile'] = array(
	'crm_label' => 'Phone (Mobile)',
	'crm_field' => 'phone+mobile',
);

$engagebay_fields['phone_number_main'] = array(
	'crm_label' => 'Phone (Main)',
	'crm_field' => 'phone+main',
);

$engagebay_fields['phone_number_fax'] = array(
	'crm_label' => 'Phone (Home Fax)',
	'crm_field' => 'phone+home_fax',
);

$engagebay_fields['phone_number_work_fax'] = array(
	'crm_label' => 'Phone (Work Fax)',
	'crm_field' => 'phone+work_fax',
);

$engagebay_fields['phone_number_other'] = array(
	'crm_label' => 'Phone (Other)',
	'crm_field' => 'phone+other',
);


// URL FIELDS

$engagebay_fields['website'] = array(
	'crm_label' => 'Website (Personal)',
	'crm_field' => 'website+URL',
);

$engagebay_fields['linkedin'] = array(
	'crm_label' => 'Website (LinkedIn)',
	'crm_field' => 'website+LINKEDIN',
);

$engagebay_fields['skype'] = array(
	'crm_label' => 'Website (Skype URL)',
	'crm_field' => 'website+SKYPE',
);

$engagebay_fields['twitter'] = array(
	'crm_label' => 'Website (Twitter)',
	'crm_field' => 'website+TWITTER',
);

$engagebay_fields['facebook'] = array(
	'crm_label' => 'Website (Facebook)',
	'crm_field' => 'website+FACEBOOK',
);

$engagebay_fields['xing'] = array(
	'crm_label' => 'Website (XING)',
	'crm_field' => 'website+XING',
);

$engagebay_fields['blog'] = array(
	'crm_label' => 'Website (Your Blog)',
	'crm_field' => 'website+FEED',
);

$engagebay_fields['google_plus'] = array(
	'crm_label' => 'Website (Google+)',
	'crm_field' => 'website+GOOGLE-PLUS',
);

$engagebay_fields['flikr'] = array(
	'crm_label' => 'Website (Flikr)',
	'crm_field' => 'website+FLIKR',
);

$engagebay_fields['github'] = array(
	'crm_label' => 'Website (github)',
	'crm_field' => 'website+GITHUB',
);

$engagebay_fields['youtube'] = array(
	'crm_label' => 'Website (YouTube)',
	'crm_field' => 'website+YOUTUBE',
);

// Address

$engagebay_fields['billing_address_1'] = array(
	'crm_label' => 'Address 1',
	'crm_field' => 'address+address'
);

$engagebay_fields['billing_city'] = array(
	'crm_label' => 'City',
	'crm_field' => 'address+city'
);

$engagebay_fields['billing_state'] = array(
	'crm_label' => 'State',
	'crm_field' => 'address+state'
);

$engagebay_fields['billing_postcode'] = array(
	'crm_label' => 'Zip',
	'crm_field' => 'address+zip'
);

$engagebay_fields['billing_country'] = array(
	'crm_label' => 'Country',
	'crm_field' => 'address+country'
);
