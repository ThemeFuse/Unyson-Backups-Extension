<?php
/**
 * Send parts of file contents
 */

$megabyte = 1000 * 1000;

{
	$cfg = array();

	/**
	 * You can create `config.php` in the same directory to overwrite default config
	 */
	if (file_exists($config_path = dirname(__FILE__) .'/config.php')) {
		include $config_path;
	}

	$cfg = array_merge(array(
		'files' => array(
			// 'demo-id' => '/path/to/demo.zip',
		),
		'size' => $megabyte * 3, // piece size in bytes
	), $cfg);

	if (empty($cfg['files'])) {
		header('HTTP/1.1 500 Internal Server Error');
		exit;
	}
}

{
	{ // file id
		if (empty($_GET['id'])) {
			header('HTTP/1.1 400 Bad Request');
			exit;
		}

		$id = $_GET['id'];

		if (!isset($cfg['files'][ $id ])) {
			header('HTTP/1.1 400 Bad Request');
			exit;
		}
	}

	{ // file byte position
		if (
			!isset($_GET['position'])
			||
		    !is_numeric($_GET['position'])
		) {
			header('HTTP/1.1 400 Bad Request');
			exit;
		}

		$position = intval($_GET['position']);

		if ($position < 0) {
			header('HTTP/1.1 400 Bad Request');
			exit;
		}
	}

	if (isset($_GET['size'])) { // custom piece size requested by client
		$size = $_GET['size'];

		if (
			is_numeric($size)
			&&
			($size = intval($size))
			&&
			$size > 0
			&&
			$size < $megabyte * 10
		) {
			$cfg['size'] = $size;
		} else {
			header('HTTP/1.1 400 Bad Request');
			exit;
		}
	}
}

$f = fopen($cfg['files'][ $id ], 'r');

if (!$f) {
	header('HTTP/1.1 500 Internal Server Error');
	exit;
}

if (-1 === fseek($f, $position)) {
	header('HTTP/1.1 500 Internal Server Error');
	exit;
}

if (false === ($data = fread($f, $cfg['size']))) {
	header('HTTP/1.1 500 Internal Server Error');
	exit;
}

$f = null;

$data_length = strlen($data);

header('Content-Type: application/octet-stream');
header('Content-Transfer-Encoding: binary');
header('X-Position: '. ($data_length ? ($position + $data_length) : '-1'));

if (!$position) {
	if ( $filesize = filesize( $cfg['files'][ $id ] ) ) {
		header( 'X-Filesize: ' . $filesize );
	} else {
		header( 'HTTP/1.1 500 Internal Server Error' );
		exit;
	}
}

echo $data;
