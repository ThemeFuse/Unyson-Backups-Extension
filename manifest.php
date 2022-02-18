<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Backup & Demo Content', 'fw' );
$manifest['description'] = __(
	'This extension lets you create an automated backup schedule,'
	.' import demo content or even create a demo content archive for migration purposes.',
	'fw'
);
$manifest['author'] = 'ThemeFuse';
$manifest['author_uri'] = 'http://themefuse.com/';
$manifest['github_repo'] = 'https://github.com/ThemeFuse/Unyson-Backups-Extension';
$manifest['uri'] = 'http://manual.unyson.io/en/latest/extension/backups/index.html';
$manifest['version'] = '2.0.33';
$manifest['display'] = true;
$manifest['standalone'] = true;
$manifest['requirements'] = array(
	'framework' => array(
		'min_version' => '2.6.16',
	),
);

$manifest['github_update'] = 'ThemeFuse/Unyson-Backups-Extension';
