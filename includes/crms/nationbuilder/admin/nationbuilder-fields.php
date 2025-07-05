<?php

$nationbuilder_fields = array();

$nationbuilder_fields['first_name'] = array(
	'crm_field' => 'first_name',
	'crm_label' => 'First Name',
);

$nationbuilder_fields['last_name'] = array(
	'crm_field' => 'last_name',
	'crm_label' => 'Last Name',
);

$nationbuilder_fields['user_email'] = array(
	'crm_field' => 'email',
	'crm_label' => 'Email',
);

$nationbuilder_fields['display_name'] = array(
	'crm_field' => 'full_name',
	'crm_label' => 'Full Name',
);

$nationbuilder_fields['user_login'] = array(
	'crm_field' => 'username',
	'crm_label' => 'Username',
);

$nationbuilder_fields['user_url'] = array(
	'crm_field' => 'website',
	'crm_label' => 'Website',
);

$nationbuilder_fields['phone_number'] = array(
	'crm_field' => 'phone',
	'crm_label' => 'Phone',
);

$nationbuilder_fields['billing_phone'] = array(
	'crm_field' => 'work_phone_number',
	'crm_label' => 'Phone (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'fax_number',
	'crm_label' => 'Phone (Fax)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'mobile',
	'crm_label' => 'Phone (Mobile)',
);

// Billing
$nationbuilder_fields['billing_address_1'] = array(
	'crm_field' => 'billing_address+address1',
	'crm_label' => 'Address 1 (Billing)',
);

$nationbuilder_fields['billing_address_2'] = array(
	'crm_field' => 'billing_address+address2',
	'crm_label' => 'Address 2 (Billing)',
);

$nationbuilder_fields['billing_city'] = array(
	'crm_field' => 'billing_address+city',
	'crm_label' => 'City (Billing)',
);

$nationbuilder_fields['billing_state'] = array(
	'crm_field' => 'billing_address+state',
	'crm_label' => 'State (Billing)',
);

$nationbuilder_fields['billing_postcode'] = array(
	'crm_field' => 'billing_address+zip',
	'crm_label' => 'Postcode (Billing)',
);

$nationbuilder_fields['billing_country'] = array(
	'crm_field' => 'billing_address+country_code',
	'crm_label' => 'Country (Billing)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'billing_address+county',
	'crm_label' => 'County (Billing)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'billing_address+lat',
	'crm_label' => 'Latitude (Billing)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'billing_address+lng',
	'crm_label' => 'Longitude (Billing)',
);

// Registered Address
$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+address1',
	'crm_label' => 'Address 1 (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+address2',
	'crm_label' => 'Address 2 (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+city',
	'crm_label' => 'City (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+state',
	'crm_label' => 'State (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+zip',
	'crm_label' => 'Postcode (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+country_code',
	'crm_label' => 'Country (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+county',
	'crm_label' => 'County (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+lat',
	'crm_label' => 'Latitude (Registered)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'registered_address+lng',
	'crm_label' => 'Longitude (Registered)',
);

// Home Address
$nationbuilder_fields['shipping_address_1'] = array(
	'crm_field' => 'home_address+address1',
	'crm_label' => 'Address 1 (Home / Primary)',
);

$nationbuilder_fields['shipping_address_2'] = array(
	'crm_field' => 'home_address+address2',
	'crm_label' => 'Address 2 (Home / Primary)',
);

$nationbuilder_fields['shipping_city'] = array(
	'crm_field' => 'home_address+city',
	'crm_label' => 'City (Home / Primary)',
);

$nationbuilder_fields['shipping_state'] = array(
	'crm_field' => 'home_address+state',
	'crm_label' => 'State (Home / Primary)',
);

$nationbuilder_fields['shipping_postcode'] = array(
	'crm_field' => 'home_address+zip',
	'crm_label' => 'Postcode (Home / Primary)',
);

$nationbuilder_fields['shipping_country'] = array(
	'crm_field' => 'home_address+country_code',
	'crm_label' => 'Country (Home / Primary)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'home_address+county',
	'crm_label' => 'County (Home)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'home_address+lat',
	'crm_label' => 'Latitude (Home)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'home_address+lng',
	'crm_label' => 'Longitude (Home)',
);

// Work Address
$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+address1',
	'crm_label' => 'Address 1 (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+address2',
	'crm_label' => 'Address 2 (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+city',
	'crm_label' => 'City (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+state',
	'crm_label' => 'State (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+zip',
	'crm_label' => 'Postcode (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+country_code',
	'crm_label' => 'Country (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+county',
	'crm_label' => 'County (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+lat',
	'crm_label' => 'Latitude (Work)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'work_address+lng',
	'crm_label' => 'Longitude (Work)',
);

// Other stuff
$nationbuilder_fields[] = array(
	'crm_field' => 'birthdate',
	'crm_label' => 'Birthdate',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'sex',
	'crm_label' => 'Sex',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'twitter_name',
	'crm_label' => 'Twitter Name',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'bio',
	'crm_label' => 'Bio',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'children_count',
	'crm_label' => 'Children Count',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'church',
	'crm_label' => 'Church',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'language',
	'crm_label' => 'Language',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'facebook_username',
	'crm_label' => 'Facebook Username',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'facebook_profile_url',
	'crm_label' => 'Facebook Profile URL',
);

// Booleans
$nationbuilder_fields[] = array(
	'crm_field' => 'is_absentee_voter',
	'crm_label' => 'Is Absentee Voter?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_active_voter',
	'crm_label' => 'Is Active Voter?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_deceased',
	'crm_label' => 'Is Deceased?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_donor',
	'crm_label' => 'Is Donor?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_dropped_from_file',
	'crm_label' => 'Is Dropped From File?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_early_voter',
	'crm_label' => 'Is Early Voter?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_fundraiser',
	'crm_label' => 'Is Fundraiser?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_ignore_donation_limits',
	'crm_label' => 'Is Ignore Donation Limits?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_leaderboardable',
	'crm_label' => 'Is Leaderboarable?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_mobile_bad',
	'crm_label' => 'Is Mobile Bad?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_permanent_absentee_voter',
	'crm_label' => 'Is Permanent Absentee Voter?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_possible_duplicate',
	'crm_label' => 'Is Possible Duplicate?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_profile_private',
	'crm_label' => 'Is Profile Private?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_profile_searchable',
	'crm_label' => 'Is Profile Searchable?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_prospect',
	'crm_label' => 'Is Prospect?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_supporter',
	'crm_label' => 'Is Supporter?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_survey_question_private',
	'crm_label' => 'Is Survey Question Private?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'is_volunteer',
	'crm_label' => 'Is Volunteer?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'party_member',
	'crm_label' => 'Is Party Member?',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'occupation',
	'crm_label' => 'Occupation',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'employer',
	'crm_label' => 'Employer',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'signup_type',
	'crm_label' => 'Signup Type',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'email_opt_in',
	'crm_label' => 'Email Opt-in (boolean)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'external_id',
	'crm_label' => 'External ID',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'marital_status',
	'crm_label' => 'Marital Status',
);

/* Voting district */

$nationbuilder_fields[] = array(
	'crm_field' => 'city_district',
	'crm_label' => 'City District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'city_sub_district',
	'crm_label' => 'City Sub District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'county_district',
	'crm_label' => 'County District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'federal_district',
	'crm_label' => 'Federal District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'fire_district',
	'crm_label' => 'Fire District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'judicial_district',
	'crm_label' => 'Judicial District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'school_district',
	'crm_label' => 'Shool District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'shool_sub_district',
	'crm_label' => 'School Sub District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'state_lower_district',
	'crm_label' => 'State Lower District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'state_upper_district',
	'crm_label' => 'State Upper District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'supranational_district',
	'crm_label' => 'Supranational District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'village_district',
	'crm_label' => 'Village District (Voting)',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'availability',
	'crm_label' => 'Availability',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'support_level',
	'crm_label' => 'Support Level',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'inferred_support_level',
	'crm_label' => 'Inferred Support Level',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'priority_level',
	'crm_label' => 'Priority Level',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'do_not_call',
	'crm_label' => 'Do Not Call',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'mobile_opt_in',
	'crm_label' => 'Mobile Opt-In',
);

$nationbuilder_fields[] = array(
	'crm_field' => 'do_not_contact',
	'crm_label' => 'Do Not Contact',
);


$nationbuilder_fields[] = array(
	'crm_field' => 'recruiter_id',
	'crm_label' => 'Recruiter ID',
);

// Fields to ignore (for now)
$nationbuilder_ignore_fields = array(
	'civicrm_id',
	'county_file_id',
	'created_at',
	'datatrust_id',
	'dw_id',
	'has_facebook',
	'id',
	'is_twitter_follower',
	'labour_region',
	'linkedin_id',
	'mobile',
	'middle_name',
	'nbec_guid',
	'ngp_id',
	'note',
	'party',
	'pf_strat_id',
	'precinct_id',
	'primary_address',
	'profile_image_url_ssl',
	'rnc_id',
	'rnc_regid',
	'salesforce_id',
	'state_file_id',
	'tags',
	'updated_at',
	'van_id',
	'ward',
	'active_customer_expires_at',
	'active_customer_started_at',
	'author',
	'author_id',
	'auto_import_id',
	'ballots',
	'banned_at',
	'billing_address',
	'call_status_id',
	'call_status_name',
	'capital_amount_in_cents',
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
	'invoice_payments_amount_in_cents',
	'invoice_payments_referred_amount_in_cents',
	'invoices_amount_in_cents',
	'invoices_count',
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
	'phone_normalized',
	'phone_time',
	'precinct_code',
	'precinct_name',
	'prefix',
	'previous_party',
	'primary_email_id',
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
	'work_address',
);
