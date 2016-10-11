<?php if (!defined('FW')) die('Forbidden');

/**
 * Create zip
 */
class FW_Ext_Backups_Task_Type_Unzip extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'unzip';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Archive Unzip', 'fw') .(
			empty($state['extracted_files'])
				? ''
				: ' '. sprintf(__('(%d files extracted)', 'fw'), $state['extracted_files'])
		);
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * zip - file path
	 * * dir - where the zip file will be extract
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

		if (empty($state)) {
			if (!fw_ext_backups_is_dir_empty($args['dir'])) {
				return new WP_Error(
					'destination_not_empty', __('Destination dir is not empty', 'fw')
				);
			}

			$state = array(
				'entry' => '', // Last extracted file (cursor)
				'extracted_files' => 0,
			);
		}

		wp_cache_flush();
		FW_Cache::clear();

		if (is_wp_error($extract_result = fw_ext_backups_unzip_partial($args['zip'], $args['dir'], $state['entry']))) {
			return $extract_result;
		} else {
			if ($extract_result['finished']) {
				return true;
			} else {
				$state['entry'] = $extract_result['last_entry'];
				$state['extracted_files'] += $extract_result['extracted_files'];
				return $state;
			}
		}
	}
}
