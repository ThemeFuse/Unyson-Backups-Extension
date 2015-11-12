<?php if (!defined('FW')) die('Forbidden');

/**
 * @since 2.0.0
 */
final class FW_Ext_Backups_Task {
	/**
	 * @var string
	 * @since 2.0.0
	 */
	private $id;

	/**
	 * A registered type that will process this task
	 * @var string
	 * @since 2.0.0
	 */
	private $type;

	/**
	 * @var array
	 * @since 2.0.0
	 */
	private $args = array();

	/**
	 * @var int timestamp
	 * @since 2.0.0
	 * @internal
	 */
	private $last_execution_start_time;

	/**
	 * @var int timestamp
	 * @since 2.0.0
	 * @internal
	 */
	private $last_execution_end_time;

	/**
	 * @var mixed
	 *   // null     - in progress, not finished yet
	 *   // {...}    - step finished successfully. current state. task not finished
	 *   // true     - task finished successfully
	 *   // string   - task finished successfully with message
	 *   // false    - task failed
	 *   // WP_Error - task failed with message
	 * @since 2.0.0
	 * @internal
	 */
	private $result;

	public function __construct($id, $type, $args = array()) {
		$this->id = (string)$id;
		$this->type = (string)$type;
		$this->set_args($args);
	}

	/**
	 * @return string
	 * @since 2.0.0
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * @return string
	 * @since 2.0.0
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * @return array
	 * @since 2.0.0
	 */
	public function get_args() {
		return $this->args;
	}

	/**
	 * @param array $args
	 *
	 * @return $this
	 * @since 2.0.0
	 */
	public function set_args(array $args) {
		$this->args = $args;

		return $this;
	}

	/**
	 * @return int|null
	 * @since 2.0.0
	 * @internal
	 */
	public function get_last_execution_start_time() {
		return $this->last_execution_start_time;
	}

	/**
	 * @param int $last_execution_start_time
	 *
	 * @return $this
	 * @since 2.0.0
	 * @internal
	 */
	public function set_last_execution_start_time($last_execution_start_time) {
		$this->last_execution_start_time = $last_execution_start_time;

		return $this;
	}

	/**
	 * @return int|null
	 * @since 2.0.0
	 * @internal
	 */
	public function get_last_execution_end_time() {
		return $this->last_execution_end_time;
	}

	/**
	 * @param int $last_execution_end_time
	 *
	 * @return $this
	 * @since 2.0.0
	 * @internal
	 */
	public function set_last_execution_end_time($last_execution_end_time) {
		$this->last_execution_end_time = $last_execution_end_time;

		return $this;
	}

	/**
	 * @return null|array|true|string|false|WP_Error
	 * @since 2.0.0
	 */
	public function get_result() {
		return $this->result;
	}

	/**
	 * @param $result
	 *
	 * @return $this
	 * @since 2.0.0
	 * @internal
	 */
	public function set_result($result) {
		if (
			in_array($result, array(null, true, false), true)
			||
			is_string($result)
			||
			is_array($result)
			||
			is_wp_error($result)
		) {} else {
			throw new LogicException('Not supported result value');
		}

		$this->result = $result;

		return $this;
	}

	/**
	 * @return bool Task execution finished and will never continue/execute
	 * @since 2.0.0
	 */
	public function result_is_finished() {
		return !(
			is_null($this->get_result())
			||
		    is_array($this->get_result())
		);
	}

	/**
	 * @return bool
	 * @since 2.0.0
	 */
	public function result_is_fail() {
		return (
			$this->get_result() === false
			||
		    is_wp_error($this->get_result())
		);
	}

	/**
	 * @return bool
	 * @since 2.0.0
	 */
	public function result_is_success() {
		return (
			$this->get_result() === true
			||
		    is_string($this->get_result())
		);
	}

	/**
	 * @return array
	 * @since 2.0.0
	 * @internal
	 */
	public function to_array() {
		return array(
			'id' => $this->get_id(),
			'type' => $this->get_type(),
			'args' => $this->get_args(),
			'last_execution_start_time' => $this->get_last_execution_start_time(),
			'last_execution_end_time' => $this->get_last_execution_end_time(),
			'result' => $this->get_result(),
		);
	}

	/**
	 * @param array $t
	 *
	 * @return FW_Ext_Backups_Task
	 * @since 2.0.0
	 * @internal
	 */
	public static function from_array(array $t) {
		$task = new self($t['id'], $t['type'], $t['args']);
		$task->set_last_execution_start_time($t['last_execution_start_time']);
		$task->set_last_execution_end_time($t['last_execution_end_time']);
		$task->set_result($t['result']);

		return $task;
	}
}
