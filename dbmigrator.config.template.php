<?php declare(encoding='UTF-8');

// Copy this file to the root of where you will place all of your migration scripts.
// For example, if your project looks like this:

// /path/to/project/
//   build/
//     sql/
//   webroot/

// And you wanted the sql directory to contain your scripts and snapshots, this file would be
// placed in the build directory.


define('DB_TYPE', 'mysql');
define('DB_HOST', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');

// Only change this if you know what you're doing
$path = dirname(__FILE__);
$object_path = realpath($path . '/sql/');
define('OBJECT_PATH', $object_path);
