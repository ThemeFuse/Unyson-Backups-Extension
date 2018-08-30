<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Image_Sizes_Restore extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'image-sizes-restore';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Image Sizes Restore', 'fw') .(
			empty($state)
				? ''
				: ' '. sprintf(__('(%d of %d)', 'fw'), $state['processed_images'], $state['total_images'])
		);
	}

	public function get_custom_timeout(array $args, array $state = array()) {
		/** @var FW_Extension_Backups $backups */
		$backups = fw_ext( 'backups' );

		/**
		 * Use a small value because problems with this step are quite often
		 * and it is frustrating to wait 10+ minutes just to see the Timed Out error (better to see it earlier)
		 */
		return $backups->get_task_step_execution_threshold() - 1;
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 */
	public function execute(array $args, array $state = array()) {
		/** @var WPDB $wpdb */
		global $wpdb;

		$args = array_merge(
			array('remove_old_files' => false),
			$args
		);

		if ( empty( $state ) ) {
			$state = array(
				// The attachment at which the execution stopped and will continue in next request
				'attachment_id' => 0,
				// Process one image size per request
				'pending_sizes' => array(),
				// Collect the processed sizes and use this value as attachment meta to prevent regenerating all sizes
				'processed_sizes' => array(),
				// Count the processed images
				'processed_images' => 0,
			);

			if ($state['total_images'] = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT COUNT(1) as total_images FROM {$wpdb->posts}"
					." WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND post_mime_type NOT LIKE %s"
					." LIMIT 1",
					$wpdb->esc_like( 'image/' ) . '%',
					$wpdb->esc_like( 'image/svg' ) . '%'
				),
				0
			)) {
				$state['total_images'] = array_pop($state['total_images']);
			} else {
				return new WP_Error(
					'total_images_fail',
					__('Cannot get total images count', 'fw')
				);
			}
		}

		$max_time = time() + $this->get_custom_timeout( array(), array() ) - 20;

		while (time() < $max_time) {
			if ( $attachment_id = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}"
					." WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND post_mime_type NOT LIKE %s AND ID > %d"
					." ORDER BY ID"
					." LIMIT 1",
					$wpdb->esc_like( 'image/' ) . '%',
					$wpdb->esc_like( 'image/svg' ) . '%',
					$state['attachment_id']
				),
				0
			) ) {
				$attachment_id = array_pop($attachment_id);
			} else {
				return true;
			}

			if (
				isset($args['remove_old_files'])
				&&
				$args['remove_old_files']
				&&
				// Only remove when we start to process a single attachment
				empty($state['processed_sizes'])
			) {
				$this->_remove_images_without_sizes_for_attachment(
					$attachment_id
				);
			}

			if ( $file_exists = file_exists( $file = get_attached_file( $attachment_id ) ) ) {
				if (empty($state['pending_sizes'])) {
					$state['pending_sizes'] = apply_filters( 'fw_ext_backups_restore_exclude_img_sizes', get_intermediate_image_sizes() );
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
				++$state['processed_images'];
			}
		}

		return $state;
	}

	private $current_size = '';

	/**
	 * Very similar to the wp-cli implementation
	 * https://github.com/wp-cli/media-command/blob/e5574686c01de22ef294efc57f71dc05bd7c162e/src/Media_Command.php#L530-L555
	 */
	public function _remove_images_without_sizes_for_attachment($attachment_id) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( empty( $metadata['sizes'] ) ) {
			return;
		}

		$fullsizepath = get_attached_file($attachment_id);

		$dir_path = dirname( $fullsizepath ) . '/';

		foreach ( $metadata['sizes'] as $size_info ) {
			$intermediate_path = $dir_path . $size_info['file'];

			if ( $intermediate_path === $fullsizepath )
				continue;

			if ( file_exists( $intermediate_path ) )
				@unlink( $intermediate_path );
		}
	}

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
