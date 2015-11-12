<?php if (!defined('FW')) die('Forbidden');

abstract class _FW_Ext_Backups_Module {
	final public function __construct(FW_Access_Key $access_key) {
		if ($access_key->get_key() !== 'fw:ext:backups') {
			// only the Backups extension can create instances
			trigger_error(__METHOD__ .' denied', E_USER_ERROR);
		}
	}

	abstract public function _init();
}
