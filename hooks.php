<?php if (!defined('FW')) die('Forbidden');

/**
 * @param bool $exclude
 * @param string $option_name
 * @param bool $is_full_backup
 *
 * @return bool
 */
function _filter_fw_ext_backups_db_export_exclude_option($exclude, $option_name, $is_full_backup) {
	foreach (array(
		'_site_transient_',
		'_transient_'
	) as $option_prefix) {
		if (substr($option_name, 0, strlen($option_prefix)) === $option_prefix) {
			return true;
		}
	}

	return $exclude;
}
add_filter('fw_ext_backups_db_export_exclude_option', '_filter_fw_ext_backups_db_export_exclude_option', 10, 3);

/**
 * Other extensions options
 */
{
	function _filter_fw_ext_backups_db_export_exclude_other_extensions_options($exclude, $option_name, $is_full_backup) {
		if (!$is_full_backup) {
			if ($option_name === 'fw_ext_settings_options:mailer') {
				return true;
			}
		}

		return $exclude;
	}
	add_filter('fw_ext_backups_db_export_exclude_option',
		'_filter_fw_ext_backups_db_export_exclude_other_extensions_options', 10, 3
	);

	function _filter_fw_ext_backups_db_restore_keep_other_extensions_options($options, $is_full) {
		if (!$is_full) {
			$options[ 'fw_ext_settings_options:mailer' ] = true;
		}

		return $options;
	}
	add_filter('fw_ext_backups_db_restore_keep_options',
		'_filter_fw_ext_backups_db_restore_keep_other_extensions_options', 10, 2
	);
}
