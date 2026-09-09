<?php
/*
	CLI harness: run sun.php with a query string and print the result.

	sun.php reads $_GET and sends headers, so it cannot simply be included from
	the CLI. This populates $_GET, suppresses the header() calls, buffers the
	output and prints it.

	Usage: php tests/generate.php "lat=45.5152&lng=-122.6784&gmt=-8&all&length=5"
*/

if ( $argc < 2 ){
	fwrite(STDERR, "usage: php tests/generate.php \"<query string>\" [path-to-sun.php]\n");
	exit(2);
}

$target = ( $argc > 2 ) ? $argv[2] : __DIR__ . '/../sun.php';

if ( !is_file($target) ){
	fwrite(STDERR, "no such file: $target\n");
	exit(2);
}

parse_str($argv[1], $_GET);

//sun.php calls header(); under the CLI SAPI that is a harmless no-op, but keep
//output clean regardless.
ob_start();
require $target;
$out = ob_get_clean();

echo $out;
