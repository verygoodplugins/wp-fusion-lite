<?php

$ortto_fields = array();

$ortto_fields['user_login'] = array(
	'crm_label' => 'Name',
	'crm_field' => 'str::first',
);

$ortto_fields['first_name'] = array(
	'crm_label' => 'First Name',
	'crm_field' => 'str::first',
);

$ortto_fields['last_name'] = array(
	'crm_label' => 'Last Name',
	'crm_field' => 'str::last',
);

$ortto_fields['user_email'] = array(
	'crm_label' => 'Email Address',
	'crm_field' => 'str::email',
);


$ortto_fields['phone_number'] = array(
	'crm_label' => 'Phone number',
	'crm_field' => 'phn::phone',
);


$ortto_fields['billing_city'] = array(
	'crm_label' => 'City',
	'crm_field' => 'geo::city',
);

$ortto_fields['billing_postcode'] = array(
	'crm_label' => 'Postal Code',
	'crm_field' => 'str::postal',
);

$ortto_fields['billing_country'] = array(
	'crm_label' => 'Country',
	'crm_field' => 'geo::country',
);
