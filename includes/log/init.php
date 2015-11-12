<?php if (!defined('FW')) die('Forbidden');

/**
 * @internal
 */
class _FW_Ext_Backups_Log {
	/**
	 * @var FW_Access_Key|null
	 */
	private static $access_key;

	/**
	 * @return FW_Access_Key|null
	 */
	private static function get_access_key() {
		if (empty(self::$access_key)) {
			self::$access_key = new FW_Access_Key('fw:ext:backups:log');
		}

		return self::$access_key;
	}

	/**
	 * @return FW_Extension_Backups
	 */
	private static function backups() {
		return fw_ext('backups');
	}

	private static $wp_option = 'fw:ext:backups:log';

	private static $log_limit = 30;

	public function __construct() {
		add_action('fw:ext:backups:task:fail', array($this, '_action_task_fail'));
		add_action('fw:ext:backups:tasks:success', array($this, '_action_tasks_success'));
		add_action('fw_ext_backups_page_footer', array($this, '_action_page_footer'));
		add_action('fw:ext:backups:enqueue_scripts', array($this, '_action_enqueue_scripts'));

		add_filter(
			'fw_ext_backups_db_export_exclude_option',
			array($this, '_filter_fw_ext_backups_db_export_exclude_option'),
			10, 3
		);
		add_filter(
			'fw_ext_backups_db_restore_keep_options',
			array($this, '_filter_fw_ext_backups_db_restore_keep_options')
		);
		add_filter(
			'fw_ext_backups_ajax_status_extra_response',
			array($this, '_filter_fw_ext_backups_ajax_status_extra_response')
		);
	}

	private function get_log() {
		return get_option(self::$wp_option, array());
	}

	private function set_log($log) {
		while (count($log) > self::$log_limit) {
			array_pop($log);
		}

		return update_option(self::$wp_option, $log, false);
	}

	private function add_log($type, $title, array $data = array()) {
		if (!in_array($type, array('success', 'info', 'warning', 'error'))) {
			trigger_error('Invalid log type: '. $type, E_USER_WARNING);
		}

		$log = $this->get_log();

		array_unshift($log, array(
			'type'  => $type,
			'title' => $title,
			'data'  => $data,
			'time'  => time(),
		));

		$this->set_log($log);
	}

	private function render_log() {
		return fw_render_view(dirname(__FILE__) .'/view.php', array(
			'log' => $this->get_log()
		));
	}

	/**
	 * @param FW_Ext_Backups_Task $task
	 * @internal
	 */
	public function _action_task_fail(FW_Ext_Backups_Task $task) {
		$this->add_log(
			'error',
			self::backups()->tasks()->get_task_type_title($task->get_type())
			. (is_wp_error($task->get_result()) ? ': '. $task->get_result()->get_error_message() : ''),
			is_wp_error($task->get_result()) ? (array)$task->get_result()->get_error_data() : array()
		);
	}

	/**
	 * @param FW_Ext_Backups_Task_Collection $tasks
	 * @internal
	 */
	public function _action_tasks_success(FW_Ext_Backups_Task_Collection $tasks) {
		$this->add_log(
			'success',
			$tasks->get_title()
		);
	}

	/**
	 * @param array $options {option_name: true}
	 * @return array
	 */
	public function _filter_fw_ext_backups_db_restore_keep_options($options) {
		$options[ self::$wp_option] = true;

		return $options;
	}

	/**
	 * @param bool $exclude
	 * @param string $option_name
	 * @param bool $is_full_backup
	 * @return bool
	 */
	public function _filter_fw_ext_backups_db_export_exclude_option($exclude, $option_name, $is_full_backup) {
		if (!$is_full_backup && $option_name === self::$wp_option) {
			return true;
		}

		return $exclude;
	}

	/**
	 * @internal
	 */
	public function _action_page_footer() {
		echo '<div id="fw-ext-backups-log"></div>';
	}

	/**
	 * @param $data
	 * @return mixed
	 * @internal
	 */
	public function _filter_fw_ext_backups_ajax_status_extra_response($data) {
		$data['log'] = array(
			'html' => $this->render_log(),
		);

		return $data;
	}

	/**
	 * @internal
	 */
	public function _action_enqueue_scripts() {
		wp_enqueue_style(
			'fw-ext-backups-log',
			self::backups()->get_uri('/includes/log/styles.css'),
			array(),
			self::backups()->manifest->get_version()
		);
		wp_enqueue_script(
			'fw-ext-backups-log',
			self::backups()->get_uri('/includes/log/scripts.js'),
			array('fw'),
			self::backups()->manifest->get_version()
		);
	}
}
new _FW_Ext_Backups_Log();
