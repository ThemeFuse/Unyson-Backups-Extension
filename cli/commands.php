<?php

namespace Unyson\Extension\Backups;


class Command extends \Unyson\Extension\Command {

	public function __construct( $name = 'backups' ) {
		parent::__construct( $name );

		spl_autoload_register( function ( $class ) {
			switch ( $class ) {
				case 'Unyson\Extension\Backups\BackupCommand' :
					include_once dirname( __FILE__ ) . '/BackupCommand.php';
					break;
				case 'Unyson\Extension\Backups\DemoCommand' :
					include_once dirname( __FILE__ ) . '/DemoCommand.php';
					break;
			}
		} );
	}

	public function backup( $args, $options = array() ) {
		$this->get_backup()->run_command( array_shift( $args ), $args, $options );
	}

	public function demo( $args, $options = array() ) {
		$this->get_demo()->run_command( array_shift( $args ), $args, $options );
	}

	/**
	 * @return BackupCommand
	 */
	protected function get_backup() {
		return new BackupCommand();
	}

	/**
	 * @return DemoCommand
	 */
	protected function get_demo() {
		return new DemoCommand();
	}

	/**
	 * @return \FW_Extension_Backups
	 */
	protected function get_ext() {
		return fw_ext( 'backups' );
	}
}