<?php

namespace Unyson\Extension\Backups;

/**
 * Unyson Backups Extension CLI Commands.
 *
 * @package wp-cli
 */
class Command extends \Unyson\Extension\Command {

	/**
	 * List all available backups.
	 *
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *
	 *     # List all backups files
	 *     $ wp unyson ext backups list
	 *        +--------------------------------------+--------------------+---------+
	 *        | Name                                 | Time               | Type    |
	 *        +--------------------------------------+--------------------+---------+
	 *        | fw-backup-2017_03_25-05_58_28-2.0.23 | 25 Mar 2017, 05:58 | Content |
	 *        | fw-backup-2017_03_25-05_57_44-2.0.23 | 25 Mar 2017, 05:57 | Content |
	 *        | fw-backup-2017_03_25-05_55_58-2.0.23 | 25 Mar 2017, 05:55 | Content |
	 *        | fw-backup-2017_03_25-05_24_04-2.0.23 | 25 Mar 2017, 05:24 | Content |
	 *        | fw-backup-2017_03_24-18_49_46-2.0.23 | 24 Mar 2017, 18:49 | Content |
	 *        | fw-backup-2017_03_24-11_23_29-2.0.23 | 24 Mar 2017, 11:23 | Full    |
	 *        | fw-backup-2017_03_24-11_23_01-2.0.23 | 24 Mar 2017, 11:23 | Full    |
	 *        +--------------------------------------+--------------------+---------+
	 *
	 *
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

	/**
	 * Create a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Specify to create a full backup.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create content backup
	 *     $ wp unyson ext backups create
	 *     Backup successfully created
	 *
	 *     # Create full backup
	 *     $ wp unyson ext backups create --full
	 *     Backup successfully created
	 *
	 *
	 * @param array $args
	 * @param array $options
	 */
	public function create( $args, $options = array() ) {
		$this
			->get_ext()
			->tasks()
			->do_backup( isset( $options['full'] ) );

		$this->message( "Backup successfully created" );
	}

	/**
	 * Restore a backup.
	 *
	 * ## OPTIONS
	 *
	 * <backup-id>
	 * : The ID of the backup to restore. The ID is the backup file name without `.zip` extension.
	 *
	 *
	 * ## EXAMPLES
	 *
	 *     # Restore backup
	 *     $ wp unyson ext backups restore fw-backup-2017_03_25-05_58_28-2.0.23
	 *     Backup fw-backup-2017_03_25-05_58_28-2.0.23 was successfully restored
	 *
	 *
	 * @param array $args
	 * @param array $options
	 */
	public function restore( $args, $options = array() ) {
		$id = array_shift( $args );
		try {
			$backup = $this->get( $id );
		} catch ( \Exception $e ) {
			$this->error( "Backup %g$id%n doesn't seem to exist" );
		}

		$this->initialize_fs();
		$this
			->get_ext()
			->tasks()
			->do_restore( $backup['full'], $backup['path'] );

		$this->message( "Backup %g$id%n was successfully restored" );
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
