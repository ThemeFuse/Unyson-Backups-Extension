<?php if (!defined('FW')) die('Forbidden');

class _FW_Ext_Backups_Module_Schedule extends _FW_Ext_Backups_Module {
	private static $wp_ajax_get_settings = 'fw:ext:backups:schedule:get-settings';
	private static $wp_ajax_set_settings = 'fw:ext:backups:schedule:set-settings';

	private static $wp_option_settings = 'fw:ext:backups:schedule:settings';

	private static $wp_cron_prefix = 'fw:ext:backups:cron:';

	private static $default_lifetime = 7;

	/**
	 * @return FW_Extension_Backups
	 */
	private static function backups() {
		return fw_ext('backups');
	}

	public function _init() {
		{
			add_action('fw:ext:backups:enqueue_scripts', array($this, '_action_enqueue_backup_scripts'));

			add_action('wp_ajax_' . self::$wp_ajax_get_settings, array($this, '_action_ajax_get_settings'));
			add_action('wp_ajax_' . self::$wp_ajax_set_settings, array($this, '_action_ajax_set_settings'));

			foreach (array('full', 'content') as $type) {
				add_action(self::$wp_cron_prefix . $type, array($this, '_action_cron_'. $type));
			}
		}

		{
			add_filter('fw:ext:backups:script_localized_data', array($this, '_filter_script_localized_data'));
			add_filter('fw_ext_backups_ajax_status_extra_response', array($this, '_filter_ajax_status_response_data'));

			add_filter('fw_ext_backups_db_export_exclude_option', array($this, '_filter_db_exclude_option'), 10, 2);
			add_filter('fw_ext_backups_db_restore_exclude_option', array($this, '_filter_db_exclude_option'), 10, 2);
			add_filter('fw_ext_backups_db_restore_keep_options', array($this, '_filter_db_restore_keep_options'));

			add_filter('cron_schedules', array($this, '_filter_cron_schedules'));
		}

		$this->cleanup();
	}

	// At this hour the cron will be executed
	private function get_cron_run_hour() {
		return self::backups()->get_config('schedule.hour');
	}

	/**
	 * @param int $hour 0..24
	 * current_hour=22, hour=3, result=5 hours
	 * current_hour=2,  hour=3, result=1 hour
	 * current_hour=5,  hour=3, result=22 hours
	 *
	 * Test <?php
		fw_print(
			date('H:i:s', time()),
			date('H:i:s', $this->get_time_until_hour(3)),
			date('H:i:s', $this->get_time_until_hour(15)),
			date('H:i:s', $this->get_time_until_hour(21)),
			date('H:i:s', $this->get_time_until_hour(0)),
			date('H:i:s', $this->get_time_until_hour(24)),
			date('H:i:s', $this->get_time_until_hour(25)),
			date('H:i:s', $this->get_time_until_hour(85))
		);
	 * ?>
	 *
	 * @return int
	 */
	private function get_time_until_hour($hour) {
		if ($hour < 0 || $hour > 24) {
			$hour = 3;
		}

		$result  = $hour - (int)date('H'); // hours until give hour
		$result += $result < 1 ? 24 : 0; // add one day if hour already passed
		$result *= HOUR_IN_SECONDS; // transform hours to seconds
		$result -= ( // remove current minutes and seconds
			(MINUTE_IN_SECONDS + (int)date('i') * MINUTE_IN_SECONDS)
			-
			(MINUTE_IN_SECONDS - (int)date('s'))
		);

		return $result;
	}

	private function get_options() {
		$options = fw_get_variables_from_file(
			dirname(__FILE__) .'/settings-options.php',
			array('options' => array())
		);

		return $options['options'];
	}

	private function get_settings($type = null) {
		$settings = get_option(self::$wp_option_settings, array(
			'full' => array(
				'interval' => '',
				'lifetime' => self::$default_lifetime,
			),
			'content' => array(
				'interval' => '',
				'lifetime' => self::$default_lifetime,
			),
		));

		/**
		 * In case wp option value and cron schedule will be different (after full restore or something else)
		 * To be sure, use every time cron schedule value
		 */
		foreach ($settings as $_type => &$_settings) {
			$_settings['interval'] = (string)wp_get_schedule(self::$wp_cron_prefix . $_type);
		}

		if (is_null($type)) {
			return $settings;
		} else {
			return $settings[$type];
		}
	}

	private function set_settings($type, $settings) {
		if (!in_array($type, array('full', 'content'))) {
			return false;
		}

		if (!in_array($settings['interval'], array('monthly', 'weekly', 'daily'))) {
			$settings['interval'] = '';
		}

		if (($settings['lifetime'] = (int)$settings['lifetime']) < 1) {
			$settings['lifetime'] = self::$default_lifetime;
		}

		$settings = array(
			'interval' => $settings['interval'],
			'lifetime' => $settings['lifetime'],
		);

		$current_settings = $this->get_settings();
		$all_settings = array_merge(
			array(
				'full' => $current_settings['full'],
				'content' => $current_settings['content'],
			),
			array($type => $settings)
		);

		update_option(
			self::$wp_option_settings,
			$all_settings,
			false
		);

		// update cron
		{
			$hour = $this->get_cron_run_hour();

			foreach ( $all_settings as $type => $settings ) {
				$hook = self::$wp_cron_prefix . $type;

				wp_clear_scheduled_hook($hook);

				if ( $settings['interval'] ) {
					wp_schedule_event(
						time() + $this->get_time_until_hour( $hour ),
						$settings['interval'],
						$hook
					);
				}
			}
		}

		return true;
	}

	public function _filter_script_localized_data($data) {
		$data['schedule'] = array(
			'ajax_action' => array(
				'get_settings' => self::$wp_ajax_get_settings,
				'set_settings' => self::$wp_ajax_set_settings,
			),
			'popup_title' => __('Backup Schedule', 'fw'),
		);

		return $data;
	}

	public function _action_enqueue_backup_scripts() {
		fw()->backend->enqueue_options_static($this->get_options());
	}

	/**
	 * @param bool $exclude
	 * @param string $option_name
	 *
	 * @return bool
	 */
	public function _filter_db_exclude_option($exclude, $option_name) {
		if ($option_name === self::$wp_option_settings) {
			return true;
		}

		return $exclude;
	}

	/**
	 * @param array $options {option_name: true}
	 * @return array
	 */
	public function _filter_db_restore_keep_options($options) {
		$options[ self::$wp_option_settings ] = true;

		return $options;
	}

	public function _action_ajax_get_settings() {
		if (!current_user_can(self::backups()->get_capability())) {
			wp_send_json_error();
		}

		$settings = $this->get_settings();

		$values = array(
			'full' => array(
				'interval' => $settings['full']['interval'],
				$settings['full']['interval'] => array(
					'lifetime' => $settings['full']['lifetime'],
				),
			),
			'content' => array(
				'interval' => $settings['content']['interval'],
				$settings['content']['interval'] => array(
					'lifetime' => $settings['content']['lifetime'],
				),
			),
		);

		wp_send_json_success(array(
			'options' => $this->get_options(),
			'values' => $values,
		));
	}

	public function _action_ajax_set_settings() {
		if (!current_user_can(self::backups()->get_capability())) {
			wp_send_json_error();
		}

		if (
			empty($_POST['values'])
			||
		    !is_array($values = $_POST['values'])
		) {
			wp_send_json_error();
		}

		foreach (array('full', 'content') as $type) {
			if ($type === 'full' && !fw_ext_backups_current_user_can_full()) {
				$this->set_settings( $type, array(
					'interval' => '',
					'lifetime' => self::$default_lifetime,
				));
			} else {
				$this->set_settings( $type, array(
					'interval' => $values[ $type ]['interval'],
					'lifetime' => isset( $values[ $type ][ $values[ $type ]['interval'] ] )
						? $values[ $type ][ $values[ $type ]['interval'] ]['lifetime']
						: self::$default_lifetime,
				) );
			}
		}

		wp_send_json_success();
	}

	/**
	 * @link https://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules
	 * @param array $schedules
	 * @return array
	 */
	public function _filter_cron_schedules($schedules) {
		$schedules['weekly'] = array(
			'interval' => 604800,
			'display' => __('Once Weekly')
		);
		$schedules['monthly'] = array(
			'interval' => 2635200,
			'display' => __('Once a month')
		);

		return $schedules;
	}

	public function _action_cron_full() {
		$this->cleanup();
		self::backups()->tasks()->do_backup(true);
	}

	public function _action_cron_content() {
		$this->cleanup();
		self::backups()->tasks()->do_backup(false);
	}

	private function cleanup() {
		foreach (array('full' => true, 'content' => false) as $type => $full) {
			$settings = $this->get_settings($type);

			if (empty($settings['interval'])) { // this schedule is disabled
				continue;
			}

			{
				$time_step = null;

				switch ($settings['interval']) {
					case 'monthly': $time_step = WEEK_IN_SECONDS * 4; break;
					case 'weekly':  $time_step = WEEK_IN_SECONDS; break;
					case 'daily':   $time_step = DAY_IN_SECONDS; break;
				}

				if (is_null($time_step)) { // should not happen, but just in case
					continue;
				}
			}

			foreach (self::backups()->get_archives($full) as $archive) {
				if ($archive['time'] < time() - $settings['lifetime'] * $time_step) {
					unlink($archive['path']);
				}
			}
		}
	}

	public function _filter_ajax_status_response_data($data) {
		$html = array();

		foreach (array(
			'full' => esc_html__('Full', 'fw'),
			'content' => esc_html__('Content', 'fw')
		) as $type => $title) {
			$hook = self::$wp_cron_prefix . $type;

			if (false === ($recurrence = wp_get_schedule($hook))) {
				continue;
			}

			switch ($recurrence) {
				case 'monthly': $recurrence_title = esc_html__('Monthly', 'fw'); break;
				case 'weekly':  $recurrence_title = esc_html__('Weekly', 'fw'); break;
				case 'daily':   $recurrence_title = esc_html__('Daily', 'fw'); break;
				default: $recurrence_title = $recurrence;
			}

			$time = get_date_from_gmt(
				gmdate('Y-m-d H:i:s', wp_next_scheduled($hook)),
				get_option('date_format') . ' ' . get_option('time_format')
			);

			$html[] =
				'<div class="updated"><p>'.
				/**/'<strong>'. $title .' '. esc_html__('Backup Schedule Active', 'fw') .'</strong>: '.
				/**/$recurrence_title .' | '. esc_html__('Next backup on', 'fw') .' '. $time .
				'</p></div>';
		}

		if (empty($html)) {
			$html =
				'<div class="error"><p>' .
				/**/esc_html__( 'No backup schedule created yet! We advise you to do it asap!', 'fw' ) .
				'</p></div>';
		}

		$data['schedule'] = array(
			'status_html' => $html,
		);

		return $data;
	}
}
