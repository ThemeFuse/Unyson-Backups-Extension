<?php if (!defined('FW')) die('Forbidden');

/**
 * Exported format: Lines of JSON objects
 *
 * {
 *  type: "table",
 *  data: {
 *      name: "table_name", // without wp prefix
 *      opts: "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 *      columns: {
 *          'column_name': 'bigint(20) unsigned NOT NULL AUTO_INCREMENT'
 *      },
 *      indexes: [
 *          'PRIMARY KEY (`meta_id`)',
 *          'KEY `comment_id` (`comment_id`)'
 *      ]
 *  }
 * }
 *
 * {
 *  type: "row",
 *  data: {
 *      table: "table_name", // without wp prefix
 *      row: {
 *          "column_name": "column_value",
 *          ...
 *      }
 *  }
 * }
 */
class FW_Ext_Backups_Task_Type_DB_Export extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'db-export';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __('Database export', 'fw');
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * dir - destination directory in which will be created `database.json.txt`
	 */
	public function execute(array $args, array $state = array()) {
		{
			if (!isset($args['dir'])) {
				return new WP_Error(
					'no_destination_dir', __('Destination dir not specified', 'fw')
				);
			}

			$args['full'] = isset($args['full']) ? (bool)$args['full'] : false;
		}

		$tables = $this->get_tables($args['full']);

		if (empty($state)) {
			$state = array(
				'table' => key($tables),
				'limit' => 0,
				'params' => false, // if params exported or not
			);
		} else {
			$state['limit'] = (int)$state['limit']; // just to make sure. this will not be escaped in sql
		}

		if (!isset($tables[$state['table']])) {
			return new WP_Error(
				'table_disappeared', __('Database table disappeared', 'fw')
			);
		}

		$backups = fw_ext('backups'); /** @var FW_Extension_Backups $backups */
		$exclude_options = $backups->get_config('db.backup.exclude_options');

		global $wpdb; /** @var WPDB $wpdb */

		$max_time = time() + fw_ext( 'backups' )->get_timeout(-7);

		while (time() < $max_time) {
			// open file for writing
			{
				$file_path = $args['dir'] .'/database.json.txt';

				if (!file_exists($file_path)) {
					if (!($fp = @fopen($file_path, 'w'))) {
						return new WP_Error(
							'cannot_create_file', __('Cannot create file', 'fw') .': '. $file_path
						);
					}
				} else {
					if (!($fp = @fopen($file_path, 'a'))) {
						return new WP_Error(
							'cannot_reopen_file', __('Cannot reopen file', 'fw') .': '. $file_path
						);
					}
				}
			}

			if (!$state['params']) {
				fwrite(
					$fp,
					json_encode(array(
						'type' => 'param',
						'data' => array(
							'name' => 'wpdb_prefix',
							'value' => $wpdb->prefix,
						),
					)) . "\n"
				);

				{
					$tmp = wp_upload_dir();

					fwrite(
						$fp,
						json_encode(array(
							'type' => 'param',
							'data' => array(
								'name' => 'wp_upload_dir_baseurl',
								'value' => $tmp['baseurl'],
							),
						)) . "\n"
					);

					unset($tmp);
				}

				$state['params'] = true;
			}

			if ($state['limit'] == 0) { // create table before data insert
				$sql = $wpdb->get_col('SHOW CREATE TABLE '. $wpdb->prefix . esc_sql($state['table']), 1);

				if (empty($sql)) {
					fclose($fp);
					return new WP_Error(
						'create_table_sql',
						sprintf(__('Cannot export CREATE TABLE sql for %s', 'fw'), $state['table'])
						.( $wpdb->last_error ? '. '. $wpdb->last_error : '' )
					);
				} else {
					$sql = $sql[0];
				}

				$data = array(
					'name' => $state['table'],
					'opts' => '', // 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
					'columns' => array(
						// 'column_name' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT'
					),
					'indexes' => array(
						// 'PRIMARY KEY (`meta_id`)'
						// 'KEY `comment_id` (`comment_id`)'
					),
				);

				/**
				 * Remove 'CREATE TABLE `wp_...` ('
				 */
				{
					$sql = explode('(', $sql);
					array_shift($sql);
					$sql = implode('(', $sql);
				}

				{
					$sql = explode(')', $sql);
					$data['opts'] = trim(array_pop($sql));
					$sql = implode(')', $sql);
				}

				foreach (explode(",\n", trim($sql)) as $column_or_index) {
					$column_or_index = trim($column_or_index);

					if (empty($column_or_index)) continue; // don't know when this happens, just in case

					if ($column_or_index{0} === '`') { // column
						$column_or_index = explode(' ', $column_or_index);
						$column_name = trim(array_shift($column_or_index), '`');
						$column_or_index = implode(' ', $column_or_index);
						$column_opts = $column_or_index;

						$data['columns'][ $column_name ] = $column_opts;
					} else {
						$data['indexes'][] = $column_or_index;
					}
				}

				fwrite(
					$fp,
					json_encode(array(
						'type' => 'table',
						'data' => $data
					)) . "\n"
				);

				unset($sql, $data);
			}

			if (!($column = $this->get_table_order_by_column($state['table']))) {
				$state['table'] = $this->get_next_table($state['table'], $args['full']);

				if (is_null($state['table'])) {
					fclose($fp);
					return true;
				} elseif (false === $state['table']) {
					fclose($fp);
					return new WP_Error(
						'no_next_table', __('Cannot get next database table', 'fw')
					);
				} else {
					$state['limit'] = 0;
				}
			}

			$limit = $this->get_table_limit($state['table']);

			$count = 0;

			foreach ($wpdb->get_results(
				'SELECT * FROM '. $wpdb->prefix . esc_sql($state['table'])
				.' ORDER BY '. esc_sql($column)
				.' LIMIT '. $state['limit'] .','. $limit,
				ARRAY_A
			) as $row) {
				$count++;

				if ('options' === $state['table']) {
					if (
						(
							!$args['full'] && isset($exclude_options[ $row['option_name'] ])
						)
						||
						apply_filters('fw_ext_backups_db_export_exclude_option', false,
							$row['option_name'], $args['full']
						)
					) {
						continue;
					}
				}

				fwrite(
					$fp,
					json_encode(array(
						'type' => 'row',
						'data' => array(
							'table' => $state['table'],
							'row' => $row,
						)
					)) . "\n"
				);
			}

			fclose($fp);

			if ($count > 0 && $count == $limit) {
				$state['limit'] += $limit;
			} else {
				$state['table'] = $this->get_next_table($state['table'], $args['full']);

				if (is_null($state['table'])) {
					return true;
				} elseif (false === $state['table']) {
					return new WP_Error(
						'no_next_table', __('Cannot get next database table', 'fw')
					);
				} else {
					$state['limit'] = 0;
				}
			}
		}

		return $state;
	}

	/**
	 * Cache
	 * @var array
	 */
	private $tables;

	/**
	 * @param bool $is_full
	 * @return array {'table_name': {}} Note: Table name is without $wpdb->prefix
	 */
	private function get_tables($is_full) {
		if (is_null($this->tables)) {
			global $wpdb; /** @var WPDB $wpdb */

			$tables = $wpdb->get_col(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$wpdb->esc_like($wpdb->prefix) .'%'
				)
			);

			/**
			 * Use /i in regex because prefix can be mixed case.
			 * Fixes https://github.com/ThemeFuse/Unyson/issues/2068#issuecomment-250196792
			 */
			$prefix_regex = '/^'. preg_quote($wpdb->prefix, '/') .'/i';

			foreach ($tables as $i => $table) {
				$tables[$i] = preg_replace($prefix_regex, '', $table);

				if (is_numeric($tables[$i]{0})) {
					/**
					 * Skip multisite tables '1_options' (wp_1_options)
					 * This happens when export is done on main site.
					 * So when doing export on main site,
					 * only main site tables will be exported, without sub sites tables.
					 */
					unset($tables[$i]);
				}
			}

			asort( $tables );

			$this->tables = array_fill_keys( $tables, array() );

			if (!$is_full) {
				/** @since 2.0.24 */
				$this->tables = apply_filters( 'fw:ext:backups:db-export:tables', $this->tables );
			}
		}

		if ($is_full) {
			return $this->tables;
		} else {
			$tables = $this->tables;

			foreach(array_keys(array(
				'users' => true,
				'usermeta' => true,

				'blogs' => true,
				'blog_versions' => true,
				'registration_log' => true,
				'signups' => true,
				'site' => true,
				'sitemeta' => true,
				'sitecategories' => true
			)) as $excluded_table) {
				unset($tables[$excluded_table]);
			}

			return $tables;
		}
	}

	private function get_next_table($name, $is_full) {
		$tables = $this->get_tables($is_full);
		$keys = array_keys($tables);
		$index = array_search($name, $keys);

		if ($index === false) {
			return false;
		} elseif (isset($keys[$index + 1])) {
			return $keys[$index + 1];
		} else {
			return null;
		}
	}

	/**
	 * Cache
	 * @var array {'table' => 'column'}
	 */
	private $table_order_by_columns = array();

	/**
	 * @param string $name
	 *
	 * @return string|false
	 */
	private function get_table_order_by_column($name) {
		if (!isset($this->table_order_by_columns[$name])) {
			global $wpdb; /** @var WPDB $wpdb */

			$columns = $wpdb->get_results( 'SHOW COLUMNS FROM '. $wpdb->prefix . esc_sql($name), ARRAY_A );

			if (empty($columns)) {
				// table has no columns, not sure when this can happen, but better treat this case
				$this->table_order_by_columns[$name] = false;
			} else {
				foreach ( $columns as $colum ) {
					if ( $colum['Key'] === 'PRI' ) { // Column is primary key
						$this->table_order_by_columns[ $name ] = $colum['Field'];
						break;
					}
				}

				// If table has no primary key, just use first column
				$this->table_order_by_columns[ $name ] = $columns[0]['Field'];
			}
		}

		return $this->table_order_by_columns[$name];
	}

	/**
	 * @param string $name Can be tables that can contain a lot of data in a single row, and can have a lower limit
	 *
	 * @return int How much rows to select in one query
	 */
	private function get_table_limit($name) {
		return 100;
	}
}
