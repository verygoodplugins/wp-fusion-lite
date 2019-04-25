<?php

class WPF_CRM_Queue {

	/**
	 * Holds the current active CRM object
	 */

	private $crm;

	/**
	 * Buffer for queued API calls
	 */

	private $buffer;


	public function __construct( $crm ) {

		$this->crm    = $crm;
		$this->buffer = array();

		// Run shutdown at PHP shutdown
		register_shutdown_function( array( $this, 'shutdown' ) );

	}

	/**
	 * Passes get requests to the base CRM class
	 *
	 * @access  public
	 * @return  mixed
	 */

	public function __get( $name ) {

		if ( is_object( $this->crm ) ) {
			return $this->crm->$name;
		} else {
			return false;
		}

	}

	/**
	 * Routes queue-able API calls to the buffer
	 *
	 * @access  public
	 * @return  mixed
	 */

	public function __call( $method, $args ) {

		$args = apply_filters( 'wpf_api_' . $method . '_args', $args );

		if ( wp_fusion()->settings->get( 'staging_mode' ) == true || defined( 'WPF_STAGING_MODE' ) ) {
			
			wp_fusion()->logger->handle( 'notice', 0, 'Staging mode enabled. Method ' . $method . ':', array( 'source' => $this->crm->slug, 'args' => $args ) );

			require_once WPF_DIR_PATH . 'includes/crms/staging/class-staging.php';
			$staging_crm = new WPF_Staging;

			$result = call_user_func_array( array( $staging_crm, $method ), $args );

			return $result;

		}

		// Queue sending data
		if ( ( $method == 'apply_tags' || $method == 'remove_tags' || $method == 'update_contact' ) ) {

			if( $method == 'update_contact' && ! isset( $args[2] ) ) {

				// Possbily quit early if none of the data is mapped to CRM fields

				$mapped_fields = wp_fusion()->crm_base->map_meta_fields( $args[1] );

				if( empty( $mapped_fields ) ) {
					return false;
				}

			}

			$this->add_to_buffer( $method, $args );

			return true;

		} else {

			$result = call_user_func_array( array( $this->crm, $method ), $args );

			return $result;

		}

	}


	/**
	 * Adds API requests to the API buffer
	 *
	 * @access  private
	 * @return  void
	 */

	private function add_to_buffer( $method, $args ) {

		if ( $method == 'apply_tags' || $method == 'remove_tags' ) {

			$cid = $args[1];

		} elseif( $method == 'update_contact' ) {

			// If call sets $map_meta_fields to false, create separate buffer entry
			if( isset( $args[2] ) && $args[2] == false ) {
				$cid = $args[0] . '_nomap';
			} else {
				$cid = $args[0];
			}

		}

		if ( ! isset( $this->buffer[ $method ] ) ) {

			$this->buffer[ $method ] = array( $cid => $args );

		} elseif ( ! isset( $this->buffer[ $method ][ $cid ] ) ) {

			$this->buffer[ $method ][ $cid ] = $args;

		} else {

			if ( $method == 'apply_tags' ) {

				// Prevent tags getting added and removed in the same request

				if(isset($this->buffer[ 'remove_tags' ]) && isset($this->buffer[ 'remove_tags' ][ $cid ])) {

					foreach( $args[0] as $tag ) {

						$match = array_search($tag, $this->buffer[ 'remove_tags' ][ $cid ][0]);

						if($match !== false) {
							unset($this->buffer[ 'remove_tags' ][ $cid ][0][$match]);
							return;
						}

					}

				}

				$this->buffer[ 'apply_tags' ][ $cid ][0] = array_unique( array_merge( $this->buffer[ 'apply_tags' ][ $cid ][0], $args[0] ) );

			} elseif( $method == 'remove_tags' ) {

				// Prevent tags getting added and removed in the same request

				if(isset($this->buffer[ 'apply_tags' ]) && isset($this->buffer[ 'apply_tags' ][ $cid ])) {

					foreach( $args[0] as $tag ) {

						$match = array_search($tag, $this->buffer[ 'apply_tags' ][ $cid ][0]);

						if($match !== false) {
							unset($this->buffer[ 'apply_tags' ][ $cid ][0][$match]);
							return;
						}

					}

				}

				$this->buffer[ 'remove_tags' ][ $cid ][0] = array_unique( array_merge( $this->buffer[ 'remove_tags' ][ $cid ][0], $args[0] ) );

			} elseif( $method == 'update_contact' ) {

				$this->buffer[ $method ][ $cid ][1] = array_merge( $this->buffer[ $method ][ $cid ][1], $args[1] );

			}

		}

	}

	/**
	 * Executes the queued API requests on PHP shutdown
	 *
	 * @access  public
	 * @return  void
	 */

	public function shutdown() {

		if ( empty( $this->buffer ) ) {
			return;
		}

		foreach ( $this->buffer as $method => $contacts ) {

			foreach ( $contacts as $cid => $args ) {

				// Don't send empty data
				if(!empty($args[0]) && !empty($args[1]) ) {

					$result = call_user_func_array( array( $this->crm, $method ), $args );

					// Error handling
					if( is_wp_error( $result ) ) {

						// Handle no-map CIDs
						$cid = str_replace('_nomap', '', $cid);

						$user_id = wp_fusion()->user->get_user_id( $cid );

						if( $user_id == false ) {
							$user_id = 0;
						}

						$args[1] = wp_fusion()->crm_base->map_meta_fields( $args[1] );

						wp_fusion()->logger->handle( 'error', $user_id, 'Error while performing method <strong>' . $method . '</strong>: ' . $result->get_error_message(), array( 'source' => $this->crm->slug, 'args' => $args ) );

					}

				}

			}
		}

	}


}