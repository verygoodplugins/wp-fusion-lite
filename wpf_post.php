<?php

if ( ! isset( $_REQUEST['contactId'] ) ) {
	return;
}

set_time_limit( 0 );
error_reporting( 1 );

$full_path    = getcwd();
$ar           = explode( "wp-", $full_path );
$wp_root_path = $ar[0];

include_once( $wp_root_path . DIRECTORY_SEPARATOR . "wp-config.php" );
//include_once($wp_root_path . DIRECTORY_SEPARATOR . "wp-load.php");
include_once( $wp_root_path . "wp-includes" . DIRECTORY_SEPARATOR . "wp-db.php" );

wp_fusion_process_post();

function wp_fusion_process_post() {

	if ( ! isset( $_REQUEST['action'] ) || $_REQUEST['action'] == 'update' ) {

		$result = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'body' => array(
					'action'     => 'wpf_update_user',
					'contact_id' => $_REQUEST['contactId']
				)
			)
		);

	} elseif ( $_REQUEST['action'] == 'add' ) {

		$wpf_options = get_option( 'wpf_options' );

		// If access key doesn't match
		if ( ! isset( $_REQUEST['access_key'] ) || $_REQUEST['access_key'] != $wpf_options['access_key'] ) {
			echo "Access Key Invalid";

			return;
		}

		if ( isset( $_REQUEST['send_notification'] ) && $_REQUEST['send_notification'] == 'true' ) {
			$send_notification = true;
		} else {
			$send_notification = false;
		}

		$result = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'body' => array(
					'action'            => 'wpf_add_user',
					'contact_id'        => $_REQUEST['contactId'],
					'send_notification' => $send_notification,
					'role'              => $_REQUEST['role']
				)
			)
		);

	}

	wp_die( '', '', array( 'response' => 200 ) );

}