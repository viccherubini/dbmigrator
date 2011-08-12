<?php declare(encoding='UTF-8');

function error($message) {
	echo("  ## \033[1;31m{$message}\033[m".PHP_EOL);
}

function success($message) {
	echo("  ## \033[1;32m{$message}\033[m".PHP_EOL);
}

function message($message) {
	echo("  ## \033[1;34m{$message}\033[m".PHP_EOL);
}