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

	public $pending_orders = array();

	/**
	 * API key.
	 *
	 * Used with the XMLRPC API.
	 *
	 * @var string API key.
	 *
	 * @since 3.44.10
	 */
	public $api_key;

	/**
	 * @var array The mapping of old field names to new field names.
	 */
	public $fields_mapping = array(
		'ProductName'  => 'product_name',
		'ProductPrice' => 'product_price',
		'ProductDesc'  => 'product_desc',
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
	 * We can't create a blank order anymore so let's just generate a temporary order ID.
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

		$new_order_id = uniqid();

		$this->pending_orders[ $new_order_id ] = array(
			'contact_id'  => $contact_id,
			'order_title' => $desc,
			'order_date'  => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $order_date ) ),
			'order_items' => array(),
			'order_type'  => 'Online',
		);

		if ( $lead_aff ) {
			$this->pending_orders[ $new_order_id ]['lead_affiliate_id'] = $lead_aff;
		}
		if ( $sale_aff ) {
			$this->pending_orders[ $new_order_id ]['sales_affiliate_id'] = $sale_aff;
		}

		return $new_order_id;
	}

	/**
	 * Adds an order note.
	 *
	 * @since 3.45.0
	 *
	 * @param int    $order_id The order ID.
	 * @param string $note The note to add.
	 * @return bool True on success, false otherwise.
	 */
	public function addOrderNote( $order_id, $note ) {

		$this->api_key = wpf_get_option( 'api_key' );

		$data = array(
			$this->api_key,
			'Job',
			(int) $order_id,
			array(
				'JobNotes' => $note,
			),
		);

		$response = $this->xmlrpc_request( 'DataService.update', $data );

		return $response;

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
	public function manualPmt( $invoice_id = '', $amt = '', $payment_date = '', $payment_type = '', $payment_description = '', $bypass_commissions = false, $order_notes = '' ) {

		$params = $this->params;

		if ( 0 > floatval( $amt ) || 'Credit' === $payment_type ) {
			// Refunds use the old XMLRPC API.
			return $this->process_refund( $invoice_id, $amt, $payment_date, $payment_type, $payment_description );
		}

		// If there's a pending order, create it now in a single API call.

		if ( isset( $this->pending_orders[ $invoice_id ] ) ) {

			$data = $this->pending_orders[ $invoice_id ];

			$params['body'] = wp_json_encode( $data );
			$request        = $this->url . 'orders/';

			$response = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response = json_decode( wp_remote_retrieve_body( $response ), true );

			unset( $this->pending_orders[ $invoice_id ] );

			$invoice_id = $response['id'];
			// Fixes error "The manual payment amount exceeds the amount due on the invoices being processed." when
			// sending the payment amount to Infusionsoft if the order total calculation is off by a couple of cents
			// due to taxes, discounts, and the totals being rounded.
			if ( floatval( $amt ) > floatval( $response['total_due'] ) ) {
				$amt = $response['total_due'];
			}
		}

		// Free orders.
		if ( empty( floatval( $amt ) ) ) {
			return $invoice_id;
		}

		if ( strpos( strtolower( $payment_type ), 'cash' ) !== false ) {
			$payemnt_type = 'CASH';
		} elseif ( strpos( strtolower( $payment_type ), 'check' ) !== false ) {
			$payemnt_type = 'CHECK';
		} else {
			$payemnt_type = 'CREDIT_CARD';
		}

		$payment_date = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $payment_date ) );

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

		$response = json_decode( wp_remote_retrieve_body( $response ), true );

		// In some cases the invoice ID is different than the Job ID.
		$invoice_id = $response['invoice_id'];

		return $invoice_id;
	}


	/**
	 * The new API doesn't support line items, so we'll make product IDs for them.
	 *
	 * @since 3.44.2
	 *
	 * @param int    $type  The type of item from the XMLRPC API.
	 * @param float  $price The price of the item.
	 * @param string $desc  The description of the item.
	 * @return int|WP_Error The product ID or error.
	 */
	public function get_line_item_product_id( $type, $price, $desc ) {

		switch ( $type ) {
			case 7:
				$type = 'Discount';
				break;
			case 2:
				$type = 'Tax';
				break;
			case 1:
				$type = 'Shipping';
				break;
			case 3:
				$type = 'Fee';
				break;
		}

		$infusionsoft_products = get_option( 'wpf_infusionsoft_products', array() );

		$product_id = array_search( $type, $infusionsoft_products );

		if ( $product_id ) {
			return $product_id;
		}

		// Make an API call to see if it exists.

		$response = $this->dsFind( 'Product', 1, 0, 'ProductName', $type, array( 'Id' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! empty( $response ) ) {
			$product_id = absint( $response[0]['Id'] );
		} else {

			// Add the product if not.

			$new_product = array(
				'ProductName'  => $type,
				'ProductPrice' => $price,
				'ProductDesc'  => $desc,
			);

			$product_id = $this->dsAdd( 'Product', $new_product );

			if ( is_wp_error( $product_id ) ) {
				return $product_id;
			}
		}

		$infusionsoft_products[ $product_id ] = $type;
		update_option( 'wpf_infusionsoft_products', $infusionsoft_products );
		return $product_id;
	}

	/**
	 * Adds an order item.
	 *
	 * @since 3.44.0
	 *
	 * @param int    $order_id The order ID.
	 * @param int    $product_id The product ID.
	 * @param int    $type The type of item.
	 * @param float  $price The price of the item.
	 * @param int    $qty The quantity of items.
	 * @param string $desc The description of the item.
	 * @param string $notes Any notes for the item.
	 * @return bool|WP_Error True or error on failure.
	 */
	public function addOrderItem( $order_id, $product_id, $type, $price, $qty, $desc = '', $notes = '' ) {

		if ( 0 === $product_id && 4 !== $type ) {
			// Line items.
			$product_id = $this->get_line_item_product_id( $type, $price, $desc );

			if ( is_wp_error( $product_id ) ) {
				return $product_id;
			}
		}

		if ( 0 === $product_id ) {
			return new WP_Error( 'error', 'Invalid product ID.' );
		}

		$data = array(
			'product_id'  => $product_id,
			'quantity'    => $qty,
			'price'       => $price,
			'description' => $desc,
		);

		if ( isset( $this->pending_orders[ $order_id ] ) ) {

			// Pending order
			$this->pending_orders[ $order_id ]['order_items'][] = $data;

		} else {

			// Send the API call.

			$params           = $this->params;
			$params['body']   = wp_json_encode( $data );
			$params['method'] = 'POST';
			$request          = $this->url . 'orders/' . $order_id . '/items/';
			$response         = wp_safe_remote_post( $request, $params );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
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
	public function dsQuery( $t_name, $limit = 1000, $page = 0, $query = array(), $r_fields = array() ) {

		$offset = $page * $limit;

		$request  = $this->url . strtolower( $t_name ) . 's/?limit=' . $limit . '&offset=' . $offset;
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

		$results = $this->remap_fields( $response[ $t_name ], true );

		foreach ( $results as $result ) {
			if ( isset( $result[ $field ] ) && $result[ $field ] === $value ) {
				return array( array( 'Id' => $result['Id'] ) );
			}
		}

		return false;
	}

	/**
	 *
	 * XMLRPC API
	 *
	 */

	/**
	 * Process a WooCommerce refund and post it to Infusionsoft.
	 *
	 * @param int    $order_id          The ID of the refunded order.
	 * @param object $order             The WooCommerce order object.
	 */
	public function process_refund( $invoice_id, $amt, $payment_date, $payment_type, $payment_description ) {

		$this->api_key = wpf_get_option( 'api_key' );

		$payment_date = new DateTime( $payment_date );

		$refund_data = array(
			$this->api_key,
			(int) $invoice_id,
			(float) $amt,
			$payment_date,
			$payment_type,
			$payment_description,
			true, // Bypass commissions.
		);

		$response = $this->xmlrpc_request( 'InvoiceService.addManualPayment', $refund_data );

		return $response;
	}

	/**
	 * Post data to Infusionsoft.
	 *
	 * @since 3.45.0
	 *
	 * @param string $method The XML-RPC method.
	 * @param array $data The data to send.
	 * @return bool True if the request was successful, false otherwise.
	 */
	private function xmlrpc_request( $method, $data ) {
		$xml      = $this->build_xml_rpc_request( $method, $data );
		$response = $this->send_xml_rpc_request( $xml );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		return $this->parse_xml_rpc_response( $body );
	}

	/**
	 * Build XML-RPC request.
	 *
	 * @param string $method The XML-RPC method.
	 * @param array  $params The parameters for the request.
	 * @return string The generated XML-RPC request.
	 */
	private function build_xml_rpc_request( $method, $params ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml .= '<methodCall>';
		$xml .= '<methodName>' . esc_xml( $method ) . '</methodName>';
		$xml .= '<params>';

		foreach ( $params as $param ) {
			$xml .= '<param><value>';
			
			if ( $param instanceof DateTime ) {
				$xml .= '<dateTime.iso8601>' . esc_xml( $param->format( 'Ymd\TH:i:s' ) ) . '</dateTime.iso8601>';
			} elseif ( is_array( $param ) ) {
				$xml .= '<struct>';
				foreach ( $param as $key => $value ) {
					$xml .= '<member>';
					$xml .= '<name>' . esc_xml( $key ) . '</name>';
					$xml .= '<value>';
					if ( is_string( $value ) ) {
						$xml .= '<string>' . esc_xml( $value ) . '</string>';
					} elseif ( is_int( $value ) ) {
						$xml .= '<int>' . $value . '</int>';
					} elseif ( is_float( $value ) || is_double( $value ) ) {
						$xml .= '<double>' . number_format( $value, 2, '.', '' ) . '</double>';
					} elseif ( is_bool( $value ) ) {
						$xml .= '<boolean>' . ( $value ? '1' : '0' ) . '</boolean>';
					}
					$xml .= '</value>';
					$xml .= '</member>';
				}
				$xml .= '</struct>';
			} elseif ( is_string( $param ) ) {
				$xml .= '<string>' . esc_xml( htmlspecialchars( $param ) ) . '</string>';
			} elseif ( is_int( $param ) ) {
				$xml .= '<int>' . intval( $param ) . '</int>';
			} elseif ( is_float( $param ) || is_double( $param ) ) {
				$xml .= '<double>' . number_format( $param, 2, '.', '' ) . '</double>';
			} elseif ( is_bool( $param ) ) {
				$xml .= '<boolean>' . ( $param ? '1' : '0' ) . '</boolean>';
			}
			
			$xml .= '</value></param>';
		}

		$xml .= '</params>';
		$xml .= '</methodCall>';

		return $xml;
	}

	/**
	 * Send an XML-RPC request.
	 *
	 * @param string $xml The XML-RPC request.
	 * @return WP_Error|array The response or WP_Error on failure.
	 */
	private function send_xml_rpc_request( $xml ) {
		$url  = 'https://api.infusionsoft.com/crm/xmlrpc/v1';
		$args = array(
			'body'    => $xml,
			'headers' => array(
				'Content-Type'   => 'text/xml',
				'X-Keap-API-Key' => sanitize_text_field( $this->api_key ),
			),
			'timeout' => 30,
		);

		return wp_remote_post( esc_url_raw( $url ), $args );
	}

	/**
	 * Parse the XML-RPC response.
	 *
	 * @param string $xml The XML response.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	private function parse_xml_rpc_response( $xml ) {
		$doc = new DOMDocument();
		if ( ! $doc->loadXML( $xml ) ) {
			return new WP_Error( 'error', 'Failed to parse XML response.' );
		}

		$fault_node = $doc->getElementsByTagName( 'fault' )->item( 0 );
		if ( $fault_node ) {
			$fault_string = $this->extract_fault_string( $fault_node );
			return new WP_Error( 'error', $fault_string );
		}

		$value_node = $doc->getElementsByTagName( 'value' )->item( 0 );
		return ( $value_node && 'boolean' === $value_node->firstChild->nodeName && '1' === $value_node->nodeValue );
	}

	/**
	 * Extract the fault string from the fault node.
	 *
	 * @param DOMNode $fault_node The fault node.
	 * @return string The fault string.
	 */
	private function extract_fault_string( $fault_node ) {
		$fault_string = '';
		$members      = $fault_node->getElementsByTagName( 'member' );

		foreach ( $members as $member ) {
			$name_node = $member->getElementsByTagName( 'name' )->item( 0 );
			if ( $name_node && 'faultString' === $name_node->nodeValue ) {
				$fault_string = $member->getElementsByTagName( 'value' )->item( 0 )->nodeValue;
				break;
			}
		}

		return $fault_string;
	}
}
