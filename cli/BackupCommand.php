<?php

namespace Unyson\Extension\Backups;


use Unyson\Extension\Backups\Command;

class BackupCommand extends Command {
	/**
	 * @subcommand list
	 */
	public function _list() {

	}

	public function create( $args, $options = array() ) {
		$this->get_ext()->tasks();
	}
}