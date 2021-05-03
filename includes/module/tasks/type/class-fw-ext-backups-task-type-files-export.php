<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Files_Export extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'files-export';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Files Export', 'fw');
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * source_dirs - {'dir_id': 'dir_path'}
	 * * destination - dir
	 * * [exclude_paths] - {'dir_path': true}
	 */
	public function execute(array $args, array $state = array()) {
		$backups = fw_ext('backups'); /** @var FW_Extension_Backups $backups */

		{
			if (empty($args['source_dirs'])) {
				return new WP_Error(
					'no_source', __('Source dirs not specified', 'fw')
				);
			} else {
				$args['source_dirs'] = array_filter(array_map('fw_fix_path', $args['source_dirs']), 'file_exists');
			}

			if (empty($args['destination'])) {
				return new WP_Error(
					'no_destination', __('Destination not specified', 'fw')
				);
			} else {
				$args['destination'] = fw_fix_path($args['destination']);
			}

			{
				if (empty($args['exclude_paths'])) {
					$args['exclude_paths'] = array(
						// '/path/to/dir' => true,
						// '/path/to/file.txt' => true, // NOT WORKING. If you need this feature let us know
					);
				}

				/**
				 * @since 2.0.18
				 */
				$args['exclude_paths'] = apply_filters(
					'fw:ext:backups:task-type:files-export:exclude-paths',
					$args['exclude_paths']
				);

				$wp_upload_dir = wp_upload_dir();

				$args['exclude_paths'] = array_merge($args['exclude_paths'], array(
					$backups->get_backups_dir() => true,
					$backups->get_tmp_dir() => true, // by default it's in backups dir, just in case it will be changed
					fw_fix_path($wp_upload_dir['basedir']) .'/backup' => true, // Backup v1
					fw_fix_path($wp_upload_dir['basedir']) .'/fw' => true, // created in Unyson 2.6.0 by FW_File_Cache
					fw_fix_path($wp_upload_dir['basedir']) .'/brizy' => true, // exclude brizy screenshots
				));
			}
		}

		if (empty($state)) {
			$state = array(
				'dir_id' => key($args['source_dirs']),
				'dirs' => array(
					// 'parent_dir', 'dir', 'sub_dir' // --> {source_dir}/parent_dir/dir/sub_dir
				),
				'file' => '', // --> {source_dir}/parent_dir/dir/sub_dir/{file}
			);
		}

		$max_time = time() + fw_ext( 'backups' )->get_timeout(-7);

		while (time() < $max_time) {
			if (empty($args['source_dirs'][ $state['dir_id'] ])) {
				return new WP_Error(
					'source_dir_empty',
					sprintf(__('Source dir %s is empty', 'fw'), $state['dir_id'])
				);
			}

			if (is_wp_error($files = $this->get_next_files(
				$args['source_dirs'][ $state['dir_id'] ],
				$state['dirs'],
				$state['file'],
				$args['exclude_paths']
			))) {
				return $files;
			} elseif ($files === true) {
				// move to the next source dir
				{
					while (key($args['source_dirs']) !== $state['dir_id']) {
						next($args['source_dirs']);
					}

					next($args['source_dirs']);
				}

				if ($state['dir_id'] = key($args['source_dirs'])) {
					$state['dirs'] = array();
					$state['file'] = '';
					continue;
				} else {
					return true;
				}
			}

			$rel_dir = empty($state['dirs']) ? '' : '/'. implode('/', $state['dirs']);
			$source_dir = $args['source_dirs'][ $state['dir_id'] ] . $rel_dir;
			$destination_dir = $args['destination'] .'/'. $state['dir_id'] . $rel_dir;
			$created_dirs = array();

			foreach ($files as $file) {
				if (!isset($created_dirs[$destination_dir])) {
					if (!is_dir($destination_dir)) {
						if ($source_dir_chmod = substr(sprintf('%o', fileperms($source_dir)), -4)) {
							$source_dir_chmod = intval($source_dir_chmod, 8);
						} else {
							return new WP_Error(
								'get_dir_chmod_fail', __('Failed to get dir chmod', 'fw')
							);
						}

						if (!mkdir($destination_dir, $source_dir_chmod, true)) {
							return new WP_Error(
								'destination_dir_create_fail',
								__('Failed to create destination dir', 'fw'),
								array('dir' => $destination_dir,)
							);
						}
					}

					$created_dirs[$destination_dir] = true;
				}

				if (!copy(
					$source_dir .'/'. $file,
					$destination_dir .'/'. $file
				)) {
					return new WP_Error(
						'copy_failed', sprintf(__('Failed to copy: %s', 'fw'), $source_dir .'/'. $file)
					);
				}
			}

			$state['file'] = $file;
		}

		return $state;
	}

	private function get_max_files_per_cycle() {
		return 33;
	}

	/**
	 * @param string $root_dir
	 * @param array $dirs
	 * @param string $previous_file
	 * @param array $exclude_paths
	 * @return array|true|WP_Error ['file.txt', 'file.php', ...]
	 *         Important: It will never return an empty array. Only: (array)files, true, WP_Error
	 */
	private function get_next_files($root_dir, array &$dirs, $previous_file, array $exclude_paths) {
		$rel_dir = empty($dirs) ? '' : '/'. implode('/', $dirs);
		$included_hidden_names = fw_ext('backups')->get_config('included_hidden_names');

		if ($names = array_diff(($names = scandir($dir = $root_dir . $rel_dir)) ? $names : array(), array('.', '..'))) {
			$files = array(); // result
			$file_found = empty($previous_file); // find previous file and return next files
			$count = 0;

			foreach ($names as $file) {
				$path = $dir .'/'. $file;

				if (!$file_found) {
					$file_found = ($file === $previous_file);
					continue;
				}

				if ($file[0] === '.' && !isset($included_hidden_names[$file])) {
					continue;
				}

				if (is_dir($path)) {
					if (isset($exclude_paths[ fw_fix_path($path) ])) {
						continue;
					} elseif ($files) { // return collected files, will go inside directory on next call
						return $files;
					} else {
						$dirs[] = $file;

						return $this->get_next_files($root_dir, $dirs, '', $exclude_paths);
					}
				} else {
					$files[] = $file;

					if (++$count > $this->get_max_files_per_cycle()) {
						return $files;
					}
				}
			}

			if (empty($files)) {
				if ($file_found) { // reached end of the directory
					if ($dirs) { // go a directory back
						$previous_file = array_pop($dirs);
						return $this->get_next_files($root_dir, $dirs, $previous_file, $exclude_paths);
					} else { // root directory end reached
						return true;
					}
				} else {
					return new WP_Error(
						'previous_file_not_found',
						sprintf(__('Failed to restore dir listing from: %s', 'fw'), $root_dir . $rel_dir . '/' . $previous_file)
					);
				}
			}

			return $files;
		} else { // directory is empty
			if ($dirs) { // go a directory back
				$previous_file = array_pop($dirs);
				return $this->get_next_files($root_dir, $dirs, $previous_file, $exclude_paths);
			} else { // root directory end reached
				return true;
			}
		}
	}
}
