<?php if (!defined('FW')) die('Forbidden');

class _FW_Ext_Backups_Task_Type_Register {
	/**
	 * @var FW_Ext_Backups_Task_Type[]
	 */
	private $task_types = array();

	public function register(FW_Ext_Backups_Task_Type $type) {
		if (isset($this->task_types[$type->get_type()])) {
			throw new Exception('Backup Task Type '. $type->get_type() .' already exists');
		}

		$this->task_types[$type->get_type()] = $type;
	}

	/**
	 * @param FW_Access_Key $access_key
	 *
	 * @return FW_Ext_Backups_Task_Type[]
	 * @internal
	 */
	public function _get_task_types(FW_Access_Key $access_key) {
		if ($access_key->get_key() !== 'fw:ext:backups:tasks') {
			trigger_error('Method call denied', E_USER_ERROR);
		}

		return $this->task_types;
	}
}
