<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handles all old app SDK methods using the new REST API.
 *
 * @since 3.44.0
 */
class WPF_Infusionsoft_App {

	/**
	 * @var string The base URL for the API (Ecommerce not available in v2).
	 */
	public $url = 'https://api.infusionsoft.com/crm/rest/v1/';

	/**
	 * @var array The parameters for the API request.
	 */
	public $params;

	/**
	 * @var array The mapping of old field names to new field names.
	 */
	public $fields_mapping = array(
		'ProductName'  => 'product_name',
		'ProductPrice' => 'product_price',
		'Id'           => 'id',
		'Sku'          => 'sku',
		'Referral'     => 'affiliate',
		'ContactId'    => 'contact_id',
		'AffiliateId'  => 'code',
		'IPAddress'    => '',
		'Type'         => '',
		'DateSet'      => '',
	);

	/**
	 * Constructor for WPF_Infusionsoft_App class.
	 * 
	 * @since 3.44.0
	 *
	 * @param array $params The parameters to initialize the class with.
	 */
	public function __construct( $params ) {

		$this->params = $params;

	}

	/**
	 * Magic method to get a property.
	 * 
	 * @since 3.44.0
	 *
	 * @param string $name The name of the property.
	 * @return mixed
	 */
	public function __get( $name ) {
		if ( property_exists( $this, $name ) ) {
			return $this->$name;
		}

		wpf_log( 'notice', 0, 'This property does not exist: ' . $name, array( 'source' => 'infusionsoft' ) );
		return false;
	}

	/**
	 * Magic method to call a method.
	 *
	 * @since 3.44.0
	 *
	 * @param string $name The name of the method.
	 * @param array  $arguments The method arguments.
	 * @return mixed
	 */
	public function __call( $name, $arguments ) {
		if ( method_exists( $this, $name ) ) {
			return call_user_func_array( array( $this, $name ), $arguments );
		}

		wpf_log( 'notice', 0, 'This function does not exist: ' . $name, array( 'source' => 'infusionsoft' ) );
		return false;
	}

	/**
	 * Remaps fields in the provided data.
	 *
	 * @since 3.44.0
	 *
	 * @param array|string $data The data to remap.
	 * @param bool         $returned Whether the data is being returned.
	 * @return array|string
	 */
	private function remap_fields( $data, $returned = false ) {

		if ( $returned ) {
			$fields_mapping = array_flip( $this->fields_mapping );
		} else {
			$fields_mapping = $this->fields_mapping;
		}

		if ( ! is_array( $data ) ) {
			if ( isset( $fields_mapping[ $data ] ) ) {
				return $fields_mapping[ $data ];
			}

			return $data;
		}

		$data = $this->remap_array_fields( $data, $fields_mapping );

		return $data;
	}

	/**
	 * Remaps fields in the provided array recursively.
	 *
	 * @since 3.44.0
	 *
	 * @param array $data The data array to remap.
	 * @param array $fields_mapping The mapping of old field names to new field names.
	 * @return array
	 */
	private function remap_array_fields( $data, $fields_mapping = array() ) {
		$result = array();

		foreach ( $data as $key => $value ) {
			if ( ! empty( $fields_mapping[ $key ] ) ) {
				$key = $fields_mapping[ $key ];
			}

			if ( is_array( $value ) ) {
				$result[ $key ] = $this->remap_array_fields( $value, $fields_mapping );
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Creates a blank order.
	 *
	 * @since 3.44.0
	 *
	 * @param int    $contact_id The contact ID.
	 * @param string $desc The order description.
	 * @param string $order_date The order date.
	 * @param int    $lead_aff The lead affiliate ID.
	 * @param int    $sale_aff The sales affiliate ID.
	 * @return mixed
	 */
	public function blankOrder( $contact_id, $desc, $order_date, $lead_aff, $sale_aff ) {

		// Add fake product as we can't create a blank order or empty order_items in rest api.
		$rest_product_id = wpf_get_option( 'rest_product_id' );
		if ( empty( $rest_product_id ) ) {
			$rest_product_id = $this->dsAdd(
				'Product',
				array(
					'ProductName'  => 'wpf_rest_product',
					'ProductPrice' => 0,
				)
			);
			wp_fusion()->settings->set( 'rest_product_id', $rest_product_id );
		}

		$params     = $this->params;
		$order_date = date( 'Y-m-d\TH:i:s\Z', strtotime( $order_date ) );

		$data = array(
			'contact_id'  => $contact_id,
			'order_title' => $desc,
			'order_date'  => $order_date,
			'order_items' => array(
				array(
					'product_id' => $rest_product_id,
					'quantity'   => 0,
				),
			),
			'order_type'  => 'Online',
		);

		if ( $lead_aff ) {
			$data['lead_affiliate_id'] = $lead_aff;
		}
		if ( $sale_aff ) {
			$data['sales_affiliate_id'] = $sale_aff;
		}

		$params['body'] = wp_json_encode( $data );
		$request        = $this->url . 'orders/';
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		// Save fake order item to remove it later.
		wp_fusion()->settings->set( 'rest_order_item', $response['order_items'][0]['id'] );

		return $response['id'];
	}

	/**
	 * Delete invoices before re-processing an Enhanced Ecommerce order.
	 *
	 * @todo At the moment this fails because an order cannot be deleted when it
	 * has valid payments.
	 *
	 * @since 3.44.0
	 *
	 * @param int $invioce_id The invoice ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function deleteInvoice( $invoice_id ) {

		$params           = $this->params;
		$params['method'] = 'DELETE';

		$request = $this->url . 'orders/' . $invoice_id;

		$response = wp_safe_remote_request( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;

	}

	/**
	 * Loads a data set.
	 *
	 * @since 3.44.0
	 *
	 * @param string $table         The table name.
	 * @param int    $id            The ID of the record.
	 * @param array  $return_fields The fields to retrieve.
	 * @return array|bool The data or false.
	 */
	public function dsLoad( $table, $id, $return_fields ) {

		if ( 'Invoice' === $table ) {
			// No longer needed but this prevents warnings in Enhanced Ecommerce.
			return array( 'JobId' => 0 );
		}

		return true;

	}

	/**
	 * Manually processes a payment.
	 *
	 * @since 3.44.0
	 *
	 * @param string  $invoice_id          The invoice ID.
	 * @param string  $amt                 The amount to pay.
	 * @param string  $payment_date        The payment date.
	 * @param string  $payment_type        The payment type.
	 * @param string  $payment_description The payment description.
	 * @param boolean $bypass_commissions  Whether to bypass commission.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function manualPmt( $invoice_id = '', $amt = '', $payment_date = '', $payment_type = '', $payment_description = '', $bypass_commissions = false ) {
		$params = $this->params;

		if ( strpos( strtolower( $payment_type ), 'cash' ) !== false ) {
			$payemnt_type = 'CASH';
		} elseif ( strpos( strtolower( $payment_type ), 'check' ) !== false ) {
			$payemnt_type = 'CHECK';
		} else {
			$payemnt_type = 'CREDIT_CARD';
		}

		$payment_date = date( 'Y-m-d\TH:i:s\Z', strtotime( $payment_date ) );

		$data = array(
			'date'                => $payment_date,
			'notes'               => $payment_description,
			'payment_amount'      => $amt,
			'payment_method_type' => $payemnt_type,
		);

		$params['body'] = wp_json_encode( $data );
		$request        = $this->url . 'orders/' . $invoice_id . '/payments';

		$response = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Adds an order item.
	 *
	 * @since 3.44.0
	 *
	 * @param int    $order_id The order ID.
	 * @param int    $product_id The product ID.
	 * @param string $type The type of item.
	 * @param float  $price The price of the item.
	 * @param int    $qty The quantity of items.
	 * @param string $desc The description of the item.
	 * @param string $notes Any notes for the item.
	 * @return boolean
	 */
	public function addOrderItem( $order_id, $product_id, $type, $price, $qty, $desc = '', $notes = '' ) {
		$params = $this->params;

		// Remove fake rest product.
		$rest_order_item = wpf_get_option( 'rest_order_item' );
		if ( $rest_order_item ) {
			$params['method'] = 'DELETE';
			$request          = $this->url . 'orders/' . $order_id . '/items/' . $rest_order_item . '';
			$result           = wp_safe_remote_post( $request, $params );
			$response         = json_decode( wp_remote_retrieve_body( $result ), true );
		}

		$data = array(
			'product_id'  => $product_id,
			'quantity'    => $qty,
			'price'       => $price,
			'description' => $desc,
		);

		$params['body']   = wp_json_encode( $data );
		$params['method'] = 'POST';
		$request          = $this->url . 'orders/' . $order_id . '/items/';
		$response         = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Queries a data set.
	 *
	 * @since 3.44.0
	 *
	 * @param string $t_name The table name.
	 * @param int    $limit The number of records to retrieve.
	 * @param int    $page The page number.
	 * @param array  $query The query parameters.
	 * @param array  $r_fields The fields to retrieve.
	 * @return mixed
	 */
	public function dsQuery( $t_name, $limit = 1000, $page = 1, $query = array(), $r_fields = array() ) {
		$request  = $this->url . strtolower( $t_name ) . 's/';
		$response = wp_safe_remote_get( $request, $this->params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $response['products'] ) || empty( $response['products'] ) ) {
			return array();
		}
		return $this->remap_fields( $response['products'], true );
	}

	/**
	 * Adds a new record to a data set.
	 *
	 * @since 3.44.0
	 *
	 * @param string $t_name The table name.
	 * @param array  $data The data to add.
	 * @return mixed
	 */
	public function dsAdd( $t_name, $data ) {
		$params = $this->params;

		$data = $this->remap_fields( $data );

		if ( 'Referral' === $t_name ) {
			$data['password'] = wp_generate_password( 16 );
			$data['status']   = 'active';
			$t_name           = 'affiliate';
		}
		$params['body'] = wp_json_encode( $data );
		$request        = $this->url . strtolower( $t_name ) . 's/';
		$response       = wp_safe_remote_post( $request, $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		return $response['id'];

	}

	/**
	 * Updates an existing record in a data set.
	 *
	 * @since 3.44.0
	 *
	 * @param string $t_name The table name.
	 * @param int    $id The ID of the record.
	 * @param array  $data The data to update.
	 * @return mixed
	 */
	public function dsUpdate( $t_name, $id, $data ) {

		// There is no job update in rest api, it already happens in the create order.
		if ( 'Job' === $t_name ) {
			return true;
		}

		$params           = $this->params;
		$params['body']   = wp_json_encode( $this->remap_fields( $data ) );
		$params['method'] = 'PATCH';
		$request          = $this->url . strtolower( $t_name ) . 's/' . $id;
		$response         = wp_safe_remote_post( $request, $params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );
		return $response['id'];

	}

	/**
	 * Finds a record in a data set.
	 *
	 * @since 3.44.0
	 *
	 * @param string $t_name The table name.
	 * @param int    $limit The number of records to retrieve.
	 * @param int    $page The page number.
	 * @param string $field The field name to search by.
	 * @param string $value The value to search for.
	 * @param array  $r_fields The fields to retrieve.
	 * @return mixed
	 */
	public function dsFind( $t_name, $limit = 1, $page = 0, $field = '', $value = '', $r_fields = array() ) {
		$t_name  = strtolower( $t_name ) . 's';
		$request = $this->url . $t_name . '/?limit=1000';
		if ( 'AffCode' === $field ) {
			$field   = 'AffiliateId';
			$request = add_query_arg( 'code', $value, $request );
		}

		$response = wp_safe_remote_get( $request, $this->params );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		$results  = $this->remap_fields( $response[ $t_name ], true );

		foreach ( $results as $result ) {
			if ( isset( $result[ $field ] ) && $result[ $field ] === $value ) {
				return array( array( 'Id' => $result['Id'] ) );
			}
		}

		return false;
	}
}
