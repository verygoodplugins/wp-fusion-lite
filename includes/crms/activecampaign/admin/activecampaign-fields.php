<?php

$activecampaign_fields = array();

$activecampaign_fields['first_name'] = array(
	'crm_label' => 'First Name',
	'crm_field' => 'first_name',
);

$activecampaign_fields['last_name'] = array(
	'crm_label' => 'Last Name',
	'crm_field' => 'last_name',
);

$activecampaign_fields['user_email'] = array(
	'crm_label' => 'Email',
	'crm_field' => 'email',
);

$activecampaign_fields['phone_number'] = array(
	'crm_label' => 'Phone',
	'crm_field' => 'phone',
);

$activecampaign_fields['billing_company'] = array(
	'crm_label' => 'Account Name',
	'crm_field' => 'orgname',
);

// $activecampaign_fields[] = array(
// 'crm_label' => 'Job Title',
// 'crm_field' => 'jobtitle',
// );

// Can't be updated over the API (https://testpublic.ideas.aha.io/ideas/AC-I-13844)
