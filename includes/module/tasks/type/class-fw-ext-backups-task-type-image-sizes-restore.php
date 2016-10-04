<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Image_Sizes_Restore extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'image-sizes-restore';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Image Sizes Restore', 'fw');
	}

	public function get_custom_timeout(array $args, array $state = array()) {
		return fw_ext( 'backups' )->get_config('max_timeout');
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 */
	public function execute(array $args, array $state = array()) {
		if ( empty( $state ) ) {
			$state = array(
				// The attachment at which the execution stopped and will continue in next request
				'attachment_id' => 0,
				// Process one image size per request
				'pending_sizes' => array(),
				// Collect the processed sizes and use this value as attachment meta to prevent regenerating all sizes
				'processed_sizes' => array(),
			);
		}

		/** @var WPDB $wpdb */
		global $wpdb;

		$max_time = time() + fw_ext( 'backups' )->get_timeout() - 7;

		while (time() < $max_time) {
			if ( $attachment_id = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}"
					." WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d"
					." ORDER BY ID"
					." LIMIT 1",
					$wpdb->esc_like( 'image/' ) . '%', $state['attachment_id']
				),
				0
			) ) {
				$attachment_id = array_pop($attachment_id);
			} else {
				return true;
			}

			if ( $file_exists = file_exists( $file = get_attached_file( $attachment_id ) ) ) {
				if (empty($state['pending_sizes'])) {
					$state['pending_sizes'] = get_intermediate_image_sizes();
					$state['processed_sizes'] = array();
				}

				while (time() < $max_time && !empty($state['pending_sizes'])) {
					{
						$this->current_size = array_shift($state['pending_sizes']);
						add_filter('intermediate_image_sizes', array($this, '_filter_image_sizes'));

						$meta = wp_generate_attachment_metadata($attachment_id, $file);

						$this->current_size = '';
						remove_filter('intermediate_image_sizes', array($this, '_filter_image_sizes'));
					}

					$state['processed_sizes'] = array_merge($state['processed_sizes'], $meta['sizes']);
				}

				if (isset($meta)) {
					$meta['sizes'] = $state['processed_sizes'];
					wp_update_attachment_metadata($attachment_id, $meta);
					unset($meta);
				}
			}

			if (empty($state['pending_sizes']) || !$file_exists) { // Proceed to next attachment
				$state['attachment_id'] = $attachment_id;
				$state['processed_sizes'] = $state['pending_sizes'] = array();
			}
		}

		return $state;
	}

	private $current_size = '';

	/**
	 * Leave only one image size so it will be generated fast and will prevent timeout
	 * @param array $sizes
	 * @return array
	 */
	public function _filter_image_sizes($sizes) {
		if ($this->current_size) {
			return array($this->current_size);
		} else {
			return $sizes;
		}
	}
}
