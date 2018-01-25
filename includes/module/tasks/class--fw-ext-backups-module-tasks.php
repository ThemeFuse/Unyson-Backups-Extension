<?php if (!defined('FW')) die('Forbidden');

class _FW_Ext_Backups_Module_Tasks extends _FW_Ext_Backups_Module {
	/**
	 * @var string
	 *
	 * Stored data structure:
	 * * id: 'collection_id'
	 * * tasks: [
	 *  {
	 *      id: 'unique_string' // to be able to update this task
	 *      type: 'a_registered_task_type'
	 *      args: {...} // input parameters
	 *      ...
	 *  }
	 *  ...
	 * ]
	 */
	private static $wp_option_active_task_collection = 'fw:ext:backups:active_task_collection';

	/**
	 * @var string
	 *
	 * Stored data structure:
	 * [
	 *  {id: 'collection_id', tasks: [...]},
	 *  {id: 'collection_id', tasks: [...]},
	 *  ...
	 * ]
	 */
	private static $wp_option_pending_task_collections = 'fw:ext:backups:pending_task_collections';

	private static $wp_ajax_action = 'fw:ext:backups:continue-pending-task';

	private static $skip_shutdown_function = false;

	public function _init() {
		require_once dirname(__FILE__) .'/entity/class-fw-ext-backups-task.php';
		require_once dirname(__FILE__) .'/entity/class-fw-ext-backups-task-collection.php';

		require_once dirname(__FILE__) .'/type/init.php'; // predefined task types

		add_action(
			'wp_ajax_nopriv_' . self::$wp_ajax_action,
			array($this, '_action_ajax_continue_pending_task')
		);
		/**
		 * @since 2.0.5
		 */
		add_action(
			'wp_ajax_' . self::$wp_ajax_action,
			array($this, '_action_ajax_continue_pending_task')
		);

		add_filter(
			'fw_ext_backups_db_export_exclude_option',
			array($this, '_filter_fw_ext_backups_db_exclude_option'),
			10, 2
		);
		add_filter(
			'fw_ext_backups_db_restore_exclude_option',
			array($this, '_filter_fw_ext_backups_db_exclude_option'),
			10, 2
		);
		add_filter(
			'fw_ext_backups_db_restore_keep_options',
			array($this, '_filter_fw_ext_backups_db_restore_keep_options')
		);
	}

	/**
	 * @var FW_Ext_Backups_Task_Type[]
	 */
	private static $task_types;

	/**
	 * @param FW_Access_Key $access_key
	 *
	 * @return FW_Ext_Backups_Task_Type[]
	 * @internal
	 */
	public function _get_task_types(FW_Access_Key $access_key) {
		if (!in_array($access_key->get_key(), array('fw:ext:backups', 'fw:ext:backups:tasks'))) {
			trigger_error('Method call denied', E_USER_ERROR);
		}

		if (is_null(self::$task_types)) {
			if (!class_exists('_FW_Ext_Backups_Task_Type_Register')) {
				require_once dirname(__FILE__) .'/class--fw-ext-backups-task-register.php';
			}

			$task_types = new _FW_Ext_Backups_Task_Type_Register();

			if (!class_exists('FW_Ext_Backups_Task_Type')) {
				require_once dirname(__FILE__) .'/class-fw-ext-backups-task-type.php';
			}

			do_action('fw_ext_backups_task_types_register', $task_types);

			self::$task_types = $task_types->_get_task_types(self::get_access_key());

			unset($task_types);
		}

		return self::$task_types;
	}

	/**
	 * @param string $type
	 * @param FW_Access_Key $access_key
	 *
	 * @return FW_Ext_Backups_Task_Type|null
	 * @internal
	 */
	public function _get_task_type($type, FW_Access_Key $access_key) {
		if (!in_array($access_key->get_key(), array('fw:ext:backups', 'fw:ext:backups:tasks'))) {
			trigger_error('Method call denied', E_USER_ERROR);
		}

		$types = $this->_get_task_types(self::get_access_key());

		if (isset($types[$type])) {
			return $types[$type];
		} else {
			return null;
		}
	}

	/**
	 * @param string $type
	 *
	 * @return null|string
	 */
	public function get_task_type_title($type, array $args = array(), $state = array()) {
		if (!is_array($state)) {
			$state = array();
		}

		if ($type_instance = $this->_get_task_type($type, self::get_access_key())) {
			return $type_instance->get_title($args, $state);
		} else {
			return __('undefined', 'fw');
		}
	}

	/**
	 * @return FW_Extension_Backups
	 */
	private static function backups() {
		return fw_ext('backups');
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
			self::$access_key = new FW_Access_Key('fw:ext:backups:tasks');
		}

		return self::$access_key;
	}

	private function do_task_fail_action(FW_Ext_Backups_Task $task) {
		if ($task->result_is_fail()) {
			do_action( 'fw:ext:backups:task:fail', $task );
		}
	}

	/**
	 * @param FW_Ext_Backups_Task_Collection|null $collection
	 *
	 * @return bool
	 *
	 * Important! Must be used with caution, only when:
	 * * Active task collection is empty (to set new active collection for execution)
	 * * Need to update one task
	 */
	private function set_active_task_collection($collection) {
		if ($collection instanceof FW_Ext_Backups_Task_Collection) {
			return update_option(
				self::$wp_option_active_task_collection,
				/**
				 * Do not store in database entities/instances
				 * This gives the possibility to change/rename the entities in the future
				 * without affecting the database values
				 */
				$collection->to_array(),
				false
			);
		} elseif (empty($collection)) {
			return update_option(self::$wp_option_active_task_collection, null, false);
		} else {
			throw new LogicException('Wrong task collection provided');
		}
	}

	/**
	 * @return FW_Ext_Backups_Task_Collection|null
	 */
	public function get_active_task_collection() {
		if ($db_value = get_option(
			self::$wp_option_active_task_collection,
			null
		)) {
			return $this->check_and_fix_tasks(
				FW_Ext_Backups_Task_Collection::from_array( $db_value )
			);
		} else {
			return $db_value;
		}
	}

	/**
	 * @param FW_Ext_Backups_Task_Collection[] $collections
	 *
	 * @return bool
	 */
	private function set_pending_task_collections(array $collections) {
		/**
		 * Do not store in database entities/instances
		 * This gives the possibility to change/rename the entities in the future
		 * without affecting the database values
		 */
		$db_value = array();
		foreach ($collections as $collection) {
			$db_value[] = $collection->to_array();
		}

		return update_option(
			self::$wp_option_pending_task_collections,
			$db_value,
			false
		);
	}

	/**
	 * @return FW_Ext_Backups_Task_Collection[]
	 */
	public function get_pending_task_collections() {
		$collections = array();

		$db_value = get_option(
			self::$wp_option_pending_task_collections,
			array()
		);
		while ($collection = array_shift($db_value)) {
			$collections[] = FW_Ext_Backups_Task_Collection::from_array($collection);
		}

		return $collections;
	}

	/**
	 * @param FW_Ext_Backups_Task_Collection|null $collection
	 *
	 * @return FW_Ext_Backups_Task_Collection|null
	 */
	private function check_and_fix_tasks($collection) {
		if ($collection instanceof FW_Ext_Backups_Task_Collection) {} else {
			return null;
		}

		$finished = true;

		foreach ($collection->get_tasks() as $task) {
			if ( ! $task->get_last_execution_start_time() ) {
				// check only started tasks
				$finished = false;
				break;
			}

			// Check for problems and maybe finish (set failed)
			if ( ! $task->result_is_finished() ) {
				if ( ! $this->_get_task_type( $task->get_type(), self::get_access_key() ) ) {
					$task->set_result( new WP_Error(
						'type_not_registered', __( 'Task type not registered', 'fw' )
					));
				}

				if ( $task->get_last_execution_end_time() ) { // step finished and should continue
					if ( $task->get_last_execution_end_time() + self::backups()->get_timeout() + 5 < time() ) {
						$task->set_result(new WP_Error(
							'execution_stopped', __( 'Execution stopped (next step did not started)', 'fw' )
						));
					}
				} else { // is currently executing
					{
						if (
							($task_type = $this->_get_task_type( $task->get_type(), self::get_access_key() ))
							&&
							($custom_timeout = $task_type->get_custom_timeout(
								$task->get_args(),
								is_array($task->get_result()) ? $task->get_result() : array()
							))
						) {
							$timeout = abs($custom_timeout);
						} else {
							$timeout = self::backups()->get_timeout();
						}

						if ( $task->get_last_execution_start_time() + $timeout + 1 < time() ) {
							$task->set_result( new WP_Error(
								'timeout', __( 'The execution failed. Please check error.log', 'fw' )
							) );
						}
					}
				}

				if ( $task->result_is_fail() ) {
					$task->set_last_execution_end_time(microtime(true));

					$this->set_active_task_collection( $collection );

					$this->do_task_fail_action($task);
				}
			}

			if ( $task->result_is_finished() ) {
				if ( $task->result_is_fail() ) {
					do_action( 'fw:ext:backups:tasks:fail:id:'. $collection->get_id(), $collection,
						/** @since 2.0.23 */
						$task );
					do_action( 'fw:ext:backups:tasks:fail', $collection,
						/** @since 2.0.23 */
						$task );
					break;
				}
			} else { // Stop on first executing (not finished) task
				$finished = false;
				break;
			}
		}

		if ($finished) {
			$this->set_active_task_collection(null);

			// Allow the tasks to be executed again
			file_put_contents( $this->get_executed_tasks_path(), '' );

			do_action('fw:ext:backups:tasks:finish:id:'. $collection->get_id(), $collection);
			do_action('fw:ext:backups:tasks:finish', $collection);

			if (isset($task) && $task->result_is_fail()) {
				// if last task is fail, then the collection was failed and foreach stopped
			} else {
				do_action('fw:ext:backups:tasks:success:id:'. $collection->get_id(), $collection);
				do_action('fw:ext:backups:tasks:success', $collection);

			}

			flush_rewrite_rules();

			return null;
		}

		return $collection;
	}

	/**
	 * A task that started but did not finished
	 * @return null|FW_Ext_Backups_Task
	 */
	public function get_executing_task() {
		if ($collection = $this->get_active_task_collection()) {
			foreach ($collection->get_tasks() as $task) {
				if ( ! $task->get_last_execution_start_time() ) {
					break;
				} elseif ( ! $task->get_last_execution_end_time() ) {
					if ($task->result_is_finished()) {
						{
							$task->set_last_execution_end_time(microtime(true));
							$task->set_result(new WP_Error(
								'invalid_execution_end_time', __('Invalid execution end time', 'fw')
							));

							$this->set_active_task_collection( $collection );

							$this->do_task_fail_action($task);
						}

						return $this->get_executing_task();
					} else {
						return $task;
					}
				}
			}
		}

		return null;
	}

	/**
	 * The task that is waiting for execution,
	 * with result array (state) - should continue execution
	 * or with result null - never executed
	 * @return null|FW_Ext_Backups_Task with result null|array
	 */
	public function get_pending_task() {
		if ($this->get_executing_task()) {
			return null;
		}

		if ($collection = $this->get_active_task_collection()) {
			$last_executed_task = null;

			$pending_task = null;

			do {
				foreach ( $collection->get_tasks() as $task ) {
					if ( $task->get_last_execution_start_time() ) {
						unset($last_executed_task); // reset reference
						$last_executed_task = $task;
					} else {
						if ($last_executed_task) {
							if ( $last_executed_task->get_last_execution_end_time() ) {
								if ( is_array($last_executed_task->get_result()) ) { // result is a state and must continue
									$pending_task = $last_executed_task;
									break 2;
								} elseif ($last_executed_task->result_is_success()) {
									$pending_task = $task;
									break 2;
								} else {
									// this must not happen, must be detected by $this->check_and_fix_tasks()
									return null;
								}
							} else {
								return null;
							}
						} else {
							$pending_task = $task;
							break 2;
						}
					}
				}

				if (
					$last_executed_task
					&&
					$last_executed_task->get_last_execution_end_time()
					&&
					is_array( $last_executed_task->get_result() )
				) {
					$pending_task = $last_executed_task;
					break;
				}
			} while(false);

			return $pending_task;
		} elseif ($collections = $this->get_pending_task_collections()) {
			$collection = array_shift($collections);

			/**
			 * Here you can add/append new tasks to be executed after predefined tasks
			 * @param FW_Ext_Backups_Task_Collection $collection
			 * Note: $collection can be modified by reference http://stackoverflow.com/a/2715039
			 */
			do_action('fw:ext:backups:tasks:before_process', $collection);

			$this->set_active_task_collection($collection);
			$this->set_pending_task_collections($collections);

			return $this->get_pending_task();
		}

		return null;
	}

	private function execute_pending_task() {
		if ($task = $this->get_pending_task()) {} else {
			return false;
		}

		if ($task_type = $this->_get_task_type($task->get_type(), self::get_access_key())) {} else {
			return false; // must not happen, must be detected by $this->get_pending_task()
		}

		/**
		 * Get all active tasks and keep a reference on current task
		 * Then keep updating the task and flush/save active tasks on change
		 */
		{
			$task_id = $task->get_id();
			unset($task);

			$collection = $this->get_active_task_collection();
			$task = $collection->get_task($task_id);
		}

		if (!is_array($state = $task->get_result())) {
			$state = array();
		}

		if ($this->was_executed($task)) {
			return;
		} else {
			$this->set_executed($task);
		}

		$task->set_last_execution_start_time(microtime(true));
		$task->set_last_execution_end_time(null);

		$this->set_active_task_collection($collection);

		/**
		 * Raise memory limit and max_execution_time
		 * Note: This is not guaranteed to work on all servers
		 */
		if (
			($task_type = $this->_get_task_type( $task->get_type(), self::get_access_key() ))
			&&
			($custom_timeout = $task_type->get_custom_timeout(
				$task->get_args(),
				is_array($task->get_result()) ? $task->get_result() : array()
			))
		) {
			@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );
			@set_time_limit( abs($custom_timeout) );
		}

		/**
		 * Log in as super admin to prevent current_user_can() limitations
		 */
		{
			global $wpdb;

			if (
				($super_admin = $wpdb->get_results(
					"SELECT user_id"
					." FROM $wpdb->usermeta"
					." WHERE `meta_key` = 'wp_user_level' AND `meta_value` = 10" // https://codex.wordpress.org/User_Levels#User_Levels_9_and_10
					." LIMIT 1"
				))
				&&
				($super_admin = get_user_by('id', $super_admin[0]->user_id ))
				&&
				isset($super_admin->caps['administrator'])
				&&
				$super_admin->caps['administrator']
			) {
				wp_set_current_user($super_admin->ID);
			}
		}

		if (
			($collection_tasks = $collection->get_tasks())
			&&
			isset($collection_tasks[0])
			&&
			$collection_tasks[0]->get_id() === $task->get_id() // current task is the first task
			&&
			$collection_tasks[0]->get_last_execution_start_time() // the task has never executed
		) {
			/**
			 * Fixes https://github.com/ThemeFuse/Unyson/issues/2116
			 * @since 2.0.15
			 */
			do_action('fw:ext:backups:tasks:start:id:'. $collection->get_id(), $collection);
			do_action('fw:ext:backups:tasks:start', $collection);

			unset($collection_tasks);
		} else {
			unset($collection_tasks);
		}

		register_shutdown_function(array($this, '_shutdown_function'), array(
			'collection' => $collection,
			'task' => $task,
		));

		if ('POST' === $_SERVER['REQUEST_METHOD']) { ob_start(); } // prevent execution abort on output (see 'blocking')
		try {
			$task->set_result(
				$task_type->execute( $task->get_args(), $state )
			);
		} catch (Exception $e) {
			$task->set_result(
				new WP_Error('exception', $e->getMessage())
			);
		}
		if ('POST' === $_SERVER['REQUEST_METHOD']) { ob_end_clean(); }

		self::$skip_shutdown_function = true;

		$task->set_last_execution_end_time(microtime(true));

		if (!(
			is_array($task->get_result())
			||
			is_string($task->get_result())
			||
			is_wp_error($task->get_result())
			||
			in_array($task->get_result(), array(true, false), true)
		)) {
			$task->set_result(new WP_Error(
				'invalid_execution_result', __('Invalid execution result', 'fw')
			));
		}

		$this->set_active_task_collection($collection);

		do_action( 'fw:ext:backups:task:executed', $task);

		if ($task->result_is_finished()) {
			do_action( 'fw:ext:backups:task:executed:finished', $task );

			if ($task->result_is_fail()) {
				$this->do_task_fail_action($task);
			} else {
				do_action( 'fw:ext:backups:task:success', $task );
			}
		} else {
			do_action( 'fw:ext:backups:task:executed:unfinished', $task );
		}

		$this->request_next_step_execution();

		return true;
	}

	private function request_next_step_execution() {
		if (
			!($task = $this->get_pending_task())
			||
			$this->get_executing_task()
		) {
			return false;
		} elseif ($this->was_executed($task)) {
			/**
			 * Early prevent duplicate execution
			 * Same check is done in @see execute_pending_task()
			 * but this will prevent a redundant request
			 */
			return false;
		}

		/**
		 * @since 2.0.23
		 */
		if ( fw_is_cli() ) {
			static $is_wp_cli_executing = false;
			if ($is_wp_cli_executing) {
				return false;
			}

			$is_wp_cli_executing = true;
			while ($this->execute_pending_task());
			$is_wp_cli_executing = false;

			return true;
		} else {
			$http = new WP_Http();
			$http->post(
				site_url( 'wp-admin/admin-ajax.php' ),
				array(
					/**
					 * The request should start (in background) and current request should (continue and) stop
					 * without stopping the started background request execution
					 */
					'blocking'  => false,
					'timeout'   => 0.01,
					/** @see spawn_cron() */
					'sslverify' => false,
					'body'      => array(
						'action'            => self::$wp_ajax_action,
						'token'             => md5(
							defined( 'NONCE_SALT' ) ? NONCE_SALT : self::backups()->manifest->get_version()
						),
						'active_tasks_hash' => ( $collection = $this->get_active_task_collection() )
							? md5( serialize( $collection ) ) : ''
					),
				)
			);
		}

		return true;
	}

	/**
	 * @param FW_Access_Key $access_key
	 *
	 * @return bool
	 * @internal
	 */
	public function _request_next_step_execution(FW_Access_Key $access_key) {
		if (!in_array($access_key->get_key(), array('fw:ext:backups', 'fw:ext:backups-demo'))) {
			trigger_error('Method call denied', E_USER_ERROR);
		}

		if (
			file_exists($path = $this->get_executed_tasks_path())
			&&
			($mtime = filemtime($path))
			&&
			(time() - $mtime <= 30)
		) {
			/**
			 * Do nothing if the file was modified recently
			 * that means the execution is running and no need to do the request
			 */
			return;
		}

		return $this->request_next_step_execution();
	}

	public function _action_ajax_continue_pending_task() {
		if (
			!isset($_POST['token'])
			||
			$_POST['token'] !== md5(
				defined('NONCE_SALT') ? NONCE_SALT : self::backups()->manifest->get_version()
			)
		) {
			wp_send_json_error(new WP_Error('invalid_token', __('Invalid token', 'fw')));
		}

		/**
		 * Prevent duplicate execution.
		 *
		 * Same task started execution starts at the same state parallel execution,
		 * I didn't found why that happens. Maybe because of WPDB cache or queries are executed asynchronous,
		 * or because of some delays the requests order is asynchronous.
		 *
		 * Check if current collection is the same as the hash when it was requested to be executed.
		 */
		if (!isset($_POST['active_tasks_hash'])) {
			wp_send_json_error();
		} elseif (
			(($collection = $this->get_active_task_collection()) ? md5(serialize($collection)) : '')
			!==
			$_POST['active_tasks_hash']
		) {
			wp_send_json_error(new WP_Error('invalid_tasks_hash', __('Invalid tasks hash', 'fw')));
		}

		$result = $this->execute_pending_task();

		if (empty($_SERVER['HTTP_REFERER'])) {
			/**
			 * Background/Loopback request
			 *
			 * Do not make any output to prevent non-blocking request cancel
			 *
			 * > a broken pipe due to client aborting the connection doesn't stop execution right away,
			 * > only at the point you next try to write to the script output
			 * http://php.net/manual/en/function.ignore-user-abort.php
			 */
			exit;
		} else {
			/**
			 * Regular ajax request from browser
			 * @since 2.0.5
			 */
			if ($result) {
				wp_send_json_success();
			} else {
				wp_send_json_error();
			}
		}
	}

	/**
	 * The collection will be added in pending. If task manager is busy, it will be executed later.
	 * @param FW_Ext_Backups_Task_Collection $collection
	 */
	public function execute_task_collection(FW_Ext_Backups_Task_Collection $collection) {
		if ($collections = $this->get_pending_task_collections()) {
			foreach ($collections as $i => $_collection) {
				if ($_collection->get_id() === $collection->get_id()) {
					unset($collections[$i]);
				}
			}
		}

		$collections[] = $collection;

		$this->set_pending_task_collections($collections);

		$this->request_next_step_execution();
	}

	private function get_dirs($full = false) {
		$wp_upload_dir = wp_upload_dir();

		$dirs = array(
			'uploads' => fw_fix_path($wp_upload_dir['basedir']),
			'plugins' => fw_fix_path(WP_PLUGIN_DIR),
			'themes'  => fw_fix_path(get_theme_root()),
		);

		if (is_multisite() && WPMU_PLUGIN_DIR) {
			$dirs['mu-plugins'] = fw_fix_path(WPMU_PLUGIN_DIR);
		}

		if (!$full) {
			unset($dirs['plugins']);
			unset($dirs['mu-plugins']);
			unset($dirs['themes']);
		}

		return $dirs;
	}

	/**
	 * Add backup tasks to a collection
	 * Useful when you create a collection that does some data replace on the site, first you can add tasks to do backup
	 * @param FW_Ext_Backups_Task_Collection $collection
	 * @param bool $full
	 * @return FW_Ext_Backups_Task_Collection
	 */
	public function add_backup_tasks(FW_Ext_Backups_Task_Collection $collection, $full = false) {
		$full = (bool)$full;
		$tmp_dir = self::backups()->get_tmp_dir();
		$dirs = $this->get_dirs($full);
		$id_prefix = 'backup:';

		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'tmp-dir-clean:before',
			'dir-clean',
			array(
				'dir' => $tmp_dir
			)
		));
		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'db-export',
			'db-export',
			array(
				'dir' => $tmp_dir,
				'full' => $full,
			)
		));
		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'files-export',
			'files-export',
			array(
				'source_dirs' => $dirs,
				'destination' => $tmp_dir .'/f',
				'exclude_paths' => ( is_multisite() && ($wp_upload_dir = wp_upload_dir()) )
					? array(fw_fix_path($wp_upload_dir['basedir']) .'/sites' => true)
					: array(),
			)
		));
		if (
			!$full
			&&
			/** @since 2.0.16 */
			apply_filters('fw:ext:backups:add-backup-task:image-sizes-remove', true, $collection)
		) {
			$collection->add_task(new FW_Ext_Backups_Task(
				$id_prefix .'image-sizes-remove',
				'image-sizes-remove',
				array(
					'uploads_dir' => $tmp_dir .'/f/uploads',
				)
			));
		}
		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'zip',
			'zip',
			array(
				'source_dir' => $tmp_dir,
				'destination_dir' => self::backups()->get_backups_dir(),
			))
		);
		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'tmp-dir-clean:after',
			'dir-clean',
			array(
				'dir' => $tmp_dir
			))
		);

		/** @since 2.0.16 */
		do_action('fw:ext:backups:add-backup-tasks', $collection, array(
			'is_full' => $full,
			'tmp_dir' => $tmp_dir,
			'dirs'    => $dirs,
		));

		return $collection;
	}

	/**
	 * Add restore tasks to a collection
	 * @param FW_Ext_Backups_Task_Collection $collection
	 * @param bool $full
	 * @param string|null $zip_path
	 * @param array $filesystem_args {}|{hostname: '', username: '', password: '', connection_type: ''}
	 * @return FW_Ext_Backups_Task_Collection
	 */
	public function add_restore_tasks(
		FW_Ext_Backups_Task_Collection $collection,
		$full = false,
		$zip_path = null,
		$filesystem_args = array()
	) {
		$full = (bool)$full;
		$tmp_dir = self::backups()->get_tmp_dir();
		$dirs = $this->get_dirs($full);
		$id_prefix = 'restore:';

		if ($zip_path) {
			$collection->add_task( new FW_Ext_Backups_Task(
				$id_prefix . 'unzip',
				'unzip',
				array(
					'zip' => $zip_path,
					'dir' => $tmp_dir,
				) )
			);
		}
		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'files-restore',
			'files-restore',
			array(
				'source_dir' => $tmp_dir .'/f',
				'destinations' => $dirs,
				'filesystem_args' => $filesystem_args,
				'skip_dirs' => ( is_multisite() && ($wp_upload_dir = wp_upload_dir()) )
					? array(fw_fix_path($wp_upload_dir['basedir']) .'/sites' => true)
					: array(),
			))
		);
		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'db-restore',
			'db-restore',
			array(
				'dir' => $tmp_dir,
				'full' => $full,
			))
		);
		if (
			!$full
			&&
			apply_filters('fw:ext:backups:add-restore-task:image-sizes-restore', true, $collection)
		) {
			$collection->add_task(new FW_Ext_Backups_Task(
				$id_prefix .'image-sizes-restore',
				'image-sizes-restore',
				array()
			));
		}
		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'tmp-dir-clean:after',
			'dir-clean',
			array('dir' => $tmp_dir)
		));

		/** @since 2.0.16 */
		do_action('fw:ext:backups:add-restore-tasks', $collection, array(
			'is_full' => $full,
			'tmp_dir' => $tmp_dir,
			'dirs'    => $dirs,
		));

		return $collection;
	}

	/**
	 * @param bool $full
	 */
	public function do_backup($full = false) {
		$this->execute_task_collection(
			$this->add_backup_tasks(
				new FW_Ext_Backups_Task_Collection(
					($full ? 'full' : 'content') .'-backup'
				),
				$full
			)
		);
	}

	/**
	 * @param bool $full
	 * @param string $zip_path
	 * @param array $filesystem_args {}|{hostname: '', username: '', password: '', connection_type: ''}
	 */
	public function do_restore($full = false, $zip_path, $filesystem_args = array()) {
		$tmp_dir = self::backups()->get_tmp_dir();
		$id_prefix = 'restore:';

		$collection = new FW_Ext_Backups_Task_Collection(($full ? 'full' : 'content') .'-restore');

		$collection->add_task(new FW_Ext_Backups_Task(
			$id_prefix .'tmp-dir-clean:before',
			'dir-clean',
			array('dir' => $tmp_dir)
		));

		$this->execute_task_collection(
			$this->add_restore_tasks($collection, $full, $zip_path, $filesystem_args)
		);
	}

	public function do_cancel() {
		if (!(
			($collection = $this->get_active_task_collection())
			&&
			$collection->is_cancelable()
		)) {
			return false;
		} else {
			$this->set_active_task_collection(null);
			file_put_contents($this->get_executed_tasks_path(), '');

			do_action('fw:ext:backups:tasks:cancel:id:'. $collection->get_id());

			return true;
		}
	}

	/**
	 * @param bool $exclude
	 * @param string $option_name
	 *
	 * @return bool
	 */
	public function _filter_fw_ext_backups_db_exclude_option($exclude, $option_name) {
		if (
			$option_name === self::$wp_option_pending_task_collections
			||
			$option_name === self::$wp_option_active_task_collection
		) {
			return true;
		}

		return $exclude;
	}

	/**
	 * @param array $options {option_name: true}
	 * @return array
	 */
	public function _filter_fw_ext_backups_db_restore_keep_options($options) {
		$options[ self::$wp_option_pending_task_collections ] = true;

		/**
		 * This must be (updated) created after step execution
		 * because its value is stored in variable while the db replace is happening
		 */
		//$options[ self::$wp_option_active_task_collection ] = true;

		return $options;
	}

	private function get_executed_tasks_path() {
		return self::backups()->get_backups_dir() .'/.executed-tasks';
	}

	/**
	 * Prevent multiple/duplicate/parallel execution of the same task/state.
	 * This has the same idea like verification by hash in @see _action_ajax_continue_pending_task()
	 *
	 * @param FW_Ext_Backups_Task $task
	 * @return bool
	 */
	private function was_executed(FW_Ext_Backups_Task $task) {
		$path = $this->get_executed_tasks_path();

		if (file_exists($path)) {
			$hashes = array_fill_keys(explode(',', file_get_contents($path)), true);

			if (isset($hashes[ md5(serialize($task)) ])) {
				if (
					($mtime = filemtime($path))
					&&
					(time() - $mtime > self::backups()->get_timeout())
				) {
					/**
					 * If something went wrong and the script blocked next task execution (I don't know why but this happens)
					 * If file is old, ignore contents and allow execution
					 */
					return false;
				} else {
					return true;
				}
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	/**
	 * @param FW_Ext_Backups_Task $task
	 */
	private function set_executed(FW_Ext_Backups_Task $task) {
		$path = $this->get_executed_tasks_path();

		if (file_exists($path)) {
			$hashes = array_fill_keys(explode(',', file_get_contents($path)), true);
		} else {
			$hashes = array();
		}

		$hashes[ md5(serialize($task)) ] = true;

		while (count($hashes) > 3) {
			array_shift($hashes);
		}

		@file_put_contents($path, implode(',', array_keys($hashes)));
	}

	/**
	 * @internal
	 * @param array $data
	 */
	public function _shutdown_function($data) {
		if (
			!self::$skip_shutdown_function
			&&
			($error = error_get_last())
			&&
			in_array(
				$error['type'],
				// http://php.net/manual/en/errorfunc.constants.php
				array(E_ERROR, E_USER_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR),
				true
			)
		) {
			$data['task']->set_result(new WP_Error(
				'execution_failed',
				$error['message'] .' in '. $error['file'] .' on line '. $error['line']
			));

			$this->set_active_task_collection($data['collection']);

			$this->do_task_fail_action($data['task']);
		}
	}
}
