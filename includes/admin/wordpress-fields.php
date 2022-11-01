<?php

// Contains default names and types for standard WordPress fields. Can be filtered with wpf_meta_fields.
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

$wp_fields['user_nicename'] = array(
	'type'  => 'text',
	'label' => __( 'Nicename', 'wp-fusion-lite' ),
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
	'type'   => 'integer',
	'label'  => __( 'User ID', 'wp-fusion-lite' ),
	'pseudo' => true,
);

$wp_fields['locale'] = array(
	'type'  => 'text',
	'label' => __( 'Language', 'wp-fusion-lite' ),
);

$wp_fields['role'] = array(
	'type'  => 'text',
	'label' => __( 'User Role', 'wp-fusion-lite' ),
);

// Add the capabilities key. Usually wp_capabilities but sometimes
// different if the table prefix has been changed.

$user = wp_get_current_user();

$wp_fields[ $user->cap_key ] = array(
	'type'  => 'multiselect',
	'label' => __( 'User Capabilities', 'wp-fusion-lite' ),
);

$wp_fields['user_pass'] = array(
	'type'  => 'text',
	'label' => __( 'Password', 'wp-fusion-lite' ),
);

$wp_fields['user_registered'] = array(
	'type'   => 'date',
	'label'  => __( 'User Registered', 'wp-fusion-lite' ),
	'pseudo' => true,
);

$wp_fields['description'] = array(
	'type'  => 'textarea',
	'label' => __( 'Biography', 'wp-fusion-lite' ),
);

$wp_fields['user_url'] = array(
	'type'  => 'text',
	'label' => __( 'Website (URL)', 'wp-fusion-lite' ),
);

$wp_fields['ip'] = array(
	'type'   => 'text',
	'label'  => __( 'IP Address', 'wp-fusion-lite' ),
	'pseudo' => true,
);
