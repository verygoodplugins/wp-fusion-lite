<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handles the upsells and other stuff specific to WP Fusion Lite.
 *
 * @since 3.37.17
 */
class WPF_Lite_Helper {

	public function __construct() {

		add_action( 'wp_fusion_init', array( $this, 'init' ) );

	}


	/**
	 * Add everything on init so it can be disabled in the theme if needed.
	 *
	 * @since  3.37.17
	 */

	public function init() {

		if ( apply_filters( 'wp_fusion_hide_upgrade_nags', false ) ) {
			return;
		}

		add_filter( 'wpf_configure_settings', array( $this, 'configure_settings' ) );
		add_filter( 'wpf_meta_field_groups', array( $this, 'add_meta_field_group' ) );

		if ( class_exists( 'WooCommerce' ) ) {
			add_filter( 'wpf_meta_fields', array( $this, 'meta_fields_woocommerce' ) );
			add_action( 'woocommerce_product_write_panel_tabs', array( $this, 'woocommerce_write_panel_tabs' ) );
			add_action( 'woocommerce_product_data_panels', array( $this, 'woocommerce_write_panels' ) );
		}

		if ( class_exists( 'BuddyPress' ) ) {
			add_filter( 'wpf_meta_fields', array( $this, 'meta_fields_buddypress' ) );
		}

		if ( class_exists( 'Affiliate_WP' ) ) {
			add_filter( 'wpf_meta_fields', array( $this, 'meta_fields_affiliatewp' ) );
		}

		add_action( 'show_field_contact_fields_end', array( $this, 'contact_fields_upgrade_message' ), 10, 2 );

		add_action( 'wpf_settings_page_title', array( $this, 'title_upgrade_message' ) );

	}

	/**
	 * These settings don't do anything in WPF Lite so we'll hide them.
	 *
	 * @since  3.37.17
	 *
	 * @param  array $settings The settings.
	 * @return array  Settings.
	 */

	public function configure_settings( $settings ) {

		$pro_settings = array(
			'access_key_desc',
			'access_key',
			'webhook_url',
			'test_webhooks',
			'license_heading',
			'license_key',
			'license_status',
		);

		foreach ( $pro_settings as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				unset( $settings[ $key ] );
			}
		}

		// Add some promo HTML

		$webhooks_desc  = '<div id="webhooks-notice" class="wpf-upgrade-nag-container">';
		$webhooks_desc .= $this->get_sync_svg();
		$webhooks_desc .= '<div class="innercontent">';
		$webhooks_desc .= '<h3>Webhooks allow for an automatic, bi-directional integration with ' . wp_fusion()->crm->name . '.</h3>';
		$webhooks_desc .= '<ul>';
		$webhooks_desc .= '<li>Import new WordPress users based on ' . wp_fusion()->crm->name . ' automations and generate passwords.</li>';
		$webhooks_desc .= '<li>Sync ' . strtolower( wp_fusion()->settings->get( 'crm_tag_type' ) ) . ' changes in ' . wp_fusion()->crm->name . ' back to WordPress to unlock content, trigger course enrollments, and change membership levels.</li>';
		$webhooks_desc .= '<li>Edits to contact records in ' . wp_fusion()->crm->name . ' are synced back to WordPress instantly.</li>';
		$webhooks_desc .= '</ul>';
		$webhooks_desc .= '<strong>Upgrade to the full version of WP Fusion to enable ' . wp_fusion()->crm->name . ' webhooks.</strong>';

		$webhooks_desc .= '<div class="buttonwrapper">';
		$webhooks_desc .= '<a style="margin-left: 0px" class="button-primary" href="https://wpfusion.com/documentation/?utm_source=free-plugin&utm_medium=webhooks&utm_campaign=free-plugin#webhooks" target="_blank">Learn More About Webhooks</a>';
		$webhooks_desc .= ' <span class="orange">or</span> <a class="button-primary" href="https://wpfusion.com/pricing/?utm_source=free-plugin&utm_medium=webhooks&utm_campaign=free-plugin" target="_blank">View Pricing</a>';
		$webhooks_desc .= '</div></div></div>';

		$new_setting = array(
			'webhooks_lite_notice' => array(
				'type'    => 'paragraph',
				'desc'    => $webhooks_desc,
				'section' => 'main',
			),
		);

		$settings = wp_fusion()->settings->insert_setting_after( 'access_key_header', $settings, $new_setting );

		return $settings;

	}

	/**
	 * Show meta field groups for detected plugins.
	 *
	 * @since  3.37.17
	 *
	 * @param  array $field_groups The field groups.
	 * @return array  Field groups.
	 */

	public function add_meta_field_group( $field_groups ) {

		if ( class_exists( 'WooCommerce' ) ) {

			$field_groups['woocommerce'] = array(
				'title'    => 'WooCommerce Customer',
				'fields'   => array(),
				'disabled' => true,
			);

			$field_groups['woocommerce_order'] = array(
				'title'    => 'WooCommerce Order',
				'fields'   => array(),
				'disabled' => true,
			);

		}

		if ( class_exists( 'BuddyPress' ) ) {

			$field_groups['buddypress'] = array(
				'title'    => 'BuddyPress',
				'fields'   => array(),
				'disabled' => true,
			);

		}

		if ( class_exists( 'AffiliateWP' ) ) {

			$field_groups['awp']          = array(
				'title'    => 'Affiliate WP - Affiliate',
				'fields'   => array(),
				'disabled' => true,
			);
			$field_groups['awp_referrer'] = array(
				'title'    => 'Affiliate WP - Referrer',
				'fields'   => array(),
				'disabled' => true,
			);

		}

		return $field_groups;

	}

	/**
	 * Show meta fields (WooCommerce)
	 *
	 * @since 3.37.17
	 *
	 * @param array $meta_fields The fields.
	 * @return array Fields
	 */
	public function meta_fields_woocommerce( $meta_fields ) {

		$woocommerce_fields = WC()->countries->get_address_fields( '', 'billing_' );

		// Cleanup
		unset( $woocommerce_fields['billing_first_name'] );
		unset( $woocommerce_fields['billing_last_name'] );
		unset( $woocommerce_fields['billing_email'] );

		// Support for WooCommerce Checkout Field Editor
		$additional_fields = get_option( 'wc_fields_additional' );

		if ( ! empty( $additional_fields ) ) {
			$woocommerce_fields = array_merge( $woocommerce_fields, $additional_fields );
		}

		foreach ( $woocommerce_fields as $key => $data ) {

			if ( ! isset( $meta_fields[ $key ] ) && ! wpf_is_field_active( $key ) ) {

				$meta_fields[ $key ] = array(
					'label' => isset( $data['label'] ) ? $data['label'] : '',
					'type'  => isset( $data['type'] ) ? $data['type'] : 'text',
					'group' => 'woocommerce',
				);

			}

		}

		// Support for WooCommerce Checkout Field Editor Pro
		if ( class_exists( 'WCFE_Checkout_Section' ) ) {

			$additional_fields = get_option( 'thwcfe_sections' );

			if ( ! empty( $additional_fields ) ) {

				foreach ( $additional_fields as $section ) {

					if ( ! empty( $section->fields ) ) {

						foreach ( $section->fields as $field ) {

							if ( ! isset( $meta_fields[ $field->id ] ) ) {

								$meta_fields[ $field->id ] = array(
									'label' => $field->title,
									'type'  => $field->type,
									'group' => 'woocommerce',
								);

							}
						}
					}
				}
			}
		}

		$meta_fields['generated_password'] = array(
			'label' => 'Generated Password',
			'type'  => 'text',
			'group' => 'woocommerce',
		);

		$meta_fields['order_date'] = array(
			'label' => 'Last Order Date',
			'type'  => 'date',
			'group' => 'woocommerce_order',
		);

		$meta_fields['coupon_code'] = array(
			'label' => 'Last Coupon Used',
			'type'  => 'text',
			'group' => 'woocommerce_order',
		);

		$meta_fields['order_id'] = array(
			'label' => 'Last Order ID',
			'type'  => 'int',
			'group' => 'woocommerce_order',
		);

		$meta_fields['order_total'] = array(
			'label' => 'Last Order Total',
			'type'  => 'int',
			'group' => 'woocommerce_order',
		);

		$meta_fields['order_payment_method'] = array(
			'label' => 'Last Order Payment Method',
			'type'  => 'text',
			'group' => 'woocommerce_order',
		);

		return $meta_fields;

	}


	/**
	 * Adds tabs to left side of Woo product editor panel
	 *
	 * @since 3.37.17
	 */
	public function woocommerce_write_panel_tabs() {

		echo '<li class="custom_tab wp-fusion-settings-tab hide_if_grouped">';
		echo '<a href="#wp_fusion_tab">';
		echo wpf_logo_svg( '14px' );
		echo '<span>' . __( 'WP Fusion', 'wp-fusion-lite' ) . '</span>';
		echo '</a>';
		echo '</li>';

	}

	/**
	 * Display Woo settings panel.
	 *
	 * @since 3.37.17
	 */
	public function woocommerce_write_panels() {

		if ( ! is_admin() ) {
			return; // YITH WooCommerce Frontend Manager adds these panels to the frontend, which crashes WPF
		}

		echo '<div id="wp_fusion_tab" class="panel woocommerce_options_panel wpf-meta wpf-panel-disabled">';

		// Product

		echo '<div class="options_group wpf-product">';

		echo '<p class="form-field"><label><strong>Product</strong></label></p>';

		echo '<p class="form-field"><label for="wpf-apply-tags-woo">' . __( 'Apply tags when<br />purchased', 'wp-fusion-lite' ) . '</label>';
		wpf_render_tag_multiselect();

		echo '<br /><span style="margin-left: 0px;" class="description show_if_variable">Tags for product variations can be configured within the Variations tab.</span>';

		echo '</p>';

		echo '<p class="form-field"><label for="wpf-apply-tags-woo">' . __( 'Apply tags when<br />refunded', 'wp-fusion-lite' );
		echo ' <span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="' . __( 'The tags specified above for \'Apply tags when purchased\' will automatically be removed if an order is refunded.', 'wp-fusion-lite' ) . '"></span>';
		echo '</label>';

		wpf_render_tag_multiselect();

		echo '</p>';

		echo '<p class="form-field"><label for="wpf-apply-tags-woo">' . __( 'Apply tags when transaction failed', 'wp-fusion-lite' );
		echo ' <span class="dashicons dashicons-editor-help wpf-tip wpf-tip-bottom" data-tip="' . __( 'A contact record will be created and these tags will be applied when an initial transaction on an order fails.<br /><br />Note that this may create problems since WP Fusion normally doesn\'t create a contact record until a successful payment is received.<br /><br />In almost all cases it\'s preferable to use abandoned cart tracking instead of failed transaction tagging.', 'wp-fusion-lite' ) . '"></span>';
		echo '</label>';

		wpf_render_tag_multiselect();

		echo '</p>';

		echo '</div>';

		if ( class_exists( 'WC_Subscriptions' ) ) {

			// Subscription

			echo '<div class="options_group">';

			echo '<p class="form-field"><label><strong>Subscription</strong></label></p>';

			echo '<p class="form-field"><label for="wpf-apply-tags-woo">' . __( 'Remove tags', 'wp-fusion-lite' ) . '</label>';
			echo '<input class="checkbox" type="checkbox" id="wpf-remove-tags-woo" name="wpf-settings-woo[remove_tags]" value="1" />';
			echo '<span class="description">' . __( 'Remove original tags (above) when the subscription is cancelled, put on hold, expires, or is switched', 'wp-fusion-lite' ) . '.</span>';
			echo '</p>';

			// Payment failed
			echo '<p class="form-field"><label>Payment failed</label>';
			wpf_render_tag_multiselect();
			echo '<span class="description">' . __( 'Apply these tags when a renewal payment fails', 'wp-fusion-lite' ) . '.</span>';
			echo '</p>';

			// Cancelled
			echo '<p class="form-field"><label>Cancelled</label>';
			wpf_render_tag_multiselect();
			echo '<span class="description">' . __( 'Apply these tags when a subscription is cancelled', 'wp-fusion-lite' ) . '.</span>';
			echo '</p>';

			// Put on hold
			echo '<p class="form-field"><label>Put on hold</label>';
			wpf_render_tag_multiselect();
			echo '<span class="description">' . __( 'Apply these tags when a subscription is put on hold', 'wp-fusion-lite' ) . '.</span>';
			echo '</p>';

			// Expires
			echo '<p class="form-field"><label>Pending cancellation</label>';
			wpf_render_tag_multiselect();
			echo '<span class="description">' . __( 'Apply these tags when a subscription has been cancelled by the user but there is still time remaining in the subscription', 'wp-fusion-lite' ) . '.</span>';
			echo '</p>';

			// Expires
			echo '<p class="form-field"><label>Expired</label>';
			wpf_render_tag_multiselect();
			echo '<span class="description">' . __( 'Apply these tags when a subscription expires', 'wp-fusion-lite' ) . '.</span>';
			echo '</p>';

			echo '<p class="form-field"><label>Free trial over</label>';
			wpf_render_tag_multiselect();
			echo '<span class="description">' . __( 'Apply these tags when free trial ends', 'wp-fusion-lite' ) . '.</span>';
			echo '</p>';

			echo '</div>';

		}

		echo '<div class="wpf-upgrade-nag-container">';

		echo $this->get_sync_svg();

		echo '<div class="innercontent">';

		echo '<h3>' . sprintf( __( 'Segment customers using tags in %s.' ), wp_fusion()->crm->name ) . '</h3>';

		echo '<p>' . sprintf( __( 'With the full version of WP Fusion you can apply tags in %s based on product purchases, variations, coupon usage, order status changes, and more.' ), wp_fusion()->crm->name ) . '</p>';

		if ( class_exists( 'WC_Subscriptions' ) ) {

			echo '<p>' . sprintf( __( 'With WooCommerce Subscriptions active you can also %1$sapply tags based on subscription status changes and payment failures%2$s.' ), '<a href="https://wpfusion.com/documentation/ecommerce/woocommerce-subscriptions/?utm_source=free-plugin&utm_medium=woocommerce&utm_campaign=free-plugin" target="_blank">', '</a>' ) . '</p>';

		}

		echo '<p>' . sprintf( __( '<strong>Upgrade WP Fusion today</strong> and automate your WooCommerce marketing with %s.' ), wp_fusion()->crm->name ) . '</p>';

		echo '<div class="buttonwrapper">';
		echo '<a style="margin-left: 0px" class="button-primary" href="https://wpfusion.com/documentation/ecommerce/woocommerce/?utm_source=free-plugin&utm_medium=woocommerce&utm_campaign=free-plugin" target="_blank">Learn More</a>';
		echo ' <span class="orange">or</span> <a class="button-primary" href="https://wpfusion.com/pricing/?utm_source=free-plugin&utm_medium=woocommerce&utm_campaign=free-plugin" target="_blank">View Pricing</a>';
		echo '</div>';

		echo '</div></div>';

		echo '</div>';

	}

	/**
	 * Show meta fields (BuddyPress)
	 *
	 * @since 3.37.17
	 *
	 * @param array $meta_fields The fields.
	 * @return array Fields
	 */
	public function meta_fields_buddypress( $meta_fields ) {

		$meta_fields['bbp_profile_type'] = array(
			'label' => 'Profile Type',
			'type'  => 'text',
			'group' => 'buddypress',
		);

		if ( ! class_exists( 'BP_XProfile_Data_Template' ) ) {
			return $meta_fields;
		}

		$groups = bp_xprofile_get_groups(
			array(
				'fetch_fields' => true,
			)
		);

		$meta_fields['bbp_avatar'] = array(
			'label' => 'Avatar URL',
			'type'  => 'text',
			'group' => 'buddypress',
		);

		foreach ( $groups as $group ) {

			if ( ! empty( $group->fields ) ) {

				foreach ( $group->fields as $field ) {

					if ( $field->type == 'checkbox' ) {
						$type = 'multiselect';
					} elseif ( $field->type == 'multiselect_custom_taxonomy' ) {
						$type = 'multiselect';
					} elseif ( $field->type == 'multiselectbox' ) {
						$type = 'multiselect';
					} elseif ( $field->type == 'datebox' ) {
						$type = 'date';
					} else {
						$type = 'text';
					}

					$meta_fields[ 'bbp_field_' . $field->id ] = array(
						'label' => $field->name,
						'type'  => $type,
						'group' => 'buddypress',
					);

				}
			}
		}

		return $meta_fields;

	}

	/**
	 * Show meta fields (AffiliateWP)
	 *
	 * @since 3.37.17
	 *
	 * @param array $meta_fields The fields.
	 * @return array Fields
	 */
	public function meta_fields_affiliatewp( $meta_fields ) {

		// Affiliate
		$meta_fields['awp_affiliate_id'] = array(
			'label'  => 'Affiliate\'s Affiliate ID',
			'type'   => 'text',
			'group'  => 'awp',
			'pseudo' => true,
		);

		$meta_fields['awp_referral_rate'] = array(
			'label'  => 'Affiliate\'s Referral Rate',
			'type'   => 'text',
			'group'  => 'awp',
			'pseudo' => true,
		);

		$meta_fields['awp_payment_email'] = array(
			'label'  => 'Affiliate\'s Payment Email',
			'type'   => 'text',
			'group'  => 'awp',
			'pseudo' => true,
		);

		$meta_fields['affwp_user_url'] = array(
			'label'  => 'Affiliate\'s Website URL',
			'type'   => 'text',
			'group'  => 'awp',
			'pseudo' => true,
		);

		$meta_fields['affwp_promotion_method'] = array(
			'label'  => 'Affiliate\'s Promotion Method',
			'type'   => 'text',
			'group'  => 'awp',
			'pseudo' => true,
		);

		$meta_fields['affwp_total_earnings'] = array(
			'label'  => 'Affiliate\'s Total Earnings',
			'type'   => 'int',
			'group'  => 'awp',
			'pseudo' => true,
		);

		$meta_fields['affwp_referral_count'] = array(
			'label'  => 'Affiliate\'s Total Referrals',
			'type'   => 'int',
			'group'  => 'awp',
			'pseudo' => true,
		);

		// Groups
		if ( class_exists( 'AffiliateWP_Affiliate_Groups' ) ) {

			$meta_fields['affiliate_groups'] = array(
				'label'  => 'Affiliate\'s Groups',
				'type'   => 'multiselect',
				'group'  => 'awp',
				'pseudo' => true,
			);

		}

		// Custom slugs
		if ( class_exists( 'AffiliateWP_Custom_Affiliate_Slugs' ) ) {

			$meta_fields['custom_slug'] = array(
				'label'  => 'Affiliate\'s Custom Slug',
				'type'   => 'text',
				'group'  => 'awp',
				'pseudo' => true,
			);

		}

		// Landing pages

		if ( class_exists( 'AffiliateWP_Affiliate_Landing_Pages' ) ) {

			$meta_fields['affwp_landing_page'] = array(
				'label'  => 'Affiliate\'s Landing Page',
				'type'   => 'text',
				'group'  => 'awp',
				'pseudo' => true,
			);

		}

		// Referrer
		$meta_fields['awp_referrer_id'] = array(
			'label'  => 'Referrer\'s Affiliate ID',
			'type'   => 'text',
			'group'  => 'awp_referrer',
			'pseudo' => true,
		);

		$meta_fields['awp_referrer_first_name'] = array(
			'label'  => 'Referrer\'s First Name',
			'type'   => 'text',
			'group'  => 'awp_referrer',
			'pseudo' => true,
		);

		$meta_fields['awp_referrer_last_name'] = array(
			'label'  => 'Referrer\'s Last Name',
			'type'   => 'text',
			'group'  => 'awp_referrer',
			'pseudo' => true,
		);

		$meta_fields['awp_referrer_email'] = array(
			'label'  => 'Referrer\'s Email',
			'type'   => 'text',
			'group'  => 'awp_referrer',
			'pseudo' => true,
		);

		$meta_fields['awp_referrer_username'] = array(
			'label'  => 'Referrer\'s Username',
			'type'   => 'text',
			'group'  => 'awp_referrer',
			'pseudo' => true,
		);

		$meta_fields['awp_referrer_url'] = array(
			'label'  => 'Referrer\'s Website URL',
			'type'   => 'text',
			'group'  => 'awp_referrer',
			'pseudo' => true,
		);

		return $meta_fields;

	}

	/**
	 * Show upgrade notice on Contact Fields list.
	 *
	 * @since 3.37.17
	 *
	 * @param string $id     The field ID.
	 * @param array  $field  The field details.
	 * @return mixed HTML output.
	 */
	public function contact_fields_upgrade_message( $id, $field ) {

		echo '<div id="contact-fields-pro-notice">';

		echo '<div class="wpf-upgrade-nag-container">';

		echo $this->get_sync_svg();

		echo '<div class="innercontent">';

		echo '<h2>Sync more data with ' . wp_fusion()->crm->name . '.</h2>';

		echo '<p>You\'re currently using <strong>WP Fusion Lite</strong>, which syncs your WordPress "core" fields with your CRM.</p>';

		echo '<p>Did you know that the full version of WP Fusion supports syncing data from ';

		if ( class_exists( 'WooCommerce' ) ) {
			echo '<a href="https://wpfusion.com/documentation/ecommerce/woocommerce/?utm_campaign=free-plugin&utm_source=free-plugin&utm-medium=contact-fields" target="_blank">WooCommerce</a>, ';
		}

		if ( class_exists( 'BuddyPress' ) ) {
			echo '<a href="https://wpfusion.com/documentation/membership/buddypress/?utm_campaign=free-plugin&utm_source=free-plugin&utm-medium=contact-fields" target="_blank">BuddyPress</a>, ';
		}

		if ( class_exists( 'Elementor\\Frontend' ) ) {
			echo '<a href="https://wpfusion.com/documentation/page-builders/elementor/?utm_campaign=free-plugin&utm_source=free-plugin&utm-medium=contact-fields" target="_blank">Elementor</a>, ';
		}

		if ( class_exists( 'SFWD_LMS' ) ) {
			echo '<a href="https://wpfusion.com/documentation/learning-management/learndash/?utm_campaign=free-plugin&utm_source=free-plugin&utm-medium=contact-fields" target="_blank">LearnDash</a>, ';
		}

		if ( class_exists( 'Affiliate_WP' ) ) {
			echo '<a href="https://wpfusion.com/documentation/other/affiliate-wp/?utm_campaign=free-plugin&utm_source=free-plugin&utm-medium=contact-fields" target="_blank">AffiliateWP</a>, ';
		}

		if ( class_exists( 'LifterLMS' ) ) {
			echo '<a href="https://wpfusion.com/documentation/learning-management/lifterlms/?utm_campaign=free-plugin&utm_source=free-plugin&utm-medium=contact-fields" target="_blank">LifterLMS</a>, ';
		}

		echo 'and <a href="https://wpfusion.com/documentation/?utm_campaign=free-plugin&utm_source=free-plugin&utm-medium=contact-fields#integrations" target="_blank">over 100 other plugins</a> bidirectionally with ' . wp_fusion()->crm->name . '</strong>?</p>';

		echo '<div class="buttonwrapper">';
		echo '<a style="margin-left: 0px" class="button-primary" href="https://wpfusion.com/documentation/getting-started/syncing-contact-fields/?utm_source=free-plugin&utm_medium=contact-fields&utm_campaign=free-plugin" target="_blank">Learn More About Syncing Custom Fields</a>';
		echo ' <span class="orange">or</span> <a class="button-primary" href="https://wpfusion.com/pricing/?utm_source=free-plugin&utm_medium=contact-fields&utm_campaign=free-plugin" target="_blank">View Pricing</a>';
		echo '</div>';

		echo '</div></div></div>';

	}

	/**
	 * Gets the SVG for the webhooks section and contact fields list.
	 *
	 * @since  3.37.17
	 *
	 * @return string The SVG code.
	 */
	public function get_sync_svg() {

		return '<svg width="100%" viewBox="0 0 572 475" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs> <path d="M418.424293,158.01353 C456.552553,193.252559 463.5789,245.737807 445.496391,290.458524 C427.413883,335.104582 384.222521,372.135426 326.66848,394.981067 C269.11444,417.826709 197.30105,426.561807 144.810112,407.74775 C92.3191737,388.859033 59.3573445,342.42116 44.8913379,293.743518 C30.3220027,245.065877 34.3518188,194.073807 70.310178,159.208073 C106.268537,124.26768 174.052111,105.378963 242.662313,105.005669 C311.169187,104.632374 380.399362,122.699842 418.424293,158.01353 Z" id="path-1"></path> <rect id="path-3" x="0" y="0" width="258" height="98" rx="20"></rect> <filter x="-35.3%" y="-79.6%" width="170.5%" height="308.2%" filterUnits="objectBoundingBox" id="filter-4"> <feOffset dx="0" dy="35" in="SourceAlpha" result="shadowOffsetOuter1"></feOffset> <feGaussianBlur stdDeviation="24.5" in="shadowOffsetOuter1" result="shadowBlurOuter1"></feGaussianBlur> <feColorMatrix values="0 0 0 0 0.898039216   0 0 0 0 0.356862745   0 0 0 0 0.062745098  0 0 0 0.1 0" type="matrix" in="shadowBlurOuter1" result="shadowMatrixOuter1"></feColorMatrix> <feOffset dx="0" dy="2" in="SourceAlpha" result="shadowOffsetOuter2"></feOffset> <feGaussianBlur stdDeviation="19" in="shadowOffsetOuter2" result="shadowBlurOuter2"></feGaussianBlur> <feColorMatrix values="0 0 0 0 0.898039216   0 0 0 0 0.356862745   0 0 0 0 0.062745098  0 0 0 0.1 0" type="matrix" in="shadowBlurOuter2" result="shadowMatrixOuter2"></feColorMatrix> <feMerge> <feMergeNode in="shadowMatrixOuter1"></feMergeNode> <feMergeNode in="shadowMatrixOuter2"></feMergeNode> </feMerge> </filter> <rect id="path-5" x="0" y="0" width="258" height="98" rx="20"></rect> <filter x="-35.3%" y="-79.6%" width="170.5%" height="308.2%" filterUnits="objectBoundingBox" id="filter-6"> <feOffset dx="0" dy="35" in="SourceAlpha" result="shadowOffsetOuter1"></feOffset> <feGaussianBlur stdDeviation="24.5" in="shadowOffsetOuter1" result="shadowBlurOuter1"></feGaussianBlur> <feColorMatrix values="0 0 0 0 0.898039216   0 0 0 0 0.356862745   0 0 0 0 0.062745098  0 0 0 0.1 0" type="matrix" in="shadowBlurOuter1" result="shadowMatrixOuter1"></feColorMatrix> <feOffset dx="0" dy="2" in="SourceAlpha" result="shadowOffsetOuter2"></feOffset> <feGaussianBlur stdDeviation="19" in="shadowOffsetOuter2" result="shadowBlurOuter2"></feGaussianBlur> <feColorMatrix values="0 0 0 0 0.898039216   0 0 0 0 0.356862745   0 0 0 0 0.062745098  0 0 0 0.1 0" type="matrix" in="shadowBlurOuter2" result="shadowMatrixOuter2"></feColorMatrix> <feMerge> <feMergeNode in="shadowMatrixOuter1"></feMergeNode> <feMergeNode in="shadowMatrixOuter2"></feMergeNode> </feMerge> </filter> <circle id="path-7" cx="40" cy="40" r="40"></circle> <filter x="-131.2%" y="-131.2%" width="362.5%" height="362.5%" filterUnits="objectBoundingBox" id="filter-8"> <feOffset dx="0" dy="0" in="SourceAlpha" result="shadowOffsetOuter1"></feOffset> <feGaussianBlur stdDeviation="35" in="shadowOffsetOuter1" result="shadowBlurOuter1"></feGaussianBlur> <feColorMatrix values="0 0 0 0 0.898039216   0 0 0 0 0.356862745   0 0 0 0 0.062745098  0 0 0 0.5 0" type="matrix" in="shadowBlurOuter1"></feColorMatrix> </filter> </defs> <g id="Connect-X-to-Y" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd"> <g id="Connect-X-to-Y-with-Extra-v2" transform="translate(-718.000000, -809.000000)"> <g id="Content" transform="translate(196.000000, 817.000000)"> <g id="Connect-X-to-Y-Example" transform="translate(571.000000, 0.000000)"> <g id="blob-shape-(3)" transform="translate(66.000000, 0.000000)"> <mask id="mask-2" fill="white"> <use xlink:href="#path-1"></use> </mask> <use id="Path" fill-opacity="0.557528409" fill="#FFE5D6" fill-rule="nonzero" transform="translate(246.000000, 261.500000) rotate(123.000000) translate(-246.000000, -261.500000) " xlink:href="#path-1"></use> </g> <g id="Card" transform="translate(0.000000, 271.000000)"> <g id="BG-Copy-2" fill-rule="nonzero"> <use fill="black" fill-opacity="1" filter="url(#filter-4)" xlink:href="#path-3"></use> <use fill="#FFFFFF" xlink:href="#path-3"></use> </g> <g id="explore-user" transform="translate(32.000000, 27.000000)" fill="#FFE5D6" stroke="#E55B10" stroke-linecap="round" stroke-linejoin="round" stroke-width="3"> <path d="M43.172,16 C43.711,17.907 44,19.92 44,22 C44,34.15 34.15,44 22,44 C9.85,44 0,34.15 0,22 C0,9.85 9.85,0 22,0 C26.651,0 31.018,1.475 34.572,3.938" id="Path"></path> <circle id="Oval" cx="38" cy="6" r="4"></circle> <path d="M31,32 L13,32 L13,29.758 C13,27.983 14.164,26.424 15.866,25.92 C17.46,25.448 19.604,25 22,25 C24.356,25 26.514,25.456 28.125,25.932 C29.83,26.436 31,27.994 31,29.773 L31,32 Z" id="Path"></path> <path d="M17,16 C17,13.239 19.239,11 22,11 C24.761,11 27,13.239 27,16 C27,18.761 24.761,22 22,22 C19.239,22 17,18.761 17,16 Z" id="Path"></path> </g> <rect id="Rectangle-Copy-16" fill="#FFE5D6" x="95" y="32" width="128" height="8" rx="4"></rect> <rect id="Rectangle-Copy-17" fill="#FFE5D6" x="95" y="55" width="88" height="8" rx="4"></rect> <polyline id="Path" stroke="#FFE5D6" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" points="196 56.8 199.2 60 207.2 52"></polyline> </g> <g id="Card" transform="translate(216.000000, 28.000000)"> <g id="BG-Copy-2" fill-rule="nonzero"> <use fill="black" fill-opacity="1" filter="url(#filter-6)" xlink:href="#path-5"></use> <use fill="#FFFFFF" xlink:href="#path-5"></use> </g> <g id="explore-user" transform="translate(32.000000, 27.000000)" fill="#FFE5D6" stroke="#E55B10" stroke-linecap="round" stroke-linejoin="round" stroke-width="3"> <path d="M43.172,16 C43.711,17.907 44,19.92 44,22 C44,34.15 34.15,44 22,44 C9.85,44 0,34.15 0,22 C0,9.85 9.85,0 22,0 C26.651,0 31.018,1.475 34.572,3.938" id="Path"></path> <circle id="Oval" cx="38" cy="6" r="4"></circle> <path d="M31,32 L13,32 L13,29.758 C13,27.983 14.164,26.424 15.866,25.92 C17.46,25.448 19.604,25 22,25 C24.356,25 26.514,25.456 28.125,25.932 C29.83,26.436 31,27.994 31,29.773 L31,32 Z" id="Path"></path> <path d="M17,16 C17,13.239 19.239,11 22,11 C24.761,11 27,13.239 27,16 C27,18.761 24.761,22 22,22 C19.239,22 17,18.761 17,16 Z" id="Path"></path> </g> <rect id="Rectangle-Copy-16" fill="#FFE5D6" x="95" y="32" width="128" height="8" rx="4"></rect> <rect id="Rectangle-Copy-17" fill="#FFE5D6" x="95" y="55" width="88" height="8" rx="4"></rect> <polyline id="Path" stroke="#FFE5D6" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" points="196 57.8 199.2 61 207.2 53"></polyline> </g> <g id="Lines" transform="translate(176.500000, 303.000000) scale(-1, 1) translate(-176.500000, -303.000000) translate(54.000000, 83.000000)" stroke="#E55B10" stroke-dasharray="2,14" stroke-linecap="round" stroke-linejoin="round" stroke-width="4"> <path d="M29,32 L29,69 C29,98.2710917 52.7289083,122 82,122 L135,122 L190,122 C220.375661,122 245,146.624339 245,177 L245,205 L245,205" id="Line-4-Copy"></path> </g> <g id="WPF" transform="translate(133.000000, 164.000000)"> <g id="Oval"> <use fill="black" fill-opacity="1" filter="url(#filter-8)" xlink:href="#path-7"></use> <use fill="#E55B10" fill-rule="evenodd" xlink:href="#path-7"></use> </g> <g id="Type-3" transform="translate(40.500000, 40.000000) scale(-1, -1) rotate(-270.000000) translate(-40.500000, -40.000000) translate(26.000000, 25.000000)" fill="#FFFFFF"> <path d="M3.98571429,5.5 C4.81414141,5.5 5.48571429,6.17157288 5.48571429,7 L5.485,24.014 L22.5,24.0142857 C23.3284271,24.0142857 24,24.6858586 24,25.5142857 L24,28 C24,28.8284271 23.3284271,29.5 22.5,29.5 L2.18571429,29.5 C1.59431478,29.5 1.0828526,29.1577476 0.838589801,28.660505 C0.342252351,28.4171474 -5.6929507e-14,27.9056852 -5.68434189e-14,27.3142857 L-5.68434189e-14,7 C-5.71669165e-14,6.17157288 0.671572875,5.5 1.5,5.5 L3.98571429,5.5 Z" id="Rectangle-Copy-4" transform="translate(12.000000, 17.500000) rotate(-180.000000) translate(-12.000000, -17.500000) "></path> <path d="M8.98571429,-4.08562073e-14 C9.81414141,-4.10083869e-14 10.4857143,0.671572875 10.4857143,1.5 L10.485,18.514 L27.5,18.5142857 C28.3284271,18.5142857 29,19.1858586 29,20.0142857 L29,22.5 C29,23.3284271 28.3284271,24 27.5,24 L7.18571429,24 C6.59431478,24 6.0828526,23.6577476 5.8385898,23.160505 C5.34225235,22.9171474 5,22.4056852 5,21.8142857 L5,1.5 C5,0.671572875 5.67157288,-4.07040277e-14 6.5,-4.08562073e-14 L8.98571429,-4.08562073e-14 Z" id="Rectangle-Copy-7"></path> </g> </g> </g> </g> </g> </g> </svg>';

	}

	/**
	 * Show upgrade notice across top of settings page.
	 *
	 * @since 3.37.17
	 *
	 * @return mixed HTML output.
	 */
	public function title_upgrade_message() {

		if ( wp_fusion()->settings->get( 'connection_configured' ) ) {

			echo '<div id="wpf-pro">';
			echo '<img src="' . WPF_DIR_URL . '/assets/img/logo-sm-trans.png">';
			echo '<strong>You are using the free version of WP Fusion.</strong> For an even deeper integration with ' . wp_fusion()->crm->name . ', consider <a href="https://wpfusion.com/pricing/?utm_source=free-plugin&utm_medium=header&utm_campaign=free-plugin" target="_blank">upgrading to one of our paid plans</a> 🚀';
			echo '</div>';

		}

	}

}

new WPF_Lite_Helper;
