<?php if ( ! defined( 'FW' ) ) die( 'Forbidden' );

/**
 * Download zip piece by piece (not at once, to prevent timeout)
 */
class FW_Ext_Backups_Task_Type_Download_Piecemeal extends FW_Ext_Backups_Task_Type_Download_Type {
	public function get_type() {
		return 'piecemeal';
	}

	public function get_title(array $args = array(), array $state = array()) {
		if (empty($state)) {
			return __( 'Downloading', 'fw' );
		} elseif ('download' === $state['type']) {
			if (!empty($state['filesize']) && $state['position']) {
				return sprintf(
					__( 'Downloading (%s of %s)', 'fw' ),
					size_format($state['position']), size_format($state['filesize'])
				);
			} else {
				return __( 'Downloading', 'fw' );
			}
		} elseif ('unzip' === $state['type']) {
			if ($state['extracted_files']) {
				return sprintf(__('Archive Unzip (%d files extracted)', 'fw'), $state['extracted_files']);
			} else {
				return __('Download finished. Doing unzip', 'fw');
			}
		} else {
			return __( 'Downloading (invalid state)', 'fw' );
		}
	}

	private function get_min_piece_size() {
		return $this->get_mb_in_bytes();
	}

	private function get_mb_in_bytes() {
		return 1000 * 1000;
	}

	/**
	 * {@inheritdoc}
	 * @param $args
	 * * destination_dir - Path to dir where the downloaded files must be placed
	 * * url - Remote php script that will send the pieces of the zip file
	 * * file_id - File name/id registered in server script
	 */
	public function download(array $args, array $state = array()) {
		// Note: destination_dir is already validated

		{
			if (empty($args['url'])) {
				return new WP_Error(
					'no_url',
					__('Url not specified', 'fw')
				);
			} elseif (filter_var($args['url'], FILTER_VALIDATE_URL) === false) {
				return new WP_Error(
					'invalid_url',
					__('Invalid url', 'fw')
				);
			}

			if (empty($args['file_id'])) {
				return new WP_Error(
					'no_file_id',
					__('File id not specified', 'fw')
				);
			} elseif (!is_string($args['file_id'])) {
				return new WP_Error(
					'invalid_file_id',
					__('Invalid file id', 'fw')
				);
			}
		}

		if (empty($state)) {
			$state = array(
				'type' => 'download', // 'unzip'
				'position' => 0, // byte position in file (also can be used as 'downloaded bytes')
				'file_size' => 0, // total file size in bytes
				'piece_size' => $this->get_mb_in_bytes() * 3, // piece size in bytes
			);
		}

		$zip_path = $args['destination_dir'] .'/'. $this->get_type() .'.zip';

		if ('download' === $state['type']) {
			if (true === ($state = $this->do_download($args, $state, $zip_path))) {
				return array(
					'type' => 'unzip',
					'entry' => '',
					'extracted_files' => 0
				);
			} else {
				return $state;
			}
		} elseif ('unzip' === $state['type']) {
			return $this->do_unzip($args, $state, $zip_path);
		} else {
			return new WP_Error(
				'invalid_state',
				__('Partial download invalid state', 'fw')
			);
		}
	}

	private function do_download($args, $state, $zip_path) {
		/** @var FW_Extension_Backups $backups */
		$backups = fw_ext('backups');

		$response = wp_remote_get(add_query_arg(
			array_merge(
				/**
				 * @since 2.0.18
				 */
				apply_filters(
					'fw:ext:backups:task-type:piecemeal-download:query-args',
					$default_query_args = array(
						'id' => urlencode($args['file_id']),
						'position' => $state['position'],
						'size' => $state['piece_size']
					),
					$args
				),
				$default_query_args
			),
			$args['url']
		), array(
			'timeout' => $backups->get_timeout() - 7
		));

		if (is_wp_error($response)) {
			if (
				($state['piece_size'] = abs($state['piece_size'] - $this->get_mb_in_bytes()))
				&&
				$state['piece_size'] >= $this->get_min_piece_size()
			) {
				return $state;
			}

			return $response;
		} elseif (200 !== ($response_code = intval(wp_remote_retrieve_response_code($response)))) {
			return new WP_Error(
				'request_failed',
				sprintf(__('Request failed. Error code: %d', 'fw'), $response_code)
			);
		} elseif (
		(
			!($position = intval(isset($response['headers']['x-position']) ? $response['headers']['x-position'] : 0))
			||
			($position > 0 && $position <= $state['position'])
		)
		) {
			return new WP_Error(
				'invalid_position',
				__('Invalid byte position', 'fw') .' (current: '. $state['position'] .', received: '. $position .')'
			);
		} elseif ($position > 0 && empty($response['body'])) {
			return new WP_Error(
				'empty_body',
				__('Empty response body', 'fw')
			);
		}

		if (!$state['position']) {
			if (
				($filesize = intval(isset($response['headers']['x-filesize']) ? $response['headers']['x-filesize'] : 0))
				&&
				$filesize > 0
				&&
				$filesize > $position
			) {
				$state['filesize'] = $filesize;
			}
		}

		if ($position < 0) {
			if (!$state['position']) {
				return new WP_Error(
					'empty_file',
					__('File ended without content', 'fw')
				);
			}

			$state['position'] = $position;

			return true;
		}

		$state['position'] = $position;

		if (!($f = fopen($zip_path, $state['position'] ? 'a' : 'w'))) {
			return new WP_Error(
				'file_open_fail',
				__('Failed to open file', 'fw')
			);
		}

		if (substr($response['body'], 0, 3) === "\xEF\xBB\xBF") {
			/**
			 * Remove UTF-8 BOM added by the server
			 * Fixes https://github.com/ThemeFuse/Unyson-Backups-Extension/issues/25
			 */
			$response['body'] = substr($response['body'], 3);
		}

		$write_result = fwrite($f, $response['body']);

		fclose($f);

		if (false === $write_result) {
			return new WP_Error(
				'file_write_fail',
				__('Failed to write data to file', 'fw')
			);
		}

		return $state;
	}

	private function do_unzip($args, $state, $zip_path) {
		if (is_wp_error($extract_result = fw_ext_backups_unzip_partial(
			$zip_path, $args['destination_dir'], $state['entry'], 1
		))) {
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
