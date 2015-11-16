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

	if (!$is_full_backup) {
		if ($option_name === 'fw_ext_settings_options:mailer') {
			return true;
		}
	}

	return $exclude;
}
add_filter('fw_ext_backups_db_export_exclude_option', '_filter_fw_ext_backups_db_export_exclude_option', 10, 3);

