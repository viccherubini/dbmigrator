<?php

declare(encoding='UTF-8');

define('DB_TYPE', strtoupper('MYSQL'));
define('DB_HOST', '');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');

// Only change this if you know what you're doing
$path = dirname(__FILE__);
$migration_path = realpath($path . '/sql/');
define('MIGRATION_PATH', $migration_path);