<?php

$klaviyo_fields = array();

$klaviyo_fields['first_name'] = array(
	'crm_label' => 'First Name',
	'crm_field' => '$first_name',
);

$klaviyo_fields['last_name'] = array(
	'crm_label' => 'Last Name',
	'crm_field' => '$last_name',
);

$klaviyo_fields['user_email'] = array(
	'crm_label' => 'Email',
	'crm_field' => '$email',
);

$klaviyo_fields['phone_number'] = array(
	'crm_label' => 'Phone Number',
	'crm_field' => '$phone_number',
);

$klaviyo_fields[] = array(
	'crm_label' => 'Title',
	'crm_field' => '$title',
);

$klaviyo_fields[] = array(
	'crm_label' => 'Organization',
	'crm_field' => '$organization',
);

$klaviyo_fields['billing_city'] = array(
	'crm_label' => 'City',
	'crm_field' => '$city',
);

$klaviyo_fields['billing_state'] = array(
	'crm_label' => 'Region',
	'crm_field' => '$region',
);

$klaviyo_fields['billing_country'] = array(
	'crm_label' => 'Country',
	'crm_field' => '$country',
);

$klaviyo_fields['billing_postcode'] = array(
	'crm_label' => 'Zip',
	'crm_field' => '$zip',
);
