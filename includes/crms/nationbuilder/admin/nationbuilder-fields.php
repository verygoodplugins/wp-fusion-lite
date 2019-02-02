<?php

$nationbuilder_fields = array();

$nationbuilder_fields['first_name'] = array(
	'crm_field' => 'first_name',
	'crm_label'	=> 'First Name'
);

$nationbuilder_fields['last_name'] = array(
	'crm_field' => 'last_name',
	'crm_label'	=> 'Last Name'
);

$nationbuilder_fields['user_email'] = array(
	'crm_field' => 'email',
	'crm_label'	=> 'Email'
);

$nationbuilder_fields['display_name'] = array(
	'crm_field' => 'full_name',
	'crm_label'	=> 'Full Name'
);

$nationbuilder_fields['user_login'] = array(
	'crm_field' => 'username',
	'crm_label'	=> 'Username'
);

$nationbuilder_fields['user_url'] = array(
	'crm_field' => 'website',
	'crm_label'	=> 'Website'
);

$nationbuilder_fields['phone_number'] = array(
	'crm_field' => 'phone',
	'crm_label'	=> 'Phone'
);

$nationbuilder_fields['billing_phone'] = array(
	'crm_field' => 'work_phone_number',
	'crm_label'	=> 'Phone (Work)'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'fax_number',
	'crm_label'	=> 'Phone (Fax)'
);

// Billing

$nationbuilder_fields['billing_address_1'] = array(
	'crm_field' => 'billing_address+address1',
	'crm_label'	=> 'Address 1 (Billing)'
);

$nationbuilder_fields['billing_address_2'] = array(
	'crm_field' => 'billing_address+address2',
	'crm_label'	=> 'Address 2 (Billing)'
);

$nationbuilder_fields['billing_city'] = array(
	'crm_field' => 'billing_address+city',
	'crm_label'	=> 'City (Billing)'
);

$nationbuilder_fields['billing_state'] = array(
	'crm_field' => 'billing_address+state',
	'crm_label'	=> 'State (Billing)'
);

$nationbuilder_fields['billing_postcode'] = array(
	'crm_field' => 'billing_address+zip',
	'crm_label'	=> 'Postcode (Billing)'
);

$nationbuilder_fields['billing_country'] = array(
	'crm_field' => 'billing_address+country_code',
	'crm_label'	=> 'City (Billing)'
);

// Primary Address

$nationbuilder_fields[] = array(
	'crm_field' => 'primary_address+address1',
	'crm_label'	=> 'Address 1 (Primary)'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'primary_address+address2',
	'crm_label'	=> 'Address 2 (Primary)'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'primary_address+city',
	'crm_label'	=> 'City (Primary)'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'primary_address+state',
	'crm_label'	=> 'State (Primary)'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'primary_address+zip',
	'crm_label'	=> 'Postcode (Primary)'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'primary_address+country_code',
	'crm_label'	=> 'City (Primary)'
);

// Home Address

$nationbuilder_fields['shipping_address_1'] = array(
	'crm_field' => 'home_address+address1',
	'crm_label'	=> 'Address 1 (Home)'
);

$nationbuilder_fields['shipping_address_2'] = array(
	'crm_field' => 'home_address+address2',
	'crm_label'	=> 'Address 2 (Home)'
);

$nationbuilder_fields['shipping_city'] = array(
	'crm_field' => 'home_address+city',
	'crm_label'	=> 'City (Home)'
);

$nationbuilder_fields['shipping_state'] = array(
	'crm_field' => 'home_address+state',
	'crm_label'	=> 'State (Home)'
);

$nationbuilder_fields['shipping_postcode'] = array(
	'crm_field' => 'home_address+zip',
	'crm_label'	=> 'Postcode (Home)'
);

$nationbuilder_fields['shipping_country'] = array(
	'crm_field' => 'home_address+country_code',
	'crm_label'	=> 'City (Home)'
);

// Other stuff

$nationbuilder_fields[] = array(
	'crm_field' => 'birthdate',
	'crm_label'	=> 'Birthdate'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'sex',
	'crm_label'	=> 'Sex'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'twitter_name',
	'crm_label'	=> 'Twitter Name'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'bio',
	'crm_label'	=> 'Bio'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'children_count',
	'crm_label'	=> 'Children Count'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'church',
	'crm_label'	=> 'Church'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'language',
	'crm_label'	=> 'Language'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'facebook_username',
	'crm_label'	=> 'Facebook Username'
);

$nationbuilder_fields[] = array(
	'crm_field' => 'facebook_profile_url',
	'crm_label'	=> 'Facebook Profile URL'
);

// Fields to ignore (for now)

$nationbuilder_ignore_fields = array(
	'city_district',
	'civicrm_id',
	'county_district',
	'county_file_id',
	'created_at',
	'datatrust_id',
	'do_not_call',
	'do_not_contact',
	'dw_id',
	'email_opt_in',
	'employer',
	'external_id',
	'federal_district',
	'fire_district',
	'has_facebook',
	'id',
	'is_twitter_follower',
	'is_volunteer',
	'judicial_district',
	'labour_region',
	'linkedin_id',
	'mobile',
	'mobile_opt_in',
	'middle_name',
	'nbec_guid',
	'ngp_id',
	'note',
	'occupation',
	'party',
	'pf_strat_id',
	'precinct_id',
	'primary_address',
	'profile_image_url_ssl',
	'recruiter_id',
	'rnc_id',
	'rnc_regid',
	'salesforce_id',
	'school_district',
	'school_sub_district',
	'signup_type',
	'state_file_id',
	'state_lower_district',
	'state_upper_district',
	'support_level',
	'supranational_district',
	'tags',
	'updated_at',
	'van_id',
	'village_district',
	'ward',
	'active_customer_expires_at',
	'active_customer_started_at',
	'author',
	'author_id',
	'auto_import_id',
	'availability',
	'ballots',
	'banned_at',
	'billing_address',
	'call_status_id',
	'call_status_name',
	'capital_amount_in_cents',
	'city_sub_district',
	'closed_invoices_amount_in_cents',
	'closed_invoices_count',
	'contact_status_id',
	'contact_status_name',
	'could_vote_status',
	'demo',
	'donations_amount_in_cents',
	'donations_amount_this_cycle_in_cents',
	'donations_count',
	'donations_count_this_cycle',
	'donations_pledged_amount_in_cents',
	'donations_raised_amount_in_cents',
	'donations_raised_amount_this_cycle_in_cents',
	'donations_raised_count',
	'donations_raised_count_this_cycle',
	'donations_to_raise_amount_in_cents',
	'email1',
	'email1_is_bad',
	'email2',
	'email2_is_bad',
	'email3',
	'email3_is_bad',
	'email4',
	'email4_is_bad',
	'emails',
	'ethnicity',
	'facebook_address',
	'facebook_updated_at',
	'federal_donotcall',
	'first_donated_at',
	'first_fundraised_at',
	'first_invoice_at',
	'first_prospect_at',
	'first_recruited_at',
	'first_supporter_at',
	'first_volunteer_at',
	'home_address',
	'import_id',
	'inferred_party',
	'inferred_support_level',
	'invoice_payments_amount_in_cents',
	'invoice_payments_referred_amount_in_cents',
	'invoices_amount_in_cents',
	'invoices_count',
	'is_absentee_voter',
	'is_active_voter',
	'is_deceased',
	'is_donor',
	'is_dropped_from_file',
	'is_early_voter',
	'is_fundraiser',
	'is_ignore_donation_limits',
	'is_leaderboardable',
	'is_mobile_bad',
	'is_permanent_absentee_voter',
	'is_possible_duplicate',
	'is_profile_private',
	'is_profile_searchable',
	'is_prospect',
	'is_supporter',
	'is_survey_question_private',
	'last_call_id',
	'last_contacted_at',
	'last_contacted_by',
	'last_donated_at',
	'last_fundraised_at',
	'last_invoice_at',
	'last_rule_violation_at',
	'legal_name',
	'locale',
	'mailing_address',
	'marital_status',
	'media_market_name',
	'meetup_id',
	'meetup_address',
	'mobile_normalized',
	'nbec_precinct_code',
	'nbec_precinct',
	'note_updated_at',
	'outstanding_invoices_amount_in_cents',
	'outstanding_invoices_count',
	'overdue_invoices_count',
	'page_slug',
	'parent',
	'parent_id',
	'party_member',
	'phone_normalized',
	'phone_time',
	'precinct_code',
	'precinct_name',
	'prefix',
	'previous_party',
	'primary_email_id',
	'priority_level',
	'priority_level_changed_at',
	'profile_content',
	'profile_content_html',
	'profile_headline',
	'received_capital_amount_in_cents',
	'recruiter',
	'recruits_count',
	'registered_address',
	'registered_at',
	'religion',
	'rule_violations_count',
	'signup_sources',
	'spent_capital_amount_in_cents',
	'submitted_address',
	'subnations',
	'suffix',
	'support_level_changed_at',
	'support_probability_score',
	'township',
	'turnout_probability_score',
	'twitter_address',
	'twitter_id',
	'twitter_description',
	'twitter_followers_count',
	'twitter_friends_count',
	'twitter_location',
	'twitter_login',
	'twitter_updated_at',
	'twitter_website',
	'unsubscribed_at',
	'user_submitted_address',
	'voter_updated_at',
	'warnings_count',
	'work_address'
);

