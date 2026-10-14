<?php
/**
 * Turns WP_DEBUG, WP_DEBUG_LOG on (and WP_DEBUG_DISPLAY off) in Playground's wp-config.php for EVERY
 * PHP request. The blueprint step defineWpConfigConsts only defines the constants per PHP worker,
 * and the stock wp-config.php has define( 'WP_DEBUG', false ), so a request served by another
 * worker ran with WP_DEBUG off and its notices never reached debug.log.
 * Run by the first blueprint step of every scenario (bin/smoke-test.sh). Not part of the plugin ZIP.
 */

$file   = '/wordpress/wp-config.php';
$config = (string) file_get_contents( $file );

$config = (string) preg_replace( "/define\\(\\s*['\"]WP_DEBUG(_LOG|_DISPLAY)?['\"]\\s*,[^;]*;/", '', $config );
$config = (string) preg_replace(
	'/^<\?php/',
	"<?php\ndefine( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );",
	$config,
	1
);

file_put_contents( $file, $config );
