<?php
wt_reset( array( 'posts' => array( array( 'ID' => 10 ), array( 'ID' => 11, 'post_status' => 'draft' ) ) ) );
wpa11y_save_result( 10, wt_result( array(
	wt_issue( 'error', 'image-alt', 'img' ),
	wt_issue( 'warning', 'color-contrast', '#a' ),
	wt_issue( 'warning', 'color-contrast', 'a[title="<x>"]' ),
) ) );

$html = wpa11y_render_detail( 10 );
check( 'detail: errors heading with count', strpos( $html, 'Errors <span class="wpa11y-count">(1)</span>' ) !== false, true );
check( 'detail: warnings heading focusable target', strpos( $html, '<h2 id="wpa11y-h-warnings" tabindex="-1">' ) !== false, true );
check( 'detail: only warnings get a Dismiss button', substr_count( $html, 'wpa11y-dismiss-open' ), 2 );
check( 'detail: selector escaped', array( strpos( $html, '&lt;x&gt;' ) !== false, strpos( $html, '<x>' ) ), array( true, false ) );
check( 'detail: grouped by rule', substr_count( $html, '<details class="wpa11y-group"' ), 2 );
check( 'detail: help link', strpos( $html, 'href="https://dequeuniversity.com/rules/axe/4.11/image-alt"' ) !== false, true );
check( 'detail: last scanned shown', strpos( $html, 'Last scanned Sep 30, 2026 10:00 am.' ) !== false, true );
check( 'detail: never scanned', strpos( wt_render_unscanned(), 'Not scanned yet.' ) !== false, true );

$key = wpa11y_issue_key( 'color-contrast', '#a' );
$res = wpa11y_rest_dismiss( new WP_REST_Request( array( 'post' => 10, 'key' => $key, 'note' => '  <b>ok</b> by eye ' ) ) );
check( 'dismiss: counts move', $res['counts'], array( 'errors' => 1, 'warnings' => 1, 'dismissed' => 1, 'state' => 'issues' ) );
check( 'dismiss: note sanitized', wpa11y_rows()[0]['note'], 'ok by eye' );
check( 'dismiss: user recorded', wpa11y_rows()[0]['user_id'], '5' );
check( 'dismiss: html shows who', strpos( $res['html'], 'Dismissed by Pat Editor' ) !== false, true );
check( 'dismiss: summary', $res['summary'], '1 error, 1 warning needing review.' );
check( 'dismiss: errors refused', wpa11y_rest_dismiss( new WP_REST_Request( array( 'post' => 10, 'key' => wpa11y_issue_key( 'image-alt', 'img' ) ) ) )->get_error_code(), 'wpa11y_error_not_dismissable' );
check( 'dismiss: unknown issue refused', wpa11y_rest_dismiss( new WP_REST_Request( array( 'post' => 10, 'key' => 'stale' ) ) )->get_error_data(), array( 'status' => 409 ) );
check( 'dismiss: draft refused', wpa11y_rest_dismiss( new WP_REST_Request( array( 'post' => 11, 'key' => $key ) ) )->get_error_code(), 'wpa11y_bad_post' );

$res = wpa11y_rest_undismiss( new WP_REST_Request( array( 'id' => 1 ) ) );
check( 'undismiss: back to review', $res['counts']['warnings'], 2 );
check( 'undismiss: twice is 404', wpa11y_rest_undismiss( new WP_REST_Request( array( 'id' => 1 ) ) )->get_error_data(), array( 'status' => 404 ) );

update_option( 'wpa11y_github_token', 'tok' );
// Last scan well in the past, so the test doesn't depend on today's clock.
wpa11y_save_result( 10, wt_result( array( wt_issue( 'error', 'image-alt', 'img' ) ), '2020-01-01T00:00:00Z' ) );
$res = wpa11y_rest_rescan( new WP_REST_Request( array( 'post' => 10 ) ) );
check( 'rescan: dispatched once', count( $GLOBALS['wt_http']['calls'] ), 1 );
check( 'rescan: sends the post permalink', json_decode( $GLOBALS['wt_http']['calls'][0]['args']['body'], true )['inputs']['url'], WT_SITE . '/page-10/' );
check( 'rescan: pending', $res['pending'], true );
check( 'rescan: button marked busy, still focusable', array( strpos( $res['html'], 'aria-disabled="true"' ) !== false, strpos( $res['html'], ' disabled' ) ), array( true, false ) );
wpa11y_rest_rescan( new WP_REST_Request( array( 'post' => 10 ) ) );
check( 'rescan: repeat click while pending does not dispatch again', count( $GLOBALS['wt_http']['calls'] ), 1 );
check( 'status: still pending', wpa11y_rest_status( new WP_REST_Request( array( 'post' => 10 ) ) )['pending'], true );
wpa11y_save_result( 10, wt_result( array( wt_issue( 'error', 'image-alt', 'img' ) ), wpa11y_iso( time() + 5 ) ) );
$res = wpa11y_rest_status( new WP_REST_Request( array( 'post' => 10 ) ) );
check( 'status: done when the new scan lands', array( $res['pending'], $res['summary'] ), array( false, '1 error, 0 warnings needing review.' ) );
check( 'rescan: draft refused', wpa11y_rest_rescan( new WP_REST_Request( array( 'post' => 11 ) ) )->get_error_code(), 'wpa11y_bad_post' );

wt_reset( array( 'posts' => array( array( 'ID' => 10 ) ) ) );
update_option( 'wpa11y_github_token', 'tok' );
$GLOBALS['wt_http']['response'] = array( 'response' => array( 'code' => 404 ), 'body' => '{"message":"Not Found"}' );
check( 'rescan: GitHub failure returned', wpa11y_rest_rescan( new WP_REST_Request( array( 'post' => 10 ) ) )->get_error_code(), 'wpa11y_github' );
check( 'rescan: failure leaves nothing pending', get_post_meta( 10, WPA11Y_RESCAN_META, true ), '' );

$failed             = wt_result( array( wt_issue( 'error', 'image-alt', 'img' ) ) );
$failed['error']    = 'Navigation timeout';
$failed['error_at'] = '2026-09-30T11:00:00Z';
wpa11y_save_result( 10, $failed );
$html = wpa11y_render_detail( 10 );
check( 'detail: failure explained', strpos( $html, 'The scan on Sep 30, 2026 11:00 am failed: Navigation timeout' ) !== false, true );
check( 'detail: stale issues labelled', strpos( $html, 'from the last successful scan' ) !== false, true );

$GLOBALS['wt_routes'] = array();
wpa11y_register_routes();
check( 'routes: all seven registered', count( $GLOBALS['wt_routes'] ), 7 );
check( 'routes: editor routes use editor permission', $GLOBALS['wt_routes']['wpa11y/v1/dismiss']['permission_callback'], 'wpa11y_editor_permission' );
