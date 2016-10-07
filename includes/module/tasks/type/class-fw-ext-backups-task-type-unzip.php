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

		if (!function_exists('zip_open')) {
			return new WP_Error(
				'zip_ext_missing', __('Zip extension missing', 'fw')
			);
		}

		if (!is_resource($zip = zip_open($args['zip']))) {
			return new WP_Error(
				'cannot_open_zip', sprintf(__('Cannot open zip (Error code: %s)', 'fw'), $zip)
			);
		}

		wp_cache_flush();
		FW_Cache::clear();

		$max_time = time() + fw_ext( 'backups' )->get_timeout()
			- 10; // leave some time in case it is a slow server and a large image is extracted

		if ($state['entry']) {
			while(
				($entry = zip_read($zip))
				&&
				zip_entry_name($entry) !== $state['entry']
			);

			if (!$entry) {
				zip_close($zip);
				return new WP_Error(
					'entry_restore_fail',
					sprintf(__('Cannot restore previous zip entry: %s', 'fw'), $state['entry'])
				);
			}
		}

		while (time() < $max_time) {
			if (!($entry = zip_read($zip))) {
				return true;
			}

			$name = zip_entry_name($entry);

			if (substr($name, -1) === '/') {
				continue; // it is a directory
			}

			$destination_path = $args['dir'] .'/'. $name;

			if (
				!file_exists($destination_dir = dirname($destination_path))
				&&
				!mkdir($destination_dir, 0777, true)
			) {
				zip_close($zip);
				return new WP_Error(
					'mkdir_fail',
					sprintf(__('Cannot create directory: %s', 'fw'), $destination_dir)
				);
			}

			if (false === ($unzipped = fopen($destination_path, 'wb'))) {
				zip_close($zip);
				return new WP_Error(
					'fopen_fail',
					sprintf(__('Cannot create file: %s', 'fw'), $destination_path)
				);
			}

			$size = zip_entry_filesize($entry);

			while ($size > 0) {
				$chunk_size = min($size, 10240);
				$size -= $chunk_size;

				if (false === ($chunk = zip_entry_read($entry, $chunk_size))) {
					fclose($unzipped);
					zip_close($zip);
					return new WP_Error(
						'zip_entry_read_fail',
						sprintf(__('Cannot read chunk from zip entry: %s', 'fw'), $name)
					);
				} else {
					fwrite($unzipped, $chunk);
				}
			}

			if (false === fclose($unzipped)) {
				zip_close($zip);
				return new WP_Error(
					'fclose_fail',
					sprintf(__('Cannot close file: %s', 'fw'), $destination_path)
				);
			}

			$state['entry'] = $name;
			++$state['extracted_files'];
		}

		return $state;
	}
}
