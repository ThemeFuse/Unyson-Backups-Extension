<?php

namespace Unyson\Extension\Backups;


class Command extends \Unyson\Extension\Command {

	/**
	 * @return \FW_Extension_Backups
	 */
	protected function get_ext() {
		return fw_ext( 'backups' );
	}

	public function test() {
		echo "test\n";
	}
}