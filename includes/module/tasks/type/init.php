<?php if (!defined('FW')) die('Forbidden');

/**
 * @param _FW_Ext_Backups_Task_Type_Register $task_types
 * @internal
 */
function _action_fw_ext_backups_register_built_in_task_types(_FW_Ext_Backups_Task_Type_Register $task_types) {
	$dir = dirname(__FILE__);

	require_once $dir .'/class-fw-ext-backups-task-type-db-export.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_DB_Export());

	require_once $dir .'/class-fw-ext-backups-task-type-db-restore.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_DB_Restore());

	require_once $dir .'/class-fw-ext-backups-task-type-dir-clean.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Dir_Clean());

	require_once $dir .'/class-fw-ext-backups-task-type-zip.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Zip());

	require_once $dir .'/class-fw-ext-backups-task-type-unzip.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Unzip());

	require_once $dir .'/class-fw-ext-backups-task-type-files-export.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Files_Export());

	require_once $dir .'/class-fw-ext-backups-task-type-files-restore.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Files_Restore());

	require_once $dir .'/class-fw-ext-backups-task-type-image-sizes-remove.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Image_Sizes_Remove());

	require_once $dir .'/class-fw-ext-backups-task-type-image-sizes-restore.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Image_Sizes_Restore());

	require_once $dir .'/download/class-fw-ext-backups-task-type-download.php';
	$task_types->register(new FW_Ext_Backups_Task_Type_Download());
}
add_action(
	'fw_ext_backups_task_types_register',
	'_action_fw_ext_backups_register_built_in_task_types'
);
