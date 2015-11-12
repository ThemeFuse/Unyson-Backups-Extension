<?php if ( ! defined( 'FW' ) ) die( 'Forbidden' );

class FW_Ext_Backups_Task_Type_Download_Type_Register extends FW_Type_Register {
	protected function validate_type(FW_Type $type) {
		return $type instanceof FW_Ext_Backups_Task_Type_Download_Type;
	}
}
