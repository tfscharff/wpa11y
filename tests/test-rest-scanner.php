<?php
wt_reset( array( 'posts' => array(
	array( 'ID' => 10 ),
	array( 'ID' => 11, 'post_status' => 'draft' ),
	array( 'ID' => 12, 'post_type' => 'post', 'post_title' => 'News &amp; Notes', 'post_name' => 'news' ),
) ) );
update_option( 'wpa11y_secret_hash', hash( 'sha256', 's3cret' ) );
$auth = array( 'Authorization' => 'Bearer s3cret' );

check( 'perm: good token', wpa11y_scanner_permission( new WP_REST_Request( array(), $auth ) ), true );
check( 'perm: bad token is 401', wpa11y_scanner_permission( new WP_REST_Request( array(), array( 'Authorization' => 'Bearer nope' ) ) )->get_error_data(), array( 'status' => 401 ) );
check( 'perm: no header is 401', is_wp_error( wpa11y_scanner_permission( new WP_REST_Request() ) ), true );
check( 'perm: editor allowed', wpa11y_editor_permission(), true );
$GLOBALS['wt_caps'] = array();
check( 'perm: non-editor refused', wpa11y_editor_permission(), false );
$GLOBALS['wt_caps'] = array( 'edit_pages' => true );

$pages = wpa11y_rest_pages();
check( 'pages: published only, by title', array_column( $pages, 'id' ), array( 12, 10 ) );
check( 'pages: shape', $pages[1], array( 'id' => 10, 'url' => WT_SITE . '/page-10/', 'type' => 'page', 'title' => 'Page 10' ) );
check( 'pages: title decoded', $pages[0]['title'], 'News & Notes' );

$body = array( 'post_id' => 10, 'url' => WT_SITE . '/page-10/', 'scanned_at' => '2026-09-30T10:00:00Z', 'issues' => array( wt_issue( 'error', 'image-alt', 'img' ) ) );
check( 'results: ok', wpa11y_rest_results( new WP_REST_Request( array(), $auth, $body ) ), array( 'ok' => true ) );
check( 'results: stored', wpa11y_get_result( 10 )['issues'][0]['code'], 'image-alt' );
check( 'results: draft refused', wpa11y_rest_results( new WP_REST_Request( array(), $auth, array_merge( $body, array( 'post_id' => 11 ) ) ) )->get_error_code(), 'wpa11y_bad_post' );
check( 'results: missing post_id refused', wpa11y_rest_results( new WP_REST_Request( array(), $auth, array() ) )->get_error_code(), 'wpa11y_bad_post' );
check( 'results: url of another page refused', wpa11y_rest_results( new WP_REST_Request( array(), $auth, array_merge( $body, array( 'url' => WT_SITE . '/news/' ) ) ) )->get_error_code(), 'wpa11y_url_mismatch' );
wpa11y_rest_results( new WP_REST_Request( array(), $auth, array( 'post_id' => 10, 'url' => WT_SITE . '/page-10/', 'scanned_at' => '2026-09-30T11:00:00Z', 'error' => 'Timeout' ) ) );
$r = wpa11y_get_result( 10 );
check( 'results: failure keeps issues', array( count( $r['issues'] ), $r['error'], $r['scanned_at'] ), array( 1, 'Timeout', '2026-09-30T10:00:00Z' ) );

check( 'scan-complete: ok', wpa11y_rest_scan_complete( new WP_REST_Request( array(), $auth, array( 'scanned' => 2 ) ) ), array( 'ok' => true ) );
check( 'scan-complete: time recorded', 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', get_option( 'wpa11y_last_full_scan' ) ), true );

$GLOBALS['wt_routes'] = array();
wpa11y_register_routes();
check( 'routes: scanner routes registered', array_slice( array_keys( $GLOBALS['wt_routes'] ), 0, 3 ), array( 'wpa11y/v1/pages', 'wpa11y/v1/results', 'wpa11y/v1/scan-complete' ) );
check( 'routes: every route checks permission', count( array_filter( $GLOBALS['wt_routes'], function ( $r ) { return empty( $r['permission_callback'] ) || '__return_true' === $r['permission_callback']; } ) ), 0 );
check( 'routes: scanner routes take the secret, editor routes the capability', array_map( function ( $r ) { return array( $r['methods'], $r['permission_callback'] ); }, $GLOBALS['wt_routes'] ), array(
	'wpa11y/v1/pages'         => array( 'GET', 'wpa11y_scanner_permission' ),
	'wpa11y/v1/results'       => array( 'POST', 'wpa11y_scanner_permission' ),
	'wpa11y/v1/scan-complete' => array( 'POST', 'wpa11y_scanner_permission' ),
	'wpa11y/v1/status'        => array( 'GET', 'wpa11y_editor_permission' ),
	'wpa11y/v1/dismiss'       => array( 'POST', 'wpa11y_editor_permission' ),
	'wpa11y/v1/dismiss-group' => array( 'POST', 'wpa11y_editor_permission' ),
	'wpa11y/v1/undismiss'     => array( 'POST', 'wpa11y_editor_permission' ),
	'wpa11y/v1/rescan'        => array( 'POST', 'wpa11y_editor_permission' ),
) );

// The page list asks WordPress for published, unprotected pages of every scanned type.
wpa11y_rest_pages();
check( 'pages: query asks for published pages without a password', array_intersect_key( $GLOBALS['wt_get_posts_args'], array_flip( array( 'post_type', 'post_status', 'has_password', 'numberposts' ) ) ), array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'has_password' => false, 'numberposts' => -1 ) );

// Knowing the stored hash is not enough, and nothing gets in before a secret exists.
check( 'perm: the stored hash itself is refused', is_wp_error( wpa11y_scanner_permission( new WP_REST_Request( array(), array( 'Authorization' => 'Bearer ' . hash( 'sha256', 's3cret' ) ) ) ) ), true );
check( 'perm: other schemes refused', is_wp_error( wpa11y_scanner_permission( new WP_REST_Request( array(), array( 'Authorization' => 'Basic s3cret' ) ) ) ), true );
update_option( 'wpa11y_secret_hash', '' );
check( 'perm: no secret generated, nothing gets in', is_wp_error( wpa11y_scanner_permission( new WP_REST_Request( array(), $auth ) ) ), true );
update_option( 'wpa11y_secret_hash', hash( 'sha256', 's3cret' ) );

// A refused payload changes nothing and emails nobody.
update_option( 'wpa11y_notify', 'email' );
update_option( 'wpa11y_notify_email', 'web@example.edu' );
$before = wpa11y_get_result( 10 );
check( 'results: one malformed issue refuses the body', wpa11y_rest_results( new WP_REST_Request( array(), $auth, array_merge( $body, array( 'issues' => array( wt_issue( 'error', 'link-name', 'a' ), 'junk' ) ) ) ) )->get_error_code(), 'wpa11y_bad_issue' );
check( 'results: too many issues refused', wpa11y_rest_results( new WP_REST_Request( array(), $auth, array_merge( $body, array( 'issues' => array_fill( 0, 1001, wt_issue( 'error', 'x', 'p' ) ) ) ) ) )->get_error_code(), 'wpa11y_bad_issues' );
check( 'results: non-object body refused', wpa11y_rest_results( new WP_REST_Request( array(), $auth, null ) )->get_error_code(), 'wpa11y_bad_post' );
check( 'results: refused payloads leave the stored result alone', wpa11y_get_result( 10 ), $before );
check( 'results: refused payloads send no email', $GLOBALS['wt_mail'], array() );
