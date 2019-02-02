<?php

class WPF_Batch_Process extends WPF_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'batch';


	/**
	 * Task
	 *
	 * Override this method to perform any actions required on each
	 * queue item. Return the modified item for further processing
	 * in the next pass through. Or, return false to remove the
	 * item from the queue.
	 *
	 * @param mixed $item Queue item to iterate over
	 *
	 * @return mixed
	 */

	protected function task( $item ) {

		// Disable turbo for bulk processes
		wp_fusion()->crm = wp_fusion()->crm_base->crm_no_queue;

		do_action_ref_array( $item['action'], $item['args'] );

		$sleep = apply_filters( 'wpf_batch_sleep_time', 0 );

		if( $sleep > 0 ) {
			sleep( $sleep );
		}

		return false;

	}

	/**
	 * Complete
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */

	protected function complete() {

		parent::complete();

		// Show notice to user or perform some other arbitrary task...
	}

}