<?php
require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/wpa11y.php';
require __DIR__ . '/fixtures.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	wt_reset();
	require $file;
}

$r = $GLOBALS['wt_results'];
echo "{$r['pass']} passed, {$r['fail']} failed\n";
exit( $r['fail'] ? 1 : 0 );
