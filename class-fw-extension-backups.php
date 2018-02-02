<?php if (!defined('FW')) die('Forbidden');

class FW_Extension_Backups extends FW_Extension {
	/**
	 * @var _FW_Ext_Backups_Module_Tasks
	 */
	private $tasks;

	/**
	 * @return _FW_Ext_Backups_Module_Tasks
	 */
	public function tasks() {
		return $this->tasks;
	}

	/**
	 * @var _FW_Ext_Backups_Module_Schedule
	 */
	private $schedule;

	/**
	 * @return _FW_Ext_Backups_Module_Schedule
	 */
	public function schedule() {
		return $this->schedule;
	}

	private static $wp_ajax_action_status  = 'fw:ext:backups:status';
	private static $wp_ajax_action_backup  = 'fw:ext:backups:backup';
	private static $wp_ajax_action_restore = 'fw:ext:backups:restore';
	private static $wp_ajax_action_delete  = 'fw:ext:backups:delete';
	private static $wp_ajax_action_cancel  = 'fw:ext:backups:cancel';

	private static $wp_ajax_action_test    = 'fw:ext:backups:test';

	private static $download_GET_parameter = 'download-archive';

	/**
	 * Also can be used as "is current user allowed to make backups?"
	 * @return string
	 */
	public function get_capability() {
		/**
		 * https://codex.wordpress.org/Roles_and_Capabilities#Capability_vs._Role_Table
		 * Should work on both single and multi-site
		 */
		return 'export';
	}

	/**
	 * @param int $sum Since 2.0.16
	 * @return int
	 */
	public function get_timeout($sum = 0) {
		$timeout = (int)ini_get('max_execution_time');

		/**
		 * Fix timeout value
		 * For e.g. timeout 0 messes up the tasks execution verification logic
		 */
		if ($timeout < 1 || $timeout > $this->get_config('max_timeout')) {
			$timeout = $this->get_config('max_timeout');
		}

		return max($timeout + $sum, 1); // Prevent negative or 0 value
	}

	/**
	 * If a task step execution takes more that this amount of second, then perhaps it is something wrong.
	 * @return int
	 * @since 2.0.14
	 */
	public function get_task_step_execution_threshold() {
		return 30; // http://php.net/manual/en/info.configuration.php#ini.max-execution-time
	}

	public function get_page_slug() {
		return 'fw-backups';
	}

	public function get_page_url() {
		if ($this->is_disabled()) {
			return;
		}

		$rel_path = 'admin.php?page=' . urlencode( $this->get_page_slug() );

		if (is_multisite() && is_network_admin()) {
			return network_admin_url( $rel_path );
		} else {
			return admin_url( $rel_path );
		}
	}

	/**
	 * On some installations the backup actions need to be disabled for security reasons
	 * (for e.g. public testlabs for clients to test your theme and demo content install)
	 * @return bool
	 * @since 2.0.1
	 */
	public function is_disabled() {
		$cache_key = $this->get_cache_key('/disabled');

		try {
			return FW_Cache::get($cache_key);
		} catch (FW_Cache_Not_Found_Exception $e) {
			$is_disabled = (
				is_multisite() && !current_user_can('manage_network_plugins') &&
				apply_filters('fw:ext:backups:multisite_disabled', false)
			);

			FW_Cache::set($cache_key, $is_disabled);

			return $is_disabled;
		}
	}

	/**
	 * @since 2.0.22
	 * @return string Error message
	 */
	public function server_requirements_not_met() {
		if (class_exists('ZipArchive')) {
			return false;
		} else {
			return sprintf(
				__('Oops, %s requires %s but it is not enabled on your server. If you are not familiar with PHP Zip module, please contact your hosting provider.', 'fw'),
				fw_html_tag('a', array(
					'href' => function_exists('menu_page_url')
						? menu_page_url(fw()->extensions->manager->get_page_slug(), false) .'#ext-backups'
						: '#',
				), __('Unyson Backup', 'fw')),
				fw_html_tag('a', array(
					'href' => 'https://www.google.com/search#q=hosting+enable+php+zip',
					'target' => '_blank',
				), __('PHP Zip module', 'fw'))
			);
		}
	}

	protected function _init() {

		if ( is_admin() && isset( $_SERVER['SERVER_SOFTWARE'] ) && strpos( $_SERVER['SERVER_SOFTWARE'], 'LiteSpeed' ) !== false ) {
			if ( ! is_file( ABSPATH . '.htaccess' ) || ! preg_match( '/noabort/i', file_get_contents( ABSPATH . '.htaccess' ) ) ) {
				add_action( 'admin_notices', array( $this, '_action_admin_notices_litespeed' ) );
			}
		}

		{
			if (!$this->is_disabled()) {
				add_action('admin_menu', array($this, '_action_admin_menu'));
				add_action('current_screen',  array($this, '_action_download'));
				add_action('admin_enqueue_scripts', array($this, '_action_enqueue_scripts'));

				add_action('wp_ajax_' . self::$wp_ajax_action_status,  array($this, '_action_ajax_status'));
				add_action('wp_ajax_' . self::$wp_ajax_action_backup,  array($this, '_action_ajax_backup'));
				add_action('wp_ajax_' . self::$wp_ajax_action_restore, array($this, '_action_ajax_restore'));
				add_action('wp_ajax_' . self::$wp_ajax_action_delete,  array($this, '_action_ajax_delete'));
				add_action('wp_ajax_' . self::$wp_ajax_action_cancel,  array($this, '_action_ajax_cancel'));
			}

			add_action('network_admin_menu', array($this, '_action_admin_menu'));
			add_action('wp_ajax_nopriv_' . self::$wp_ajax_action_test,  array($this, '_action_ajax_test'));
		}

		$dir = dirname(__FILE__);

		// load and init modules/parts
		{
			require_once $dir .'/includes/module/class--fw-ext-backups-module.php';

			require_once $dir .'/includes/module/tasks/class--fw-ext-backups-module-tasks.php';
			$this->tasks = new _FW_Ext_Backups_Module_Tasks(self::get_access_key());

			require_once $dir .'/includes/module/schedule/class--fw-ext-backups-module-schedule.php';
			$this->schedule = new _FW_Ext_Backups_Module_Schedule(self::get_access_key());

			$this->tasks->_init();
			$this->schedule->_init();
		}

		require_once $dir .'/includes/log/init.php';
	}

	public function _action_admin_notices_litespeed() {

		$screen = get_current_screen();

		if ( ! $this->is_backups_page() && 'tools_page_fw-backups-demo-content' !== $screen->id ) {
			return;
		}

		echo
			'<div class="notice notice-warning">
				<p><strong>Unyson: </strong>' .
					sprintf(
						esc_html__( 'Your website is hosted using the LiteSpeed web server. Please consult this %sarticle%s if you have problems backing up.', 'fw' ),
						'<a href="http://manual.unyson.io/en/latest/extension/backups/index.html#litespeed-webserver" target="_blank">',
						'</a>'
					) .
				'</p>
			</div>';
	}

	/**
	 * @var FW_Access_Key
	 */
	private static $access_key;

	/**
	 * @return FW_Access_Key
	 */
	private static function get_access_key() {
		if (empty(self::$access_key)) {
			self::$access_key = new FW_Access_Key('fw:ext:backups');
		}

		return self::$access_key;
	}

	/**
	 * @return bool
	 */
	public function is_backups_page() {
		$current_screen = get_current_screen();

		if (!$current_screen) {
			return false;
		}

		$cache_key = $this->get_cache_key('/is_backups_page');

		try {
			return FW_Cache::get($cache_key);
		} catch (FW_Cache_Not_Found_Exception $e) {
			$is = false;

			foreach (array( '_page_'. $this->get_page_slug(), '_page_'. $this->get_page_slug() .'-network' ) as $suffix) {
				if (substr($current_screen->id, -strlen($suffix)) === $suffix) {
					$is = true;
					break;
				}
			}

			FW_Cache::set($cache_key, $is);

			return $is;
		}
	}

	public function _action_enqueue_scripts() {
		if ($this->is_backups_page()) {
			wp_enqueue_style('fw');
			wp_enqueue_media(); // needed for modal styles

			wp_enqueue_style(
				'fw-ext-backups',
				$this->get_uri('/static/style.css'),
				array('fw'),
				$this->manifest->get_version()
			);

			wp_enqueue_script(
				'fw-ext-backups',
				$this->get_uri('/static/scripts.js'),
				array('fw'),
				$this->manifest->get_version()
			);
			wp_localize_script(
				'fw-ext-backups',
				'_fw_ext_backups_localized',
				array_merge(
					apply_filters('fw:ext:backups:script_localized_data', array()),
					array(
						'ajax_action_status'  => self::$wp_ajax_action_status,
						'ajax_action_backup'  => self::$wp_ajax_action_backup,
						'ajax_action_restore' => self::$wp_ajax_action_restore,
						'ajax_action_delete'  => self::$wp_ajax_action_delete,
						'ajax_action_cancel'  => self::$wp_ajax_action_cancel,
						'l10n' => array(
							'abort_confirm' => __('Are you sure?', 'fw'),
						),
					)
				)
			);

			do_action('fw:ext:backups:enqueue_scripts');
		}
	}

	/**
	 * @internal
	 */
	public function _action_ajax_status() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		// in case the execution chain stopped and there is a pending task
		$this->tasks()->_request_next_step_execution(self::get_access_key());

		$is_busy = (bool)$this->tasks()->get_active_task_collection();
		$archives = $this->get_archives();

		$response = array(
			'is_busy' => $is_busy,
			'tasks_status' => array(
				'html' => $this->render_view('tasks-status', array(
					'active_task_collection' => $this->tasks()->get_active_task_collection(),
					'executing_task' => $this->tasks()->get_executing_task(),
					'pending_tasks' => $this->tasks()->get_pending_task(),
				)),
			),
			'archives' => array(
				'count' => count($archives),
				'html' => $this->render_view('archives', array(
					'archives' => $archives,
					'is_busy' => $is_busy,
				)),
			),
			'ajax_steps' => array(
				'token' => md5(
					defined('NONCE_SALT')
						? NONCE_SALT
						: $this->manifest->get_version()
				),
				'active_tasks_hash' => (($collection = $this->tasks()->get_active_task_collection())
					? md5(serialize($collection))
					: ''
				)
			),
		);

		wp_send_json_success(array_merge(
			apply_filters('fw_ext_backups_ajax_status_extra_response', array(), array('is_busy' => $is_busy)),
			$response
		));
	}

	/**
	 * @internal
	 */
	public function _action_ajax_backup() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		$this->tasks()->do_backup(
			!empty($_POST['full'])
			&&
			fw_ext_backups_current_user_can_full()
		);

		wp_send_json_success();
	}

	/**
	 * @internal
	 */
	public function _action_ajax_restore() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		$archives = $this->get_archives();

		if (
			empty($_POST['file'])
			||
			!isset($archives[ $filename = (string)$_POST['file'] ])
		) {
			wp_send_json_error(new WP_Error(
				'no_file', __('File not specified', 'fw')
			));
		}

		$fs_args = array();

		if ($archives[ $filename ]['full'] && !FW_WP_Filesystem::has_direct_access(ABSPATH)) {
			if (empty($_POST['filesystem_args'])) {
				wp_send_json_error(array(
					'message' => esc_html__('Filesystem access required', 'fw'),
					'request_fs' => true,
				));
			} else {
				$fs_args = $_POST['filesystem_args'];

				if (
					is_array($_POST['filesystem_args']) &&
					isset($fs_args['hostname']) && is_string($fs_args['hostname']) &&
					isset($fs_args['username']) && is_string($fs_args['username']) &&
					isset($fs_args['password']) && is_string($fs_args['password']) &&
					isset($fs_args['connection_type']) && is_string($fs_args['connection_type'])
				) {
					$fs_args = array(
						'hostname' => $fs_args['hostname'],
						'username' => $fs_args['username'],
						'password' => $fs_args['password'],
						'connection_type' => $fs_args['connection_type'],
					);

					if (!WP_Filesystem($fs_args, ABSPATH)) {
						wp_send_json_error(array(
							'message' => esc_html__('Invalid filesystem credentials', 'fw')
						));
					}
				} else {
					wp_send_json_error(array(
						'message' => esc_html__('Invalid filesystem credentials', 'fw')
					));
				}
			}
		}

		$this->tasks()->do_restore(
			$archives[ $filename ]['full'] && fw_ext_backups_current_user_can_full(),
			$archives[ $filename ]['path'],
			$fs_args
		);

		wp_send_json_success();
	}

	/**
	 * @internal
	 */
	public function _action_ajax_delete() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		$archives = $this->get_archives();

		if (
			empty($_POST['file'])
			||
			!isset($archives[ $filename = (string)$_POST['file'] ])
		) {
			wp_send_json_error(new WP_Error(
				'no_file', __('File not specified', 'fw')
			));
		}

		if (@unlink($archives[ $filename ]['path'])) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * @internal
	 */
	public function _action_ajax_cancel() {
		if (!current_user_can($this->get_capability())) {
			wp_send_json_error(new WP_Error('denied', 'Access Denied'));
		}

		if ($this->tasks()->do_cancel()) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		call_user_func_array(
			is_multisite() && is_network_admin() ? 'add_menu_page' : 'add_management_page',
			array(
				__( 'Backup', 'fw' ),
				__( 'Backup', 'fw' ),
				$this->get_capability(),
				$this->get_page_slug(),
				array( $this, '_render_page' )
			)
		);
	}

	/**
	 * @param null|bool Get only full or content backups
	 * @return array Descending date sorting
	 */
	public function get_archives($full = null) {
		$archives = array();

		if ($this->server_requirements_not_met()) {
			return $archives;
		} elseif ($paths = glob($this->get_backups_dir() .'/*.zip')) {
			foreach ( $paths as $path ) {
				{
					$zip = new ZipArchive();

					if ( true === $zip->open( $path ) ) {
						$is_full = (bool) (
							$zip->locateName( 'f/themes/index.php' ) !== false
							||
							$zip->locateName( 'f/plugins/index.php' ) !== false
						);

						$zip->close();
					} else {
						trigger_error('Cannot open zip: '. $path, E_USER_WARNING);
						continue;
					}
				}

				if (
					!is_null($full)
					&&
					$full != $is_full
				) {
					continue;
				}

				$archives[ basename( $path ) ] = array(
					'path' => $path,
					'full' => $is_full,
					'time' => filemtime($path),
				);
			}
		}

		uasort($archives, array($this, '_archive_sort_callback'));

		return $archives;
	}

	public function _archive_sort_callback($a, $b) {
		if ($a['time'] == $b['time']) {
			return 0;
		} else {
			return ($a['time'] > $b['time']) ? -1 : 1;
		}
	}

	/**
	 * @internal
	 */
	public function _render_page() {
		echo '<div class="wrap">';

		if ($error_message = $this->server_requirements_not_met()) {
			echo "<div class=\"notice notice-error\"><p>{$error_message}</p></div>";
		} else {
			$this->render_view( 'page', array(
				'archives_html' => $this->render_view( 'archives', array(
					'archives' => $this->get_archives(),
					'is_busy'  => (bool) $this->tasks()->get_active_task_collection(),
				) ),
			), false );
		}

		echo '</div>';

		echo '<div id="fw-ext-backups-filesystem-form" style="display:none;">';
		FW_WP_Filesystem::request_access(ABSPATH);
		echo '</div>';
	}

	/**
	 * @return string
	 */
	public function get_tmp_dir() {
		return $this->get_backups_dir() . '/tmp';
	}

	/**
	 * All backups (zip) will go in this directory
	 * @return string
	 */
	public function get_backups_dir() {
		return $this->get_config( 'dirs.destination' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function _get_link() {
		if (current_user_can($this->get_capability())) {
			return $this->get_page_url();
		} else {
			return null;
		}
	}

	/**
	 * @internal
	 */
	public function _get_test_ajax_action() {
		return self::$wp_ajax_action_test;
	}

	/**
	 * @internal
	 */
	public function _action_ajax_test() {
		wp_send_json_success();
	}

	public function get_download_link($archive_filename) {
		return add_query_arg(self::$download_GET_parameter, urlencode($archive_filename), $this->get_page_url());
	}

	public function _action_download() {
		if (
			!isset($_GET[self::$download_GET_parameter])
			||
		    !is_string($archive_filename = $_GET[self::$download_GET_parameter])
			||
		    !$this->is_backups_page()
		) {
			return;
		}

		$error = __('Unknown error', 'fw');

		do {
			if (!current_user_can($this->get_capability())) {
				$error = __('Access Denied', 'fw');
				break;
			}

			$archives = $this->get_archives();

			if (!isset($archives[$archive_filename])) {
				$error = __('Archive not found', 'fw');
				break;
			}

			$archive = $archives[$archive_filename];

			if ($archive['full'] && !fw_ext_backups_current_user_can_full()) {
				$error = __('Access Denied', 'fw');
				break;
			}

			if ($f = fopen($archive['path'], 'r')) {
				// ok
			} else {
				$error = __('Failed to open file', 'fw');
				break;
			}

			header('Content-Type: application/zip, application/octet-stream');
			header('Content-Disposition: attachment; filename="'. esc_attr($archive_filename) .'"');
			header('Content-length: '. filesize($archive['path']));
			header('Cache-control: private');

			/**
			 * Some files can be huge, do not load entire file in php memory then output, it can cause memory limit error
			 * Read and output parts
			 */
			{
				$output_buffer_size = max(
					// https://github.com/ThemeFuse/Unyson/issues/2070#issuecomment-258427852
					(int)ini_get('output_buffering'),
					// default to this value in case ini_get() will return 0 (some server restrictions)
					// http://php.net/manual/en/outcontrol.configuration.php#ini.output-buffering
					4096
				);

				while (!feof($f)) {
					echo fread($f, $output_buffer_size);
					if (ob_get_level()) { ob_flush(); }
					flush();
				}
			}

			fclose($f);

			exit;
		} while(false);

		wp_die($error, $error);
	}
}
