<?php if (!defined('FW')) die('Forbidden');

/**
 * @return int
 * @since 2.0.3
 */
function fw_ext_backups_demo_count() {
	static $access_key = null;

	if (is_null($access_key)) {
		$access_key = new FW_Access_Key('fw:ext:backups-demo:helper:count');
	}

	/**
	 * @var FW_Extension_Backups_Demo $extension
	 */
	$extension = fw_ext('backups-demo');

	return $extension->_get_demos_count($access_key);
}
