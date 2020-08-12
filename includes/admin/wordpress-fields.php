<?php

// Contains default names and types for standard WordPress fields. Can be filtered with wpf_meta_fields
$wp_fields['first_name'] = array(
	'type'  => 'text',
	'label' => __( 'First Name', 'wp-fusion-lite' ),
);

$wp_fields['last_name'] = array(
	'type'  => 'text',
	'label' => __( 'Last Name', 'wp-fusion-lite' ),
);

$wp_fields['user_email'] = array(
	'type'  => 'text',
	'label' => __( 'E-mail Address', 'wp-fusion-lite' ),
);

$wp_fields['display_name'] = array(
	'type'  => 'text',
	'label' => __( 'Profile Display Name', 'wp-fusion-lite' ),
);

$wp_fields['nickname'] = array(
	'type'  => 'text',
	'label' => __( 'Nickname', 'wp-fusion-lite' ),
);

$wp_fields['user_login'] = array(
	'type'  => 'text',
	'label' => __( 'Username', 'wp-fusion-lite' ),
);

$wp_fields['user_id'] = array(
	'type'  => 'integer',
	'label' => __( 'User ID', 'wp-fusion-lite' ),
);

$wp_fields['locale'] = array(
	'type'  => 'text',
	'label' => __( 'Language', 'wp-fusion-lite' ),
);

$wp_fields['role'] = array(
	'type'  => 'text',
	'label' => __( 'User Role', 'wp-fusion-lite' ),
);

$wp_fields['wp_capabilities'] = array(
	'type'  => 'multiselect',
	'label' => __( 'User Capabilities', 'wp-fusion-lite' ),
);

$wp_fields['user_pass'] = array(
	'type'  => 'text',
	'label' => __( 'Password', 'wp-fusion-lite' ),
);

$wp_fields['user_registered'] = array(
	'type'  => 'date',
	'label' => __( 'User Registered', 'wp-fusion-lite' ),
);

$wp_fields['description'] = array(
	'type'  => 'textarea',
	'label' => __( 'Biography', 'wp-fusion-lite' ),
);

$wp_fields['user_url'] = array(
	'type'  => 'text',
	'label' => __( 'Website (URL)', 'wp-fusion-lite' ),
);

$wp_fields['leadsource'] = array(
	'type'  => 'text',
	'label' => __( 'Lead Source', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['utm_campaign'] = array(
	'type'  => 'text',
	'label' => __( 'Google Analytics Campaign', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['utm_source'] = array(
	'type'  => 'text',
	'label' => __( 'Google Analytics Source', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['utm_medium'] = array(
	'type'  => 'text',
	'label' => __( 'Google Analytics Medium', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['utm_term'] = array(
	'type'  => 'text',
	'label' => __( 'Google Analytics Term', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['utm_content'] = array(
	'type'  => 'text',
	'label' => __( 'Google Analytics Content', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['gclid'] = array(
	'type'  => 'text',
	'label' => __( 'Google Click Identifier', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['original_ref'] = array(
	'type'  => 'text',
	'label' => __( 'Original Referrer', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);

$wp_fields['landing_page'] = array(
	'type'  => 'text',
	'label' => __( 'Landing Page', 'wp-fusion-lite' ),
	'group' => 'leadsource',
);
