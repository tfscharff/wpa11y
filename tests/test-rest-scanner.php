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
check( 'routes: scanner routes registered', array_keys( $GLOBALS['wt_routes'] ), array( 'wpa11y/v1/pages', 'wpa11y/v1/results', 'wpa11y/v1/scan-complete' ) );
check( 'routes: every route checks permission', count( array_filter( $GLOBALS['wt_routes'], function ( $r ) { return empty( $r['permission_callback'] ) || '__return_true' === $r['permission_callback']; } ) ), 0 );
