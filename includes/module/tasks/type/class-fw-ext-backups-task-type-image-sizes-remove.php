<?php if (!defined('FW')) die('Forbidden');

class FW_Ext_Backups_Task_Type_Image_Sizes_Remove extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'image-sizes-remove';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Image Sizes Remove', 'fw');
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * uploads_dir - path to exported uploads dir (not the actual WP uploads dir! that remains untouched)
	 */
	public function execute(array $args, array $state = array()) {
		$backups = fw_ext('backups'); /** @var FW_Extension_Backups $backups */

		{
			if (empty($args['uploads_dir'])) {
				return new WP_Error(
					'no_uploads_dir', __('Uploads dir not specified', 'fw')
				);
			} else {
				$args['uploads_dir'] = fw_fix_path($args['uploads_dir']);

				if (!file_exists($args['uploads_dir'])) {
					/**
					 * The uploads directory was not exported, nothing to do.
					 */
					return true;
				}
			}
		}

		if (empty($state)) {
			$state = array(
				// The attachment at which the execution stopped and will continue in next request
				'attachment_id' => 0,
			);
		}

		/**
		 * @var WPDB $wpdb
		 */
		global $wpdb;

		$sql = implode( array(
			"SELECT * FROM {$wpdb->posts}",
			"WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND ID > %d",
			"ORDER BY ID",
			"LIMIT 7"
		), " \n");

		$started_time = time();
		$timeout = $backups->get_timeout() - 7;

		while (time() - $started_time < $timeout) {
			$attachments = $wpdb->get_results( $wpdb->prepare( $sql, $state['attachment_id'] ), ARRAY_A );

			if (empty($attachments)) {
				return true;
			}

			foreach ($attachments as $attachment) {
				// todo: delete sizes from: $args['uploads_dir'] .'/path/to/size.jpg'
			}

			$state['attachment_id'] = $attachment['ID'];
		}

		return $state;
	}
}
