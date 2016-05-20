<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Image_Sizes_Restore extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'image-sizes-restore';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Image Sizes Restore', 'fw');
	}

	/**
	 * Even if we process only one image per request, some times happens that:
	 * image is big and it has a lot of resizes and the script times out
	 * {@inheritdoc}
	 */
	public function get_custom_timeout(array $args, array $state = array()) {
		return fw_ext('backups')->get_config('max_timeout');
	}

	/**
	 * @var int Currently used/whitelisted/processed size
	 */
	private $current_size_index = -1;

	/**
	 * {@inheritdoc}
	 * @param array $args
	 */
	public function execute(array $args, array $state = array()) {
		$backups = fw_ext( 'backups' );
		/** @var FW_Extension_Backups $backups */

		if ( empty( $state ) ) {
			$state = array(
				// The attachment at which the execution stopped and will continue in next request
				'attachment_id' => 0,
				'size_index' => 0,
			);
		}

		/**
		 * @var WPDB $wpdb
		 */
		global $wpdb;

		$sql = implode( array(
			"SELECT * FROM {$wpdb->posts}",
			"WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d",
			"ORDER BY ID",
			"LIMIT 1"
		), " \n" );


		$attachments = $wpdb->get_results( $wpdb->prepare(
			$sql, $wpdb->esc_like( 'image/' ) . '%', $state['attachment_id']
		), ARRAY_A );

		if ( empty( $attachments ) ) {
			return true;
		}

		foreach ( $attachments as $attachment ) {
			if ( ! file_exists( $file = get_attached_file( $attachment['ID'] ) ) ) {
				continue;
			}

			// Generate one size
			{
				$this->current_size_index = $state['size_index'];

				add_filter('intermediate_image_sizes_advanced', array($this, '_filter_image_sizes'), 99999);

				wp_update_attachment_metadata(
					$attachment['ID'], wp_generate_attachment_metadata( $attachment['ID'], $file )
				);

				remove_filter('intermediate_image_sizes_advanced', array($this, '_filter_image_sizes'), 99999);

				if ($this->current_size_index != $state['size_index']) {
					$state['size_index'] = $this->current_size_index;
				} else {
					return new WP_Error(
						'size_restore_fail',
						sprintf(
							__('Failed to process size %d of attachment %d', 'fw'),
							$state['size_index'], $attachment['ID']
						)
					);
				}
			}

			if ($state['size_index'] == -1) { // Sizes were processed one by one, now update meta with all sizes
				if ( ! defined('wp_restore_image') ) {
					require_once ABSPATH .'wp-admin/includes/image-edit.php';
				}

				wp_restore_image($attachment['ID']);

				$state['size_index'] = 0;
			} else {
				return $state;
			}
		}

		$state['attachment_id'] = $attachment['ID'];

		return $state;
	}

	/**
	 * Pick sizes one by one then all at the end
	 * @param array $sizes
	 * @return array
	 */
	public function _filter_image_sizes($sizes) {
		if (count($sizes) > $this->current_size_index) { // current index not reached sizes end
			$keys = array_keys( $sizes );
			$size = $keys[ $this->current_size_index ];

			++$this->current_size_index;

			return array(
				$size => $sizes[ $size ]
			);
		} else {
			$this->current_size_index = -1;

			return $sizes;
		}
	}
}
