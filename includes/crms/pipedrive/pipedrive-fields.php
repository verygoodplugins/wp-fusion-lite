<?php

$pipedrive_fields = array();

$pipedrive_fields['user_login'] = array(
	'crm_label' => 'Name',
	'crm_field' => 'name',
);

$pipedrive_fields['first_name'] = array(
	'crm_label' => 'First Name',
	'crm_field' => 'first_name',
);

$pipedrive_fields['last_name'] = array(
	'crm_label' => 'Last Name',
	'crm_field' => 'last_name',
);

$pipedrive_fields['user_email'] = array(
	'crm_label' => 'Email (Work)',
	'crm_field' => 'email+work',
);

$pipedrive_fields[] = array(
	'crm_label' => 'Email (Home)',
	'crm_field' => 'email+home',
);

$pipedrive_fields[] = array(
	'crm_label' => 'Email (Other)',
	'crm_field' => 'email+other',
);

$pipedrive_fields['phone_number'] = array(
	'crm_label' => 'Phone (Work)',
	'crm_field' => 'phone+work',
);

$pipedrive_fields[] = array(
	'crm_label' => 'Phone (Home)',
	'crm_field' => 'phone+home',
);

$pipedrive_fields[] = array(
	'crm_label' => 'Phone (Mobile)',
	'crm_field' => 'phone+mobile',
);

$pipedrive_fields[] = array(
	'crm_label' => 'Phone (Other)',
	'crm_field' => 'phone+other',
);
