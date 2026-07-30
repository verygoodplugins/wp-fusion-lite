<?php
/**
 * WP Fusion - Kit default field mappings
 *
 * @package WP Fusion
 * @copyright Copyright (c) 2024, Very Good Plugins, https://verygoodplugins.com
 * @license GPL-3.0+
 * @since 3.47.14
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

$kit_fields = array();

$kit_fields['first_name'] = array(
	'crm_label' => 'First Name',
	'crm_field' => 'first_name',
);

$kit_fields['user_email'] = array(
	'crm_label' => 'Email',
	'crm_field' => 'email_address',
);
