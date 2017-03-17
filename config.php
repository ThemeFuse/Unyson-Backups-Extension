<?php if (!defined('FW')) die('Forbidden');

$cfg = array();

/**
 * WhiteList hidden files and directories
 * By default all hidden files and dirs are skipped (like .git/ .idea/)
 */
$cfg['included_hidden_names'] = array(
	'.htaccess' => true,
);

global $wpdb; /** @var WPDB $wpdb */

// Note: Exclude and Keep are for content backup. On Full backup everything is exported and everything is replaced.

$cfg['db.backup.exclude_options'] = array(
	$wpdb->prefix .'user_roles' => true,
	'admin_email' => true,
	'cron' => true,
	'mailserver_login' => true,
	'mailserver_pass' => true,
	'mailserver_port' => true,
	'mailserver_url' => true,
	'ftp_credentials' => true,
	'use_ssl' => true,
	'WPLANG' => true,
	'recently_edited' => true, // contains full paths
	'current_theme' => true,
	// 'template' => true, 'stylesheet' => true, // used on restore to replace option names with current child theme
);

$cfg['db.restore.keep_options'] = array_merge(
	$cfg['db.backup.exclude_options'],
	array(
		'home' => true,
		'siteurl' => true,
		'date_format' => true,
		'links_updated_date_format' => true,
		'time_format' => true,
		'timezone_string' => true,
		'gmt_offset' => true,
		'start_of_week' => true,
		// 'permalink_structure' => true, // imported links with different structure will be 404 if current structure will be kept
		'rewrite_rules' => true,
		'ping_sites' => true,
		'upload_path' => true,
		'upload_url_path' => true,
		'uploads_use_yearmonth_folders' => true,
		'users_can_register' => true,
		'use_smilies' => true,
		'use_trackback' => true,
		'blogname' => true,
		'blogdescription' => true,
		'blog_charset' => true,
		'active_plugins' => true,
		'uninstall_plugins' => true,
		'recently_activated' => true,
		'moderation_notify' => true,
		'blacklist_keys' => true,
		'comment_registration' => true,
		'default_role' => true,
		'blog_public' => true,
		'can_compress_scripts' => true,
		'template' => true, 'stylesheet' => true, // keep current theme active
	)
);

/**
 * Automatic backups will be scheduled to run at this hour
 * Format: 0...23
 */
$cfg['schedule.hour'] = 3;

/**
 * The tasks that can't be executed in steps (for e.g. zip)
 * will use this value to try to increase php's default timeout
 */
$cfg['max_timeout'] = 60 * 10;

/**
 * Destination directory for backups archives
 */
$cfg['dirs.destination'] = fw_callback( 'fw_ext_backups_destination_directory' );