<?php if (!defined('FW')) die('Forbidden');

/**
 * Restore database from database.json.txt
 */
class FW_Ext_Backups_Task_Type_DB_Restore extends FW_Ext_Backups_Task_Type {
	public function get_type() {
		return 'db-restore';
	}

	public function get_title(array $args = array(), array $state = array()) {
		return __( 'Database restore', 'fw' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_custom_timeout(array $args, array $state = array()) {
		return 10; // Fixes https://github.com/ThemeFuse/Unyson/issues/1818
	}

	/* @see get_custom_timeout() */
	private function get_timeout_padding() {
		return 3;
	}

	private $cache_table_index_columns = array();

	private function get_index_column($table_name) {
		if (!isset($this->cache_table_index_columns[$table_name])) {
			global $wpdb; /** @var WPDB $wpdb */

			$this->cache_table_index_columns[$table_name] = false;

			foreach ($wpdb->get_results('SHOW INDEX FROM '. $table_name, ARRAY_A) as $row) {
				if ($row['Key_name'] === 'PRIMARY') {
					$this->cache_table_index_columns[$table_name] = $row['Column_name'];
					break;
				}
			}
		}

		return $this->cache_table_index_columns[$table_name];
	}

	/**
	 * @param string $engine
	 * @return bool
	 * @since 2.0.20
	 */
	private function engine_is_supported($engine) {
		try {
			$engines = FW_Cache::get($cache_key = 'fw:ext:backups:db-engines');
		} catch (FW_Cache_Not_Found_Exception $e) {
			/** @var wpdb $wpdb */
			global $wpdb;

			$engines = array();

			if ($list = $wpdb->get_results('SHOW ENGINES', ARRAY_A)) {
				foreach ($list as $item) {
					$engines[ $item['Engine'] ] = in_array($item['Support'], array('YES', 'DEFAULT'), true);
				}
			}

			FW_Cache::set($cache_key, $engines);
		}

		return isset($engines[$engine]) && $engines[$engine];
	}

	/**
	 * {@inheritdoc}
	 * @param array $args
	 * * dir - source directory in which is located `database.json.txt`
	 * * [full] - (bool) force full or content restore. if not specified, will be detected automatically
	 * * [required] - (default: false) if database file must exist, else if db restore is optional
	 */
	public function execute(array $args, array $state = array()) {
		{
			if (!isset($args['dir'])) {
				return new WP_Error(
					'no_source_dir', __('Source dir not specified', 'fw')
				);
			} else {
				$args['dir'] = fw_fix_path($args['dir']);
			}

			if (!isset($args['required'])) {
				$args['required'] = false;
			} else {
				$args['required'] = (bool)$args['required'];
			}
		}

		{
			if (empty($state)) {
				if (!file_exists($args['dir'] .'/database.json.txt')) {
					if ($args['required']) {
						return new WP_Error(
							'no_db_file', __('Database file not found', 'fw')
						);
					} else {
						return true;
					}
				}

				$state = array(
					'task' => 'cleanup',
						// cleanup - delete all temporary tables
						// inspect - search and collect params (for e.g. `siteurl` and `home` wp options)
						// import - import data from file to db (at the same time, replace in rows old url with new)
						// keep:options - replace some imported rows with current values (prevent overwrite)
						// replace - replace original tables with imported temporary tables
					'step' => 0, // file read position (line) or other state (depending on task)

					/**
					 * These are populated on 'inspect'
					 */
					'params' => array( // extracted from db file
						// 'siteurl' => 'http...',
						// 'home' => 'http...',
					),
					'tables' => array(
						// 'table_name' => bool, // name without prefix // true - restore, false - ignore
					),
					'full' => isset($args['full']) ? (bool)$args['full'] : null, // is full or content backup
				);
			}
		}

		if (!class_exists('SplFileObject')) {
			return new WP_Error(
				'no_spl_file_object',
				__('PHP class SplFileObject is required but not available. Please contact your hosting', 'fw')
			);
		}

		if ($state['task'] === 'cleanup') {
			return $this->do_cleanup($args, $state);
		} elseif ($state['task'] === 'inspect') {
			return $this->do_inspect($args, $state);
		} elseif ($state['task'] === 'import') {
			return $this->do_import($args, $state);
		} elseif ($state['task'] === 'keep:options') {
			return $this->do_keep_options($args, $state);
		} elseif ($state['task'] === 'replace') {
			return $this->do_replace($args, $state);
		} else {
			return new WP_Error(
				'invalid_sub_task',
				sprintf(__( 'Invalid sub task %s', 'fw' ), $state['task'])
			);
		}

		return $state;
	}

	private function get_tmp_table_prefix() {
		global $wpdb; /** @var WPDB $wpdb */

		return '_fwbk_'. $wpdb->prefix;
	}

	/**
	 * @param string $value
	 * @return string
	 * @internal
	 */
	public function _wpdb_prepare_string($value) {
		global $wpdb; /** @var WPDB $wpdb */

		return $wpdb->prepare('%s', $value);
	}

	/**
	 * @return array {'table_name': {}} Note: Table name is without $wpdb->prefix
	 */
	private function get_tables() {
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
				 * This happens when restore is done on main site.
				 * So when doing restore on main site,
				 * only main site tables will be restored, without sub sites tables.
				 */
				unset($tables[$i]);
			}
		}

		return array_fill_keys( $tables, array() );
	}

	private function array_str_replace_recursive($search, $replace, &$subject) {
		if (is_array($subject)) {
			foreach($subject as &$_subject) {
				$this->array_str_replace_recursive( $search, $replace, $_subject );
			}

			unset($_subject);
		} elseif (is_string($subject)) {
			$_subject = maybe_unserialize( $subject );
			$unserialized = (
				gettype($_subject) !== gettype($subject)
				||
			    $_subject !== $subject
			);

			if (is_string($_subject)) {
				$_subject = str_replace($search, $replace, $_subject);
			} else {
				$this->array_str_replace_recursive( $search, $replace, $_subject );
			}

			if ($unserialized) {
				$_subject = serialize($_subject);
			}

			$subject = $_subject;

			unset($_subject);
		}
	}

	private function do_cleanup(array $args, array $state) {
		global $wpdb; /** @var WPDB $wpdb */

		// delete all tables with temporary prefix $this->get_tmp_table_prefix()
		if ($table_names = $wpdb->get_col($wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$wpdb->esc_like($this->get_tmp_table_prefix()) .'%'
		))) {
			if (!$wpdb->query('DROP TABLE '. esc_sql(
				/**
				 * Delete only one table at once, because some tables can be huge
				 * and deleting them all at once can exceed timeout limit
				 */
				$table_name = array_pop($table_names)
			))) {
				return new WP_Error(
					'drop_tmp_table_fail',
					sprintf(__('Cannot drop temporary table: %s', 'fw'), $table_name)
					.($wpdb->last_error ? '. '. $wpdb->last_error : '')
				);
			}

			++$state['step'];

			return $state;
		} else {
			$state['task'] = 'inspect';
			$state['step'] = 0;

			return $state;
		}

		return $state;
	}

	private function do_inspect(array $args, array $state) {
		global $wpdb; /** @var WPDB $wpdb */

		{
			try {
				$fo = new SplFileObject( $args['dir'] . '/database.json.txt' );
			} catch (RuntimeException $e) {
				$fo = null;
				return new WP_Error(
					'cannot_open_file', __('Cannot open db file', 'fw')
				);
			}

			try {
				$fo->seek( $state['step'] );
			} catch (RuntimeException $e) {
				$fo = null;
				return new WP_Error(
					'cannot_move_file_cursor', __( 'Cannot move cursor in db file', 'fw' )
				);
			}
		}

		$max_time = time() + fw_ext( 'backups' )->get_timeout(-$this->get_timeout_padding());

		while ( time() < $max_time ) {
			if ( $line = $fo->current() ) {
				if ( is_null( $line = json_decode( $line, true ) ) ) {
					$fo = null;

					return new WP_Error(
						'line_decode_fail',
						sprintf(
							__( 'Failed to decode line %d from db file.', 'fw' ) .' '. fw_get_json_last_error_message(),
							$state['step'] + 1
						)
					);
				}

				if (
					$line['type'] === 'row'
					&&
					$line['data']['table'] === 'options'
					&&
					isset($line['data']['row']['option_name'])
					&&
					in_array($line['data']['row']['option_name'], array(
						'siteurl', 'home', // used to replace imported urls with current
						'template', 'stylesheet', // used to replace imported theme slug with current
					))
				) {
					$state['params'][ $line['data']['row']['option_name'] ] = $line['data']['row']['option_value'];
				} elseif (
					$line['type'] === 'table'
					&&
					!isset($state['tables'][ $line['data']['name'] ])
				) {
					$state['tables'][ $line['data']['name'] ] = true;
				} elseif (
					$line['type'] === 'param'
				) {
					$state['params'][ $line['data']['name'] ] = $line['data']['value'];
				}
			} elseif ( $line === false && ! $fo->eof() ) {
				$fo = null;

				return new WP_Error(
					'line_read_fail',
					sprintf(__( 'Cannot read line %d from db file', 'fw' ), $state['step'] + 1)
				);
			} else {
				if (
					!isset($state['params']['siteurl'])
					||
					!isset($state['params']['home'])
				) {
					return new WP_Error(
						'params_not_found', __( 'Required params not found', 'fw' )
					);
				}

				// decide if it's full backup or not
				{
					$is_full_backup = (
						isset($state['tables']['commentmeta']) &&
						isset($state['tables']['comments']) &&
						isset($state['tables']['links']) &&
						isset($state['tables']['options']) &&
						isset($state['tables']['postmeta']) &&
						isset($state['tables']['posts']) &&
						isset($state['tables']['terms']) &&
						isset($state['tables']['term_relationships']) &&
						isset($state['tables']['term_taxonomy']) &&
						isset($state['tables']['usermeta']) &&
						isset($state['tables']['users'])
					);

					if (is_multisite()) { /* @link https://codex.wordpress.org/Database_Description */
						$is_full_backup = $is_full_backup && (
								isset($state['tables']['blogs']) &&
								isset($state['tables']['blog_versions']) &&
								isset($state['tables']['registration_log']) &&
								isset($state['tables']['signups']) &&
								isset($state['tables']['site']) &&
								isset($state['tables']['sitemeta'])
								// && isset($state['tables']['sitecategories']) // $wpdb->sitecategories exists but in docs not
							);
					}

					if (is_null($state['full'])) {
						$state['full'] = $is_full_backup;
					} elseif ($state['full'] && !$is_full_backup) {
						return new WP_Error(
							'full_db_restore_impossible',
							__('Cannot do full db restore because backup is missing some tables', 'fw')
						);
					}
				}

				// skip tables
				{
					$skip_tables = array(
						'users' => true,
						'usermeta' => true
					);

					if (!$state['full']) {
						$skip_tables = array_merge($skip_tables, array(
							'blogs' => true,
							'blog_versions' => true,
							'registration_log' => true,
							'signups' => true,
							'site' => true,
							'sitemeta' => true,
							'sitecategories' => true
						));
					}

					foreach (array_keys($skip_tables) as $table_name) {
						if (isset($state['tables'][$table_name])) {
							$state['tables'][$table_name] = false;
						}
					}

					unset($skip_tables);
				}

				$state['step'] = 0;
				$state['task'] = 'import';

				$fo = null;

				return $state;
			}

			$state['step'] ++;
			$fo->next();
		}

		$fo = null;

		return $state;
	}

	private function do_import(array $args, array $state) {
		global $wpdb; /** @var WPDB $wpdb */

		{
			try {
				$fo = new SplFileObject( $args['dir'] . '/database.json.txt' );
			} catch (RuntimeException $e) {
				$fo = null;
				return new WP_Error(
					'cannot_open_file', __('Cannot open db file', 'fw')
				);
			}

			try {
				$fo->seek( $state['step'] );
			} catch (RuntimeException $e) {
				$fo = null;
				return new WP_Error(
					'cannot_move_file_cursor', __( 'Cannot move cursor in db file', 'fw' )
				);
			}
		}

		/**
		 * Prepare search and replace strings
		 * Used for search and replace demo url with current url in each imported row
		 * Are searched all possible formats: json encoded, json encoded escaped, WP Shortcodes encoded strings, etc.
		 */
		{
			$params = array(
				'search' => array(),
				'replace' => array(),
			);

			// Prepare clean search->replace strings
			{
				/**
				 * Note: rtrim(..., '/') is used to prevent wrong link replace
				 *       'http://abc.com/img.jpg' -> 'http://def.comimg.jpg'
				 * Note: First links should be the longest, to prevent the short part replace
				 *       when the long part must be replaced (thus making wrong replace)
				 */
				$search_replace = array();

				/**
				 * This parameter (fix) was added after extension release
				 * so some demo installs may not have it
				 */
				if (isset($state['params']['wp_upload_dir_baseurl'])) {
					$wp_upload_dir = wp_upload_dir();

					$search_replace[
						rtrim($state['params']['wp_upload_dir_baseurl'], '/')
					] = rtrim($wp_upload_dir['baseurl'], '/');

					unset($wp_upload_dir);
				}

				foreach (array('siteurl', 'home') as $_wp_option) {
					$search_replace[
						rtrim($state['params'][$_wp_option], '/')
					] = rtrim(get_option($_wp_option), '/');
				}

				foreach ($search_replace as $search => $replace) {
					$search_replace[
						($old_url = fw_get_url_without_scheme($search))
					] = ($new_url = fw_get_url_without_scheme($replace));

					/**
					 * If imported url is 'http://hello' and current url is 'http://hello.site.com'
					 * after str_replace() all urls will be broken 'http://hello.site.com.site.com.site.com.site.com'
					 */
					if (
						strlen($old_url) !== strlen($new_url)
						&&
						preg_match('/^'. preg_quote($old_url, '/') .'/', $new_url)
					) {
						return new WP_Error(
							'url_replace_fail',
							sprintf(__('Imported url "%s" is prefix of current url', 'fw'), $search)
						);
					}
				}
			}

			// Add all possible combinations of encoding
			foreach ($search_replace as $search => $replace) {
				if ($search === $replace) {
					continue;
				}

				$_search_replace = array(
					$search => $replace,
					json_encode($search) => json_encode($replace),
				);

				/**
				 * Loop through all available shortcodes coders
				 * Fixes https://github.com/ThemeFuse/Unyson-Backups-Extension/issues/23
				 */
				if ($shortcodes_ext = fw_ext('shortcodes')) { /** @var FW_Extension_Shortcodes $shortcodes_ext */
					$_search_replace_coders = array();

					foreach ($shortcodes_ext->get_attr_coder() as $coder) {
						foreach ($_search_replace as $search => $replace) {
							/**
							 * Shortcodes atts can contain other encoded shortcodes
							 * so the values will be encoded multiple times
							 */
							for ($i = 0; $i < 3; $i++) {
								if (
									(
										!is_wp_error($search  = $coder->encode(array('x' => $search),  'x', 0))
										&&
										isset($search['x']) // there is no other way to extract the encoded value
										&&
										($search = $search['x'])
									)
									&&
									(
										!is_wp_error($replace = $coder->encode(array('x' => $replace), 'x', 0))
										&&
										isset($replace['x'])
										&&
										($replace = $replace['x'])
									)
								) {
									$_search_replace_coders[ $search ] = $replace;
								} else {
									break;
								}
							}
						}
					}

					$_search_replace = array_merge($_search_replace, $_search_replace_coders);

					unset($shortcodes_ext, $_search_replace_coders);
				}

				foreach ($_search_replace as $search => $replace) {
					$params['search'][] = $search;
					$params['replace'][] = $replace;

					$params['search'][] = str_replace( '/', '\\/', $search);
					$params['replace'][] = str_replace( '/', '\\/', $replace);

					$params['search'][] = str_replace( '/', '\\\\/', $search);
					$params['replace'][] = str_replace( '/', '\\\\/', $replace);

					$params['search'][] = str_replace( '/', '\\\\\\/', $search);
					$params['replace'][] = str_replace( '/', '\\\\\\/', $replace);
				}

				unset($_search_replace);
			}

			unset($search_replace, $search, $replace);
		}

		/**
		 * Rename wp_option names which contain stylesheed in their names
		 * Fixes https://github.com/ThemeFuse/Unyson-Backups-Extension/issues/27
		 */
		{
			$replace_option_names = array();

			{
				$filter_data = array();

				if (
					is_child_theme() // must be: get_stylesheet() !== get_template()
					&&
					! empty($state['params']['stylesheet'])
					&&
					// do nothing if it's the same
					$state['params']['stylesheet'] !== get_stylesheet()
					&&
					// prevent rename stylesheet to template and duplicate wp_option error
					$state['params']['stylesheet'] !== get_template()
				) {
					$filter_data['stylesheet'] = $state['params']['stylesheet'];

					$replace_option_names[
						'theme_mods_'. $state['params']['stylesheet']
					] = 'theme_mods_'. get_stylesheet();
				}

				if (
					! empty($state['params']['template'])
					&&
					// prevent template overwrite stylesheet (prefer stylesheet)
					$state['params']['template'] !== $state['params']['stylesheet']
					&&
					// do nothing if it's the same
					$state['params']['template'] !== get_template()
					&&
					// prevent rename template to stylesheet and duplicate wp_option error
					$state['params']['template'] !== get_stylesheet()
				) {
					$filter_data['template'] = $state['params']['template'];

					$replace_option_names[
						'theme_mods_'. $state['params']['template']
					] = 'theme_mods_'. get_template();
				}
			}

			if ( ! empty($filter_data) ) {
				$replace_option_names = array_merge(
					/** @since 2.0.12 */
					apply_filters('fw_ext_backups_db_restore_option_names_replace', array(), $filter_data),
					$replace_option_names
				);
			}

			unset($filter_data);
		}

		$max_time = time() + fw_ext( 'backups' )->get_timeout(-$this->get_timeout_padding());

		while ( time() < $max_time ) {
			if ( $line = $fo->current() ) {
				if ( is_null( $line = json_decode( $line, true ) ) ) {
					$fo = null;

					return new WP_Error(
						'line_decode_fail',
						sprintf(
							__( 'Failed to decode line %d from db file.', 'fw' ) .' '. fw_get_json_last_error_message(),
							$state['step'] + 1
						)
					);
				}

				switch ( $line['type'] ) {
					case 'table':
						if (!$state['tables'][ $line['data']['name'] ]) {
							break; // skip
						}

						$tmp_table_name = $this->get_tmp_table_prefix() . $line['data']['name'];

						if (strlen($tmp_table_name) > 64) { // http://stackoverflow.com/a/6868316/1794248
							return new WP_Error(
								'tmp_table_name_invalid',
								sprintf( __( 'Table name is more than 64 characters: %s', 'fw' ), $tmp_table_name )
							);
						}

						if ( false === $wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $tmp_table_name ) ) ) {
							$fo = null;

							return new WP_Error(
								'tmp_table_drop_fail',
								sprintf( __( 'Failed to drop tmp table %s', 'fw' ), $tmp_table_name )
								.($wpdb->last_error ? '. '. $wpdb->last_error : '')
							);
						}

						if (
							($wpdb->get_col($wpdb->prepare(
								'SHOW TABLES LIKE %s', $wpdb->prefix . $line['data']['name']
							)))
							&&
							($sql = $wpdb->get_col(
								'SHOW CREATE TABLE '. esc_sql($wpdb->prefix . $line['data']['name']), 1
							))
							&&
							($sql = array_pop($sql))
						) { // Preserve table structure https://github.com/ThemeFuse/Unyson-Backups-Extension/issues/41
							$sql = preg_replace(
								'/CREATE TABLE `' . esc_sql( $wpdb->prefix . $line['data']['name'] ) . '`/i',
								'CREATE TABLE `' . esc_sql( $tmp_table_name ) . '`',
								$sql
							);

							// Use imported table's auto_increment
							if (preg_match(         '/ AUTO_INCREMENT=(\d+)/', $line['data']['opts'], $matches)) {
								$sql = preg_replace('/ AUTO_INCREMENT=\d+/', ' AUTO_INCREMENT='. $matches[1], $sql);
							}
						} else {
							$utf8mb4_is_supported = ( defined( 'DB_CHARSET' ) && DB_CHARSET === 'utf8mb4' );

							$sql = 'CREATE TABLE `' . esc_sql( $tmp_table_name ) . "` (\n";

							{
								$cols_sql = array();

								foreach ( $line['data']['columns'] as $col_name => $col_opts ) {
									$cols_sql[] = '`'. esc_sql( $col_name ) .'` '. (
										$utf8mb4_is_supported
											? $col_opts
											: str_replace( 'utf8mb4', 'utf8', $col_opts )
										);
								}

								foreach ( $line['data']['indexes'] as $index ) {
									$cols_sql[] = $index;
								}

								$sql .= implode( ", \n", $cols_sql );

								unset( $cols_sql );
							}

							// $line['data']['opts']
							{
								if (!$this->engine_is_supported('InnoDB')) {
									// fallback to MyISAM if InnoDB is not supported
									// https://github.com/ThemeFuse/Unyson/issues/2175
									$line['data']['opts'] = str_replace('InnoDB', 'MyISAM', $line['data']['opts']);
								}

								if (!$utf8mb4_is_supported) {
									$line['data']['opts'] = str_replace( 'utf8mb4', 'utf8', $line['data']['opts'] );
								}

								$sql .= ') ' . $line['data']['opts'];
							}
						}

						if ( false === $wpdb->query( $sql ) ) {
							$fo = null;

							return new WP_Error(
								'tmp_table_create_fail',
								sprintf( __( 'Failed to create tmp table %s', 'fw' ), $tmp_table_name )
								.($wpdb->last_error ? '. '. $wpdb->last_error : '')
							);
						}

						unset( $sql );
						break;
					case 'row':
						if ( ! isset( $state['tables'][ $line['data']['table'] ] ) ) {
							$fo = null;

							return new WP_Error(
								'invalid_table',
								sprintf( __( 'Tried to insert data in table that was not imported %s', 'fw' ), $line['data']['table'] )
							);
						} elseif ( ! $state['tables'][ $line['data']['table'] ] ) {
							break; // the table was skipped
						} elseif (
							'options' === $line['data']['table']
							&&
							apply_filters('fw_ext_backups_db_restore_exclude_option', false,
								$line['data']['row']['option_name'], $state['full']
							)
						) {
							break;
						}

						if ( ! empty($params['search']) ) {
							$this->array_str_replace_recursive(
								$params['search'],
								$params['replace'],
								$line['data']['row']
							);
						}

						if (
							! empty($replace_option_names)
							&&
							$line['data']['table'] === 'options'
						) {
							if (isset($replace_option_names[ $line['data']['row']['option_name'] ])) {
								$line['data']['row']['option_name']
									= $replace_option_names[ $line['data']['row']['option_name'] ];
							} elseif (in_array($line['data']['row']['option_name'], $replace_option_names, true)) {
								/**
								 * Skip
								 *
								 * Do not insert this option because it will be (was) inserted on rename/replace (above)
								 * Prevent 'Duplicate entry' error https://github.com/ThemeFuse/Unyson-Backups-Extension/issues/34
								 *
								 * This happens when:
								 * 1) On Content Backup 'stylesheet' or 'template' is 'theme-name/theme-name'
								 *    but in db there is also 'theme-name' (saved before the theme changed its directory)
								 * 2) On Demo Content Install 'stylesheet' or 'template' is 'theme-name'
								 * 3) The script does replace old 'theme-name/theme-name' to current 'theme-name'
								 *    but that 'theme-name' from (1) conflicts with current 'theme-name'
								 * The solution is to ignore/skip 'theme-name' from 1)
								 */
								break;
							}
						}

						if (isset($state['params']['wpdb_prefix'])) {
							/**
							 * Rename options and usermeta like `{wpdb_prefix}user_roles`, `{wpdb_prefix}capabilities`
							 */
							{
								$column = $search = null;

								switch ($line['data']['table']) {
									case 'options':
										$column = 'option_name';
										$search = array(
											'user_roles',
										);
										break;
									case 'usermeta':
										$column = 'meta_key';
										$search = array(
											'capabilities',
											'user_level',
											'dashboard_quick_press_last_post_id',
											'user-settings',
											'user-settings-time',
										);
										break;
								}

								if ($column && $search) {
									foreach ($search as $name) {
										if (
											substr($line['data']['row'][$column], -strlen($name))
											===
											$name
											&&
											substr($line['data']['row'][$column], 0, strlen($state['params']['wpdb_prefix']))
											===
											$state['params']['wpdb_prefix']
										) {
											$line['data']['row'][$column] = $wpdb->prefix
											                                . substr($line['data']['row'][$column], strlen($state['params']['wpdb_prefix']));
										}
									}
								}
							}
						}

						$tmp_table_name = $this->get_tmp_table_prefix() . $line['data']['table'];

						/**
						 * Insert pieces of rows to prevent mysql error when inserting very big strings.
						 * Do this only if table has index column so we can do 'UPDATE ... WHERE index_column = %s'
						 */
						if ($index_column = $this->get_index_column($tmp_table_name)) {
							/**
							 * Tested, and maximum value which works is 950000
							 * but may be tables with many columns with big strings
							 * so set this value lower to be sure the limit is not reached.
							 */
							$value_max_length = 500000;
							$update_count = 0;
							$index_column_value = $line['data']['row'][ $index_column ];
							$row_lengths = array();

							while ($line['data']['row']) {
								$row = array();

								foreach (array_keys($line['data']['row']) as $column_name) {
									$row[ $column_name ] = mb_substr(
										$line['data']['row'][ $column_name ],
										0, $value_max_length
									);

									$row_length = mb_strlen($row[ $column_name ]);

									if (!isset($row_lengths[$column_name])) {
										$row_lengths[ $column_name ] = mb_strlen( $line['data']['row'][ $column_name ] );
									}

									/**
									 * The string was cut between a slashed character, for e.g. \" or \\
									 * Append next characters until the slashing is closed
									 */
									while (
										($last_char = mb_substr($row[ $column_name ], -1)) === '\\'
										&&
										/**
										 * Prevent infinite loop when the last character is a slash
										 */
										$row_length < $row_lengths[ $column_name ]
									) {
										$row[ $column_name ] .= mb_substr(
											$line['data']['row'][ $column_name ],
											$row_length - 1, 1
										);

										$row_length++; // do not call mb_strlen() on every loop
									}

									$line['data']['row'][ $column_name ] = mb_substr(
										$line['data']['row'][ $column_name ],
										$row_length
									);

									if (empty($line['data']['row'][ $column_name ])) {
										unset($line['data']['row'][ $column_name ]);
									}
								}

								if ($update_count) {
									{
										$set_sql = array();
										foreach (array_keys($row) as $column_name) {
											$set_sql[] = '`'. esc_sql($column_name) .'` = CONCAT( `'. esc_sql($column_name) .'`'
											             .' , '. $wpdb->prepare('%s', $row[$column_name]) .')';
										}
										$set_sql = implode(', ', $set_sql);
									}

									$sql = implode(" \n", array(
										"UPDATE {$tmp_table_name} SET",
										$set_sql,
										'WHERE `'. esc_sql($index_column) .'` = '. $wpdb->prepare('%s', $index_column_value)
									));
								} else {
									$sql = implode(" \n", array(
										"INSERT INTO {$tmp_table_name} (",
										'`'. implode( '`, `', array_map( 'esc_sql', array_keys( $row ) ) ) .'`',
										") VALUES (",
										implode( ', ', array_map( array( $this, '_wpdb_prepare_string' ), $row ) ),
										")"
									));
								}

								if ( false === $wpdb->query( $sql ) ) {
									if (
										! $update_count // is INSERT
										&&
										$wpdb->last_error
										&&
										/**
										 * This happens when the column type is binary.
										 * The binary value on Content Backup is exported to string in json, and now back to binary
										 * so it looses some data, especially if it contains trailing `0x...000`
										 * Fixes https://github.com/ThemeFuse/Unyson/issues/1952
										 */
										(
											strpos($wpdb->last_error, 'Duplicate') !== false
											&&
											strpos($wpdb->last_error, '\\x00') !== false
										)
									) {
										break;
									} else {
										$fo = null;

										return new WP_Error(
											'insert_fail',
											sprintf(
												__('Failed to insert row from line %d into table %s', 'fw'),
												$state['step'] + 1, $tmp_table_name
											)
											. ($wpdb->last_error ? '. ' . $wpdb->last_error : '')
										);
									}
								}

								unset( $sql );

								$update_count++;
							}
						} else {
							$sql = implode(" \n", array(
								"INSERT INTO {$tmp_table_name} (",
								'`'. implode( '`, `', array_map( 'esc_sql', array_keys( $line['data']['row'] ) ) ) .'`',
								") VALUES (",
								implode( ', ', array_map( array( $this, '_wpdb_prepare_string' ), $line['data']['row'] ) ),
								")"
							));

							if ( false === $wpdb->query( $sql ) ) {
								$fo = null;

								return new WP_Error(
									'insert_fail',
									sprintf(
										__('Failed to insert row from line %d into table %s', 'fw'),
										$state['step'] + 1, $tmp_table_name
									)
									. ($wpdb->last_error ? '. ' . $wpdb->last_error : '')
								);
							}

							unset( $sql );
						}
						break;
					case 'param':
						break;
					default:
						$fo = null;

						return new WP_Error(
							'invalid_json_type',
							sprintf( __( 'Invalid json type %s in db file', 'fw' ), $line['type'] )
						);
				}
			} elseif ( $line === false && ! $fo->eof() ) {
				$fo = null;

				return new WP_Error(
					'line_read_fail', __( 'Cannot read line from db file', 'fw' )
				);
			} else {
				$fo = null;

				$state['step'] = 0;
				$state['task'] = 'keep:options';

				return $state;
			}

			$state['step']++;
			$fo->next();
		}

		$fo = null;

		return $state;
	}

	private function do_keep_options(array $args, array $state) {
		global $wpdb; /** @var WPDB $wpdb */

		if ($state['full'] && !isset($state['tables']['options'])) {
			// on full backup nothing is kept
		} else {
			$keep_options = array_merge(
				fw_ext( 'backups' )->get_config( 'db.restore.keep_options' ),
				apply_filters('fw_ext_backups_db_restore_keep_options', array(), $state['full'])
			);

			$max_time = time() + fw_ext( 'backups' )->get_timeout(-$this->get_timeout_padding());

			// restore array pointer position
			if ($state['step']) {
				while (
					($option_name = key($keep_options))
					&&
					$option_name !== $state['step']
				) next($keep_options);

				if (empty($option_name)) {
					return new WP_Error(
						'keep_options_continue_fail',
						__('Failed to restore options keeping step', 'fw')
					);
				}
			} else {
				$state['step'] = key($keep_options);
			}

			do {
				$tmp_options_table = esc_sql($this->get_tmp_table_prefix() . 'options');

				while ( time() < $max_time ) {
					if ($row = $wpdb->get_row($wpdb->prepare(
						"SELECT * FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
						$state['step']
					), ARRAY_A)) {
						$wpdb->query($wpdb->prepare(
							"DELETE FROM {$tmp_options_table} WHERE option_name = %s",
							$state['step']
						));

						/**
						 * Prevent error: Duplicate entry '90' for key 'PRIMARY' for query INSERT INTO ...options
						 * Option id will be auto incremented on insert
						 */
						unset($row['option_id']);

						if (false === $wpdb->query(
								"INSERT INTO {$tmp_options_table} ( \n"
								. '`'. implode( '`, `', array_map( 'esc_sql', array_keys( $row ) ) ) . "`  \n"
								. ") VALUES ( \n"
								. implode( ', ', array_map( array( $this, '_wpdb_prepare_string' ), $row ) ) . " \n"
								. ')'
							)) {
							return new WP_Error(
								'option_keep_fail',
								sprintf(__('Failed to keep option: %s', 'fw'), $state['step'])
								.($wpdb->last_error ? '. '. $wpdb->last_error : '')
							);
						}
					}

					next( $keep_options );
					if ( is_null( $state['step'] = key( $keep_options ) ) ) {
						break 2;
					}
				}

				return $state;
			} while(false);
		}

		$state['step'] = 0;
		$state['task'] = 'replace';

		return $state;
	}

	private function do_replace(array $args, array $state) {
		global $wpdb; /** @var WPDB $wpdb */

		/**
		 * P.P. We can't rename tables one by one, that can cause errors on next request (db corrupt)
		 *      so the only solution is to rename all table at once
		 *      and hope that the execution will not exceed timeout limit
		 * P.P.S. Table rename should be fast http://dba.stackexchange.com/a/53850
		 */

		$current_tables = $this->get_tables();
		$rename_sql = array();
		$drop_sql = array();

		foreach ($state['tables'] as $name => $restored) {
			if ($restored) {
				if (isset($current_tables[$name])) {
					// drop only if exists. to prevent sql error
					$drop_sql[] = esc_sql( $wpdb->prefix . $name );
				}

				/**
				 * Demo Install can contain new tables like for plugins
				 * so don't use `isset($current_tables[$name])` to import only existing table names
				 */
				$rename_sql[] =
					esc_sql($this->get_tmp_table_prefix() . $name)
					. ' TO '
					. esc_sql($wpdb->prefix . $name);
			}
		}

		if (!empty($rename_sql)) {
			if (!empty($drop_sql)) {
				$drop_sql = "DROP TABLE \n". implode(" , \n", $drop_sql);

				if (!$wpdb->query($drop_sql)) {
					return new WP_Error(
						'tables_drop_fail',
						__('Tables drop failed', 'fw')
						.($wpdb->last_error ? '. '. $wpdb->last_error : '')
					);
				}
			}
			{
				$rename_sql = "RENAME TABLE \n". implode(" , \n", $rename_sql);

				$wpdb->query($rename_sql);

				// RENAME query doesn't return bool, so use the below method to detect error
				if ($rename_sql === $wpdb->last_query && $wpdb->last_error) {
					return new WP_Error(
						'tables_rename_fail',
						__('Tables rename failed.', 'fw') .' '. $wpdb->last_error
					);
				}
			}
		}

		wp_cache_flush();

		return true;
	}
}
