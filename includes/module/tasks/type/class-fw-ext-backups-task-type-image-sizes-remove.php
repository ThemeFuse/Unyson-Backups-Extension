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
			"WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d",
			"ORDER BY ID",
			"LIMIT 7"
		), " \n");

		$started_time = time();
		$timeout = $backups->get_timeout() - 7;

		while (time() - $started_time < $timeout) {
			$attachments = $wpdb->get_results( $wpdb->prepare(
				$sql,
				$wpdb->esc_like('image/').'%',
				$state['attachment_id'] ), ARRAY_A );


			if (empty($attachments)) {
				return true;
			}

			foreach ($attachments as $attachment) {
				$this->remove_intermediate_images($attachment['ID'], $args['uploads_dir']);
			}

			$state['attachment_id'] = $attachment['ID'];
		}

		return $state;
	}

	public function remove_intermediate_images( $id , $uploads_dir) {
		$meta         = wp_get_attachment_metadata( $id );
		$backup_sizes = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		$file         = get_attached_file( $id );
		$wp_uploads_dir = wp_upload_dir();

		// Remove intermediate and backup images if there are any.
		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $sizeinfo ) {
				$file_path = $uploads_dir . preg_replace('/^'. preg_quote(fw_fix_path($wp_uploads_dir['basedir']), '/') .'/', '', $file);
				$intermediate_file = str_replace( basename( $file ), $sizeinfo['file'], $file_path );
				@unlink($intermediate_file);
			}
		}
		if ( is_array( $backup_sizes ) ) {
			foreach ( $backup_sizes as $size ) {
				$del_file    = path_join( dirname( $meta['file'] ), $size['file'] );

				@ unlink( path_join($uploads_dir, $del_file) );
			}
		}
	}

}
