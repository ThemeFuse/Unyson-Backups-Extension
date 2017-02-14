<?php if (!defined('FW')) die('Forbidden');

/**
 * Create zip
 */
class FW_Ext_Backups_Task_Type_Zip extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'zip';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Archive Zip', 'fw');
	}

	/**
	 * When the zip is big, adding just a single file will recompile the entire zip.
	 * So it can't be executed in steps.
	 * {@inheritdoc}
	 */
	public function get_custom_timeout(array $args, array $state = array()) {
		return fw_ext('backups')->get_config('max_timeout');
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * source_dir - everything from this directory will be added in zip
	 * * destination_dir - where the zip file will be created
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
			if (!isset($args['source_dir'])) {
				return new WP_Error(
					'no_source_dir', __('Source dir not specified', 'fw')
				);
			} elseif (!file_exists($args['source_dir'] = fw_fix_path($args['source_dir']))) {
				return new WP_Error(
					'invalid_source_dir', __('Source dir does not exist', 'fw')
				);
			}

			if (!isset($args['destination_dir'])) {
				return new WP_Error(
					'no_destination_dir', __('Destination dir not specified', 'fw')
				);
			} else {
				$args['destination_dir'] = fw_fix_path($args['destination_dir']);
			}
		}

		if (empty($state)) {
			$state = array(
				'files_count' => 0,
				// generate the file name only on first step
				'zip_path' => $args['source_dir'] .'/'. implode('-', array(
						'fw-backup',
						date('Y_m_d-H_i_s'),
						fw_ext('backups')->manifest->get_version()
					)) .'.zip'
			);
		}

		{
			if (!class_exists('ZipArchive')) {
				return new WP_Error(
					'zip_ext_missing', __('Zip extension missing', 'fw')
				);
			}

			$zip = new ZipArchive();

			if (false === ($zip_error_code = $zip->open($state['zip_path'], ZipArchive::CREATE))) {
				return new WP_Error(
					'cannot_open_zip', sprintf(__('Cannot open zip (Error code: %s)', 'fw'), $zip_error_code)
				);
			}
		}

		/** @var FW_Extension_Backups $ext */
		$ext = fw_ext('backups');
		$max_time = time()
			/**
			 * Files for zip (in pending) are added very fast
			 * but on zip close the processing/zipping starts and it is time consuming
			 * so allocate little time for files add and leave as much time as possible for $zip->close();
			 *
			 * TODO: Limit number of files and/or total files size added in zip in one/current step
			 */
			+ min(abs($ext->get_timeout() / 2), 10);
		// $zip->setCompression*() was introduced in PHP 7.0
		$set_compression_is_available = method_exists($zip, 'setCompressionName');
		$files_count = 0;
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($args['source_dir']),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($files as $file) {
			if ($execution_not_finished = (time() > $max_time)) {
				break;
			}

			$file_path = fw_fix_path($file->getRealPath());
			$file_zip_path = substr($file_path, strlen($args['source_dir']) + 1); // relative

			if (
				$file->isDir() // Skip directories (they will be created automatically)
				||
				$file_path === $state['zip_path'] // this happens when zip exists from previous step
			) {
				continue;
			}

			if ((++$files_count) < $state['files_count']) {
				// skip already compressed files in previous step
				continue;
			}

			$zip->addFile($file_path, $file_zip_path);

			if ($set_compression_is_available) {
				$zip->setCompressionName(
					$file_zip_path,
					/**
					 * The Store method does not compress the file
					 * on slow servers this will be a huge speed improvement
					 * (of course by sacrificing the zip file size)
					 * http://php.net/manual/en/zip.constants.php#ziparchive.constants.cm-store
					 */
					ZipArchive::CM_STORE
				);
			}
		}

		// Zip archive will be created only after closing the object
		if (!$zip->close()) {
			return new WP_Error(
				'cannot_close_zip', __('Cannot close the zip file', 'fw')
			);
		}

		$state['files_count'] = $files_count;

		if ($execution_not_finished) {
			// There are more files to be processed, the execution hasn't finished
			return $state;
		}

		if (!$files_count) {
			/**
			 * Happens on Content Backup when uploads/ is empty
			 */
			return true;
		}

		if (!rename($state['zip_path'], $args['destination_dir'] .'/'. basename($state['zip_path']))) {
			return new WP_Error(
				'cannot_move_zip',
				__('Cannot move zip in destination dir', 'fw')
			);
		}

		return true;
	}
}