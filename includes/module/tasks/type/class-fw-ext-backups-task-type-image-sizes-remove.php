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

		$sql = implode( " \n", array(
			"SELECT * FROM {$wpdb->posts}",
			"WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d",
			"ORDER BY ID",
			"LIMIT 7"
		) );

		$wp_uploads_dir = wp_upload_dir();
		$wp_uploads_dir_length = mb_strlen($wp_uploads_dir['basedir']);

		$max_time = time() + fw_ext( 'backups' )->get_timeout(-7);

		while (time() < $max_time) {
			$attachments = $wpdb->get_results( $wpdb->prepare(
				$sql, $wpdb->esc_like('image/').'%', $state['attachment_id']
			), ARRAY_A );

			if (empty($attachments)) {
				return true;
			}

			foreach ($attachments as $attachment) {
				if (
					($meta = wp_get_attachment_metadata( $attachment['ID'] ))
					&&
					isset( $meta['sizes'] )
					&&
					is_array( $meta['sizes'] )
				) {
					$attachment_path = get_attached_file( $attachment['ID'] );
					$filename_length = mb_strlen( basename( $attachment_path ) );

					foreach ( $meta['sizes'] as $size => $sizeinfo ) {
						// replace wp_uploads_dir path
						$file_path = $args['uploads_dir'] . mb_substr($attachment_path, $wp_uploads_dir_length);
						// replace file name with resize name
						$file_path = mb_substr($file_path, 0, -$filename_length) . $sizeinfo['file'];

						if (file_exists($file_path)) {
							@unlink( $file_path );
						}
					}
				}

				if ( is_array( $backup_sizes = get_post_meta( $attachment['ID'], '_wp_attachment_backup_sizes', true ) ) ) {
					foreach ( $backup_sizes as $size ) {
						$del_file = path_join( dirname( $meta['file'] ), $size['file'] );

						@unlink( path_join($args['uploads_dir'], $del_file) );
					}
				}
			}

			$state['attachment_id'] = $attachment['ID'];
		}

		return $state;
	}
}
