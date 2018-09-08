<?php

abstract class WPF_Integrations_Base {

	public function __construct() {

		$this->init();

		if ( isset( $this->slug ) ) {
			wp_fusion()->integrations->{$this->slug} = $this;
		}

	}

	/**
	 * Gets things started
	 *
	 * @access  public
	 * @since   1.0
	 * @return  void
	 */

	abstract protected function init();

	/**
	 * Map meta fields collected at registration / profile update to internal fields
	 *
	 * @access  public
	 * @since   3.0
	 * @return  array Meta Fields
	 */

	protected function map_meta_fields( $meta_fields, $field_map ) {

		foreach ( $field_map as $key => $field ) {

			if ( ! empty( $meta_fields[ $key ] ) ) {
				$meta_fields[ $field ] = $meta_fields[ $key ];
			}

		}

		return $meta_fields;

	}

}