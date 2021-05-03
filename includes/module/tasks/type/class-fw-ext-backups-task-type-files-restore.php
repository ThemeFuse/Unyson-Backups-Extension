<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Files_Restore extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'files-restore';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Files Restore', 'fw');
	}

	/**
	 * Files restore can't be done in steps,
	 * because the site will not work without all files (half of a theme, or a plugin)
	 * {@inheritdoc}
	 */
	public function get_custom_timeout(array $args, array $state = array()) {
		return fw_ext('backups')->get_config('max_timeout');
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * source_dir
	 * * destinations - {'dir_id': 'destination_path'}
	 * * [filesystem_args] - {}|{hostname: '', username: '', password: '', connection_type: ''}
	 */
	public function execute(array $args, array $state = array()) {
		$backups = fw_ext('backups'); /** @var FW_Extension_Backups $backups */

		$upload_dir = wp_upload_dir();
		$upload_dir = fw_fix_path($upload_dir['basedir']);

		{
			if (empty($args['source_dir'])) {
				return new WP_Error(
					'no_source_dir', __('Source dir not specified', 'fw')
				);
			} else {
				$args['source_dir'] = fw_fix_path($args['source_dir']);

				if (empty($args['source_dir']) || !file_exists($args['source_dir'])) {
					return new WP_Error(
						'invalid_source_dir',
						sprintf(__('Invalid source dir: %s', 'fw'), $args['source_dir'])
					);
				}
			}

			if (empty($args['destinations'])) {
				return new WP_Error(
					'no_source', __('Source dirs not specified', 'fw')
				);
			} else {
				$args['destinations'] = array_map('fw_fix_path', $args['destinations']);
			}

			{
				if (empty($args['skip_dirs'])) {
					$args['skip_dirs'] = array(
						// '/path/to/dir' => true,
						// '/path/to/file.txt' => true, // NOT WORKING. If you need this feature let us know
					);
				}

				$args['skip_dirs'] = array_merge($args['skip_dirs'], array(
					$backups->get_tmp_dir() => true,
					$backups->get_backups_dir() => true,
					$upload_dir .'/backup' => true, // Backup v1
					fw_get_framework_directory() => true, // prevent framework delete, it must exist and continue task execution
				));
			}
		}

		if (empty($state)) {
			// prepare destinations
			{
				$fs_required = false;
				$upload_dir_regex = '/^'. preg_quote($upload_dir, '/') .'/';

				$destinations = array(
					'no_fs' => array(), // file operations are done via mkdir|copy|move|...
					'fs' => array(), // file operations are done via WP_Filesystem
				);

				foreach ($args['destinations'] as $dir_id => $dir_path) {
					$dir_path = fw_fix_path($dir_path);

					if (
						!file_exists($args['source_dir'] .'/'. $dir_id)
						||
						!file_exists($dir_path)
					) {
						continue;
					}

					$is_in_uploads = preg_match($upload_dir_regex, $dir_path);

					$destinations[$is_in_uploads ? 'no_fs' : 'fs'][$dir_id] = array(
						'fs' => !$is_in_uploads,
						'dir' => $dir_path,
					);

					if (!$is_in_uploads) {
						$fs_required = true;
					}
				}

				$destinations = array_merge($destinations['no_fs'], $destinations['fs']);

				if (empty($destinations)) {
					return true;
				}
			}

			$state = array(
				'pending_destinations' => $destinations,
				'current_destination' => array(
					'id' => null,
					'data' => null,
				),
				'fs_required' => $fs_required,
			);

			unset($destinations);

			$state['current_destination']['id'] = key($state['pending_destinations']);
			$state['current_destination']['data'] = array_shift($state['pending_destinations']);
		}

		if ($state['fs_required']) {
			if (!FW_WP_Filesystem::has_direct_access(ABSPATH)) {
				if (empty($args['filesystem_args'])) {
					return new WP_Error(
						'fs_no_access', __('No filesystem access, credentials required', 'fw')
					);
				} elseif (!WP_Filesystem($args['filesystem_args'])) {
					return new WP_Error(
						'invalid_fs_credentials', __('No filesystem access, invalid credentials', 'fw')
					);
				}
			} else {
				if (!WP_Filesystem()) {
					return new WP_Error(
						'fs_init_fail', __('Filesystem init failed', 'fw')
					);
				}
			}

			global $wp_filesystem; /** @var WP_Filesystem_Base $wp_filesystem */

			if (!$wp_filesystem || (is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->get_error_code())) {
				return new WP_Error(
					'fs_init_fail', __('Filesystem init failed', 'fw')
				);
			}
		}

		while ($state['current_destination']['id']) {
			if (is_wp_error($result = $this->clear_dir(
				$state['current_destination']['data']['dir'],
				$state['current_destination']['data']['fs'],
				$args['skip_dirs']
			))) {
				return $result;
			}

			if (is_wp_error($result = $this->copy_dir(
				$args['source_dir'] .'/'. $state['current_destination']['id'],
				$state['current_destination']['data']['dir'],
				$state['current_destination']['data']['fs'],
				$args['skip_dirs']
			))) {
				return $result;
			}

			$state['current_destination']['id'] = key($state['pending_destinations']);
			$state['current_destination']['data'] = array_shift($state['pending_destinations']);
		}

		return true;
	}

	/**
	 * @param string $dir path
	 * @param bool $fs Use WP_Filesystem or not
	 * @param array $skip_dirs {'path': mixed}
	 *
	 * @return WP_Error|true
	 */
	private function clear_dir($dir, $fs, $skip_dirs) {
		$included_hidden_names = fw_ext('backups')->get_config('included_hidden_names');

		if ($fs) {
			global $wp_filesystem; /** @var WP_Filesystem_Base $wp_filesystem */

			$fs_dir = fw_fix_path(FW_WP_Filesystem::real_path_to_filesystem_path($dir));

			if (empty($fs_dir)) {
				return new WP_Error(
					'dir_to_fs_failed',
					sprintf(__('Cannot convert Filesystem path: %s', 'fw'), $dir)
				);
			} elseif (false === ($list = $wp_filesystem->dirlist($fs_dir, true))) {
				return new WP_Error(
					'dir_list_failed',
					sprintf(__('Failed to list dir: %s', 'fw'), $dir)
				);
			}

			foreach ($list as $file) {
				if ($file['name'][0] === '.' && !isset($included_hidden_names[$file['name']])) {
					continue;
				}

				$file_path = $dir .'/'. $file['name'];
				$fs_file_path = $fs_dir .'/'. $file['name'];

				if ($file['type'] === 'd') {
					if (isset($skip_dirs[$file_path])) {
						continue;
					} else {
						foreach ($skip_dirs as $skip_dir => $skip_dir_data) {
							if (
								strlen(preg_replace('/^'. preg_quote($file_path, '/') .'/', '', $skip_dir))
								!=
								strlen($skip_dir)
							) {
								continue 2; // skip dir if it's inside current dir
							}
						}
					}

					if (!$wp_filesystem->rmdir($fs_file_path, true)) {
						return new WP_Error(
							'dir_rm_fail',
							sprintf(__('Failed to remove dir: %s', 'fw'), $file_path)
						);
					}
				} else {
					if (!$wp_filesystem->delete($fs_file_path)) {
						return new WP_Error(
							'file_rm_fail',
							sprintf(__('Failed to remove file: %s', 'fw'), $file_path)
						);
					}
				}
			}

			return true;
		} else {
			$files = array_diff(($files = scandir($dir)) ? $files : array(), array('.', '..'));

			foreach ($files as $file_name) {
				$file_path = $dir .'/'. $file_name;

				if ($file_name[0] === '.' && !isset($included_hidden_names[$file_name])) {
					continue;
				}

				if (is_dir($file_path)) {
					if (isset($skip_dirs[ $file_path ])) {
						continue;
					} else {
						foreach ($skip_dirs as $skip_dir => $skip_dir_data) {
							if (
								strlen(preg_replace('/^'. preg_quote($file_path, '/') .'/', '', $skip_dir))
								!=
								strlen($skip_dir)
							) {
								continue 2; // skip dir it's inside current dir
							}
						}
					}

					if (!fw_ext_backups_rmdir_recursive($file_path)) {
						return new WP_Error(
							'dir_rm_fail',
							sprintf(__('Failed to remove dir: %s', 'fw'), $file_path)
						);
					}
				} else {
					if (!unlink($file_path)) {
						return new WP_Error(
							'file_rm_fail',
							sprintf(__('Failed to remove file: %s', 'fw'), $file_path)
						);
					}
				}
			}

			return true;
		}
	}

	/**
	 * @param string $source_dir path
	 * @param string $destination_dir path
	 * @param bool $fs Use WP_Filesystem or not
	 * @param array $skip_dirs {'path': mixed}
	 *
	 * @return WP_Error|true
	 */
	private function copy_dir($source_dir, $destination_dir, $fs, $skip_dirs) {
		$included_hidden_names = fw_ext('backups')->get_config('included_hidden_names');

		if ($fs) {
			global $wp_filesystem; /** @var WP_Filesystem_Base $wp_filesystem */

			$fs_source_dir = fw_fix_path(FW_WP_Filesystem::real_path_to_filesystem_path($source_dir));

			if (empty($fs_source_dir)) {
				return new WP_Error(
					'dir_to_fs_failed',
					sprintf(__('Cannot convert Filesystem path: %s', 'fw'), $source_dir)
				);
			} elseif (false === ($list = $wp_filesystem->dirlist($fs_source_dir, true))) {
				return new WP_Error(
					'dir_list_failed',
					sprintf(__('Failed to list dir: %s', 'fw'), $source_dir)
				);
			}

			foreach ($list as $file) {
				if ($file['name'][0] === '.' && !isset($included_hidden_names[$file['name']])) {
					continue;
				}

				$file_path = $source_dir .'/'. $file['name'];
				$fs_file_path = $fs_source_dir .'/'. $file['name'];
				$destination_file_path = $destination_dir .'/'. $file['name'];
				$fs_destination_file_path = fw_fix_path(FW_WP_Filesystem::real_path_to_filesystem_path(
					$destination_file_path
				));

				if (empty($fs_destination_file_path)) {
					return new WP_Error(
						'path_to_fs_failed',
						sprintf(__('Cannot convert Filesystem path: %s', 'fw'), $destination_file_path)
					);
				}

				if ($file['type'] === 'd') {
					if (isset($skip_dirs[$destination_file_path])) {
						continue;
					} else {
						foreach ($skip_dirs as $skip_dir => $skip_dir_data) {
							if (
								strlen(preg_replace('/^'. preg_quote($destination_file_path, '/') .'/', '', $skip_dir))
								!=
								strlen($skip_dir)
							) {
								continue 2; // skip dir if it's inside current dir
							}
						}
					}

					if (!$wp_filesystem->mkdir($fs_destination_file_path)) {
						return new WP_Error(
							'fs_mkdir_fail',
							sprintf(__('Failed to create dir: %s', 'fw'), $destination_file_path)
						);
					}

					if (is_wp_error($result = copy_dir(
						$fs_file_path, $fs_destination_file_path
					))) {
						return $result;
					}
				} else {
					if (!$wp_filesystem->copy($fs_file_path, $fs_destination_file_path)) {
						return new WP_Error(
							'file_copy_fail',
							sprintf(__('Failed to copy file: %s', 'fw'), $file_path)
						);
					}
				}
			}

			return true;
		} else {
			$names = array_diff(($names = scandir($source_dir)) ? $names : array(), array('.', '..'));

			foreach ($names as $file_name) {
				$file_path = $source_dir .'/'. $file_name;
				$destination_file_path = $destination_dir .'/'. $file_name;

				if ($file_name[0] === '.' && !isset($included_hidden_names[$file_name])) {
					continue;
				}

				if (is_dir($file_path)) {
					if (isset($skip_dirs[ $destination_file_path ])) {
						continue;
					} else {
						foreach ($skip_dirs as $skip_dir => $skip_dir_data) {
							if (
								strlen(preg_replace('/^'. preg_quote($destination_file_path, '/') .'/', '', $skip_dir))
								!=
								strlen($skip_dir)
							) {
								continue 2; // skip dir it's inside current dir
							}
						}
					}

					if (is_dir($destination_file_path)) {
						/**
						 * Some times empty directories are not deleted ( @see fw_ext_backups_rmdir_recursive() )
						 * even if rmdir() returns true, the directory remains (I don't know why),
						 * so a workaround is to check if it exists and do not try to created it
						 * (because create will return false)
						 */
					} elseif (!mkdir($destination_file_path)) {
						return new WP_Error(
							'dir_mk_fail',
							sprintf(__('Failed to create dir: %s', 'fw'), $destination_file_path)
						);
					}

					if (is_wp_error($result = $this->copy_dir(
						$file_path, $destination_file_path, $fs, array()
					))) {
						return $result;
					}
				} else {
					if (!copy($file_path, $destination_file_path)) {
						return new WP_Error(
							'file_copy_fail',
							sprintf(__('Failed to copy file: %s', 'fw'), $file_path)
						);
					}
				}
			}

			return true;
		}
	}
}
