<?php if (!defined('FW')) die('Forbidden');

/**
 * Create zip
 */
class FW_Ext_Backups_Task_Type_Unzip extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'unzip';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Archive Unzip', 'fw');
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_custom_timeout(array $args, array $state = array()) {
		return fw_ext('backups')->get_config('max_timeout');
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * zip - file path
	 * * dir - where the zip file will be extract
	 *
	 * Warning!
	 *  Zip can't be executed in steps, it will execute way too long,
	 *  because it is impossible to update a zip file, every time you add a file to zip,
	 *  a new temp copy of original zip is created with new modifications, it is compressed,
	 *  and the original zip is replaced. So when the zip will grow in size,
	 *  just adding a single file, will take a very long time.
	 */
	public function execute(array $args, array $state = array()) {
		{
			if (!isset($args['zip'])) {
				return new WP_Error(
					'no_zip', __('Zip file not specified', 'fw')
				);
			} else {
				$args['zip'] = fw_fix_path($args['zip']);
			}

			if (!isset($args['dir'])) {
				return new WP_Error(
					'no_dir', __('Destination dir not specified', 'fw')
				);
			} else {
				$args['dir'] = fw_fix_path($args['dir']);
			}
		}

		if (!fw_ext_backups_is_dir_empty($args['dir'])) {
			return new WP_Error(
				'destination_not_empty', __('Destination dir is not empty', 'fw')
			);
		}

		{
			if (!class_exists('ZipArchive')) {
				return new WP_Error(
					'zip_ext_missing', __('Zip extension missing', 'fw')
				);
			}

			$zip = new ZipArchive();

			if (false === ($zip_error_code = $zip->open($args['zip']))) {
				return new WP_Error(
					'cannot_open_zip', sprintf(__('Cannot open zip (Error code: %s)', 'fw'), $zip_error_code)
				);
			}
		}

		wp_cache_flush();
		FW_Cache::clear();

		$zip->extractTo($args['dir']);

		$zip->close();
		unset($zip);

		return true;
	}
}
