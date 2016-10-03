<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Image_Sizes_Restore extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'image-sizes-restore';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Image Sizes Restore', 'fw');
	}

	public function get_custom_timeout(array $args, array $state = array()) {
		/**
		 * There is no need for more
		 * because only on image size is generated per request,
		 * if 10+ seconds is not enough, then for sure there is a problem
		 */
		return 27;
	}

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
				// Process one image size per request
				'pending_sizes' => array(),
				// Collect the processed sizes and use this value as attachment meta to prevent regenerating all sizes
				'processed_sizes' => array(),
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

		if (empty($state['pending_sizes'])) {
			$state['pending_sizes'] = get_intermediate_image_sizes();
		}

		foreach ( $attachments as $attachment ) {
			if ( file_exists( $file = get_attached_file( $attachment['ID'] ) ) ) {
				$this->current_size = array_shift($state['pending_sizes']);
				add_filter('intermediate_image_sizes', array($this, '_filter_image_sizes'));

				$meta = wp_generate_attachment_metadata( $attachment['ID'], $file );

				$this->current_size = '';
				remove_filter('intermediate_image_sizes', array($this, '_filter_image_sizes'));

				$state['processed_sizes'] = array_merge($state['processed_sizes'], $meta['sizes']);
				$meta['sizes'] = $state['processed_sizes'];

				wp_update_attachment_metadata( $attachment['ID'], $meta );
			}
		}

		if (empty($state['pending_sizes'])) { // proceed to next attachment if all sizes has been generated
			$state['attachment_id'] = $attachment['ID'];
			$state['processed_sizes'] = array();
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
