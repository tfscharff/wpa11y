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
check( 'dismiss: note trimmed, kept as typed', wpa11y_rows()[0]['note'], '<b>ok</b> by eye' );
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
check( 'routes: all eight registered', count( $GLOBALS['wt_routes'] ), 8 );
check( 'routes: dismiss-group uses editor permission', $GLOBALS['wt_routes']['wpa11y/v1/dismiss-group']['permission_callback'], 'wpa11y_editor_permission' );
check( 'routes: editor routes use editor permission', $GLOBALS['wt_routes']['wpa11y/v1/dismiss']['permission_callback'], 'wpa11y_editor_permission' );

// Review fixes.
wt_reset( array( 'posts' => array( array( 'ID' => 10 ) ) ) );
wpa11y_save_result( 10, wt_result( array( wt_issue( 'warning', 'color-contrast', '#a' ) ), '2020-01-01T00:00:00Z' ) );
update_post_meta( 10, WPA11Y_RESCAN_META, wpa11y_iso( time() - 1200 ) );
$res = wpa11y_rest_status( new WP_REST_Request( array( 'post' => 10 ) ) );
check( 'status: timed-out rescan is reported as such', array( $res['pending'], $res['rescan'] ), array( false, 'timed_out' ) );
$res = wpa11y_rest_dismiss( new WP_REST_Request( array( 'post' => 10, 'key' => wpa11y_issue_key( 'color-contrast', '#a' ), 'note' => ' needs a <main> landmark ' ) ) );
check( 'dismiss: note keeps tag names', wpa11y_rows()[0]['note'], 'needs a <main> landmark' );
check( 'dismiss: note escaped on display', strpos( $res['html'], 'needs a &lt;main&gt; landmark' ) !== false, true );

// Dismiss all in a rule group.
wt_reset( array( 'posts' => array( array( 'ID' => 10 ) ) ) );
$three = array(
	wt_issue( 'error', 'image-alt', 'img' ),
	wt_issue( 'warning', 'color-contrast', '#a' ),
	wt_issue( 'warning', 'color-contrast', '#b' ),
	wt_issue( 'warning', 'color-contrast', '#c' ),
	wt_issue( 'warning', 'link-name', 'a' ),
);
wpa11y_save_result( 10, wt_result( $three ) );
$html = wpa11y_render_detail( 10 );
check( 'dismiss all: button only on warning groups of two or more', substr_count( $html, 'wpa11y-dismiss-all-open' ), 1 );
check( 'dismiss all: button names the count', strpos( $html, 'Dismiss all 3…<span class="screen-reader-text"> color-contrast warnings</span>' ) !== false, true );
check( 'dismiss all: form labelled', strpos( $html, 'Why are all 3 OK?' ) !== false, true );
$k = function ( $sel ) { return wpa11y_issue_key( 'color-contrast', $sel ); };
wpa11y_add_dismissal( 10, 'color-contrast', '#c', 'earlier', 5 );
check( 'dismiss all: stale key refuses the whole group', wpa11y_rest_dismiss_group( new WP_REST_Request( array( 'post' => 10, 'keys' => array( $k( '#a' ), $k( '#gone' ) ) ) ) )->get_error_data(), array( 'status' => 409 ) );
check( 'dismiss all: nothing written on a stale key', count( wpa11y_rows() ), 1 );
check( 'dismiss all: errors refused', wpa11y_rest_dismiss_group( new WP_REST_Request( array( 'post' => 10, 'keys' => array( $k( '#a' ), wpa11y_issue_key( 'image-alt', 'img' ) ) ) ) )->get_error_code(), 'wpa11y_error_not_dismissable' );
check( 'dismiss all: empty refused', wpa11y_rest_dismiss_group( new WP_REST_Request( array( 'post' => 10, 'keys' => array() ) ) )->get_error_data(), array( 'status' => 400 ) );
check( 'dismiss all: non-list refused', wpa11y_rest_dismiss_group( new WP_REST_Request( array( 'post' => 10, 'keys' => 'x' ) ) )->get_error_code(), 'wpa11y_bad_keys' );
check( 'dismiss all: non-string key is stale', wpa11y_rest_dismiss_group( new WP_REST_Request( array( 'post' => 10, 'keys' => array( array( 'x' ) ) ) ) )->get_error_code(), 'wpa11y_not_current' );
$res = wpa11y_rest_dismiss_group( new WP_REST_Request( array( 'post' => 10, 'keys' => array( $k( '#a' ), $k( '#b' ), $k( '#c' ) ), 'note' => ' halo labels, checked by eye ' ) ) );
check( 'dismiss all: counts move', $res['counts'], array( 'errors' => 1, 'warnings' => 1, 'dismissed' => 3, 'state' => 'issues' ) );
check( 'dismiss all: one row per item, already-dismissed skipped', count( wpa11y_rows() ), 3 );
check( 'dismiss all: note on each new row, old note kept', array_column( wpa11y_rows(), 'note', 'selector' ), array( '#b' => 'halo labels, checked by eye', '#a' => 'halo labels, checked by eye', '#c' => 'earlier' ) );
check( 'dismiss all: undo stays per item', substr_count( $res['html'], 'wpa11y-undo' ), 3 );
check( 'dismiss all: draft refused', wpa11y_rest_dismiss_group( new WP_REST_Request( array( 'post' => 11, 'keys' => array( $k( '#a' ) ) ) ) )->get_error_code(), 'wpa11y_bad_post' );
