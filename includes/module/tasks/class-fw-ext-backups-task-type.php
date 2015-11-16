<?php if (!defined('FW')) die('Forbidden');

abstract class FW_Ext_Backups_Task_Type {
	/**
	 * @return string Unique type
	 */
	abstract public function get_type();

	/**
	 * @param array $args Same $args sent to $this->execute()
	 * @param array $state Same $state returned by $this->execute()
	 * @return string
	 */
	abstract public function get_title(array $args = array(), array $state = array());

	/**
	 * Execute a step
	 *
	 * @param array $args
	 * @param array $state Continue from the last step state
	 *
	 * @return array|true|WP_Error
	 * * array - current state, task is not finished (should continue)
	 * * true - task finished successfully
	 * * WP_Error - task failed
	 */
	abstract public function execute(array $args, array $state = array());

	/**
	 * @param array $args
	 * @param array $state
	 * @return int
	 */
	public function get_custom_timeout(array $args, array $state = array()) {}
}
