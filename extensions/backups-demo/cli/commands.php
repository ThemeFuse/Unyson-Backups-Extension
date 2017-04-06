<?php

namespace Unyson\Extension\BackupsDemo;


/**
 * Unyson Backups Demo Extension CLI Commands.
 *
 * @package wp-cli
 */
class Command extends \Unyson\Extension\Command {

	/**
	 * List all available demos.
	 *
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *
	 *     # List all available demos.
	 *     $ wp unyson ext backups-demo list
	 *      +---------------+----------------+------------------------------------------+
	 *		| Id            | Name           | Preview                                  |
	 *		+---------------+----------------+------------------------------------------+
	 *		| creed         | Creed          | http://demo.themefuse.com/creed/         |
	 *		| the-core      | The Core       | http://demo.themefuse.com/the-core/      |
	 *		| hope          | Hope           | http://demo.themefuse.com/hope/          |
	 *		| gourmet       | Gourmet        | http://demo.themefuse.com/gourmet/       |
	 *		| keynote       | Keynote        | http://demo.themefuse.com/keynote/       |
	 *		| wellness      | Wellness       | http://demo.themefuse.com/wellness/      |
	 *		| cribs         | Cribs          | http://demo.themefuse.com/cribs/         |
	 *		| the-college   | The College    | http://demo.themefuse.com/the-college/   |
	 *		+---------------+----------------+------------------------------------------+
	 *
	 * @param array $_
	 * @param array $options
	 *
	 * @subcommand list
	 */
	public function _list( $_, $options = array() ) {
		\WP_CLI\Utils\format_items(
			'table',
			array_map(
				array( $this, 'get_demo_data' ),
				$this->get_demos()
			),
			array( 'Id', 'Name', 'Preview' )
		);
	}

	/**
	 * Install demo.
	 *
	 * ## OPTIONS
	 *
	 * <demo-id>
	 * : Demo ID to install.
	 *
	 * ## EXAMPLES
	 *
	 *     # Install demo
	 *     $ wp unyson ext backups-demo install the-core
	 *     Demo the-core successfully installed.
	 */
	public function install( $args, $options = array() ) {
		$id = array_shift( $args );

		try {
			$demo = $this->get( $id );
		} catch ( \Exception $e ) {
			$this->error( $e->getMessage() );
		}

		$this->get_ext()->do_install( $demo );
		$this->message( "Demo %g{$demo->get_title()}%n successfully installed." );
	}

	/**
	 * @return \FW_Extension_Backups_Demo
	 */
	protected function get_ext() {
		return fw_ext( 'backups-demo' );
	}

	protected function get( $id ) {
		$demos = $this->get_demos();

		if ( isset( $demos[ $id ] ) ) {
			return $demos[ $id ];
		}

		throw new \Exception( "Demo '$id' cannot be found" );
	}

	protected function get_demos() {
		return $this->get_ext()->get_demos();
	}

	protected function get_demo_data( \FW_Ext_Backups_Demo $demo ) {
		return array(
			'Id'      => $demo->get_id(),
			'Name'    => $demo->get_title(),
			'Preview' => $demo->get_preview_link(),
		);
	}
}