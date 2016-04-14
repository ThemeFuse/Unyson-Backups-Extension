<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Download extends FW_Ext_Backups_Task_Type {
	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'download';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_title(array $args = array(), array $state = array()) {
		if (isset($args['type']) && ($type = self::get_type_($args['type']))) {
			return $type->get_title($args, $state);
		} else {
			return __('Download', 'fw');
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_custom_timeout(array $args, array $state = array()) {
		if (isset($args['type']) && ($type = self::get_type_($args['type']))) {
			/**
			 * Download type can set custom timeout
			 * For e.g. some download types are performed in steps and don't require timeout increase
			 */
			return $type->get_custom_timeout($args, $state);
		} else {
			/**
			 * Usually downloading a file takes long time
			 */
			return fw_ext('backups')->get_config('max_timeout');
		}
	}

	private static $access_key;

	private static function get_access_key() {
		if (is_null(self::$access_key)) {
			self::$access_key = new FW_Access_Key('fw:ext:backups:task-type:download');
		}

		return self::$access_key;
	}

	/**
	 * @var FW_Ext_Backups_Task_Type_Download_Type[]
	 */
	private static $types;

	private static function get_types() {
		if (is_null(self::$types)) {
			$dir = dirname(__FILE__);

			if (!class_exists('FW_Ext_Backups_Task_Type_Download_Type_Register')) {
				require_once $dir .'/class-fw-ext-backups-task-type-download-type-register.php';
			}
			if (!class_exists('FW_Ext_Backups_Task_Type_Download_Type')) {
				require_once $dir .'/class-fw-ext-backups-task-type-download-type.php';
			}

			$register = new FW_Ext_Backups_Task_Type_Download_Type_Register(self::get_access_key()->get_key());

			{
				if (!class_exists('FW_Ext_Backups_Task_Type_Download_Local')) {
					require_once $dir .'/type/class-fw-ext-backups-task-type-download-local.php';
				}
				$register->register(new FW_Ext_Backups_Task_Type_Download_Local());

				if (!class_exists('FW_Ext_Backups_Task_Type_Download_Piecemeal')) {
					require_once $dir .'/type/piecemeal/class-fw-ext-backups-task-type-download-piecemeal.php';
				}
				$register->register(new FW_Ext_Backups_Task_Type_Download_Piecemeal());
			}

			do_action('fw:ext:backups:task-type:download:register:types', $register);

			self::$types = $register->_get_types(self::get_access_key());
		}

		return self::$types;
	}

	/**
	 * @param string $type
	 *
	 * @return FW_Ext_Backups_Task_Type_Download_Type|null
	 */
	private static function get_type_($type) {
		$types = self::get_types();

		if (isset($types[$type])) {
			return $types[$type];
		} else {
			return null;
		}
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 *  * type - registered download type
	 *  * type_args - args for registered type instance
	 *  * destination_dir - Where must be placed the downloaded files
	 */
	public function execute(array $args, array $state = array()) {
		if (empty($args['destination_dir'])) {
			return new WP_Error(
				'no_destination_dir',
				__('Destination dir not specified', 'fw')
			);
		} elseif (!($args['destination_dir'] = fw_fix_path($args['destination_dir']))) {
			return new WP_Error(
				'invalid_destination_dir',
				__('Invalid destination dir', 'fw')
			);
		}

		if (
			empty($args['type'])
			||
			!($type = self::get_types())
			||
			!isset($type[ $args['type'] ])
		) {
			return new WP_Error(
				'invalid_type',
				sprintf(__('Invalid type: %s', 'fw'), $args['type'])
			);
		}

		$type = $type[ $args['type'] ];

		if (empty($args['type_args'])) {
			return new WP_Error(
				'no_type_args',
				sprintf(__('Args not specified for type: %s', 'fw'), $type->get_type())
			);
		}

		$args['type_args'] = array_merge($args['type_args'], array('destination_dir' => $args['destination_dir']));

		return $type->download($args['type_args'], $state);
	}
}
