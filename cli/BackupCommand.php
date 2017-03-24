<?php

namespace Unyson\Extension\Backups;

class BackupCommand extends Command {
	/**
	 * @param array $_
	 * @param array $options
	 *
	 * @subcommand list
	 */
	public function _list( $_, $options = array() ) {
		$archives = array_map(
			array( $this, 'get_backup_file_data' ),
			$this->get_backups()
		);

		\WP_CLI\Utils\format_items(
			'table',
			$archives,
			array( 'Name', 'Time', 'Type' )
		);
	}

	public function create( $args, $options = array() ) {
		$this
			->get_ext()
			->tasks()
			->do_backup( isset( $options['full'] ) );

		$this->message( "Backup successfully created" );
	}

	public function restore( $args, $options = array() ) {
		$id = array_shift( $args );
		try {
			$backup = $this->get( $id );
		} catch ( \Exception $e ) {
			$this->error( "Backup $id doesn't seem to exist" );
		}

		$this->initialize_fs();
		$this
			->get_ext()
			->tasks()
			->do_restore( $backup['full'], $backup['path'] );

		$this->message( "Backup $id was successfully restored" );
	}

	protected function get_backup_file_data( array $backup ) {
		return array(
			'Name' => basename( $backup['path'], '.zip' ),
			'Type' => $backup['full'] ? 'Full' : 'Content',
			'Time' => date( 'd M Y, H:i', $backup['time'] ),
		);
	}

	protected function get_backups() {
		return $this->get_ext()->get_archives();
	}

	protected function get( $id ) {
		$backups = $this->get_backups();

		if ( isset( $backups[ $id . '.zip' ] ) ) {
			return $backups[ $id . '.zip' ];
		}

		throw new \Exception( "Backup $id cannot be found" );
	}
}