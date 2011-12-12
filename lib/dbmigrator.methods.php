<?php

function error($message, $exit=-1) {
	echo("  ## \033[1;31m{$message}\033[m".PHP_EOL);
	exit_if($exit);
}

function success($message, $exit=-1) {
	echo("  ## \033[1;32m{$message}\033[m".PHP_EOL);
	exit_if($exit);
}

function message($message, $exit=-1) {
	echo("  ## \033[1;34m{$message}\033[m".PHP_EOL);
	exit_if($exit);
}

function exit_if($exit) {
	if ($exit >= 0) {
		exit($exit);
	}
	return(true);
}