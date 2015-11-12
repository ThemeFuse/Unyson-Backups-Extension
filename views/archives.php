<?php if ( ! defined( 'FW' ) ) die( 'Forbidden' );
/**
 * @var array $archives
 * @var bool $is_busy
 */

/**
 * @var FW_Extension_Backups $backups
 */
$backups = fw_ext('backups');

if (!class_exists('_FW_Ext_Backups_List_Table')) {
	fw_include_file_isolated(
		fw_ext('backups')->get_path('/includes/list-table/class--fw-ext-backups-list-table.php')
	);
}

$list_table = new _FW_Ext_Backups_List_Table(array(
	'archives' => $archives
));

$list_table->display();
